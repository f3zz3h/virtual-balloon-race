<?php
/**
 * Emails to entrants when a race launches and when it finishes.
 *
 * The emails themselves are WooCommerce emails (WooCommerce → Settings → Emails), so organisers
 * can switch them off and change their wording there. This class decides when to send them.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notices launches and finishes, and queues one email per entrant.
 */
class LVBR_Race_Emails {

	const LAUNCHED = 'launched';
	const RESULTS  = 'results';

	const CHECK_HOOK = 'lvbr_check_race_emails';
	const SEND_HOOK  = 'lvbr_send_race_email';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
		add_action( self::CHECK_HOOK, array( __CLASS__, 'check' ) );
		add_action( self::SEND_HOOK, array( __CLASS__, 'send' ), 10, 3 );
		add_action( 'lvbr_after_tick', array( __CLASS__, 'check' ) );
		add_action( 'lvbr_race_stage_changed', array( __CLASS__, 'check' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Add the two emails to WooCommerce → Settings → Emails.
	 *
	 * @param array $emails Email classes.
	 * @return array
	 */
	public function register_emails( $emails ) {
		require_once LVBR_PLUGIN_DIR . 'includes/emails/class-lvbr-email-race.php';
		require_once LVBR_PLUGIN_DIR . 'includes/emails/class-lvbr-email-launched.php';
		require_once LVBR_PLUGIN_DIR . 'includes/emails/class-lvbr-email-results.php';

		$emails['LVBR_Email_Launched'] = new LVBR_Email_Launched();
		$emails['LVBR_Email_Results']  = new LVBR_Email_Results();

		return $emails;
	}

	/**
	 * Check hourly even when automatic movement updates are off (races still launch and finish by the clock).
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CHECK_HOOK );
		}
	}

	/**
	 * Stop the hourly check (plugin deactivated).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CHECK_HOOK );
	}

	/**
	 * Queue launch and results emails for races that have launched or finished since the last check.
	 *
	 * A race's `launch_email_sent_at` / `results_email_sent_at` is set when its emails are queued, so each
	 * goes out once. A race that finishes before its launch email went out gets only the results email.
	 */
	public static function check() {
		foreach ( LVBR_Races::all() as $race ) {
			$status = LVBR_Races::status( $race );

			if ( LVBR_Races::FINISHED === $status ) {
				if ( ! $race->launch_email_sent_at ) {
					LVBR_Races::set_time( (int) $race->id, 'launch_email_sent_at', time() );
				}
				if ( ! $race->results_email_sent_at ) {
					self::queue( $race, self::RESULTS );
				}
			} elseif ( LVBR_Races::FLYING === $status && ! $race->launch_email_sent_at ) {
				self::queue( $race, self::LAUNCHED );
			}
		}
	}

	/**
	 * Queue one email per entrant in a race and mark the race as sent.
	 *
	 * @param object $race Race.
	 * @param string $type launched or results.
	 * @return int Emails queued.
	 */
	public static function queue( $race, $type ) {
		LVBR_Races::set_time( (int) $race->id, self::RESULTS === $type ? 'results_email_sent_at' : 'launch_email_sent_at', time() );

		$recipients = self::recipients( (int) $race->id );

		foreach ( $recipients as $email ) {
			$args = array( (int) $race->id, $type, $email );

			// WooCommerce's Action Scheduler sends them in the background, a batch at a time.
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::SEND_HOOK, $args, 'lvbr' );
			} else {
				self::send( ...$args );
			}
		}

		$counts = (array) get_option( 'lvbr_email_counts', array() );

		$counts[ $race->id ][ $type ] = count( $recipients );
		update_option( 'lvbr_email_counts', $counts, false );

		return count( $recipients );
	}

	/**
	 * Entrants' email addresses in a race (balloons still in the race only).
	 *
	 * @param int $race_id Race ID.
	 * @return string[]
	 */
	public static function recipients( $race_id ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$rows  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT LOWER(owner_email) FROM {$table} WHERE race_id = %d AND withdrawn_at IS NULL AND owner_email <> ''", $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		return array_values( array_filter( $rows, 'is_email' ) );
	}

