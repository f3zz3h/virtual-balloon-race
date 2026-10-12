<?php
/**
 * Plugin Name:       Virtual Balloon Race
 * Plugin URI:        https://example.com/virtual-balloon-race
 * Description:       Sell virtual balloon race entries via WooCommerce, capture balloon customizations, and display a live fundraising leaderboard.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Virtual Balloon Race
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       virtual-balloon-race
 * Domain Path:       /languages
 * WC requires at least: 7.0
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

define( 'VBR_VERSION', '1.0.0' );
define( 'VBR_PLUGIN_FILE', __FILE__ );
define( 'VBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VBR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VBR_TABLE_SUFFIX', 'vbr_balloons' );

/**
 * Main plugin bootstrap.
 */
final class Virtual_Balloon_Race {

	/**
	 * Singleton instance.
	 *
	 * @var Virtual_Balloon_Race|null
	 */
	private static $instance = null;

	/**
	 * Allowed balloon colours.
	 *
	 * @var string[]
	 */
	private $colors = array( 'Red', 'Blue', 'Green', 'Yellow', 'Purple' );

	/**
	 * Helium fill strategies (stored in helium_level).
	 *
	 * @var string[]
	 */
	private $helium_strategies = array( 'Balanced', 'Max Height', 'Eco-Glide' );

	/**
	 * Get singleton instance.
	 *
	 * @return Virtual_Balloon_Race
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( VBR_PLUGIN_FILE, array( $this, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
	}

	/**
	 * Initialise hooks once WordPress (and optionally WooCommerce) is ready.
	 */
	public function init() {
		load_plugin_textdomain( 'virtual-balloon-race', false, dirname( plugin_basename( VBR_PLUGIN_FILE ) ) . '/languages' );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		add_shortcode( 'balloon_leaderboard', array( $this, 'render_leaderboard_shortcode' ) );

		if ( ! $this->is_woocommerce_active() ) {
			return;
		}

		// Product-page custom fields.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'output_product_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_product_fields' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );

		// Cart / checkout display.
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// Persist custom fields onto the order line item (emails + order screens).
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// WooCommerce product flag: "Balloon Entry".
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'output_product_option' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_option' ) );

		// Create balloon records when payment is taken / order completes.
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_balloons_from_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_balloons_from_order' ) );
	}

	// -------------------------------------------------------------------------
	// Activation / schema
	// -------------------------------------------------------------------------

