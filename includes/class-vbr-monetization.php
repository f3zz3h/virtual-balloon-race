<?php
/**
 * Paid add-ons on balloon products: tactical helium strategy, custom launch point, logo.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upsell fields, cart pricing and order → balloon hand-off.
 */
class VBR_Monetization {

	const LOGO_FIELD     = 'vbr_logo';
	const LOGO_MAX_BYTES = 1048576; // 1 MB.

	/**
	 * Allowed logo types: extension regex => MIME.
	 *
	 * @var array
	 */
	private static $logo_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'vbr_product_fields_after', array( $this, 'output_fields' ) );
		add_filter( 'vbr_helium_option_label', array( $this, 'helium_option_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 20, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_order_item_meta' ) );

		add_filter( 'vbr_new_balloon_row', array( $this, 'apply_to_new_balloon' ), 10, 2 );
	}

	/**
	 * Add-on price for a feature (0 when disabled).
	 *
	 * @param string $feature 'strategy' | 'launch' | 'logo'.
	 * @return float
	 */
	public static function price( $feature ) {
		if ( 'launch' === $feature && ! VBR_Settings::get( 'enable_launch' ) ) {
			return 0.0;
		}

		if ( 'logo' === $feature && ! VBR_Settings::get( 'enable_logo' ) ) {
			return 0.0;
		}

		return max( 0.0, (float) VBR_Settings::get( 'price_' . $feature ) );
	}

	/**
	 * Whether a helium strategy carries the tactical fee.
	 *
	 * @param string $strategy Strategy.
	 * @return bool
	 */
	public static function strategy_is_paid( $strategy ) {
		return in_array( $strategy, array( 'Max Height', 'Eco-Glide' ), true );
	}

	/**
	 * Plain-text price, e.g. "+£2.00".
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function plus_price( $amount ) {
		return '+' . html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Append the strategy fee to helium options.
	 *
	 * @param string $label    Label.
	 * @param string $strategy Strategy.
	 * @return string
	 */
	public function helium_option_label( $label, $strategy ) {
		$fee = self::price( 'strategy' );

		if ( $fee > 0 && self::strategy_is_paid( $strategy ) ) {
			$label .= ' (' . self::plus_price( $fee ) . ')';
		}

		return $label;
	}

	/**
	 * Load the product-page script (launch picker + logo preview).
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product || ! VBR_WooCommerce::is_balloon_product( $product ) ) {
			return;
		}

		VBR_Map::register_balloon_script();

		$deps = array( 'vbr-balloon' );
		if ( VBR_Settings::get( 'enable_launch' ) ) {
			VBR_Settings::register_leaflet();
			wp_enqueue_style( 'vbr-leaflet' );
			$deps[] = 'vbr-leaflet';
		}

		$races  = VBR_Race_Entries::open_races_for( $product->get_id() );
		$launch = VBR_Races::launch_point( $races ? $races[0] : null );

		wp_enqueue_script( 'vbr-product', VBR_PLUGIN_URL . 'assets/js/product.js', $deps, VBR_Balloons::asset_version( 'assets/js/product.js' ), true );
		wp_localize_script(
			'vbr-product',
			'vbrProduct',
			array(
				'launch'  => array( $launch['lat'], $launch['lng'] ),
				'maxLogo' => self::LOGO_MAX_BYTES,
				'colors'  => VBR_Map::color_hex(),
				'i18n'    => array(
					'searching' => __( 'Searching…', 'virtual-balloon-race' ),
					'noResults' => __( 'No places found.', 'virtual-balloon-race' ),
					'error'     => __( 'Search failed. Click the map instead.', 'virtual-balloon-race' ),
					'tooBig'    => __( 'That image is over 1 MB. Please choose a smaller file.', 'virtual-balloon-race' ),
					'badType'   => __( 'Please choose a PNG, JPG or WebP image.', 'virtual-balloon-race' ),
					'chosen'    => __( 'Launching from:', 'virtual-balloon-race' ),
				),
			)
		);
	}

	/**
	 * Output add-on fields inside the balloon customisation box.
	 */
	public function output_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- re-populating the form after a failed add-to-cart.
		$posted_launch = ! empty( $_POST['vbr_custom_launch'] );
		$posted_lat    = isset( $_POST['vbr_launch_lat'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lat'] ) ) : '';
		$posted_lng    = isset( $_POST['vbr_launch_lng'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lng'] ) ) : '';
		$posted_label  = isset( $_POST['vbr_launch_label'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_label'] ) ) : '';
		// phpcs:enable

		$launch_price = self::price( 'launch' );
		$logo_price   = self::price( 'logo' );

		if ( VBR_Settings::get( 'enable_launch' ) ) :
			?>
			<div class="vbr-addon vbr-addon--launch" data-vbr-launch>
				<label class="vbr-addon__toggle">
					<input type="checkbox" name="vbr_custom_launch" value="1" <?php checked( $posted_launch ); ?> />
					<span>
						<?php esc_html_e( 'Launch from my own location', 'virtual-balloon-race' ); ?>
						<?php if ( $launch_price > 0 ) : ?>
							<strong class="vbr-addon__price"><?php echo esc_html( self::plus_price( $launch_price ) ); ?></strong>
						<?php endif; ?>
					</span>
				</label>
				<p class="vbr-addon__help"><?php esc_html_e( 'Start your balloon from your school, hometown or anywhere you like.', 'virtual-balloon-race' ); ?></p>

				<div class="vbr-addon__panel" <?php echo $posted_launch ? '' : 'hidden'; ?>>
					<div class="vbr-addon__search">
						<input type="search" class="vbr-addon__search-input" placeholder="<?php esc_attr_e( 'Search a place or postcode', 'virtual-balloon-race' ); ?>" aria-label="<?php esc_attr_e( 'Search a place or postcode', 'virtual-balloon-race' ); ?>" />
						<button type="button" class="button vbr-addon__search-btn"><?php esc_html_e( 'Search', 'virtual-balloon-race' ); ?></button>
					</div>
					<ul class="vbr-addon__results" hidden></ul>
					<div class="vbr-addon__map"></div>
					<p class="vbr-addon__chosen" aria-live="polite"></p>
					<input type="hidden" name="vbr_launch_lat" value="<?php echo esc_attr( $posted_lat ); ?>" />
					<input type="hidden" name="vbr_launch_lng" value="<?php echo esc_attr( $posted_lng ); ?>" />
					<input type="hidden" name="vbr_launch_label" value="<?php echo esc_attr( $posted_label ); ?>" />
				</div>
			</div>
			<?php
		endif;

		if ( VBR_Settings::get( 'enable_logo' ) ) :
			?>
			<div class="vbr-addon vbr-addon--logo">
				<label for="vbr_logo" class="vbr-addon__label">
					<?php esc_html_e( 'Add your logo or picture (optional)', 'virtual-balloon-race' ); ?>
					<?php if ( $logo_price > 0 ) : ?>
						<strong class="vbr-addon__price"><?php echo esc_html( self::plus_price( $logo_price ) ); ?></strong>
					<?php endif; ?>
				</label>
				<input type="file" id="vbr_logo" name="<?php echo esc_attr( self::LOGO_FIELD ); ?>" accept="image/png,image/jpeg,image/webp" />
				<p class="vbr-addon__help"><?php esc_html_e( 'Shown on your balloon on the race map. PNG, JPG or WebP, up to 1 MB.', 'virtual-balloon-race' ); ?></p>
				<span class="vbr-addon__preview" role="img" aria-label="<?php esc_attr_e( 'Preview of your balloon', 'virtual-balloon-race' ); ?>" hidden></span>
			</div>
			<?php
		endif;
	}

	/**
	 * Validate add-on fields.
	 *
	 * @param bool $passed     Validation so far.
	 * @param int  $product_id Product ID.
	 * @return bool
	 */
	public function validate( $passed, $product_id ) {
		if ( ! $passed || ! VBR_WooCommerce::is_balloon_product( $product_id ) ) {
			return $passed;
		}

		// Nonce already verified by VBR_WooCommerce::validate_product_fields().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( VBR_Settings::get( 'enable_launch' ) && ! empty( $_POST['vbr_custom_launch'] ) ) {
			$lat = isset( $_POST['vbr_launch_lat'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lat'] ) ) : '';
			$lng = isset( $_POST['vbr_launch_lng'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lng'] ) ) : '';

			if ( ! VBR_Geo::is_valid( $lat, $lng ) ) {
				wc_add_notice( __( 'Please choose your launch location on the map, or untick “Launch from my own location”.', 'virtual-balloon-race' ), 'error' );
				$passed = false;
			}
		}
		// phpcs:enable

		$logo_error = $this->check_logo_upload();
		if ( is_wp_error( $logo_error ) ) {
			wc_add_notice( $logo_error->get_error_message(), 'error' );
			$passed = false;
		}

		return $passed;
	}

	/**
	 * Check the uploaded logo (if any) without trusting the browser's MIME type.
	 *
	 * @return true|null|WP_Error True if valid, null if none uploaded.
	 */
	private function check_logo_upload() {
		if ( ! VBR_Settings::get( 'enable_logo' ) || empty( $_FILES[ self::LOGO_FIELD ] ) || ! is_array( $_FILES[ self::LOGO_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}

		$file  = $_FILES[ self::LOGO_FIELD ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return null;
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || ( isset( $file['size'] ) && (int) $file['size'] > self::LOGO_MAX_BYTES ) ) {
			return new WP_Error( 'vbr_logo_size', __( 'Your logo is over 1 MB. Please choose a smaller image.', 'virtual-balloon-race' ) );
		}

		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'vbr_logo_upload', __( 'Your logo could not be uploaded. Please try again.', 'virtual-balloon-race' ) );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( (string) $file['name'] ), self::$logo_mimes );
		$info  = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::$logo_mimes, true ) || ! $info || ! in_array( $info['mime'], self::$logo_mimes, true ) ) {
			return new WP_Error( 'vbr_logo_type', __( 'Your logo must be a PNG, JPG or WebP image.', 'virtual-balloon-race' ) );
		}

		return true;
	}

	/**
	 * Store the add-ons on the cart item (and save the uploaded logo).
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @return array
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( ! VBR_WooCommerce::is_balloon_product( $product_id ) || empty( $cart_item_data['vbr_balloon_name'] ) ) {
			return $cart_item_data;
		}

		$addons = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in VBR_WooCommerce::add_cart_item_data().
		if ( self::strategy_is_paid( isset( $cart_item_data['vbr_helium_level'] ) ? $cart_item_data['vbr_helium_level'] : '' ) && self::price( 'strategy' ) > 0 ) {
			$addons['strategy'] = self::price( 'strategy' );
		}

		if ( VBR_Settings::get( 'enable_launch' ) && ! empty( $_POST['vbr_custom_launch'] ) ) {
			$lat = isset( $_POST['vbr_launch_lat'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lat'] ) ) : '';
			$lng = isset( $_POST['vbr_launch_lng'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_lng'] ) ) : '';

			if ( VBR_Geo::is_valid( $lat, $lng ) ) {
				$label = isset( $_POST['vbr_launch_label'] ) ? sanitize_text_field( wp_unslash( $_POST['vbr_launch_label'] ) ) : '';

				$cart_item_data['vbr_launch'] = array(
					'lat'   => round( (float) $lat, 6 ),
					'lng'   => round( (float) $lng, 6 ),
					'label' => mb_substr( $label, 0, 120 ),
				);
				$addons['launch']             = self::price( 'launch' );
			}
		}
		// phpcs:enable

		if ( true === $this->check_logo_upload() ) {
			$logo_id = $this->save_logo();

			if ( $logo_id ) {
				$cart_item_data['vbr_logo_id'] = $logo_id;
				$addons['logo']                = self::price( 'logo' );
			}
		}

		$cart_item_data['vbr_addons'] = array_filter( $addons );

		return $cart_item_data;
	}

	/**
	 * Save the uploaded logo as a media attachment.
	 *
	 * @return int Attachment ID or 0.
	 */
	private function save_logo() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_upload(
			self::LOGO_FIELD,
			0,
			array( 'post_title' => __( 'Balloon logo', 'virtual-balloon-race' ) ),
			array(
				'test_form' => false,
				'mimes'     => self::$logo_mimes,
			)
		);

		if ( is_wp_error( $id ) ) {
			wc_add_notice( __( 'Your logo could not be saved, so the balloon was added without it.', 'virtual-balloon-race' ), 'notice' );
			return 0;
		}

		// Tag uploads so unpaid / abandoned ones can be found and cleaned up.
		update_post_meta( $id, '_vbr_logo_upload', 'cart' );

		return (int) $id;
	}

	/**
	 * Add the add-on prices to each balloon line.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public function apply_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['vbr_addons'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}

			// Read the base price from a fresh product so repeated calculations don't stack.
			$base_product = wc_get_product( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'] );

			if ( ! $base_product ) {
				continue;
			}

			$cart_item['data']->set_price( (float) $base_product->get_price() + array_sum( array_map( 'floatval', $cart_item['vbr_addons'] ) ) );
		}
	}

	/**
	 * Show add-ons on cart and checkout lines.
	 *
	 * @param array $item_data Display rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		$addons = isset( $cart_item['vbr_addons'] ) ? (array) $cart_item['vbr_addons'] : array();

		if ( ! empty( $addons['strategy'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Tactical strategy', 'virtual-balloon-race' ),
				'value' => self::plus_price( $addons['strategy'] ),
			);
		}

		if ( ! empty( $cart_item['vbr_launch'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Launch point', 'virtual-balloon-race' ),
				'value' => self::launch_text( $cart_item['vbr_launch'] ) . ( ! empty( $addons['launch'] ) ? ' (' . self::plus_price( $addons['launch'] ) . ')' : '' ),
			);
		}

		if ( ! empty( $cart_item['vbr_logo_id'] ) ) {
			$thumb = wp_get_attachment_image( (int) $cart_item['vbr_logo_id'], array( 40, 40 ), false, array( 'class' => 'vbr-cart-logo' ) );

			$item_data[] = array(
				'key'     => __( 'Logo', 'virtual-balloon-race' ),
				'value'   => __( 'Uploaded', 'virtual-balloon-race' ),
				'display' => $thumb . ( ! empty( $addons['logo'] ) ? ' ' . esc_html( self::plus_price( $addons['logo'] ) ) : '' ),
			);
		}

		return $item_data;
	}

	/**
	 * Human-readable launch point.
	 *
	 * @param array $launch Launch data.
	 * @return string
	 */
	private static function launch_text( $launch ) {
		$coords = sprintf( '%.4f, %.4f', $launch['lat'], $launch['lng'] );

		return ! empty( $launch['label'] ) ? $launch['label'] . ' (' . $coords . ')' : $coords;
	}

	/**
	 * Copy add-ons to the order line item.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values ) {
		if ( ! empty( $values['vbr_launch'] ) ) {
			$item->add_meta_data( __( 'Launch Point', 'virtual-balloon-race' ), self::launch_text( $values['vbr_launch'] ), true );
			$item->add_meta_data( '_vbr_launch_lat', (float) $values['vbr_launch']['lat'], true );
			$item->add_meta_data( '_vbr_launch_lng', (float) $values['vbr_launch']['lng'], true );
		}

		if ( ! empty( $values['vbr_logo_id'] ) ) {
			$logo_id = (int) $values['vbr_logo_id'];
			$item->add_meta_data( '_vbr_logo_id', $logo_id, true );
			$item->add_meta_data( __( 'Balloon Logo', 'virtual-balloon-race' ), (string) wp_get_attachment_url( $logo_id ), true );
			update_post_meta( $logo_id, '_vbr_logo_upload', 'ordered' );
		}

		if ( ! empty( $values['vbr_addons'] ) ) {
			$item->add_meta_data( '_vbr_addons', array_map( 'floatval', (array) $values['vbr_addons'] ), true );
		}
	}

	/**
	 * Hide internal meta on order screens.
	 *
	 * @param string[] $hidden Hidden keys.
	 * @return string[]
	 */
	public function hide_order_item_meta( $hidden ) {
		return array_merge( $hidden, array( '_vbr_launch_lat', '_vbr_launch_lng', '_vbr_logo_id', '_vbr_addons', '_vbr_balloon_id' ) );
	}

	/**
	 * Start the new balloon at its custom launch point and attach its logo.
	 *
	 * @param array                 $row  Balloon row.
	 * @param WC_Order_Item_Product $item Order item.
	 * @return array
	 */
	public function apply_to_new_balloon( $row, $item ) {
		$lat = $item->get_meta( '_vbr_launch_lat', true );
		$lng = $item->get_meta( '_vbr_launch_lng', true );

		if ( '' !== $lat && '' !== $lng && VBR_Geo::is_valid( $lat, $lng ) ) {
			$row = array_merge( $row, VBR_Balloons::start_columns( (float) $lat, (float) $lng ) );
			$row['has_custom_start'] = 1;
		}

		$logo_id = (int) $item->get_meta( '_vbr_logo_id', true );
		if ( $logo_id && wp_attachment_is_image( $logo_id ) ) {
			$row['logo_attachment_id'] = $logo_id;
		}

		return $row;
	}
}
