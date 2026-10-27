<?php
/**
 * Admin race dashboard: leaderboard per race, manual advance, CSV export, burst toggle.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Balloon Race admin page.
 */
class LVBR_Admin {

	const PAGE = 'lvbr-admin';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register the Balloon Race admin page.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Balloon Race', 'lumaha-virtual-balloon-race' ),
			__( 'Balloon Race', 'lumaha-virtual-balloon-race' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_admin_page' ),
			'dashicons-flag',
			56
		);

		add_submenu_page(
			self::PAGE,
			__( 'Leaderboard', 'lumaha-virtual-balloon-race' ),
			__( 'Leaderboard', 'lumaha-virtual-balloon-race' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin CSS on the plugin screen.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_lvbr-admin' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lvbr-admin',
			LVBR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			LVBR_Balloons::asset_version( 'assets/css/admin.css' )
		);
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
	 * Race selected on the page (?race=ID), defaulting to the current race.
	 *
	 * @return object|null
	 */
	private function selected_race() {
		$id = isset( $_GET['race'] ) ? absint( $_GET['race'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $id ? LVBR_Races::get( $id ) : LVBR_Races::current();
	}

	/**
	 * Handle admin POST actions (advance race, CSV export, burst toggle).
	 */
	public function handle_admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_REQUEST['lvbr_action'] ) || empty( $_REQUEST['page'] ) || self::PAGE !== $_REQUEST['page'] ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_REQUEST['lvbr_action'] ) );

		if ( 'export_csv' === $action ) {
			check_admin_referer( 'lvbr_export_csv' );
			$this->export_csv( isset( $_GET['race'] ) ? absint( $_GET['race'] ) : 0 );
		}

		if ( 'advance' === $action && isset( $_POST['lvbr_advance_nonce'] ) ) {
			check_admin_referer( 'lvbr_advance_race', 'lvbr_advance_nonce' );
			$this->advance_race( isset( $_POST['lvbr_race_id'] ) ? absint( $_POST['lvbr_race_id'] ) : 0 );
		}

		if ( 'toggle_burst' === $action && isset( $_GET['balloon_id'] ) ) {
			check_admin_referer( 'lvbr_toggle_burst_' . intval( $_GET['balloon_id'] ) );
			$this->toggle_burst( intval( $_GET['balloon_id'] ) );
		}