	/**
	 * Create the custom balloons table on activation.
	 */
	public function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_name varchar(255) NOT NULL DEFAULT '',
			owner_email varchar(255) NOT NULL DEFAULT '',
			balloon_name varchar(255) NOT NULL DEFAULT '',
			balloon_color varchar(50) NOT NULL DEFAULT '',
			helium_level varchar(50) NOT NULL DEFAULT '',
			distance_km decimal(10,2) NOT NULL DEFAULT 0.00,
			is_burst tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY user_id (user_id),
			KEY is_burst (is_burst),
			KEY distance_km (distance_km)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'vbr_db_version', VBR_VERSION );
	}

	/**
	 * Fully qualified custom table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . VBR_TABLE_SUFFIX;
	}

	/**
	 * Whether WooCommerce is available.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function woocommerce_missing_notice() {
		if ( $this->is_woocommerce_active() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Virtual Balloon Race requires WooCommerce to be installed and active in order to sell balloon entries.', 'virtual-balloon-race' );
		echo '</p></div>';
	}

	// -------------------------------------------------------------------------
	// Balloon product detection
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a product is a balloon race entry.
	 *
	 * A product qualifies if:
	 * - the "Balloon Entry" checkbox is ticked, or
	 * - it has the product tag `balloon-entry`, or
	 * - its ID is listed in the `vbr_balloon_product_ids` option / filter.
	 *
	 * @param int|WC_Product $product Product ID or object.
	 * @return bool
	 */
	public function is_balloon_product( $product ) {
		$product_id = 0;

		if ( is_numeric( $product ) ) {
			$product_id = (int) $product;
		} elseif ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$product_id = (int) $product->get_id();
		}

		if ( $product_id <= 0 ) {
			return false;
		}

		$configured_ids = apply_filters( 'vbr_balloon_product_ids', get_option( 'vbr_balloon_product_ids', array() ) );
		$configured_ids = array_map( 'intval', (array) $configured_ids );

		if ( in_array( $product_id, $configured_ids, true ) ) {
			return true;
		}

		if ( 'yes' === get_post_meta( $product_id, '_vbr_is_balloon_entry', true ) ) {
			return true;
		}

		if ( has_term( 'balloon-entry', 'product_tag', $product_id ) ) {
			return true;
		}

		return (bool) apply_filters( 'vbr_is_balloon_product', false, $product_id );
	}

	/**
	 * Product data checkbox: mark this product as a balloon entry.
	 */
	public function output_product_option() {
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_vbr_is_balloon_entry',
				'label'       => __( 'Balloon Entry', 'virtual-balloon-race' ),
				'description' => __( 'Treat this product as a Virtual Balloon Race entry and show balloon customisation fields.', 'virtual-balloon-race' ),
			)
		);

		echo '</div>';
	}

	/**
	 * Save the balloon-entry product flag.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_option( $product_id ) {
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$is_entry = isset( $_POST['_vbr_is_balloon_entry'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, '_vbr_is_balloon_entry', $is_entry );
	}

	// -------------------------------------------------------------------------
	// WooCommerce product fields / cart / orders
	// -------------------------------------------------------------------------

	/**
	 * Output balloon customisation fields on the single product page.
	 */
	public function output_product_fields() {
		global $product;

		if ( ! $product || ! $this->is_balloon_product( $product ) ) {
			return;
		}

		wp_nonce_field( 'vbr_add_balloon', 'vbr_balloon_nonce' );

		$posted_name   = isset( $_POST['vbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_color  = isset( $_POST['vbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_helium = isset( $_POST['vbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_helium_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<div class="vbr-product-fields">
			<h3 class="vbr-product-fields__title"><?php esc_html_e( 'Customise your balloon', 'virtual-balloon-race' ); ?></h3>

			<p class="form-row form-row-wide">
				<label for="vbr_balloon_name">
					<?php esc_html_e( 'Balloon Name', 'virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<input type="text" id="vbr_balloon_name" name="vbr_balloon_name" maxlength="255" value="<?php echo esc_attr( $posted_name ); ?>" required />
			</p>

			<p class="form-row form-row-wide">
				<label for="vbr_balloon_color">
					<?php esc_html_e( 'Balloon Color', 'virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<select id="vbr_balloon_color" name="vbr_balloon_color" required>
					<option value=""><?php esc_html_e( 'Select a colour', 'virtual-balloon-race' ); ?></option>
					<?php foreach ( $this->colors as $color ) : ?>
						<option value="<?php echo esc_attr( $color ); ?>" <?php selected( $posted_color, $color ); ?>>
							<?php echo esc_html( $color ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="form-row form-row-wide">
				<label for="vbr_helium_level">
					<?php esc_html_e( 'Helium Fill Strategy', 'virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<select id="vbr_helium_level" name="vbr_helium_level" required>
					<option value=""><?php esc_html_e( 'Select a strategy', 'virtual-balloon-race' ); ?></option>
					<?php foreach ( $this->helium_strategies as $strategy ) : ?>
						<option value="<?php echo esc_attr( $strategy ); ?>" <?php selected( $posted_helium, $strategy ); ?>>
							<?php echo esc_html( $this->get_helium_label( $strategy ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
	}

	/**
	 * Human-readable helium strategy labels.
	 *
	 * @param string $strategy Strategy slug/name.
	 * @return string
	 */
	private function get_helium_label( $strategy ) {
		$labels = array(
			'Balanced'   => __( 'Balanced (Medium)', 'virtual-balloon-race' ),
			'Max Height' => __( 'Max Height (High) — faster, small burst risk', 'virtual-balloon-race' ),
			'Eco-Glide'  => __( 'Eco-Glide (Low)', 'virtual-balloon-race' ),
			'Low'        => __( 'Low', 'virtual-balloon-race' ),
			'Medium'     => __( 'Medium', 'virtual-balloon-race' ),
			'High'       => __( 'High', 'virtual-balloon-race' ),
		);

		return isset( $labels[ $strategy ] ) ? $labels[ $strategy ] : $strategy;
	}

	/**
	 * Validate balloon fields when adding to cart.
	 *
	 * @param bool $passed     Validation result.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public function validate_product_fields( $passed, $product_id, $quantity ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->is_balloon_product( $product_id ) ) {
			return $passed;
		}

		if ( ! isset( $_POST['vbr_balloon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vbr_balloon_nonce'] ) ), 'vbr_add_balloon' ) ) {
			wc_add_notice( __( 'Security check failed. Please reload the page and try again.', 'virtual-balloon-race' ), 'error' );
			return false;
		}

		$name   = isset( $_POST['vbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_name'] ) ) : '';
		$color  = isset( $_POST['vbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_color'] ) ) : '';
		$helium = isset( $_POST['vbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_helium_level'] ) ) : '';

		if ( '' === $name ) {
			wc_add_notice( __( 'Please enter a balloon name.', 'virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		if ( ! in_array( $color, $this->colors, true ) ) {
			wc_add_notice( __( 'Please select a valid balloon colour.', 'virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		if ( ! in_array( $helium, $this->helium_strategies, true ) ) {
			wc_add_notice( __( 'Please select a helium fill strategy.', 'virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		return $passed;
	}

	/**
	 * Persist custom balloon fields on the cart item.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->is_balloon_product( $product_id ) ) {
			return $cart_item_data;
		}

		if ( ! isset( $_POST['vbr_balloon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vbr_balloon_nonce'] ) ), 'vbr_add_balloon' ) ) {
			return $cart_item_data;
		}

		$cart_item_data['vbr_balloon_name']  = isset( $_POST['vbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_name'] ) ) : '';
		$cart_item_data['vbr_balloon_color'] = isset( $_POST['vbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_balloon_color'] ) ) : '';
		$cart_item_data['vbr_helium_level']  = isset( $_POST['vbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_helium_level'] ) ) : '';

		// Allow multiple uniquely customised balloons of the same product.
		$cart_item_data['vbr_unique_key'] = md5( microtime() . wp_rand() );

		return $cart_item_data;
	}

	/**
	 * Show balloon details in cart and checkout review.
	 *
	 * @param array $item_data Cart item display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		$map = array(
			'vbr_balloon_name'  => __( 'Balloon Name', 'virtual-balloon-race' ),
			'vbr_balloon_color' => __( 'Balloon Color', 'virtual-balloon-race' ),
			'vbr_helium_level'  => __( 'Helium Fill Strategy', 'virtual-balloon-race' ),
		);

		foreach ( $map as $key => $label ) {
			if ( empty( $cart_item[ $key ] ) ) {
				continue;
			}

			$value = $cart_item[ $key ];
			if ( 'vbr_helium_level' === $key ) {
				$value = $this->get_helium_label( $value );
			}

			$item_data[] = array(
				'key'   => $label,
				'value' => $value,
			);
		}

		return $item_data;
	}

	/**
	 * Save balloon customisation onto the order line item (visible in emails).
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order object.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! empty( $values['vbr_balloon_name'] ) ) {
			$item->add_meta_data( __( 'Balloon Name', 'virtual-balloon-race' ), sanitize_text_field( $values['vbr_balloon_name'] ), true );
		}

		if ( ! empty( $values['vbr_balloon_color'] ) ) {
			$item->add_meta_data( __( 'Balloon Color', 'virtual-balloon-race' ), sanitize_text_field( $values['vbr_balloon_color'] ), true );
		}

		if ( ! empty( $values['vbr_helium_level'] ) ) {
			$item->add_meta_data( __( 'Helium Fill Strategy', 'virtual-balloon-race' ), sanitize_text_field( $values['vbr_helium_level'] ), true );
		}
	}

	/**
	 * Insert balloon rows when an order reaches processing or completed.
	 *
	 * Duplicate inserts are prevented via hidden order-item meta `_vbr_balloon_id`.
	 *
	 * @param int $order_id Order ID.
	 */
	public function create_balloons_from_order( $order_id ) {
		$order_id = intval( $order_id );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		global $wpdb;

		$table = $this->get_table_name();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			if ( ! $product || ! $this->is_balloon_product( $product ) ) {
				continue;
			}

			$existing_id = wc_get_order_item_meta( $item_id, '_vbr_balloon_id', true );
			if ( $existing_id ) {
				continue;
			}

			$balloon_name  = $this->get_item_meta_first( $item, array( 'Balloon Name', 'vbr_balloon_name' ) );
			$balloon_color = $this->get_item_meta_first( $item, array( 'Balloon Color', 'vbr_balloon_color' ) );
			$helium_level  = $this->get_item_meta_first( $item, array( 'Helium Fill Strategy', 'vbr_helium_level', 'Helium Level' ) );

			$quantity = max( 1, (int) $item->get_quantity() );

			for ( $i = 0; $i < $quantity; $i++ ) {
				$name_to_store = $balloon_name;

				if ( $quantity > 1 && $name_to_store ) {
					$name_to_store = sprintf(
						/* translators: 1: balloon name, 2: index */
						__( '%1$s (%2$d)', 'virtual-balloon-race' ),
						$balloon_name,
						$i + 1
					);
				}

				$inserted = $wpdb->insert(
					$table,
					array(
						'order_id'      => $order_id,
						'user_id'       => intval( $order->get_user_id() ),
						'owner_name'    => sanitize_text_field( $order->get_formatted_billing_full_name() ),
						'owner_email'   => sanitize_email( $order->get_billing_email() ),
						'balloon_name'  => sanitize_text_field( $name_to_store ),
						'balloon_color' => sanitize_text_field( $balloon_color ),
						'helium_level'  => sanitize_text_field( $helium_level ),
						'distance_km'   => 0.00,
						'is_burst'      => 0,
						'created_at'    => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s' )
				);

				if ( false !== $inserted && 0 === $i ) {
					wc_add_order_item_meta( $item_id, '_vbr_balloon_id', (int) $wpdb->insert_id, true );
				}
			}
		}
	}

	/**
	 * Read the first matching order-item meta key.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @param string[]      $keys Candidate keys.
	 * @return string
	 */
	private function get_item_meta_first( $item, $keys ) {
		foreach ( $keys as $key ) {
			$value = $item->get_meta( $key, true );
			if ( '' !== $value && false !== $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Admin dashboard
	// -------------------------------------------------------------------------

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
	 * Advance all active (non-burst) balloons by a random distance in a given range.
	 * Max Height balloons have a 5% chance of bursting instead of advancing.
	 */
	private function advance_active_balloons() {
		global $wpdb;

		$min = isset( $_POST['vbr_min_km'] ) ? floatval( wp_unslash( $_POST['vbr_min_km'] ) ) : 10; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$max = isset( $_POST['vbr_max_km'] ) ? floatval( wp_unslash( $_POST['vbr_max_km'] ) ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $min < 0 ) {
			$min = 0;
		}

		if ( $max < $min ) {
			$max = $min;
		}

		$table    = $this->get_table_name();
		$balloons = $wpdb->get_results( "SELECT id, helium_level FROM {$table} WHERE is_burst = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$advanced = 0;
		$burst    = 0;

		foreach ( (array) $balloons as $balloon ) {
			$helium = (string) $balloon->helium_level;

			if ( $this->helium_can_burst( $helium ) && wp_rand( 1, 100 ) <= 5 ) {
				$wpdb->update(
					$table,
					array( 'is_burst' => 1 ),
					array( 'id' => (int) $balloon->id ),
					array( '%d' ),
					array( '%d' )
				);
				$burst++;
				continue;
			}

			$increment = $this->random_distance( $min, $max );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET distance_km = distance_km + %f WHERE id = %d AND is_burst = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$increment,
					(int) $balloon->id
				)
			);

			$advanced++;
		}

		$redirect = add_query_arg(
			array(
				'page'          => 'vbr-admin',
				'vbr_notice'    => 'advanced',
				'vbr_advanced'  => $advanced,
				'vbr_burst'     => $burst,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Whether this helium strategy can randomly burst (Max Height / High).
	 *
	 * @param string $helium Helium level.
	 * @return bool
	 */
	private function helium_can_burst( $helium ) {
		$risky = array( 'Max Height', 'High' );
		return in_array( $helium, $risky, true );
	}

	/**
	 * Random distance increment between min and max (inclusive), two decimal places.
	 *
	 * @param float $min Minimum km.
	 * @param float $max Maximum km.
	 * @return float
	 */
	private function random_distance( $min, $max ) {
		if ( $max <= $min ) {
			return round( $min, 2 );
		}

		$scale = 100;
		$value = wp_rand( (int) round( $min * $scale ), (int) round( $max * $scale ) );

		return round( $value / $scale, 2 );
	}

	/**
	 * Toggle burst status for a single balloon.
	 *
	 * @param int $balloon_id Balloon ID.
	 */
	private function toggle_burst( $balloon_id ) {
		global $wpdb;

		$balloon_id = intval( $balloon_id );
		$table      = $this->get_table_name();

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

		$table = $this->get_table_name();
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

		$table    = $this->get_table_name();
		$balloons = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY distance_km DESC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
				<h2><?php esc_html_e( 'Daily distance update', 'virtual-balloon-race' ); ?></h2>
				<p><?php esc_html_e( 'Advance every active (non-burst) balloon by a random distance in the range below. Balloons using Max Height have a 5% chance of bursting instead of moving.', 'virtual-balloon-race' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=vbr-admin' ) ); ?>" class="vbr-advance-form">
					<?php wp_nonce_field( 'vbr_advance_race', 'vbr_advance_nonce' ); ?>
					<input type="hidden" name="vbr_action" value="advance" />

					<label for="vbr_min_km"><?php esc_html_e( 'Min km', 'virtual-balloon-race' ); ?></label>
					<input type="number" id="vbr_min_km" name="vbr_min_km" value="10" min="0" step="0.01" />

					<label for="vbr_max_km"><?php esc_html_e( 'Max km', 'virtual-balloon-race' ); ?></label>
					<input type="number" id="vbr_max_km" name="vbr_max_km" value="50" min="0" step="0.01" />

					<?php submit_button( __( 'Advance all active balloons', 'virtual-balloon-race' ), 'primary', 'submit', false ); ?>
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

		if ( 'toggled' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Balloon status updated.', 'virtual-balloon-race' );
			echo '</p></div>';
		}
	}

	// -------------------------------------------------------------------------
	// Frontend leaderboard shortcode
	// -------------------------------------------------------------------------

	/**
	 * Register frontend assets; enqueue on balloon products and leaderboard pages.
	 */
	public function enqueue_frontend_assets() {
		wp_register_style(
			'vbr-frontend',
			VBR_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			VBR_VERSION
		);

		wp_register_script(
			'vbr-frontend',
			VBR_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			VBR_VERSION,
			true
		);

		$needs_leaderboard = false;
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, 'balloon_leaderboard' ) ) {
				$needs_leaderboard = true;
			}
		}

		if ( $needs_leaderboard ) {
			wp_enqueue_style( 'vbr-frontend' );
			wp_enqueue_script( 'vbr-frontend' );
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'vbr-frontend' );
		}
	}

	/**
	 * Render [balloon_leaderboard].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_leaderboard_shortcode( $atts ) {
		wp_enqueue_style( 'vbr-frontend' );
		wp_enqueue_script( 'vbr-frontend' );

		$atts = shortcode_atts(
			array(
				'limit' => 50,
			),
			$atts,
			'balloon_leaderboard'
		);

		$limit = max( 1, intval( $atts['limit'] ) );

		global $wpdb;
		$table = $this->get_table_name();

		$balloons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE is_burst = 0 ORDER BY distance_km DESC, id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);

		ob_start();
		?>
		<div class="vbr-leaderboard" data-vbr-leaderboard>
			<div class="vbr-leaderboard__header">
				<h2 class="vbr-leaderboard__title"><?php esc_html_e( 'Balloon Race Leaderboard', 'virtual-balloon-race' ); ?></h2>
				<label class="vbr-leaderboard__search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search balloons', 'virtual-balloon-race' ); ?></span>
					<input
						type="search"
						class="vbr-leaderboard__search-input"
						placeholder="<?php esc_attr_e( 'Search balloon or owner name…', 'virtual-balloon-race' ); ?>"
						autocomplete="off"
					/>
				</label>
			</div>

			<div class="vbr-leaderboard__table-wrap">
				<table class="vbr-leaderboard__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Rank', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Balloon Name', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Owner', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Color', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Distance (km)', 'virtual-balloon-race' ); ?></th>
							<th><?php esc_html_e( 'Status', 'virtual-balloon-race' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $balloons ) ) : ?>
							<tr class="vbr-leaderboard__empty">
								<td colspan="6"><?php esc_html_e( 'The race has not started yet. Be the first balloon in the sky!', 'virtual-balloon-race' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $balloons as $index => $balloon ) : ?>
								<?php
								$search_haystack = strtolower( $balloon->balloon_name . ' ' . $balloon->owner_name );
								$color_slug      = sanitize_html_class( strtolower( $balloon->balloon_color ) );
								?>
								<tr data-vbr-search="<?php echo esc_attr( $search_haystack ); ?>">
									<td data-label="<?php esc_attr_e( 'Rank', 'virtual-balloon-race' ); ?>"><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Balloon Name', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $balloon->balloon_name ); ?></td>
									<td data-label="<?php esc_attr_e( 'Owner', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $balloon->owner_name ); ?></td>
									<td data-label="<?php esc_attr_e( 'Color', 'virtual-balloon-race' ); ?>">
										<span class="vbr-color vbr-color--<?php echo esc_attr( $color_slug ); ?>">
											<?php echo esc_html( $balloon->balloon_color ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Distance (km)', 'virtual-balloon-race' ); ?>">
										<?php echo esc_html( number_format_i18n( (float) $balloon->distance_km, 2 ) ); ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Status', 'virtual-balloon-race' ); ?>">
										<span class="vbr-pill vbr-pill--active"><?php esc_html_e( 'Active', 'virtual-balloon-race' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<p class="vbr-leaderboard__empty-search" hidden><?php esc_html_e( 'No balloons match that search.', 'virtual-balloon-race' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * Kick off the plugin.
 *
 * @return Virtual_Balloon_Race
 */
function vbr() {
	return Virtual_Balloon_Race::instance();
}

vbr();
