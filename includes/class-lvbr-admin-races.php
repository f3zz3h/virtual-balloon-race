<?php
/**
 * Balloon Race → Races: schedule and manage races.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Race list and add/edit screen.
 */
class LVBR_Admin_Races {

	const PAGE = 'lvbr-races';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 15 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Admin URL for this page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Add the Races submenu.
	 */
	public function register_menu() {
		add_submenu_page(
			LVBR_Admin::PAGE,
			__( 'Races', 'lumaha-virtual-balloon-race' ),
			__( 'Races', 'lumaha-virtual-balloon-race' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Admin CSS everywhere on this page; launch picker on the form.
	 *
	 * @param string $hook Admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		LVBR_Settings::register_leaflet();
		wp_enqueue_style( 'lvbr-admin', LVBR_PLUGIN_URL . 'assets/css/admin.css', array( 'lvbr-leaflet' ), LVBR_Balloons::asset_version( 'assets/css/admin.css' ) );

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			wp_enqueue_script( 'lvbr-admin-settings', LVBR_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'lvbr-leaflet' ), LVBR_Balloons::asset_version( 'assets/js/admin-settings.js' ), true );
			wp_enqueue_script( 'lvbr-admin-teams', LVBR_PLUGIN_URL . 'assets/js/admin-teams.js', array(), LVBR_Balloons::asset_version( 'assets/js/admin-teams.js' ), true );
			wp_enqueue_media();
			wp_enqueue_script( 'lvbr-admin-sponsors', LVBR_PLUGIN_URL . 'assets/js/admin-sponsors.js', array( 'media-editor' ), LVBR_Balloons::asset_version( 'assets/js/admin-sponsors.js' ), true );
			wp_enqueue_script( 'lvbr-admin-prizes', LVBR_PLUGIN_URL . 'assets/js/admin-prizes.js', array(), LVBR_Balloons::asset_version( 'assets/js/admin-prizes.js' ), true );
		}
	}