	/**
	 * Send one entrant their email (run by Action Scheduler).
	 *
	 * @param int    $race_id Race ID.
	 * @param string $type    launched or results.
	 * @param string $email   Entrant's email address.
	 */
	public static function send( $race_id, $type, $email ) {
		$race = LVBR_Races::get( (int) $race_id );

		if ( ! $race || ! function_exists( 'WC' ) ) {
			return;
		}

		$balloons = self::balloons_for( $race, $email );

		if ( ! $balloons ) {
			return; // Withdrawn since it was queued.
		}

		$emails = WC()->mailer()->get_emails();
		$class  = self::RESULTS === $type ? 'LVBR_Email_Results' : 'LVBR_Email_Launched';

		if ( isset( $emails[ $class ] ) && $emails[ $class ] instanceof LVBR_Email_Race ) {
			$emails[ $class ]->trigger( $race, $email, $balloons );
		}
	}

	/**
	 * One entrant's balloons in a race, ready for an email: name, colour, team, position, distance, link.
	 *
	 * @param object $race  Race.
	 * @param string $email Entrant's email address.
	 * @return array[]
	 */
	public static function balloons_for( $race, $email ) {
		global $wpdb;

		$table   = LVBR_Balloons::table();
		$order   = LVBR_Races::ranking_column( $race );
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE race_id = %d AND withdrawn_at IS NULL AND LOWER(owner_email) = %s ORDER BY {$order} DESC, id ASC", (int) $race->id, strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$teams   = LVBR_Teams::by_id( $race );
		$ranks   = array();
		$entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE race_id = %d AND withdrawn_at IS NULL AND is_burst = 0", (int) $race->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		if ( $teams ) {
			foreach ( LVBR_Teams::standings( $race ) as $standing ) {
				$ranks[ $standing['id'] ] = $standing['rank'];
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$team  = isset( $teams[ (int) $row->team_id ] ) ? $teams[ (int) $row->team_id ] : null;
			$out[] = array(
				'name'      => $row->balloon_name,
				'owner'     => $row->owner_name,
				'color'     => $row->balloon_color,
				'burst'     => (bool) $row->is_burst,
				'rank'      => LVBR_Balloons::rank( $row ),
				'entries'   => $entries,
				'distance'  => LVBR_Balloons::ranked_distance( $row, $race ),
				'team'      => $team ? $team->name : '',
				'team_rank' => $team && isset( $ranks[ (int) $team->id ] ) ? $ranks[ (int) $team->id ] : null,
				'teams'     => count( $teams ),
				'url'       => LVBR_Pages::tracking_url( $race, (int) $row->id ),
			);
		}

		return $out;
	}

	/**
	 * Email status for the race screen.
	 *
	 * @param object $race Race.
	 * @return string[] type => description.
	 */
	public static function status_lines( $race ) {
		$counts = (array) get_option( 'lvbr_email_counts', array() );
		$lines  = array();

		foreach ( array( self::LAUNCHED => 'launch_email_sent_at', self::RESULTS => 'results_email_sent_at' ) as $type => $column ) {
			$label = self::LAUNCHED === $type ? __( 'Launch email', 'lumaha-virtual-balloon-race' ) : __( 'Results email', 'lumaha-virtual-balloon-race' );

			if ( $race->{$column} ) {
				$count = isset( $counts[ $race->id ][ $type ] ) ? (int) $counts[ $race->id ][ $type ] : null;
				$lines[ $type ] = null === $count
					/* translators: 1: email name, 2: date */
					? sprintf( __( '%1$s: done %2$s (before emails were added, or nobody to send to)', 'lumaha-virtual-balloon-race' ), $label, LVBR_Races::format_date( $race->{$column} ) )
					/* translators: 1: email name, 2: number of people, 3: date */
					: sprintf( _n( '%1$s: sent to %2$s person, %3$s', '%1$s: sent to %2$s people, %3$s', $count, 'lumaha-virtual-balloon-race' ), $label, number_format_i18n( $count ), LVBR_Races::format_date( $race->{$column} ) );
			} else {
				$lines[ $type ] = self::LAUNCHED === $type
					/* translators: %s: email name */
					? sprintf( __( '%s: sends when the race launches', 'lumaha-virtual-balloon-race' ), $label )
					/* translators: %s: email name */
					: sprintf( __( '%s: sends when the race finishes', 'lumaha-virtual-balloon-race' ), $label );
			}
		}

		return $lines;
	}

	/**
	 * 1.8.0: races that have already launched or finished count as emailed, so upgrading sends nothing old.
	 */
	public static function mark_past_races_sent() {
		global $wpdb;

		$table = LVBR_Races::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET launch_email_sent_at = %s WHERE launch_email_sent_at IS NULL AND launch_at <= %s", $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET results_email_sent_at = %s WHERE results_email_sent_at IS NULL AND end_at IS NOT NULL AND end_at <= %s", $now, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		// phpcs:enable
		LVBR_Races::flush();
	}
}
