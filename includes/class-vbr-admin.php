<?php
/**
 * Admin leaderboard screen: manual advance, CSV export, burst toggle.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Balloon Race admin page.
 */
class VBR_Admin {

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
			__( 'Balloon Race', 'virtual-balloon-race' ),
			__( 'Balloon Race', 'virtual-balloon-race' ),
			'manage_options',
			'vbr-admin',
			array( $this, 'render_admin_page' ),
			'dashicons-flag',
			56
		);
	}

	/**
	 * Enqueue admin CSS on the plugin screen.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_vbr-admin' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'vbr-admin',
			VBR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			VBR_VERSION
		);
	}

	/**
	 * Handle admin POST actions (advance race, CSV export, burst toggle).
	 */
	public function handle_admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_REQUEST['vbr_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_REQUEST['vbr_action'] ) );

		if ( 'export_csv' === $action ) {
			check_admin_referer( 'vbr_export_csv' );
			$this->export_csv();
		}

		if ( 'advance' === $action && isset( $_POST['vbr_advance_nonce'] ) ) {
			check_admin_referer( 'vbr_advance_race', 'vbr_advance_nonce' );
			$this->advance_active_balloons();
		}

		if ( 'toggle_burst' === $action && isset( $_GET['balloon_id'] ) ) {
			check_admin_referer( 'vbr_toggle_burst_' . intval( $_GET['balloon_id'] ) );
			$this->toggle_burst( intval( $_GET['balloon_id'] ) );
		}
	}

	/**
	 * Manual "Advance now": runs the same tick as the scheduler.
	 */
	private function advance_active_balloons() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in handle_admin_actions().
		$hours = isset( $_POST['vbr_hours'] ) ? floatval( wp_unslash( $_POST['vbr_hours'] ) ) : 1;
		$hours = min( 24 * 14, max( 0, $hours ) );
		$args  = array( 'source' => 'manual' );

		if ( ! empty( $_POST['vbr_use_range'] ) ) {
			$min            = isset( $_POST['vbr_min_km'] ) ? max( 0, floatval( wp_unslash( $_POST['vbr_min_km'] ) ) ) : 10;
			$max            = isset( $_POST['vbr_max_km'] ) ? floatval( wp_unslash( $_POST['vbr_max_km'] ) ) : 50;
			$args['min_km'] = $min;
			$args['max_km'] = max( $min, $max );
		}
		// phpcs:enable

		$summary = VBR_Cron::tick( $hours, $args );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'vbr-admin',
					'vbr_notice'   => $summary['locked'] ? 'locked' : 'advanced',
					'vbr_advanced' => $summary['advanced'],
					'vbr_burst'    => $summary['burst'],
				),
				admin_url( 'admin.php' )
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
		$table      = VBR_Balloons::table();

		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_burst FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$balloon_id
			)
		);

		if ( null === $current ) {
			return;
		}

		$wpdb->update(
			$table,
			array( 'is_burst' => $current ? 0 : 1 ),
			array( 'id' => $balloon_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'vbr-admin',
					'vbr_notice' => 'toggled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stream a CSV export of all balloon and donor rows.
	 */
	private function export_csv() {
		global $wpdb;

		$table = VBR_Balloons::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY distance_km DESC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$filename = 'virtual-balloon-race-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );

		fputcsv(
			$output,
			array(
				'ID',
				'Order ID',
				'User ID',
				'Owner Name',
				'Owner Email',
				'Balloon Name',
				'Balloon Color',
				'Helium Level',
				'Distance (km)',
				'Is Burst',
				'Created At',
			)
		);

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$output,
				array(
					$row['id'],
					$row['order_id'],
					$row['user_id'],
					$row['owner_name'],
					$row['owner_email'],
					$row['balloon_name'],
					$row['balloon_color'],
					$row['helium_level'],
					$row['distance_km'],
					$row['is_burst'] ? 'Yes' : 'No',
					$row['created_at'],
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render the Balloon Race admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'virtual-balloon-race' ) );
		}

		global $wpdb;

		$table    = VBR_Balloons::table();
		$balloons = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY distance_km DESC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$interval      = VBR_Settings::interval_seconds();
		$default_hours = $interval ? round( $interval / HOUR_IN_SECONDS, 2 ) : 1;

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'vbr-admin',
					'vbr_action' => 'export_csv',
				),
				admin_url( 'admin.php' )
			),
			'vbr_export_csv'
		);
		?>
		<div class="wrap vbr-admin">
			<h1><?php esc_html_e( 'Balloon Race', 'virtual-balloon-race' ); ?></h1>

			<?php $this->render_admin_notices(); ?>

			<section class="vbr-admin-panel">
				<h2><?php esc_html_e( 'Advance the race', 'virtual-balloon-race' ); ?></h2>
				<?php $this->render_schedule_summary(); ?>
				<p><?php esc_html_e( 'Move every active balloon now, using the same wind and speed rules as automatic updates. Burst chances scale with the hours simulated.', 'virtual-balloon-race' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vbr-admin' ) ); ?>" class="vbr-advance-form">
					<?php wp_nonce_field( 'vbr_advance_race', 'vbr_advance_nonce' ); ?>
					<input type="hidden" name="vbr_action" value="advance" />

					<label for="vbr_hours"><?php esc_html_e( 'Hours to simulate', 'virtual-balloon-race' ); ?></label>
					<input type="number" id="vbr_hours" name="vbr_hours" value="<?php echo esc_attr( (string) $default_hours ); ?>" min="0" max="336" step="0.01" />

					<label class="vbr-advance-form__range">
						<input type="checkbox" name="vbr_use_range" value="1" />
						<?php esc_html_e( 'Use a fixed km range instead', 'virtual-balloon-race' ); ?>
					</label>

					<label for="vbr_min_km"><?php esc_html_e( 'Min km', 'virtual-balloon-race' ); ?></label>
					<input type="number" id="vbr_min_km" name="vbr_min_km" value="10" min="0" step="0.01" />

					<label for="vbr_max_km"><?php esc_html_e( 'Max km', 'virtual-balloon-race' ); ?></label>
					<input type="number" id="vbr_max_km" name="vbr_max_km" value="50" min="0" step="0.01" />

					<?php submit_button( __( 'Advance now', 'virtual-balloon-race' ), 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="vbr-admin-panel">
				<h2><?php esc_html_e( 'Export', 'virtual-balloon-race' ); ?></h2>
				<p>
					<a class="button" href="<?php echo esc_url( $export_url ); ?>">
						<?php esc_html_e( 'Export all balloon &amp; donor data (CSV)', 'virtual-balloon-race' ); ?>
					</a>
				</p>
			</section>

			<section class="vbr-admin-panel">
				<h2><?php esc_html_e( 'Leaderboard', 'virtual-balloon-race' ); ?></h2>
				<table class="widefat striped vbr-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Color', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Helium', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Distance (KM)', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Burst Status', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $balloons ) ) : ?>
							<tr>
								<td colspan="8"><?php esc_html_e( 'No balloons registered yet.', 'virtual-balloon-race' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $balloons as $balloon ) : ?>
								<?php
								$toggle_url = wp_nonce_url(
									add_query_arg(
										array(
											'page'       => 'vbr-admin',
											'vbr_action' => 'toggle_burst',
											'balloon_id' => (int) $balloon->id,
										),
										admin_url( 'admin.php' )
									),
									'vbr_toggle_burst_' . (int) $balloon->id
								);
								?>
								<tr>
									<td><?php echo esc_html( (string) $balloon->id ); ?></td>
									<td>
										<?php echo esc_html( $balloon->owner_name ); ?>
										<?php if ( $balloon->owner_email ) : ?>
											<br /><small><?php echo esc_html( $balloon->owner_email ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $balloon->balloon_name ); ?></td>
									<td><?php echo esc_html( $balloon->balloon_color ); ?></td>
									<td><?php echo esc_html( $balloon->helium_level ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (float) $balloon->distance_km, 2 ) ); ?></td>
									<td>
										<?php
										echo $balloon->is_burst
											? '<span class="vbr-status vbr-status--burst">' . esc_html__( 'Burst', 'virtual-balloon-race' ) . '</span>'
											: '<span class="vbr-status vbr-status--active">' . esc_html__( 'Active', 'virtual-balloon-race' ) . '</span>';
										?>
									</td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( $toggle_url ); ?>">
											<?php echo $balloon->is_burst ? esc_html__( 'Restore', 'virtual-balloon-race' ) : esc_html__( 'Mark burst', 'virtual-balloon-race' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	/**
	 * Schedule and last-update summary.
	 */
	private function render_schedule_summary() {
		$labels  = VBR_Settings::interval_labels();
		$slug    = (string) VBR_Settings::get( 'interval' );
		$next    = VBR_Cron::next_run();
		$last    = get_option( 'vbr_last_tick_summary', array() );
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$setting = admin_url( 'admin.php?page=' . VBR_Settings::PAGE );

		echo '<ul class="vbr-schedule-summary">';

		echo '<li><strong>' . esc_html__( 'Automatic updates:', 'virtual-balloon-race' ) . '</strong> ' . esc_html( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug );
		echo ' &middot; <a href="' . esc_url( $setting ) . '">' . esc_html__( 'Change', 'virtual-balloon-race' ) . '</a></li>';

		if ( $next ) {
			echo '<li><strong>' . esc_html__( 'Next update:', 'virtual-balloon-race' ) . '</strong> ' . esc_html( wp_date( $format, $next ) ) . '</li>';
		}

		if ( ! empty( $last['time'] ) ) {
			echo '<li><strong>' . esc_html__( 'Last update:', 'virtual-balloon-race' ) . '</strong> ';
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: hours, 3: balloons moved, 4: balloons burst */
					__( '%1$s — %2$s h simulated, %3$d moved, %4$d burst', 'virtual-balloon-race' ),
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
		if ( empty( $_GET['vbr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['vbr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'advanced' === $notice ) {
			$advanced = isset( $_GET['vbr_advanced'] ) ? intval( $_GET['vbr_advanced'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$burst    = isset( $_GET['vbr_burst'] ) ? intval( $_GET['vbr_burst'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: balloons advanced, 2: balloons burst */
					__( 'Race updated: %1$d balloon(s) advanced, %2$d burst.', 'virtual-balloon-race' ),
					$advanced,
					$burst
				)
			);
			echo '</p></div>';
		}

		if ( 'locked' === $notice ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Another update is already running. Try again in a moment.', 'virtual-balloon-race' );
			echo '</p></div>';
		}

		if ( 'toggled' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Balloon status updated.', 'virtual-balloon-race' );
			echo '</p></div>';
		}
	}
}