	/**
	 * Save / delete / launch now / finish now.
	 */
	public function handle_actions() {
		if ( empty( $_REQUEST['page'] ) || self::PAGE !== $_REQUEST['page'] || empty( $_REQUEST['lvbr_race_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['lvbr_race_action'] ) );
		$id     = isset( $_REQUEST['race'] ) ? absint( $_REQUEST['race'] ) : 0;

		check_admin_referer( 'lvbr_race_' . $action . '_' . $id );

		switch ( $action ) {
			case 'save':
				$this->save( $id );
				break;

			case 'delete':
				$result = LVBR_Races::delete( $id );
				$this->redirect( array(), is_wp_error( $result ) ? $result->get_error_message() : 'deleted' );
				break;

			case 'launch_now':
				$this->launch_now( $id );
				break;

			case 'finish_now':
				$race = LVBR_Races::get( $id );
				if ( $race && LVBR_Races::FLYING === LVBR_Races::status( $race ) ) {
					LVBR_Races::set_time( $id, 'end_at', time() );
					do_action( 'lvbr_race_stage_changed', $id );
				}
				$this->redirect( array( 'action' => 'edit', 'race' => $id ), 'finished' );
				break;

			case 'create_product':
				$race   = LVBR_Races::get( $id );
				$result = $race && LVBR_Product_Type::available() ? LVBR_Product_Type::create_for_race( $race ) : new WP_Error( 'lvbr_no_woocommerce', __( 'WooCommerce is needed to sell entries.', 'lumaha-virtual-balloon-race' ) );

				if ( is_wp_error( $result ) ) {
					$this->redirect( array( 'action' => 'edit', 'race' => $id ), $result->get_error_message() );
				}

				wp_safe_redirect( admin_url( 'post.php?post=' . (int) $result . '&action=edit' ) );
				exit;
		}
	}

	/**
	 * Redirect back with a notice key (or an error message).
	 *
	 * @param array  $args   Query args.
	 * @param string $notice Notice key or message.
	 */
	private function redirect( $args, $notice ) {
		wp_safe_redirect( self::url( array_merge( $args, array( 'lvbr_notice' => rawurlencode( $notice ) ) ) ) );
		exit;
	}

	/**
	 * Movement settings this race can change: blank uses the site setting, shown as the placeholder.
	 *
	 * @param array $movement The race's own values (key => value).
	 */
	private static function render_movement_panel( $movement ) {
		$fields = LVBR_Settings::fields();
		$site   = LVBR_Settings::all();
		?>
		<section class="lvbr-admin-panel lvbr-race-movement">
			<details<?php echo $movement ? ' open' : ''; ?>>
				<summary><h2><?php esc_html_e( 'Movement', 'lumaha-virtual-balloon-race' ); ?></h2></summary>
				<p class="description">
					<?php esc_html_e( 'How often this race updates, how fast its balloons fly and how often they burst. Leave a box blank to use the site setting (shown in grey), so a short demo and a week-long race can run side by side.', 'lumaha-virtual-balloon-race' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LVBR_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Site settings', 'lumaha-virtual-balloon-race' ); ?></a>
				</p>
				<table class="form-table" role="presentation">
					<?php
					foreach ( LVBR_Settings::movement_keys() as $key ) :
						$field = $fields[ $key ];
						$id    = 'lvbr_race_movement_' . $key;
						$value = isset( $movement[ $key ] ) ? (string) $movement[ $key ] : '';
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php if ( 'select' === $field['type'] ) : ?>
									<select id="<?php echo esc_attr( $id ); ?>" name="lvbr_race[movement][<?php echo esc_attr( $key ); ?>]">
										<option value="">
											<?php
											/* translators: %s: the site setting */
											echo esc_html( sprintf( __( 'Default (%s)', 'lumaha-virtual-balloon-race' ), $field['options'][ $site[ $key ] ] ?? $site[ $key ] ) );
											?>
										</option>
										<?php foreach ( $field['options'] as $option => $label ) : ?>
											<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="number" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="lvbr_race[movement][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( (string) $field['min'] ); ?>" max="<?php echo esc_attr( (string) $field['max'] ); ?>" step="<?php echo esc_attr( (string) $field['step'] ); ?>" placeholder="<?php echo esc_attr( (string) $site[ $key ] ); ?>" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</details>
		</section>
		<?php
	}

	/**
	 * Save the add/edit form.
	 *
	 * @param int $id Race ID (0 = new).
	 */
	private function save( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle_actions().
		$in = isset( $_POST['lvbr_race'] ) && is_array( $_POST['lvbr_race'] ) ? wp_unslash( $_POST['lvbr_race'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$data = array(
			'name'          => isset( $in['name'] ) ? sanitize_text_field( $in['name'] ) : '',
			'launch_label'  => isset( $in['launch_label'] ) ? sanitize_text_field( $in['launch_label'] ) : '',
			'launch_lat'    => isset( $in['launch_lat'] ) ? sanitize_text_field( $in['launch_lat'] ) : '',
			'launch_lng'    => isset( $in['launch_lng'] ) ? sanitize_text_field( $in['launch_lng'] ) : '',
			'sales_open_at' => LVBR_Races::local_input_to_gmt( isset( $in['sales_open_at'] ) ? sanitize_text_field( $in['sales_open_at'] ) : '' ),
			'launch_at'     => LVBR_Races::local_input_to_gmt( isset( $in['launch_at'] ) ? sanitize_text_field( $in['launch_at'] ) : '' ),
			'end_at'        => LVBR_Races::local_input_to_gmt( isset( $in['end_at'] ) ? sanitize_text_field( $in['end_at'] ) : '' ),
			'ranking'       => isset( $in['ranking'] ) ? sanitize_key( $in['ranking'] ) : LVBR_Races::FROM_START,
			'flight_mode'   => isset( $in['flight_mode'] ) ? sanitize_key( $in['flight_mode'] ) : '',
			'entry_limit'   => isset( $in['entry_limit'] ) ? absint( $in['entry_limit'] ) : 0,
			'movement'      => isset( $in['movement'] ) && is_array( $in['movement'] ) ? array_map( 'sanitize_text_field', $in['movement'] ) : array(),
		);

		if ( LVBR_Teams::enabled() ) {
			$data['team_scoring'] = isset( $in['team_scoring'] ) ? sanitize_key( $in['team_scoring'] ) : '';
		}

		$result = LVBR_Races::save( $data, $id );

		if ( is_wp_error( $result ) ) {
			// Keep what was typed so the form can be corrected.
			set_transient( 'lvbr_race_form_' . get_current_user_id(), $in, 5 * MINUTE_IN_SECONDS );
			$this->redirect( array( 'action' => $id ? 'edit' : 'new', 'race' => $id ), $result->get_error_message() );
		}

		$notice = $id ? 'updated' : 'created';

		$messages = array();

		if ( LVBR_Teams::enabled() ) {
			$blocked = LVBR_Teams::save_for_race( $result, LVBR_Teams::clean_rows( isset( $in['teams'] ) ? $in['teams'] : array() ) );
			if ( $blocked ) {
				/* translators: %s: team names */
				$messages[] = sprintf( __( 'These teams have balloons so they were kept: %s.', 'lumaha-virtual-balloon-race' ), implode( ', ', $blocked ) );
			}
		}

		$messages = array_merge( $messages, LVBR_Sponsors::save_for_race( $result, LVBR_Sponsors::clean_rows( isset( $in['sponsors'] ) ? $in['sponsors'] : array() ) ) );

		// After sponsors, so a prize can name a sponsor saved in the same form only from the next save.
		LVBR_Prizes::save_for_race( $result, LVBR_Prizes::clean_rows( isset( $in['prizes'] ) ? $in['prizes'] : array(), $result ) );

		if ( $messages ) {
			/* translators: %s: what couldn't be saved as asked */
			$notice = sprintf( __( 'Race saved. %s', 'lumaha-virtual-balloon-race' ), implode( ' ', $messages ) );
		}

		$this->redirect( array( 'action' => 'edit', 'race' => $result ), $notice );
	}

	/**
	 * Start a race immediately (closes entries).
	 *
	 * @param int $id Race ID.
	 */
	private function launch_now( $id ) {
		global $wpdb;

		$race = LVBR_Races::get( $id );

		if ( $race && in_array( LVBR_Races::status( $race ), array( LVBR_Races::UPCOMING, LVBR_Races::ON_SALE ), true ) ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$row = array( 'launch_at' => $now );

			if ( LVBR_Races::ts( $race->sales_open_at ) >= time() ) {
				$row['sales_open_at'] = null;
			}

			if ( $race->end_at && LVBR_Races::ts( $race->end_at ) <= time() ) {
				$row['end_at'] = null;
			}

			$wpdb->update( LVBR_Races::table(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			LVBR_Races::flush();

			/**
			 * A race's stage changed by hand (Launch now / Finish now), e.g. to send its emails straight away.
			 *
			 * @param int $race_id Race ID.
			 */
			do_action( 'lvbr_race_stage_changed', $id );
		}

		$this->redirect( array( 'action' => 'edit', 'race' => $id ), 'launched' );
	}

	/**
	 * Render list or form.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lumaha-virtual-balloon-race' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['race'] ) ? absint( $_GET['race'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap lvbr-admin lvbr-races">';

		if ( 'new' === $action || ( 'edit' === $action && LVBR_Races::get( $id ) ) ) {
			$this->render_form( 'edit' === $action ? LVBR_Races::get( $id ) : null );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Notices after actions.
	 */
	private function render_notice() {
		if ( empty( $_GET['lvbr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// redirect() URL-encodes the notice once and PHP decodes $_GET once, so no further decoding is needed.
		$key      = sanitize_text_field( wp_unslash( $_GET['lvbr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'created'  => __( 'Race created.', 'lumaha-virtual-balloon-race' ),
			'updated'  => __( 'Race saved.', 'lumaha-virtual-balloon-race' ),
			'deleted'  => __( 'Race deleted.', 'lumaha-virtual-balloon-race' ),
			'launched' => __( 'Race launched. Entries are closed and balloons will move on the next update.', 'lumaha-virtual-balloon-race' ),
			'finished' => __( 'Race finished. Balloons have stopped.', 'lumaha-virtual-balloon-race' ),
		);

		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $key ) . '</p></div>';
		}
	}

	/**
	 * Race list.
	 */
	private function render_list() {
		$races = LVBR_Races::all();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Races', 'lumaha-virtual-balloon-race' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add race', 'lumaha-virtual-balloon-race' ); ?></a>
		<hr class="wp-header-end" />

		<?php $this->render_notice(); ?>

		<p class="description"><?php esc_html_e( 'Entries can only be bought while a race is taking entries (between “Entries open” and launch). Balloons move only while a race is flying.', 'lumaha-virtual-balloon-race' ); ?></p>

		<table class="widefat striped lvbr-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Race', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Stage', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Entries open', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Launch', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Ends', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Balloons', 'lumaha-virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Shortcode', 'lumaha-virtual-balloon-race' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $races ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No races yet.', 'lumaha-virtual-balloon-race' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $races as $race ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'race' => (int) $race->id ) ) ); ?>"><?php echo esc_html( $race->name ); ?></a></strong>
							<div class="row-actions">
								<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'race' => (int) $race->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'lumaha-virtual-balloon-race' ); ?></a> |
								<a href="<?php echo esc_url( LVBR_Admin::url( array( 'race' => (int) $race->id ) ) ); ?>"><?php esc_html_e( 'Leaderboard', 'lumaha-virtual-balloon-race' ); ?></a>
							</div>
						</td>
						<td><?php self::status_pill( $race ); ?></td>
						<td><?php echo esc_html( $race->sales_open_at ? LVBR_Races::format_date( $race->sales_open_at ) : __( 'Now', 'lumaha-virtual-balloon-race' ) ); ?></td>
						<td><?php echo esc_html( LVBR_Races::format_date( $race->launch_at ) ); ?></td>
						<td><?php echo esc_html( $race->end_at ? LVBR_Races::format_date( $race->end_at ) : __( 'When finished by hand', 'lumaha-virtual-balloon-race' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( LVBR_Races::balloon_count( $race->id ) ) ); ?></td>
						<td><code>[lvbr_map race="<?php echo esc_html( $race->slug ); ?>"]</code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Stage badge.
	 *
	 * @param object $race Race.
	 */
	public static function status_pill( $race ) {
		printf(
			'<span class="lvbr-status lvbr-status--%1$s">%2$s</span>',
			esc_attr( LVBR_Races::status( $race ) ),
			esc_html( LVBR_Races::status_label( $race ) )
		);
	}

	/**
	 * Compact race summary with quick actions (also used on the leaderboard page).
	 *
	 * @param object $race Race.
	 */
	public static function render_race_summary( $race ) {
		$status = LVBR_Races::status( $race );
		$launch = LVBR_Races::launch_point( $race );
		?>
		<section class="lvbr-admin-panel lvbr-race-summary">
			<h2><?php echo esc_html( $race->name ); ?> <?php self::status_pill( $race ); ?></h2>
			<ul class="lvbr-race-facts">
				<li><strong><?php esc_html_e( 'Entries open:', 'lumaha-virtual-balloon-race' ); ?></strong> <?php echo esc_html( $race->sales_open_at ? LVBR_Races::format_date( $race->sales_open_at ) : __( 'Now', 'lumaha-virtual-balloon-race' ) ); ?></li>
				<li><strong><?php esc_html_e( 'Launch (entries close):', 'lumaha-virtual-balloon-race' ); ?></strong> <?php echo esc_html( LVBR_Races::format_date( $race->launch_at ) ); ?></li>
				<li>
					<strong><?php esc_html_e( 'Entries:', 'lumaha-virtual-balloon-race' ); ?></strong>
					<?php
					$taken = LVBR_Race_Entries::entries_taken( $race );
					if ( (int) $race->entry_limit ) {
						$left = LVBR_Race_Entries::places_left( $race );
						echo esc_html(
							$left
								/* translators: 1: entries taken, 2: entry limit, 3: places left */
								? sprintf( __( '%1$s of %2$s (%3$s left)', 'lumaha-virtual-balloon-race' ), number_format_i18n( $taken ), number_format_i18n( (int) $race->entry_limit ), number_format_i18n( (int) $left ) )
								/* translators: 1: entries taken, 2: entry limit */
								: sprintf( __( '%1$s of %2$s — sold out', 'lumaha-virtual-balloon-race' ), number_format_i18n( $taken ), number_format_i18n( (int) $race->entry_limit ) )
						);
					} else {
						/* translators: %s: entries taken */
						echo esc_html( sprintf( __( '%s (no limit)', 'lumaha-virtual-balloon-race' ), number_format_i18n( $taken ) ) );
					}
					?>
				</li>
				<li><strong><?php esc_html_e( 'Ends:', 'lumaha-virtual-balloon-race' ); ?></strong> <?php echo esc_html( $race->end_at ? LVBR_Races::format_date( $race->end_at ) : __( 'When finished by hand', 'lumaha-virtual-balloon-race' ) ); ?></li>
				<li><strong><?php esc_html_e( 'Launch point:', 'lumaha-virtual-balloon-race' ); ?></strong> <?php echo esc_html( ( $launch['label'] ? $launch['label'] . ' ' : '' ) . sprintf( '(%.4f, %.4f)', $launch['lat'], $launch['lng'] ) ); ?></li>
				<li><strong><?php esc_html_e( 'Shortcodes:', 'lumaha-virtual-balloon-race' ); ?></strong> <code>[lvbr_map race="<?php echo esc_html( $race->slug ); ?>"]</code> <code>[lvbr_leaderboard race="<?php echo esc_html( $race->slug ); ?>"]</code></li>
				<?php if ( LVBR_Product_Type::available() ) : ?>
					<li>
						<strong><?php esc_html_e( 'Emails to entrants:', 'lumaha-virtual-balloon-race' ); ?></strong>
						<?php echo esc_html( implode( ' · ', LVBR_Race_Emails::status_lines( $race ) ) ); ?>
						&middot; <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ); ?>"><?php esc_html_e( 'Edit or switch off in WooCommerce → Settings → Emails', 'lumaha-virtual-balloon-race' ); ?></a>
					</li>
				<?php endif; ?>
			</ul>
			<p class="lvbr-race-actions">
				<?php if ( in_array( $status, array( LVBR_Races::UPCOMING, LVBR_Races::ON_SALE ), true ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'launch_now', $race->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Launch this race now? Entries will close.', 'lumaha-virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Launch now', 'lumaha-virtual-balloon-race' ); ?></a>
				<?php endif; ?>
				<?php if ( LVBR_Races::FLYING === $status ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'finish_now', $race->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Finish this race now? Balloons will stop where they are.', 'lumaha-virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Finish now', 'lumaha-virtual-balloon-race' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	/**
	 * Teams and team scoring for a race (only while teams are switched on).
	 *
	 * @param object|null $race Race being edited, or null for a new race.
	 */
	private function render_teams_panel( $race ) {
		if ( ! LVBR_Teams::enabled() ) {
			return;
		}

		$defaults = LVBR_Teams::clean_rows( (array) LVBR_Settings::get( 'default_teams' ) );

		if ( $race ) {
			$teams = array();
			foreach ( LVBR_Teams::for_race( (int) $race->id ) as $team ) {
				$teams[] = array(
					'id'       => (int) $team->id,
					'name'     => $team->name,
					'color'    => $team->color,
					'balloons' => LVBR_Teams::balloon_count( (int) $team->id ),
				);
			}
		} else {
			// A new race starts with the default teams.
			$teams = $defaults;
		}

		$scoring = $race ? (string) $race->team_scoring : '';
		$labels  = LVBR_Teams::scoring_labels();
		?>
		<section class="lvbr-admin-panel">
			<h2><?php esc_html_e( 'Teams', 'lumaha-virtual-balloon-race' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Buyers must pick one of these teams. Leave the list empty to run this race without teams.', 'lumaha-virtual-balloon-race' ); ?></p>
			<?php LVBR_Teams::render_editor( 'lvbr_race[teams]', $teams, $defaults ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="lvbr_race_team_scoring"><?php esc_html_e( 'Team scoring', 'lumaha-virtual-balloon-race' ); ?></label></th>
					<td>
						<select id="lvbr_race_team_scoring" name="lvbr_race[team_scoring]">
							<option value="">
								<?php
								/* translators: %s: the default choice from Settings */
								echo esc_html( sprintf( __( 'Default (%s)', 'lumaha-virtual-balloon-race' ), $labels[ LVBR_Teams::scoring( null ) ] ) );
								?>
							</option>
							<?php foreach ( $labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $scoring, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Entry products that sell this race, with a button to create one.
	 *
	 * @param object $race Race.
	 */
	private static function render_products( $race ) {
		if ( ! LVBR_Product_Type::available() ) {
			return;
		}

		$locked = LVBR_Product_Type::products_for_race( $race->id );
		$any    = LVBR_Product_Type::products_for_race( 0 );
		?>
		<section class="lvbr-admin-panel lvbr-race-products">
			<h2><?php esc_html_e( 'Entry products', 'lumaha-virtual-balloon-race' ); ?></h2>
			<?php if ( $locked || $any ) : ?>
				<ul>
					<?php foreach ( array_merge( $locked, $any ) as $product ) : ?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
							&middot; <?php echo wp_kses_post( $product->get_price() !== '' ? wc_price( (float) $product->get_price() ) : __( 'no price', 'lumaha-virtual-balloon-race' ) ); ?>
							&middot; <?php echo esc_html( 'publish' === $product->get_status() ? __( 'Published', 'lumaha-virtual-balloon-race' ) : __( 'Not published', 'lumaha-virtual-balloon-race' ) ); ?>
							<?php if ( in_array( $product, $any, true ) ) : ?>
								&middot; <em><?php esc_html_e( 'any race taking entries', 'lumaha-virtual-balloon-race' ); ?></em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No product sells entries for this race yet.', 'lumaha-virtual-balloon-race' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'create_product', $race->id ) ); ?>"><?php esc_html_e( 'Create entry product', 'lumaha-virtual-balloon-race' ); ?></a>
				<span class="description"><?php esc_html_e( 'Makes a draft "Balloon race entry" product for this race. Set its price and publish it.', 'lumaha-virtual-balloon-race' ); ?></span>
			</p>
		</section>
		<?php
	}

	/**
	 * Nonced action URL.
	 *
	 * @param string $action Action.
	 * @param int    $id     Race ID.
	 * @return string
	 */
	private static function action_url( $action, $id ) {
		return wp_nonce_url(
			self::url(
				array(
					'lvbr_race_action' => $action,
					'race'            => (int) $id,
				)
			),
			'lvbr_race_' . $action . '_' . (int) $id
		);
	}

	/**
	 * Add / edit form.
	 *
	 * @param object|null $race Race being edited.
	 */
	private function render_form( $race ) {
		$id       = $race ? (int) $race->id : 0;
		$defaults = LVBR_Balloons::launch_point();
		$values   = array(
			'name'          => $race ? $race->name : '',
			'launch_label'  => $race ? $race->launch_label : $defaults['label'],
			'launch_lat'    => $race ? (float) $race->launch_lat : $defaults['lat'],
			'launch_lng'    => $race ? (float) $race->launch_lng : $defaults['lng'],
			'sales_open_at' => $race ? LVBR_Races::gmt_to_local_input( $race->sales_open_at ) : '',
			'launch_at'     => $race ? LVBR_Races::gmt_to_local_input( $race->launch_at ) : '',
			'end_at'        => $race ? LVBR_Races::gmt_to_local_input( $race->end_at ) : '',
			'ranking'       => LVBR_Races::ranking( $race ),
			'flight_mode'   => $race ? (string) $race->flight_mode : '',
			'entry_limit'   => $race && (int) $race->entry_limit ? (string) (int) $race->entry_limit : '',
		);

		// Re-fill after a validation error.
		$typed = null;
		$saved = get_transient( 'lvbr_race_form_' . get_current_user_id() );
		if ( is_array( $saved ) ) {
			delete_transient( 'lvbr_race_form_' . get_current_user_id() );
			$values = array_merge( $values, array_map( 'sanitize_text_field', array_intersect_key( $saved, $values ) ) );
			$typed  = isset( $saved['movement'] ) && is_array( $saved['movement'] ) ? $saved['movement'] : array();
		}

		$movement = is_array( $typed ) ? array_map( 'sanitize_text_field', $typed ) : LVBR_Races::movement_overrides( $race );

		$count = $id ? LVBR_Races::balloon_count( $id ) : 0;
		?>
		<h1><?php echo $race ? esc_html__( 'Edit race', 'lumaha-virtual-balloon-race' ) : esc_html__( 'Add race', 'lumaha-virtual-balloon-race' ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All races', 'lumaha-virtual-balloon-race' ); ?></a></p>

		<?php $this->render_notice(); ?>

		<?php
		if ( $race ) {
			self::render_race_summary( $race );
			self::render_products( $race );
		}
		?>

		<form method="post" action="<?php echo esc_url( self::url( array( 'race' => $id ) ) ); ?>">
			<?php wp_nonce_field( 'lvbr_race_save_' . $id ); ?>
			<input type="hidden" name="lvbr_race_action" value="save" />

			<section class="lvbr-admin-panel">
				<h2><?php esc_html_e( 'Race details', 'lumaha-virtual-balloon-race' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lvbr_race_name"><?php esc_html_e( 'Name', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td><input type="text" class="regular-text" id="lvbr_race_name" name="lvbr_race[name]" value="<?php echo esc_attr( $values['name'] ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Summer Fair Balloon Race 2026', 'lumaha-virtual-balloon-race' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_sales_open_at"><?php esc_html_e( 'Entries open', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="lvbr_race_sales_open_at" name="lvbr_race[sales_open_at]" value="<?php echo esc_attr( $values['sales_open_at'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Leave blank to take entries straight away.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_launch_at"><?php esc_html_e( 'Launch', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="lvbr_race_launch_at" name="lvbr_race[launch_at]" value="<?php echo esc_attr( $values['launch_at'] ); ?>" required />
							<p class="description"><?php esc_html_e( 'Entries close and balloons start moving at this time.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_end_at"><?php esc_html_e( 'Ends', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="lvbr_race_end_at" name="lvbr_race[end_at]" value="<?php echo esc_attr( $values['end_at'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Balloons stop and the leaderboard is final after this time. Leave blank to finish by hand.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_entry_limit"><?php esc_html_e( 'Entry limit', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="lvbr_race_entry_limit" name="lvbr_race[entry_limit]" value="<?php echo esc_attr( $values['entry_limit'] ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'None', 'lumaha-virtual-balloon-race' ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. The most balloons this race can have; the shop shows "sold out" once it’s reached. Unpaid orders (bank transfer on hold, or awaiting payment from the last day) hold their places. Leave blank for no limit.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_flight_mode"><?php esc_html_e( 'Flight mode', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<select id="lvbr_race_flight_mode" name="lvbr_race[flight_mode]">
								<option value="">
									<?php
									$modes = LVBR_Flight_Modes::all();
									/* translators: %s: the default choice from Settings */
									echo esc_html( sprintf( __( 'Default (%s)', 'lumaha-virtual-balloon-race' ), $modes[ LVBR_Flight_Modes::for_race( null ) ]['label'] ) );
									?>
								</option>
								<?php foreach ( $modes as $value => $mode ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['flight_mode'], $value ); ?>><?php echo esc_html( $mode['label'] . ' — ' . $mode['tagline'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'How quickly balloons lose lift. Drift is true to life, Soar slows gradually, Jetstream never slows. See Settings for details and distance estimates.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_race_ranking"><?php esc_html_e( 'Winner decided by', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<select id="lvbr_race_ranking" name="lvbr_race[ranking]">
								<?php foreach ( LVBR_Races::ranking_labels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['ranking'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Distance from launch is the usual rule: the balloon furthest away in a straight line wins. Custom launch points are measured from their own start. Distance travelled adds up the whole trail, so wandering balloons score more. Team scores use the same measure.', 'lumaha-virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timezone', 'lumaha-virtual-balloon-race' ); ?></th>
						<td><p class="description"><?php echo esc_html( wp_timezone_string() ); ?></p></td>
					</tr>
				</table>
			</section>

			<?php self::render_movement_panel( $movement ); ?>

			<section class="lvbr-admin-panel">
				<h2><?php esc_html_e( 'Launch point', 'lumaha-virtual-balloon-race' ); ?></h2>
				<div class="lvbr-launch-picker">
					<div class="lvbr-launch-search"<?php echo LVBR_Services::search_enabled() ? '' : ' hidden'; ?>>
						<label for="lvbr-place-search" class="screen-reader-text"><?php esc_html_e( 'Search for a place', 'lumaha-virtual-balloon-race' ); ?></label>
						<input type="search" id="lvbr-place-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search for a place, e.g. Horfield, Bristol', 'lumaha-virtual-balloon-race' ); ?>" />
						<button type="button" class="button" id="lvbr-place-search-btn"><?php esc_html_e( 'Search', 'lumaha-virtual-balloon-race' ); ?></button>
						<ul class="lvbr-place-results" id="lvbr-place-results" hidden></ul>
					</div>
					<div id="lvbr-launch-map" class="lvbr-launch-map" data-zoom="<?php echo esc_attr( (string) LVBR_Settings::get( 'map_zoom' ) ); ?>"></div>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lvbr_launch_label"><?php esc_html_e( 'Launch name', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td><input type="text" class="regular-text" id="lvbr_launch_label" name="lvbr_race[launch_label]" value="<?php echo esc_attr( $values['launch_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_launch_lat"><?php esc_html_e( 'Latitude', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td><input type="number" class="small-text lvbr-coord" id="lvbr_launch_lat" name="lvbr_race[launch_lat]" value="<?php echo esc_attr( (string) $values['launch_lat'] ); ?>" min="-90" max="90" step="any" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lvbr_launch_lng"><?php esc_html_e( 'Longitude', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="number" class="small-text lvbr-coord" id="lvbr_launch_lng" name="lvbr_race[launch_lng]" value="<?php echo esc_attr( (string) $values['launch_lng'] ); ?>" min="-180" max="180" step="any" required />
							<?php if ( $count ) : ?>
								<p class="description"><?php esc_html_e( 'Moving the launch point before launch also moves balloons waiting there (custom launch points stay put).', 'lumaha-virtual-balloon-race' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</section>

			<?php $this->render_teams_panel( $race ); ?>

			<section class="lvbr-admin-panel" id="lvbr-prizes">
				<h2><?php esc_html_e( 'Prizes', 'lumaha-virtual-balloon-race' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Shown on the tracking page, next to Add to basket and in the results email. "Donated by" lists this race\'s saved sponsors: add a new sponsor below and save before picking it here.', 'lumaha-virtual-balloon-race' ); ?></p>
				<?php LVBR_Prizes::render_editor( 'lvbr_race[prizes]', $race ? LVBR_Prizes::for_race( (int) $race->id ) : array(), $race ? LVBR_Sponsors::for_race( (int) $race->id ) : array() ); ?>
			</section>

			<section class="lvbr-admin-panel" id="lvbr-sponsors">
				<h2><?php esc_html_e( 'Sponsors', 'lumaha-virtual-balloon-race' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sponsors belong to this race only. Choosing a tier ticks its usual places; change any you like. Presented by, launch flag, map badge and leaderboard strip have one spot per race.', 'lumaha-virtual-balloon-race' ); ?></p>
				<?php LVBR_Sponsors::render_editor( 'lvbr_race[sponsors]', $race ? LVBR_Sponsors::for_race( (int) $race->id ) : array(), $race ? LVBR_Sponsor_Stats::for_race( (int) $race->id ) : array() ); ?>
				<?php if ( $race && LVBR_Sponsors::for_race( (int) $race->id ) ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'lvbr_sponsor_stats_csv', 'race' => (int) $race->id ), admin_url( 'admin-post.php' ) ), 'lvbr_sponsor_stats_csv_' . (int) $race->id ) ); ?>"><?php esc_html_e( 'Download views and clicks (CSV)', 'lumaha-virtual-balloon-race' ); ?></a>
						<span class="description"><?php esc_html_e( 'How often each sponsor was shown on the race pages, and how often their links were clicked. Only totals are kept: no cookies or visitor details. Emails aren\'t counted.', 'lumaha-virtual-balloon-race' ); ?></span>
					</p>
				<?php endif; ?>
			</section>

			<p class="submit">
				<?php submit_button( $race ? __( 'Save race', 'lumaha-virtual-balloon-race' ) : __( 'Create race', 'lumaha-virtual-balloon-race' ), 'primary', 'submit', false ); ?>
				<?php if ( $race && ! $count ) : ?>
					<a class="button-link-delete lvbr-delete-race" href="<?php echo esc_url( self::action_url( 'delete', $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this race?', 'lumaha-virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Delete race', 'lumaha-virtual-balloon-race' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}
}