		if ( 'toggle_withdrawn' === $action && isset( $_GET['balloon_id'] ) ) {
			check_admin_referer( 'lvbr_toggle_withdrawn_' . intval( $_GET['balloon_id'] ) );
			$this->toggle_withdrawn( intval( $_GET['balloon_id'] ) );
		}
	}

	/**
	 * Manual "Advance now" for one flying race: runs the same tick as the scheduler.
	 *
	 * @param int $race_id Race ID.
	 */
	private function advance_race( $race_id ) {
		$race = LVBR_Races::get( $race_id );

		if ( ! $race || LVBR_Races::FLYING !== LVBR_Races::status( $race ) ) {
			wp_safe_redirect( self::url( array( 'race' => $race_id, 'lvbr_notice' => 'not_flying' ) ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_admin_actions().
		$hours = isset( $_POST['lvbr_hours'] ) ? floatval( wp_unslash( $_POST['lvbr_hours'] ) ) : 1;
		$hours = min( 24 * 14, max( 0, $hours ) );
		$args  = array(
			'race_id' => (int) $race->id,
			'source'  => 'manual',
		);

		if ( ! empty( $_POST['lvbr_use_range'] ) ) {
			$min            = isset( $_POST['lvbr_min_km'] ) ? max( 0, floatval( wp_unslash( $_POST['lvbr_min_km'] ) ) ) : 10;
			$max            = isset( $_POST['lvbr_max_km'] ) ? floatval( wp_unslash( $_POST['lvbr_max_km'] ) ) : 50;
			$args['min_km'] = $min;
			$args['max_km'] = max( $min, $max );
		}
		// phpcs:enable

		$summary = LVBR_Cron::tick( $hours, $args );

		wp_safe_redirect(
			self::url(
				array(
					'race'         => (int) $race->id,
					'lvbr_notice'   => $summary['locked'] ? 'locked' : 'advanced',
					'lvbr_advanced' => $summary['advanced'],
					'lvbr_burst'    => $summary['burst'],
				)
			)
		);
		exit;
	}

	/**
	 * Toggle burst status for a single balloon.
	 *
	 * @param int $balloon_id Balloon ID.
	 */
	private function toggle_burst( $balloon_id ) {
		global $wpdb;

		$balloon_id = intval( $balloon_id );
		$table      = LVBR_Balloons::table();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_burst, race_id FROM {$table} WHERE id = %d", $balloon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		if ( ! $row ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$table,
			array( 'is_burst' => $row->is_burst ? 0 : 1 ),
			array( 'id' => $balloon_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_safe_redirect(
			self::url(
				array(
					'race'       => (int) $row->race_id,
					'lvbr_notice' => 'toggled',
				)
			)
		);
		exit;
	}

	/**
	 * Withdraw a balloon by hand (e.g. a duplicate or unsuitable name), or reinstate a withdrawn one.
	 *
	 * Balloons withdrawn by hand stay withdrawn when their order changes; reinstating
	 * a refunded order's balloon lasts until the order next changes.
	 *
	 * @param int $balloon_id Balloon ID.
	 */
	private function toggle_withdrawn( $balloon_id ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT withdrawn_at, race_id FROM {$table} WHERE id = %d", $balloon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		if ( ! $row ) {
			return;
		}

		if ( null === $row->withdrawn_at ) {
			LVBR_Balloons::withdraw( array( $balloon_id ), 'admin' );
		} else {
			LVBR_Balloons::restore( array( $balloon_id ) );
		}

		wp_safe_redirect(
			self::url(
				array(
					'race'       => (int) $row->race_id,
					'lvbr_notice' => 'toggled',
				)
			)
		);
		exit;
	}

	/**
	 * Stream a CSV export of balloon and donor rows (one race, or all when 0).
	 *
	 * @param int $race_id Race ID.
	 */
	private function export_csv( $race_id ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$races = LVBR_Races::table();
		$where = $race_id ? $wpdb->prepare( 'WHERE b.race_id = %d', $race_id ) : '';
		$teams = LVBR_Teams::table();
		$rows  = $wpdb->get_results( "SELECT b.*, r.name AS race_name, t.name AS team_name FROM {$table} b LEFT JOIN {$races} r ON r.id = b.race_id LEFT JOIN {$teams} t ON t.id = b.team_id {$where} ORDER BY b.race_id DESC, b.from_start_km DESC, b.id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		$race     = $race_id ? LVBR_Races::get( $race_id ) : null;
		$filename = 'virtual-balloon-race-' . ( $race ? $race->slug . '-' : '' ) . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the CSV to the browser; no file is written.

		fputcsv(
			$output,
			array( 'ID', 'Race', 'Team', 'Order ID', 'User ID', 'Owner Name', 'Owner Email', 'Balloon Name', 'Balloon Color', 'Helium Level', 'From Start (km)', 'Travelled (km)', 'Is Burst', 'Withdrawn', 'Latitude', 'Longitude', 'Created At' )
		);

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$output,
				array(
					$row['id'],
					$row['race_name'],
					$row['team_name'],
					$row['order_id'],
					$row['user_id'],
					$row['owner_name'],
					$row['owner_email'],
					$row['balloon_name'],
					$row['balloon_color'],
					$row['helium_level'],
					$row['from_start_km'],
					$row['distance_km'],
					$row['is_burst'] ? 'Yes' : 'No',
					$row['withdrawn_at'] ? LVBR_Balloons::withdrawn_label( $row['withdrawn_reason'] ) : '',
					$row['latitude'],
					$row['longitude'],
					$row['created_at'],
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streams the CSV to the browser (php://output); no file is written.
		exit;
	}

	/**
	 * Render the Balloon Race admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lumaha-virtual-balloon-race' ) );
		}

		global $wpdb;

		$race  = $this->selected_race();
		$races = LVBR_Races::all();
		?>
		<div class="wrap lvbr-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Balloon Race', 'lumaha-virtual-balloon-race' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( LVBR_Admin_Races::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add race', 'lumaha-virtual-balloon-race' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_admin_notices(); ?>

			<?php if ( ! $race ) : ?>
				<div class="lvbr-admin-panel">
					<p><?php esc_html_e( 'No races yet. Create a race to start selling balloon entries.', 'lumaha-virtual-balloon-race' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( LVBR_Admin_Races::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add race', 'lumaha-virtual-balloon-race' ); ?></a></p>
				</div>
			</div>
				<?php
				return;
			endif;

			// Paging: ?per_page= (0 = all, default from Settings) and ?paged=.
			$per_page = LVBR_Frontend::per_page( isset( $_GET['per_page'] ) ? sanitize_key( wp_unslash( $_GET['per_page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$total    = LVBR_Races::balloon_count( (int) $race->id );
			$pages    = $per_page ? max( 1, (int) ceil( $total / $per_page ) ) : 1;
			$paged    = min( $pages, max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$limit    = $per_page ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $paged - 1 ) * $per_page ) : '';

			$table    = LVBR_Balloons::table();
			$order    = LVBR_Races::ranking_column( $race );
			// Withdrawn balloons are listed last.
			$balloons = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE race_id = %d ORDER BY withdrawn_at IS NOT NULL, {$order} DESC, id ASC", (int) $race->id ) . $limit ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $limit is prepared above.
			$status   = LVBR_Races::status( $race );
			$teams    = LVBR_Teams::by_id( $race );

			$export_url = wp_nonce_url(
				self::url(
					array(
						'lvbr_action' => 'export_csv',
						'race'       => (int) $race->id,
					)
				),
				'lvbr_export_csv'
			);
			?>

			<form method="get" class="lvbr-race-picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<label for="lvbr-race-select"><strong><?php esc_html_e( 'Race:', 'lumaha-virtual-balloon-race' ); ?></strong></label>
				<select id="lvbr-race-select" name="race" onchange="this.form.submit()">
					<?php foreach ( $races as $option ) : ?>
						<option value="<?php echo esc_attr( (string) $option->id ); ?>" <?php selected( (int) $option->id, (int) $race->id ); ?>>
							<?php echo esc_html( $option->name . ' — ' . LVBR_Races::status_label( $option ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Show', 'lumaha-virtual-balloon-race' ), 'secondary', '', false ); ?></noscript>
				<a href="<?php echo esc_url( LVBR_Admin_Races::url( array( 'action' => 'edit', 'race' => (int) $race->id ) ) ); ?>"><?php esc_html_e( 'Edit race', 'lumaha-virtual-balloon-race' ); ?></a>
			</form>

			<?php LVBR_Admin_Races::render_race_summary( $race ); ?>

			<section class="lvbr-admin-panel">
				<h2><?php esc_html_e( 'Advance the race', 'lumaha-virtual-balloon-race' ); ?></h2>
				<?php $this->render_schedule_summary( $race ); ?>

				<?php if ( LVBR_Races::FLYING === $status ) : ?>
					<?php
					$interval      = LVBR_Races::interval_seconds( $race );
					$default_hours = $interval ? round( $interval / HOUR_IN_SECONDS, 2 ) : 1;
					?>
					<p><?php esc_html_e( 'Move every active balloon in this race now, using the same wind and speed rules as automatic updates. Burst chances scale with the hours simulated.', 'lumaha-virtual-balloon-race' ); ?></p>

					<form method="post" action="<?php echo esc_url( self::url( array( 'race' => (int) $race->id ) ) ); ?>" class="lvbr-advance-form">
						<?php wp_nonce_field( 'lvbr_advance_race', 'lvbr_advance_nonce' ); ?>
						<input type="hidden" name="lvbr_action" value="advance" />
						<input type="hidden" name="lvbr_race_id" value="<?php echo esc_attr( (string) $race->id ); ?>" />

						<label for="lvbr_hours"><?php esc_html_e( 'Hours to simulate', 'lumaha-virtual-balloon-race' ); ?></label>
						<input type="number" id="lvbr_hours" name="lvbr_hours" value="<?php echo esc_attr( (string) $default_hours ); ?>" min="0" max="336" step="0.01" />

						<label class="lvbr-advance-form__range">
							<input type="checkbox" name="lvbr_use_range" value="1" />
							<?php esc_html_e( 'Use a fixed km range instead', 'lumaha-virtual-balloon-race' ); ?>
						</label>

						<label for="lvbr_min_km"><?php esc_html_e( 'Min km', 'lumaha-virtual-balloon-race' ); ?></label>
						<input type="number" id="lvbr_min_km" name="lvbr_min_km" value="10" min="0" step="0.01" />

						<label for="lvbr_max_km"><?php esc_html_e( 'Max km', 'lumaha-virtual-balloon-race' ); ?></label>
						<input type="number" id="lvbr_max_km" name="lvbr_max_km" value="50" min="0" step="0.01" />

						<?php submit_button( __( 'Advance now', 'lumaha-virtual-balloon-race' ), 'primary', 'submit', false ); ?>
					</form>
				<?php elseif ( LVBR_Races::FINISHED === $status ) : ?>
					<p><?php esc_html_e( 'This race has finished. Balloons no longer move.', 'lumaha-virtual-balloon-race' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Balloons wait at their start point until launch. Use “Launch now” on the race to start early.', 'lumaha-virtual-balloon-race' ); ?></p>
				<?php endif; ?>
			</section>

			<section class="lvbr-admin-panel">
				<h2><?php esc_html_e( 'Export', 'lumaha-virtual-balloon-race' ); ?></h2>
				<p>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>">
						<?php esc_html_e( 'Export this race’s balloon &amp; donor data (CSV)', 'lumaha-virtual-balloon-race' ); ?>
					</a>
				</p>
			</section>

			<section class="lvbr-admin-panel">
				<h2>
					<?php
					/* translators: %d: number of balloons */
					echo esc_html( sprintf( _n( 'Leaderboard (%d balloon)', 'Leaderboard (%d balloons)', $total, 'lumaha-virtual-balloon-race' ), $total ) );
					?>
				</h2>
				<?php $this->render_tablenav( $race, $total, $per_page, $paged, $pages, 'top' ); ?>
				<table class="widefat striped lvbr-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'lumaha-virtual-balloon-race' ); ?></th>
							<?php if ( $teams ) : ?>
								<th><?php esc_html_e( 'Team', 'lumaha-virtual-balloon-race' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Color', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Helium', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'From launch (km)', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Travelled (km)', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Burst Status', 'lumaha-virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'lumaha-virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $balloons ) ) : ?>
							<tr>
								<td colspan="<?php echo esc_attr( (string) ( $teams ? 10 : 9 ) ); ?>"><?php esc_html_e( 'No balloons in this race yet.', 'lumaha-virtual-balloon-race' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $balloons as $balloon ) : ?>
								<?php
								$toggle_url = wp_nonce_url(
									self::url(
										array(
											'lvbr_action' => 'toggle_burst',
											'balloon_id' => (int) $balloon->id,
										)
									),
									'lvbr_toggle_burst_' . (int) $balloon->id
								);
								$withdraw_url = wp_nonce_url(
									self::url(
										array(
											'lvbr_action' => 'toggle_withdrawn',
											'balloon_id' => (int) $balloon->id,
										)
									),
									'lvbr_toggle_withdrawn_' . (int) $balloon->id
								);
								$withdrawn    = null !== $balloon->withdrawn_at;
								?>
								<tr<?php echo $withdrawn ? ' class="lvbr-withdrawn"' : ''; ?>>
									<td><?php echo esc_html( (string) $balloon->id ); ?></td>
									<td>
										<?php echo esc_html( $balloon->owner_name ); ?>
										<?php if ( $balloon->owner_email ) : ?>
											<br /><small><?php echo esc_html( $balloon->owner_email ); ?></small>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( (int) $balloon->logo_attachment_id ) : ?>
											<?php echo wp_get_attachment_image( (int) $balloon->logo_attachment_id, array( 24, 24 ), false, array( 'class' => 'lvbr-admin-logo' ) ); ?>
										<?php endif; ?>
										<?php echo esc_html( $balloon->balloon_name ); ?>
										<?php if ( $balloon->has_custom_start ) : ?>
											<br /><small><?php esc_html_e( 'Custom launch point', 'lumaha-virtual-balloon-race' ); ?></small>
										<?php endif; ?>
									</td>
									<?php if ( $teams ) : ?>
										<td><?php echo esc_html( isset( $teams[ (int) $balloon->team_id ] ) ? $teams[ (int) $balloon->team_id ]->name : '—' ); ?></td>
									<?php endif; ?>
									<td><?php echo esc_html( $balloon->balloon_color ); ?></td>
									<td><?php echo esc_html( $balloon->helium_level ); ?></td>
									<td<?php echo 'from_start_km' === $order ? ' class="lvbr-ranked"' : ''; ?>><?php echo esc_html( number_format_i18n( (float) $balloon->from_start_km, 2 ) ); ?></td>
									<td<?php echo 'distance_km' === $order ? ' class="lvbr-ranked"' : ''; ?>><?php echo esc_html( number_format_i18n( (float) $balloon->distance_km, 2 ) ); ?></td>
									<td>
										<?php if ( $withdrawn ) : ?>
											<span class="lvbr-status lvbr-status--withdrawn"><?php echo esc_html( LVBR_Balloons::withdrawn_label( $balloon->withdrawn_reason ) ); ?></span>
										<?php elseif ( $balloon->is_burst ) : ?>
											<span class="lvbr-status lvbr-status--burst"><?php esc_html_e( 'Burst', 'lumaha-virtual-balloon-race' ); ?></span>
										<?php else : ?>
											<span class="lvbr-status lvbr-status--active"><?php esc_html_e( 'Active', 'lumaha-virtual-balloon-race' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="lvbr-row-actions">
										<?php if ( ! $withdrawn ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $toggle_url ); ?>">
												<?php echo $balloon->is_burst ? esc_html__( 'Undo burst', 'lumaha-virtual-balloon-race' ) : esc_html__( 'Mark burst', 'lumaha-virtual-balloon-race' ); ?>
											</a>
										<?php endif; ?>
										<a class="button button-small" href="<?php echo esc_url( $withdraw_url ); ?>"<?php echo $withdrawn ? '' : ' onclick="return confirm(\'' . esc_js( __( 'Withdraw this balloon? It disappears from the map, leaderboard and team scores, and stops moving. You can reinstate it later.', 'lumaha-virtual-balloon-race' ) ) . '\');"'; ?>>
											<?php echo $withdrawn ? esc_html__( 'Reinstate', 'lumaha-virtual-balloon-race' ) : esc_html__( 'Withdraw', 'lumaha-virtual-balloon-race' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<?php $this->render_tablenav( $race, $total, $per_page, $paged, $pages, 'bottom' ); ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Schedule and last-update summary for a race.
	 *
	 * @param object $race Race.
	 */
	private function render_schedule_summary( $race ) {
		$labels  = LVBR_Settings::interval_labels();
		$own     = LVBR_Races::movement_overrides( $race );
		$slug    = (string) LVBR_Races::movement( $race )['interval'];
		$next    = LVBR_Cron::next_for_race( $race );
		$last    = LVBR_Cron::last_summary( $race->id );
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$setting = admin_url( 'admin.php?page=' . LVBR_Settings::PAGE );

		echo '<ul class="lvbr-schedule-summary">';

		echo '<li><strong>' . esc_html__( 'Automatic updates:', 'lumaha-virtual-balloon-race' ) . '</strong> ' . esc_html( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug );
		if ( isset( $own['interval'] ) ) {
			echo ' ' . esc_html__( '(this race)', 'lumaha-virtual-balloon-race' );
		} else {
			echo ' ' . esc_html__( '(site default)', 'lumaha-virtual-balloon-race' ) . ' &middot; <a href="' . esc_url( $setting ) . '">' . esc_html__( 'Change', 'lumaha-virtual-balloon-race' ) . '</a>';
		}
		echo '</li>';

		if ( $next && LVBR_Races::FLYING === LVBR_Races::status( $race ) ) {
			echo '<li><strong>' . esc_html__( 'Next update:', 'lumaha-virtual-balloon-race' ) . '</strong> ' . esc_html( wp_date( $format, $next ) ) . '</li>';
		}

		if ( ! empty( $last['time'] ) ) {
			echo '<li><strong>' . esc_html__( 'Last update:', 'lumaha-virtual-balloon-race' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: hours, 3: balloons moved, 4: balloons burst */
					__( '%1$s — %2$s h simulated, %3$d moved, %4$d burst', 'lumaha-virtual-balloon-race' ),
					wp_date( $format, (int) $last['time'] ),
					number_format_i18n( (float) $last['hours'], 2 ),
					(int) $last['advanced'],
					(int) $last['burst']
				)
			);
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Flash notices after admin actions.
	 */
	private function render_admin_notices() {
		if ( empty( $_GET['lvbr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['lvbr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'locked'     => array( 'warning', __( 'Another update is already running. Try again in a moment.', 'lumaha-virtual-balloon-race' ) ),
			'toggled'    => array( 'success', __( 'Balloon status updated.', 'lumaha-virtual-balloon-race' ) ),
			'not_flying' => array( 'warning', __( 'Only a race that is flying can be advanced.', 'lumaha-virtual-balloon-race' ) ),
		);

		if ( 'advanced' === $notice ) {
			$messages['advanced'] = array(
				'success',
				sprintf(
					/* translators: 1: balloons advanced, 2: balloons burst */
					__( 'Race updated: %1$d balloon(s) advanced, %2$d burst.', 'lumaha-virtual-balloon-race' ),
					isset( $_GET['lvbr_advanced'] ) ? intval( $_GET['lvbr_advanced'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					isset( $_GET['lvbr_burst'] ) ? intval( $_GET['lvbr_burst'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				),
			);
		}

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/**
	 * Entries-per-page picker and page links above and below the admin leaderboard.
	 *
	 * @param object $race     Race.
	 * @param int    $total    Balloons in the race.
	 * @param int    $per_page Entries per page (0 = all).
	 * @param int    $paged    Current page.
	 * @param int    $pages    Number of pages.
	 * @param string $which    'top' or 'bottom'.
	 */
	private function render_tablenav( $race, $total, $per_page, $paged, $pages, $which ) {
		$links = paginate_links(
			array(
				'base'      => self::url(
					array(
						'race'     => (int) $race->id,
						'per_page' => (string) $per_page,
						'paged'    => '%#%',
					)
				),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '&lsaquo;',
				'next_text' => '&rsaquo;',
			)
		);
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php if ( 'top' === $which ) : ?>
				<form method="get" class="alignleft actions">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
					<input type="hidden" name="race" value="<?php echo esc_attr( (string) $race->id ); ?>" />
					<label for="lvbr-per-page"><?php esc_html_e( 'Show', 'lumaha-virtual-balloon-race' ); ?></label>
					<select id="lvbr-per-page" name="per_page" onchange="this.form.submit()">
						<?php foreach ( LVBR_Frontend::per_page_options() as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( $option ? number_format_i18n( $option ) : __( 'All', 'lumaha-virtual-balloon-race' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php esc_html_e( 'per page', 'lumaha-virtual-balloon-race' ); ?>
					<noscript><?php submit_button( __( 'Apply', 'lumaha-virtual-balloon-race' ), 'secondary', '', false ); ?></noscript>
				</form>
			<?php endif; ?>
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					/* translators: %s: number of balloons */
					echo esc_html( sprintf( _n( '%s balloon', '%s balloons', $total, 'lumaha-virtual-balloon-race' ), number_format_i18n( $total ) ) );
					?>
				</span>
				<?php if ( $links ) : ?>
					<span class="pagination-links"><?php echo wp_kses_post( $links ); ?></span>
				<?php endif; ?>
			</div>
			<br class="clear" />
		</div>
		<?php
	}
}
