<?php
/**
 * WooCommerce integration: product flag, product fields, cart, orders.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns balloon product purchases into balloon rows.
 */
class VBR_WooCommerce {

	/**
	 * Register hooks.
	 */
	public function __construct() {
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
	public static function is_balloon_product( $product ) {
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

	/**
	 * Output balloon customisation fields on the single product page.
	 */
	public function output_product_fields() {
		global $product;

		if ( ! $product || ! self::is_balloon_product( $product ) ) {
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
					<?php foreach ( VBR_Balloons::colors() as $color ) : ?>
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
					<?php foreach ( VBR_Balloons::helium_strategies() as $strategy ) : ?>
						<option value="<?php echo esc_attr( $strategy ); ?>" <?php selected( $posted_helium, $strategy ); ?>>
							<?php echo esc_html( VBR_Balloons::helium_label( $strategy ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		</div>
		<?php
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
		if ( ! self::is_balloon_product( $product_id ) ) {
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

		if ( ! in_array( $color, VBR_Balloons::colors(), true ) ) {
			wc_add_notice( __( 'Please select a valid balloon colour.', 'virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		if ( ! in_array( $helium, VBR_Balloons::helium_strategies(), true ) ) {
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
		if ( ! self::is_balloon_product( $product_id ) ) {
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
				$value = VBR_Balloons::helium_label( $value );
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

		$table = VBR_Balloons::table();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();

			if ( ! $product || ! self::is_balloon_product( $product ) ) {
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

				$start = VBR_Balloons::launch_point();
				$row   = array_merge(
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
					VBR_Balloons::start_columns( $start['lat'], $start['lng'] )
				);

				/**
				 * Filter a new balloon row before insert (used by upsells: custom launch, logo).
				 *
				 * @param array                 $row   Column => value.
				 * @param WC_Order_Item_Product $item  Order item.
				 * @param WC_Order              $order Order.
				 */
				$row = apply_filters( 'vbr_new_balloon_row', $row, $item, $order );

				$inserted = $wpdb->insert( $table, $row );

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
}
