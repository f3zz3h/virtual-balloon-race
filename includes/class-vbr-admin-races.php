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
class VBR_Admin_Races {

	const PAGE = 'vbr-races';

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
			VBR_Admin::PAGE,
			__( 'Races', 'virtual-balloon-race' ),
			__( 'Races', 'virtual-balloon-race' ),
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

		VBR_Settings::register_leaflet();
		wp_enqueue_style( 'vbr-admin', VBR_PLUGIN_URL . 'assets/css/admin.css', array( 'vbr-leaflet' ), VBR_Balloons::asset_version( 'assets/css/admin.css' ) );

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			wp_enqueue_script( 'vbr-admin-settings', VBR_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'vbr-leaflet' ), VBR_Balloons::asset_version( 'assets/js/admin-settings.js' ), true );
			wp_enqueue_script( 'vbr-admin-teams', VBR_PLUGIN_URL . 'assets/js/admin-teams.js', array(), VBR_Balloons::asset_version( 'assets/js/admin-teams.js' ), true );
		}
	}

	/**
	 * Save / delete / launch now / finish now.
	 */
	public function handle_actions() {
		if ( empty( $_REQUEST['page'] ) || self::PAGE !== $_REQUEST['page'] || empty( $_REQUEST['vbr_race_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['vbr_race_action'] ) );
		$id     = isset( $_REQUEST['race'] ) ? absint( $_REQUEST['race'] ) : 0;

		check_admin_referer( 'vbr_race_' . $action . '_' . $id );

		switch ( $action ) {
			case 'save':
				$this->save( $id );
				break;

			case 'delete':
				$result = VBR_Races::delete( $id );
				$this->redirect( array(), is_wp_error( $result ) ? $result->get_error_message() : 'deleted' );
				break;

			case 'launch_now':
				$this->launch_now( $id );
				break;

			case 'finish_now':
				$race = VBR_Races::get( $id );
				if ( $race && VBR_Races::FLYING === VBR_Races::status( $race ) ) {
					VBR_Races::set_time( $id, 'end_at', time() );
				}
				$this->redirect( array( 'action' => 'edit', 'race' => $id ), 'finished' );
				break;

			case 'create_product':
				$race   = VBR_Races::get( $id );
				$result = $race && VBR_Product_Type::available() ? VBR_Product_Type::create_for_race( $race ) : new WP_Error( 'vbr_no_woocommerce', __( 'WooCommerce is needed to sell entries.', 'virtual-balloon-race' ) );

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
		wp_safe_redirect( self::url( array_merge( $args, array( 'vbr_notice' => rawurlencode( $notice ) ) ) ) );
		exit;
	}

	/**
	 * Save the add/edit form.
	 *
	 * @param int $id Race ID (0 = new).
	 */
	private function save( $id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle_actions().
		$in = isset( $_POST['vbr_race'] ) && is_array( $_POST['vbr_race'] ) ? wp_unslash( $_POST['vbr_race'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$data = array(
			'name'          => isset( $in['name'] ) ? sanitize_text_field( $in['name'] ) : '',
			'launch_label'  => isset( $in['launch_label'] ) ? sanitize_text_field( $in['launch_label'] ) : '',
			'launch_lat'    => isset( $in['launch_lat'] ) ? sanitize_text_field( $in['launch_lat'] ) : '',
			'launch_lng'    => isset( $in['launch_lng'] ) ? sanitize_text_field( $in['launch_lng'] ) : '',
			'sales_open_at' => VBR_Races::local_input_to_gmt( isset( $in['sales_open_at'] ) ? sanitize_text_field( $in['sales_open_at'] ) : '' ),
			'launch_at'     => VBR_Races::local_input_to_gmt( isset( $in['launch_at'] ) ? sanitize_text_field( $in['launch_at'] ) : '' ),
			'end_at'        => VBR_Races::local_input_to_gmt( isset( $in['end_at'] ) ? sanitize_text_field( $in['end_at'] ) : '' ),
			'ranking'       => isset( $in['ranking'] ) ? sanitize_key( $in['ranking'] ) : VBR_Races::FROM_START,
		);

		if ( VBR_Teams::enabled() ) {
			$data['team_scoring'] = isset( $in['team_scoring'] ) ? sanitize_key( $in['team_scoring'] ) : '';
		}

		$result = VBR_Races::save( $data, $id );

		if ( is_wp_error( $result ) ) {
			// Keep what was typed so the form can be corrected.
			set_transient( 'vbr_race_form_' . get_current_user_id(), $in, 5 * MINUTE_IN_SECONDS );
			$this->redirect( array( 'action' => $id ? 'edit' : 'new', 'race' => $id ), $result->get_error_message() );
		}

		$notice = $id ? 'updated' : 'created';

		if ( VBR_Teams::enabled() ) {
			$blocked = VBR_Teams::save_for_race( $result, VBR_Teams::clean_rows( isset( $in['teams'] ) ? $in['teams'] : array() ) );
			if ( $blocked ) {
				/* translators: %s: team names */
				$notice = sprintf( __( 'Race saved, but these teams have balloons so they were kept: %s', 'virtual-balloon-race' ), implode( ', ', $blocked ) );
			}
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

		$race = VBR_Races::get( $id );

		if ( $race && in_array( VBR_Races::status( $race ), array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true ) ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$row = array( 'launch_at' => $now );

			if ( VBR_Races::ts( $race->sales_open_at ) >= time() ) {
				$row['sales_open_at'] = null;
			}

			if ( $race->end_at && VBR_Races::ts( $race->end_at ) <= time() ) {
				$row['end_at'] = null;
			}

			$wpdb->update( VBR_Races::table(), $row, array( 'id' => $id ) );
			VBR_Races::flush();
		}

		$this->redirect( array( 'action' => 'edit', 'race' => $id ), 'launched' );
	}

	/**
	 * Render list or form.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'virtual-balloon-race' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['race'] ) ? absint( $_GET['race'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap vbr-admin vbr-races">';

		if ( 'new' === $action || ( 'edit' === $action && VBR_Races::get( $id ) ) ) {
			$this->render_form( 'edit' === $action ? VBR_Races::get( $id ) : null );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Notices after actions.
	 */
	private function render_notice() {
		if ( empty( $_GET['vbr_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// redirect() URL-encodes the notice once and PHP decodes $_GET once, so no further decoding is needed.
		$key      = sanitize_text_field( wp_unslash( $_GET['vbr_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'created'  => __( 'Race created.', 'virtual-balloon-race' ),
			'updated'  => __( 'Race saved.', 'virtual-balloon-race' ),
			'deleted'  => __( 'Race deleted.', 'virtual-balloon-race' ),
			'launched' => __( 'Race launched. Entries are closed and balloons will move on the next update.', 'virtual-balloon-race' ),
			'finished' => __( 'Race finished. Balloons have stopped.', 'virtual-balloon-race' ),
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
		$races = VBR_Races::all();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Races', 'virtual-balloon-race' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><?php esc_html_e( 'Add race', 'virtual-balloon-race' ); ?></a>
		<hr class="wp-header-end" />

		<?php $this->render_notice(); ?>

		<p class="description"><?php esc_html_e( 'Entries can only be bought while a race is taking entries (between “Entries open” and launch). Balloons move only while a race is flying.', 'virtual-balloon-race' ); ?></p>

		<table class="widefat striped vbr-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Race', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Stage', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Entries open', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Launch', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Ends', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Balloons', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Shortcode', 'virtual-balloon-race' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $races ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No races yet.', 'virtual-balloon-race' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $races as $race ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'race' => (int) $race->id ) ) ); ?>"><?php echo esc_html( $race->name ); ?></a></strong>
							<div class="row-actions">
								<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'race' => (int) $race->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'virtual-balloon-race' ); ?></a> |
								<a href="<?php echo esc_url( VBR_Admin::url( array( 'race' => (int) $race->id ) ) ); ?>"><?php esc_html_e( 'Leaderboard', 'virtual-balloon-race' ); ?></a>
							</div>
						</td>
						<td><?php self::status_pill( $race ); ?></td>
						<td><?php echo esc_html( $race->sales_open_at ? VBR_Races::format_date( $race->sales_open_at ) : __( 'Now', 'virtual-balloon-race' ) ); ?></td>
						<td><?php echo esc_html( VBR_Races::format_date( $race->launch_at ) ); ?></td>
						<td><?php echo esc_html( $race->end_at ? VBR_Races::format_date( $race->end_at ) : __( 'When finished by hand', 'virtual-balloon-race' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( VBR_Races::balloon_count( $race->id ) ) ); ?></td>
						<td><code>[balloon_map race="<?php echo esc_html( $race->slug ); ?>"]</code></td>
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
			'<span class="vbr-status vbr-status--%1$s">%2$s</span>',
			esc_attr( VBR_Races::status( $race ) ),
			esc_html( VBR_Races::status_label( $race ) )
		);
	}

	/**
	 * Compact race summary with quick actions (also used on the leaderboard page).
	 *
	 * @param object $race Race.
	 */
	public static function render_race_summary( $race ) {
		$status = VBR_Races::status( $race );
		$launch = VBR_Races::launch_point( $race );
		?>
		<section class="vbr-admin-panel vbr-race-summary">
			<h2><?php echo esc_html( $race->name ); ?> <?php self::status_pill( $race ); ?></h2>
			<ul class="vbr-race-facts">
				<li><strong><?php esc_html_e( 'Entries open:', 'virtual-balloon-race' ); ?></strong> <?php echo esc_html( $race->sales_open_at ? VBR_Races::format_date( $race->sales_open_at ) : __( 'Now', 'virtual-balloon-race' ) ); ?></li>
				<li><strong><?php esc_html_e( 'Launch (entries close):', 'virtual-balloon-race' ); ?></strong> <?php echo esc_html( VBR_Races::format_date( $race->launch_at ) ); ?></li>
				<li><strong><?php esc_html_e( 'Ends:', 'virtual-balloon-race' ); ?></strong> <?php echo esc_html( $race->end_at ? VBR_Races::format_date( $race->end_at ) : __( 'When finished by hand', 'virtual-balloon-race' ) ); ?></li>
				<li><strong><?php esc_html_e( 'Launch point:', 'virtual-balloon-race' ); ?></strong> <?php echo esc_html( ( $launch['label'] ? $launch['label'] . ' ' : '' ) . sprintf( '(%.4f, %.4f)', $launch['lat'], $launch['lng'] ) ); ?></li>
				<li><strong><?php esc_html_e( 'Shortcodes:', 'virtual-balloon-race' ); ?></strong> <code>[balloon_map race="<?php echo esc_html( $race->slug ); ?>"]</code> <code>[balloon_leaderboard race="<?php echo esc_html( $race->slug ); ?>"]</code></li>
			</ul>
			<p class="vbr-race-actions">
				<?php if ( in_array( $status, array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'launch_now', $race->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Launch this race now? Entries will close.', 'virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Launch now', 'virtual-balloon-race' ); ?></a>
				<?php endif; ?>
				<?php if ( VBR_Races::FLYING === $status ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'finish_now', $race->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Finish this race now? Balloons will stop where they are.', 'virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Finish now', 'virtual-balloon-race' ); ?></a>
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
		if ( ! VBR_Teams::enabled() ) {
			return;
		}

		$defaults = VBR_Teams::clean_rows( (array) VBR_Settings::get( 'default_teams' ) );

		if ( $race ) {
			$teams = array();
			foreach ( VBR_Teams::for_race( (int) $race->id ) as $team ) {
				$teams[] = array(
					'id'       => (int) $team->id,
					'name'     => $team->name,
					'color'    => $team->color,
					'balloons' => VBR_Teams::balloon_count( (int) $team->id ),
				);
			}
		} else {
			// A new race starts with the default teams.
			$teams = $defaults;
		}

		$scoring = $race ? (string) $race->team_scoring : '';
		$labels  = VBR_Teams::scoring_labels();
		?>
		<section class="vbr-admin-panel">
			<h2><?php esc_html_e( 'Teams', 'virtual-balloon-race' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Buyers must pick one of these teams. Leave the list empty to run this race without teams.', 'virtual-balloon-race' ); ?></p>
			<?php VBR_Teams::render_editor( 'vbr_race[teams]', $teams, $defaults ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vbr_race_team_scoring"><?php esc_html_e( 'Team scoring', 'virtual-balloon-race' ); ?></label></th>
					<td>
						<select id="vbr_race_team_scoring" name="vbr_race[team_scoring]">
							<option value="">
								<?php
								/* translators: %s: default scoring method */
								echo esc_html( sprintf( __( 'Default (%s)', 'virtual-balloon-race' ), $labels[ VBR_Teams::scoring( null ) ] ) );
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
		if ( ! VBR_Product_Type::available() ) {
			return;
		}

		$locked = VBR_Product_Type::products_for_race( $race->id );
		$any    = VBR_Product_Type::products_for_race( 0 );
		?>
		<section class="vbr-admin-panel vbr-race-products">
			<h2><?php esc_html_e( 'Entry products', 'virtual-balloon-race' ); ?></h2>
			<?php if ( $locked || $any ) : ?>
				<ul>
					<?php foreach ( array_merge( $locked, $any ) as $product ) : ?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
							&middot; <?php echo wp_kses_post( $product->get_price() !== '' ? wc_price( $product->get_price() ) : __( 'no price', 'virtual-balloon-race' ) ); ?>
							&middot; <?php echo esc_html( 'publish' === $product->get_status() ? __( 'Published', 'virtual-balloon-race' ) : __( 'Not published', 'virtual-balloon-race' ) ); ?>
							<?php if ( in_array( $product, $any, true ) ) : ?>
								&middot; <em><?php esc_html_e( 'any race taking entries', 'virtual-balloon-race' ); ?></em>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No product sells entries for this race yet.', 'virtual-balloon-race' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'create_product', $race->id ) ); ?>"><?php esc_html_e( 'Create entry product', 'virtual-balloon-race' ); ?></a>
				<span class="description"><?php esc_html_e( 'Makes a draft "Balloon race entry" product for this race. Set its price and publish it.', 'virtual-balloon-race' ); ?></span>
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
					'vbr_race_action' => $action,
					'race'            => (int) $id,
				)
			),
			'vbr_race_' . $action . '_' . (int) $id
		);
	}

	/**
	 * Add / edit form.
	 *
	 * @param object|null $race Race being edited.
	 */
	private function render_form( $race ) {
		$id       = $race ? (int) $race->id : 0;
		$defaults = VBR_Balloons::launch_point();
		$values   = array(
			'name'          => $race ? $race->name : '',
			'launch_label'  => $race ? $race->launch_label : $defaults['label'],
			'launch_lat'    => $race ? (float) $race->launch_lat : $defaults['lat'],
			'launch_lng'    => $race ? (float) $race->launch_lng : $defaults['lng'],
			'sales_open_at' => $race ? VBR_Races::gmt_to_local_input( $race->sales_open_at ) : '',
			'launch_at'     => $race ? VBR_Races::gmt_to_local_input( $race->launch_at ) : '',
			'end_at'        => $race ? VBR_Races::gmt_to_local_input( $race->end_at ) : '',
			'ranking'       => VBR_Races::ranking( $race ),
		);

		// Re-fill after a validation error.
		$saved = get_transient( 'vbr_race_form_' . get_current_user_id() );
		if ( is_array( $saved ) ) {
			delete_transient( 'vbr_race_form_' . get_current_user_id() );
			$values = array_merge( $values, array_map( 'sanitize_text_field', array_intersect_key( $saved, $values ) ) );
		}

		$count = $id ? VBR_Races::balloon_count( $id ) : 0;
		?>
		<h1><?php echo $race ? esc_html__( 'Edit race', 'virtual-balloon-race' ) : esc_html__( 'Add race', 'virtual-balloon-race' ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All races', 'virtual-balloon-race' ); ?></a></p>

		<?php $this->render_notice(); ?>

		<?php
		if ( $race ) {
			self::render_race_summary( $race );
			self::render_products( $race );
		}
		?>

		<form method="post" action="<?php echo esc_url( self::url( array( 'race' => $id ) ) ); ?>">
			<?php wp_nonce_field( 'vbr_race_save_' . $id ); ?>
			<input type="hidden" name="vbr_race_action" value="save" />

			<section class="vbr-admin-panel">
				<h2><?php esc_html_e( 'Race details', 'virtual-balloon-race' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vbr_race_name"><?php esc_html_e( 'Name', 'virtual-balloon-race' ); ?></label></th>
						<td><input type="text" class="regular-text" id="vbr_race_name" name="vbr_race[name]" value="<?php echo esc_attr( $values['name'] ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Summer Fair Balloon Race 2026', 'virtual-balloon-race' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_race_sales_open_at"><?php esc_html_e( 'Entries open', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="vbr_race_sales_open_at" name="vbr_race[sales_open_at]" value="<?php echo esc_attr( $values['sales_open_at'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Leave blank to take entries straight away.', 'virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_race_launch_at"><?php esc_html_e( 'Launch', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="vbr_race_launch_at" name="vbr_race[launch_at]" value="<?php echo esc_attr( $values['launch_at'] ); ?>" required />
							<p class="description"><?php esc_html_e( 'Entries close and balloons start moving at this time.', 'virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_race_end_at"><?php esc_html_e( 'Ends', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="datetime-local" id="vbr_race_end_at" name="vbr_race[end_at]" value="<?php echo esc_attr( $values['end_at'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional. Balloons stop and the leaderboard is final after this time. Leave blank to finish by hand.', 'virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_race_ranking"><?php esc_html_e( 'Winner decided by', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<select id="vbr_race_ranking" name="vbr_race[ranking]">
								<?php foreach ( VBR_Races::ranking_labels() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $values['ranking'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Distance from launch is the usual rule: the balloon furthest away in a straight line wins. Custom launch points are measured from their own start. Distance travelled adds up the whole trail, so wandering balloons score more. Team scores use the same measure.', 'virtual-balloon-race' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timezone', 'virtual-balloon-race' ); ?></th>
						<td><p class="description"><?php echo esc_html( wp_timezone_string() ); ?></p></td>
					</tr>
				</table>
			</section>

			<section class="vbr-admin-panel">
				<h2><?php esc_html_e( 'Launch point', 'virtual-balloon-race' ); ?></h2>
				<div class="vbr-launch-picker">
					<div class="vbr-launch-search">
						<label for="vbr-place-search" class="screen-reader-text"><?php esc_html_e( 'Search for a place', 'virtual-balloon-race' ); ?></label>
						<input type="search" id="vbr-place-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search for a place, e.g. Horfield, Bristol', 'virtual-balloon-race' ); ?>" />
						<button type="button" class="button" id="vbr-place-search-btn"><?php esc_html_e( 'Search', 'virtual-balloon-race' ); ?></button>
						<ul class="vbr-place-results" id="vbr-place-results" hidden></ul>
					</div>
					<div id="vbr-launch-map" class="vbr-launch-map" data-zoom="<?php echo esc_attr( (string) VBR_Settings::get( 'map_zoom' ) ); ?>"></div>
				</div>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vbr_launch_label"><?php esc_html_e( 'Launch name', 'virtual-balloon-race' ); ?></label></th>
						<td><input type="text" class="regular-text" id="vbr_launch_label" name="vbr_race[launch_label]" value="<?php echo esc_attr( $values['launch_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_launch_lat"><?php esc_html_e( 'Latitude', 'virtual-balloon-race' ); ?></label></th>
						<td><input type="number" class="small-text vbr-coord" id="vbr_launch_lat" name="vbr_race[launch_lat]" value="<?php echo esc_attr( (string) $values['launch_lat'] ); ?>" min="-90" max="90" step="any" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="vbr_launch_lng"><?php esc_html_e( 'Longitude', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<input type="number" class="small-text vbr-coord" id="vbr_launch_lng" name="vbr_race[launch_lng]" value="<?php echo esc_attr( (string) $values['launch_lng'] ); ?>" min="-180" max="180" step="any" required />
							<?php if ( $count ) : ?>
								<p class="description"><?php esc_html_e( 'Moving the launch point before launch also moves balloons waiting there (custom launch points stay put).', 'virtual-balloon-race' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</section>

			<?php $this->render_teams_panel( $race ); ?>

			<p class="submit">
				<?php submit_button( $race ? __( 'Save race', 'virtual-balloon-race' ) : __( 'Create race', 'virtual-balloon-race' ), 'primary', 'submit', false ); ?>
				<?php if ( $race && ! $count ) : ?>
					<a class="button-link-delete vbr-delete-race" href="<?php echo esc_url( self::action_url( 'delete', $id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this race?', 'virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Delete race', 'virtual-balloon-race' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}
}
