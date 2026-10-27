<?php
/**
 * WooCommerce integration: product fields, cart, orders.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns balloon product purchases into balloon rows.
 */
class LVBR_WooCommerce {

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

		// One balloon per basket line: clearer button and message, fresh name for the next balloon.
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'add_to_cart_text' ), 10, 2 );
		add_filter( 'wc_add_to_cart_message_html', array( $this, 'added_message' ), 10, 2 );

		// Create balloon records when payment is taken / order completes.
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_balloons_from_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_balloons_from_order' ) );
	}

	/**
	 * Determine whether a product is a balloon race entry (the `lvbr_balloon_entry` product type).
	 *
	 * @param int|WC_Product $product Product ID or object.
	 * @return bool
	 */
	public static function is_balloon_product( $product ) {
		if ( is_numeric( $product ) ) {
			$product = (int) $product > 0 ? wc_get_product( (int) $product ) : null;
		}

		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$product_id = $product->get_id();

		if ( $product->is_type( LVBR_Product_Type::TYPE ) ) {
			return true;
		}

		return (bool) apply_filters( 'lvbr_is_balloon_product', false, $product_id );
	}

	/**
	 * Output balloon customisation fields on the single product page.
	 */
	public function output_product_fields() {
		global $product;

		if ( ! $product || ! self::is_balloon_product( $product ) ) {
			return;
		}

		wp_nonce_field( 'lvbr_add_balloon', 'lvbr_balloon_nonce' );

		// After a balloon is added, keep the other choices but start the next balloon with a fresh name.
		$posted_name   = isset( $_POST['lvbr_balloon_name'] ) && ! did_action( 'woocommerce_add_to_cart' ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_color  = isset( $_POST['lvbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$posted_helium = isset( $_POST['lvbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_helium_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		?>
		<div class="lvbr-product-fields">
			<h3 class="lvbr-product-fields__title"><?php esc_html_e( 'Customise your balloon', 'lumaha-virtual-balloon-race' ); ?></h3>

			<?php
			/**
			 * Fields at the top of the balloon box (race picker).
			 *
			 * @param WC_Product $product Product.
			 */
			do_action( 'lvbr_product_fields_before', $product );
			?>

			<p class="form-row form-row-wide">
				<label for="lvbr_balloon_name">
					<?php esc_html_e( 'Balloon Name', 'lumaha-virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'lumaha-virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<input type="text" id="lvbr_balloon_name" name="lvbr_balloon_name" maxlength="255" value="<?php echo esc_attr( $posted_name ); ?>" required />
			</p>

			<p class="form-row form-row-wide">
				<label for="lvbr_balloon_color">
					<?php esc_html_e( 'Balloon Color', 'lumaha-virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'lumaha-virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<select id="lvbr_balloon_color" name="lvbr_balloon_color" required>
					<option value=""><?php esc_html_e( 'Select a colour', 'lumaha-virtual-balloon-race' ); ?></option>
					<?php foreach ( LVBR_Balloons::colors() as $color ) : ?>
						<option value="<?php echo esc_attr( $color ); ?>" <?php selected( $posted_color, $color ); ?>>
							<?php echo esc_html( $color ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="form-row form-row-wide">
				<label for="lvbr_helium_level">
					<?php esc_html_e( 'Helium Fill Strategy', 'lumaha-virtual-balloon-race' ); ?>
					<abbr class="required" title="<?php esc_attr_e( 'Required', 'lumaha-virtual-balloon-race' ); ?>">*</abbr>
				</label>
				<select id="lvbr_helium_level" name="lvbr_helium_level" required>
					<option value=""><?php esc_html_e( 'Select a strategy', 'lumaha-virtual-balloon-race' ); ?></option>
					<?php foreach ( LVBR_Balloons::helium_strategies() as $strategy ) : ?>
						<option value="<?php echo esc_attr( $strategy ); ?>" <?php selected( $posted_helium, $strategy ); ?>>
							<?php echo esc_html( apply_filters( 'lvbr_helium_option_label', LVBR_Balloons::helium_label( $strategy ), $strategy ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<?php
			/**
			 * Extra fields inside the balloon box (paid add-ons).
			 *
			 * @param WC_Product $product Product.
			 */
			do_action( 'lvbr_product_fields_after', $product );
			?>
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

		if ( ! isset( $_POST['lvbr_balloon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_nonce'] ) ), 'lvbr_add_balloon' ) ) {
			wc_add_notice( __( 'Security check failed. Please reload the page and try again.', 'lumaha-virtual-balloon-race' ), 'error' );
			return false;
		}

		$name   = isset( $_POST['lvbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_name'] ) ) : '';
		$color  = isset( $_POST['lvbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_color'] ) ) : '';
		$helium = isset( $_POST['lvbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_helium_level'] ) ) : '';

		if ( '' === $name ) {
			wc_add_notice( __( 'Please enter a balloon name.', 'lumaha-virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		if ( ! in_array( $color, LVBR_Balloons::colors(), true ) ) {
			wc_add_notice( __( 'Please select a valid balloon colour.', 'lumaha-virtual-balloon-race' ), 'error' );
			$passed = false;
		}

		if ( ! in_array( $helium, LVBR_Balloons::helium_strategies(), true ) ) {
			wc_add_notice( __( 'Please select a helium fill strategy.', 'lumaha-virtual-balloon-race' ), 'error' );
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

		if ( ! isset( $_POST['lvbr_balloon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_nonce'] ) ), 'lvbr_add_balloon' ) ) {
			return $cart_item_data;
		}

		$cart_item_data['lvbr_balloon_name']  = isset( $_POST['lvbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_name'] ) ) : '';
		$cart_item_data['lvbr_balloon_color'] = isset( $_POST['lvbr_balloon_color'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_color'] ) ) : '';
		$cart_item_data['lvbr_helium_level']  = isset( $_POST['lvbr_helium_level'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_helium_level'] ) ) : '';

		// Allow multiple uniquely customised balloons of the same product.
		$cart_item_data['lvbr_unique_key'] = md5( microtime() . wp_rand() );

		return $cart_item_data;
	}

	/**
	 * "Add balloon to basket" on balloon entry products.
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function add_to_cart_text( $text, $product ) {
		return self::is_balloon_product( $product ) ? __( 'Add balloon to basket', 'lumaha-virtual-balloon-race' ) : $text;
	}

	/**
	 * After adding a balloon: name it and offer to design another.
	 *
	 * @param string $message  Message HTML.
	 * @param array  $products Product ID => quantity.
	 * @return string
	 */
	public function added_message( $message, $products ) {
		$ids = array_keys( (array) $products );

		if ( 1 !== count( $ids ) || ! self::is_balloon_product( $ids[0] ) ) {
			return $message;
		}

		$name = isset( $_POST['lvbr_balloon_name'] ) ? sanitize_text_field( wp_unslash( $_POST['lvbr_balloon_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only.

		return sprintf(
			'<a href="%1$s" class="button wc-forward">%2$s</a> %3$s <a href="%4$s">%5$s</a>',
			esc_url( wc_get_cart_url() ),
			esc_html__( 'View basket', 'lumaha-virtual-balloon-race' ),
			esc_html(
				'' !== $name
					/* translators: %s: balloon name */
					? sprintf( __( '“%s” is in your basket.', 'lumaha-virtual-balloon-race' ), $name )
					: __( 'Your balloon is in your basket.', 'lumaha-virtual-balloon-race' )
			),
			esc_url( get_permalink( $ids[0] ) ),
			esc_html__( 'Design another balloon', 'lumaha-virtual-balloon-race' )
		);
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
			'lvbr_balloon_name'  => __( 'Balloon Name', 'lumaha-virtual-balloon-race' ),
			'lvbr_balloon_color' => __( 'Balloon Color', 'lumaha-virtual-balloon-race' ),
			'lvbr_helium_level'  => __( 'Helium Fill Strategy', 'lumaha-virtual-balloon-race' ),
		);

		foreach ( $map as $key => $label ) {
			if ( empty( $cart_item[ $key ] ) ) {
				continue;
			}

			$value = $cart_item[ $key ];
			if ( 'lvbr_helium_level' === $key ) {
				$value = LVBR_Balloons::helium_label( $value );
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
		if ( ! empty( $values['lvbr_balloon_name'] ) ) {
			$item->add_meta_data( __( 'Balloon Name', 'lumaha-virtual-balloon-race' ), sanitize_text_field( $values['lvbr_balloon_name'] ), true );
		}

		if ( ! empty( $values['lvbr_balloon_color'] ) ) {
			$item->add_meta_data( __( 'Balloon Color', 'lumaha-virtual-balloon-race' ), sanitize_text_field( $values['lvbr_balloon_color'] ), true );
		}

		if ( ! empty( $values['lvbr_helium_level'] ) ) {
			$item->add_meta_data( __( 'Helium Fill Strategy', 'lumaha-virtual-balloon-race' ), sanitize_text_field( $values['lvbr_helium_level'] ), true );
		}
	}

	/**
	 * Insert balloon rows when an order reaches processing or completed.
	 *
	 * Duplicate inserts are prevented via hidden order-item meta `_lvbr_balloon_id`.
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

		$table = LVBR_Balloons::table();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;

			if ( ! $product || ! self::is_balloon_product( $product ) ) {
				continue;
			}

			$existing_id = wc_get_order_item_meta( $item_id, '_lvbr_balloon_id', true );
			if ( $existing_id ) {
				continue;
			}

			$balloon_name  = $this->get_item_meta_first( $item, array( 'Balloon Name', 'lvbr_balloon_name' ) );
			$balloon_color = $this->get_item_meta_first( $item, array( 'Balloon Color', 'lvbr_balloon_color' ) );
			$helium_level  = $this->get_item_meta_first( $item, array( 'Helium Fill Strategy', 'lvbr_helium_level', 'Helium Level' ) );

			$quantity = max( 1, (int) $item->get_quantity() );

			for ( $i = 0; $i < $quantity; $i++ ) {
				$name_to_store = $balloon_name;

				if ( $quantity > 1 && $name_to_store ) {
					$name_to_store = sprintf(
						/* translators: 1: balloon name, 2: index */
						__( '%1$s (%2$d)', 'lumaha-virtual-balloon-race' ),
						$balloon_name,
						$i + 1
					);
				}

				$start = LVBR_Balloons::launch_point();
				$row   = array_merge(
					array(
						'order_id'      => $order_id,
						'order_item_id' => (int) $item_id,
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
					LVBR_Balloons::start_columns( $start['lat'], $start['lng'] )
				);

				/**
				 * Filter a new balloon row before insert (used by upsells: custom launch, logo).
				 *
				 * @param array                 $row   Column => value.
				 * @param WC_Order_Item_Product $item  Order item.
				 * @param WC_Order              $order Order.
				 */
				$row = apply_filters( 'lvbr_new_balloon_row', $row, $item, $order );

				$inserted = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.

				if ( false !== $inserted && 0 === $i ) {
					wc_add_order_item_meta( $item_id, '_lvbr_balloon_id', (int) $wpdb->insert_id, true );
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
