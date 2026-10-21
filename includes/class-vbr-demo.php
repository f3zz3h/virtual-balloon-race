<?php
/**
 * Demo races: a ready-made race with made-up entrants, for trying the plugin or showing it off.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes demo races (Settings → Demo race, or `wp vbr demo`).
 */
class VBR_Demo {

	const OPTION = 'vbr_demo_race_ids';

	/**
	 * 'yes' (the default) or 'no': start a fresh demo race when the current one is about to end.
	 */
	const RENEW_OPTION = 'vbr_demo_renew';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'vbr_settings_page_end', array( $this, 'render_panel' ), 20 );
		add_action( 'admin_post_vbr_demo', array( $this, 'handle_post' ) );
		add_action( VBR_Housekeeping::HOOK, array( __CLASS__, 'renew_if_due' ) );
	}

	/**
	 * Whether demo races renew themselves.
	 *
	 * @return bool
	 */
	public static function renews() {
		return 'no' !== get_option( self::RENEW_OPTION, 'yes' );
	}

	/**
	 * Keep a demo running: once the newest demo race has less than a day left (or has ended),
	 * create a fresh one with as many balloons, then remove the old ones. Runs daily.
	 *
	 * If the new race can't be created, the old ones are kept.
	 *
	 * @param int|null $now Timestamp (for tests).
	 * @return int New race ID, or 0 if nothing was due.
	 */
	public static function renew_if_due( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$ids = self::race_ids();

		if ( ! $ids || ! self::renews() ) {
			return 0;
		}

		$newest = VBR_Races::get( max( $ids ) );
		$end    = $newest ? VBR_Races::ts( $newest->end_at ) : 0;

		if ( $end && $end - $now > DAY_IN_SECONDS ) {
			return 0;
		}

		$race_id = self::create( max( 1, VBR_Races::balloon_count( max( $ids ) ) ) );

		if ( is_wp_error( $race_id ) ) {
			return 0;
		}

		self::remove( array( $race_id ) );

		return $race_id;
	}

	/**
	 * IDs of the demo races that exist.
	 *
	 * @return int[]
	 */
	public static function race_ids() {
		return array_values(
			array_filter(
				array_map( 'intval', (array) get_option( self::OPTION, array() ) ),
				static function ( $id ) {
					return null !== VBR_Races::get( $id );
				}
			)
		);
	}

	/**
	 * Create a demo race: launched three days ago, ending in four, with made-up entrants flown on the wind so far.
	 *
	 * Entrants have no email addresses, so no emails are ever sent for them, and there are no orders.
	 *
	 * @param int $count Balloons.
	 * @return int|WP_Error Race ID.
	 */
	public static function create( $count = 30 ) {
		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // Flying the balloons fetches wind for each area they reach.
		}

		$launch_point = VBR_Balloons::launch_point();
		$now          = time();
		$race_id      = VBR_Races::save(
			array(
				'name'          => __( 'Demo Balloon Race', 'virtual-balloon-race' ),
				'launch_lat'    => $launch_point['lat'],
				'launch_lng'    => $launch_point['lng'],
				'launch_label'  => $launch_point['label'],
				'sales_open_at' => gmdate( 'Y-m-d H:i:s', $now - 10 * DAY_IN_SECONDS ),
				'launch_at'     => gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ),
				'end_at'        => gmdate( 'Y-m-d H:i:s', $now + 4 * DAY_IN_SECONDS ),
			)
		);

		if ( is_wp_error( $race_id ) ) {
			return $race_id;
		}

		// Launch and results emails are for real entrants: mark this race's as done.
		VBR_Races::set_time( $race_id, 'launch_email_sent_at', $now );

		$teams = array();
		if ( VBR_Teams::enabled() ) {
			VBR_Teams::copy_defaults( $race_id );
			$teams = wp_list_pluck( VBR_Teams::for_race( $race_id ), 'id' );
		}

		$first   = array( 'Amelia', 'Oliver', 'Isla', 'George', 'Ava', 'Noah', 'Freya', 'Arthur', 'Ivy', 'Leo', 'Florence', 'Oscar', 'Willow', 'Harry', 'Poppy', 'Theo', 'Rosie', 'Jack', 'Sienna', 'Alfie', 'Priya', 'Mohammed', 'Zara', 'Kofi', 'Aisha', 'Tomasz', 'Mei', 'Ellis' );
		$last    = array( 'Smith', 'Jones', 'Taylor', 'Brown', 'Williams', 'Wilson', 'Davies', 'Patel', 'Robinson', 'Wright', 'Evans', 'Walker', 'Green', 'Hall', 'Wood', 'Clarke', 'Khan', 'Hughes', 'Okafor', 'Nowak', 'Chen', 'Ahmed' );
		$names   = array( 'Sky Rocket', 'Cloud Hopper', 'Big Red', 'Whoosh', 'Captain Float', 'Breezy', 'The Flying Teapot', 'Up Up & Away', 'Lofty', 'Bubbles', 'Zoomer', 'Puffin', 'Starlight', 'Nimbus', 'Jet Stream', 'Dandelion', 'Rainbow Chaser', 'Mr Wobbles', 'Comet', 'Wind Whisperer', 'High Hopes', 'Skylark', 'Marshmallow', 'Thunderbird', 'Little Lift', 'Sunbeam', 'Moonshot', 'Pip', 'Gusty', 'Featherweight', 'Silver Lining', 'Drifter', 'Pegasus', 'Lucky Seven', 'Stratos', 'Cumulus', 'Zephyr' );
		$helium  = array( 'Balanced', 'Balanced', 'Balanced', 'Max Height', 'Eco-Glide' );
		$colours = VBR_Balloons::colors();
		shuffle( $names );

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				VBR_Balloons::table(),
				array(
					'race_id'       => $race_id,
					'team_id'       => $teams ? (int) $teams[ array_rand( $teams ) ] : 0,
					'owner_name'    => $first[ array_rand( $first ) ] . ' ' . $last[ array_rand( $last ) ],
					'owner_email'   => '',
					'balloon_name'  => $names[ $i % count( $names ) ] . ( $i >= count( $names ) ? ' ' . ( (int) floor( $i / count( $names ) ) + 1 ) : '' ),
					'balloon_color' => $colours[ array_rand( $colours ) ],
					'helium_level'  => $helium[ array_rand( $helium ) ],
					'latitude'      => $launch_point['lat'],
					'longitude'     => $launch_point['lng'],
					'start_lat'     => $launch_point['lat'],
					'start_lng'     => $launch_point['lng'],
					'trail_json'    => VBR_Balloons::encode_trail( array( array( $launch_point['lat'], $launch_point['lng'] ) ) ),
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		// Fly the three days since launch in 12-hour steps, then carry on from now.
		for ( $flown = 0; $flown < 72; $flown += 12 ) {
			VBR_Cron::tick( 12, array( 'race_id' => $race_id, 'source' => 'demo' ) );
		}
		VBR_Races::set_time( $race_id, 'last_tick_at', $now );

		update_option( self::OPTION, array_merge( self::race_ids(), array( $race_id ) ), false );

		return $race_id;
	}

	/**
	 * Remove demo races and their balloons and teams.
	 *
	 * @param int[] $keep Demo races to keep (e.g. the one that just replaced them).
	 * @return int Races removed.
	 */
	public static function remove( $keep = array() ) {
		global $wpdb;

		$keep = array_map( 'intval', $keep );
		$ids  = array_diff( self::race_ids(), $keep );

		foreach ( $ids as $id ) {
			$wpdb->delete( VBR_Balloons::table(), array( 'race_id' => $id ), array( '%d' ) );
			VBR_Races::delete( $id ); // Also removes its teams.
		}

		if ( $keep ) {
			update_option( self::OPTION, array_values( array_intersect( self::race_ids(), $keep ) ), false );
		} else {
			delete_option( self::OPTION );
		}

		return count( $ids );
	}

	/**
	 * Create / remove from Settings.
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'virtual-balloon-race' ) );
		}

		check_admin_referer( 'vbr_demo' );

		$do     = isset( $_POST['vbr_do'] ) ? sanitize_key( wp_unslash( $_POST['vbr_do'] ) ) : '';
		$notice = 'created';

		update_option( self::RENEW_OPTION, empty( $_POST['vbr_demo_renew'] ) ? 'no' : 'yes', false );

		if ( 'save' === $do ) {
			$notice = 'saved';
		} elseif ( 'remove' === $do ) {
			self::remove();
			$notice = 'removed';
		} elseif ( is_wp_error( self::create() ) ) {
			$notice = 'failed';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => VBR_Settings::PAGE, 'vbr_demo' => $notice ), admin_url( 'admin.php' ) ) . '#vbr-demo' );
		exit;
	}

	/**
	 * Demo race panel on the Settings screen.
	 */
	public function render_panel() {
		$ids      = self::race_ids();
		$notice   = isset( $_GET['vbr_demo'] ) ? sanitize_key( $_GET['vbr_demo'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'created' => __( 'Demo race created. See it on the tracking page.', 'virtual-balloon-race' ),
			'removed' => __( 'Demo races removed.', 'virtual-balloon-race' ),
			'failed'  => __( 'The demo race could not be created.', 'virtual-balloon-race' ),
			'saved'   => __( 'Demo race setting saved.', 'virtual-balloon-race' ),
		);
		?>
		<section class="vbr-admin-panel" id="vbr-demo">
			<h2><?php esc_html_e( 'Demo race', 'virtual-balloon-race' ); ?></h2>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo 'failed' === $notice ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'A ready-made race to try things out or show the plugin off: 30 made-up balloons, launched three days ago and flown on real wind, ending in four days. Demo entrants have no email addresses or orders, so nobody is emailed. Turn on automatic updates (Race movement) to keep it moving.', 'virtual-balloon-race' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vbr_demo" />
				<?php wp_nonce_field( 'vbr_demo' ); ?>
				<p>
					<label><input type="checkbox" name="vbr_demo_renew" value="1" <?php checked( self::renews() ); ?> /> <?php esc_html_e( 'Keep it running: when a demo race is about to end, replace it with a fresh one', 'virtual-balloon-race' ); ?></label>
					<button type="submit" class="button-link" name="vbr_do" value="save"><?php esc_html_e( 'Save', 'virtual-balloon-race' ); ?></button>
				</p>
				<button type="submit" class="button" name="vbr_do" value="create"><?php esc_html_e( 'Create a demo race', 'virtual-balloon-race' ); ?></button>
				<?php if ( $ids ) : ?>
					<button type="submit" class="button-link button-link-delete" name="vbr_do" value="remove" onclick="return confirm('<?php echo esc_js( __( 'Remove all demo races and their balloons?', 'virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Remove demo races', 'virtual-balloon-race' ); ?></button>
					<span class="description">
						<?php
						/* translators: %s: number of demo races */
						echo esc_html( sprintf( _n( '%s demo race exists.', '%s demo races exist.', count( $ids ), 'virtual-balloon-race' ), number_format_i18n( count( $ids ) ) ) );
						?>
					</span>
				<?php endif; ?>
			</form>
		</section>
		<?php
	}
}
