<?php
/**
 * "Balloon race entry" WooCommerce product type.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the balloon_entry product type and its admin UI.
 */
class VBR_Product_Type {

	const TYPE = 'balloon_entry';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		require_once VBR_PLUGIN_DIR . 'includes/class-wc-product-balloon-entry.php';

		add_filter( 'product_type_selector', array( $this, 'add_type' ) );
		add_filter( 'product_type_options', array( $this, 'hide_type_options' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'data_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'data_panel' ) );
		add_action( 'admin_footer', array( $this, 'admin_script' ) );

		// Simple products' add-to-cart form and handler work as-is.
		add_action( 'woocommerce_' . self::TYPE . '_add_to_cart', 'woocommerce_simple_add_to_cart' );

		add_action( 'admin_notices', array( $this, 'unconverted_notice' ) );
	}

	/**
	 * Product type dropdown entry.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public function add_type( $types ) {
		$types[ self::TYPE ] = __( 'Balloon race entry', 'virtual-balloon-race' );
		return $types;
	}

	/**
	 * Entries are always virtual and never downloadable: hide both boxes.
	 *
	 * @param array $options Product type options.
	 * @return array
	 */
	public function hide_type_options( $options ) {
		foreach ( array( 'virtual', 'downloadable' ) as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$options[ $key ]['wrapper_class'] = trim( $options[ $key ]['wrapper_class'] . ' hide_if_' . self::TYPE );
			}
		}
		return $options;
	}

	/**
	 * Add the Balloon race tab; entries need no shipping or linked-product upsells by type.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public function data_tabs( $tabs ) {
		if ( isset( $tabs['shipping'] ) ) {
			$tabs['shipping']['class'][] = 'hide_if_' . self::TYPE;
		}
		if ( isset( $tabs['general'] ) ) {
			$tabs['general']['class'][] = 'show_if_' . self::TYPE;
		}
		if ( isset( $tabs['inventory'] ) ) {
			$tabs['inventory']['class'][] = 'show_if_' . self::TYPE;
		}

		$tabs['vbr_balloon_race'] = array(
			'label'    => __( 'Balloon race', 'virtual-balloon-race' ),
			'target'   => 'vbr_balloon_race_data',
			'class'    => array( 'show_if_' . self::TYPE ),
			'priority' => 15,
		);

		return $tabs;
	}

	/**
	 * Balloon race tab contents; other classes add fields through `vbr_product_data_panel`.
	 */
	public function data_panel() {
		echo '<div id="vbr_balloon_race_data" class="panel woocommerce_options_panel hidden">';
		do_action( 'vbr_product_data_panel' );
		echo '</div>';
	}

	/**
	 * Show the price and stock fields that WooCommerce only shows for simple products.
	 */
	public function admin_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		$type = esc_js( self::TYPE );
		?>
		<script>
		jQuery( function ( $ ) {
			$( '.options_group.pricing, ._manage_stock_field, .stock_fields' ).addClass( 'show_if_<?php echo $type; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' );
			// Entries are always sold one balloon per basket line.
			$( '._sold_individually_field, .inventory_sold_individually' ).addClass( 'hide_if_<?php echo $type; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' );
			$( 'select#product-type' ).trigger( 'change' );
		} );
		</script>
		<?php
	}

	/**
	 * Old-style balloon products (tick box or tag) that couldn't become entries, e.g. variable products.
	 *
	 * @return int[]
	 */
	public static function unconverted_ids() {
		return array_map( 'intval', (array) get_option( 'vbr_unconverted_products', array() ) );
	}

	/**
	 * 1.3.0: turn simple products marked with the old tick box or tag into balloon entries.
	 */
	public static function migrate_legacy_products() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_vbr_is_balloon_entry', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);

		if ( taxonomy_exists( 'product_tag' ) ) {
			$tagged = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
						array(
							'taxonomy' => 'product_tag',
							'field'    => 'slug',
							'terms'    => 'balloon-entry',
						),
					),
				)
			);
			$ids = array_merge( $ids, $tagged );
		}

		$skipped = array();

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$terms = wp_get_object_terms( $id, 'product_type', array( 'fields' => 'slugs' ) );
			$type  = is_array( $terms ) && $terms ? $terms[0] : 'simple';

			if ( 'simple' === $type || self::TYPE === $type ) {
				wp_set_object_terms( $id, self::TYPE, 'product_type' );
				update_post_meta( $id, '_virtual', 'yes' );
				delete_post_meta( $id, '_vbr_is_balloon_entry' );
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $id );
				}
			} else {
				$skipped[] = $id;
			}
		}

		update_option( 'vbr_unconverted_products', $skipped, false );
	}

	/**
	 * Tell admins which old balloon products weren't converted.
	 */
	public function unconverted_notice() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$links = array();
		foreach ( self::unconverted_ids() as $id ) {
			if ( get_post( $id ) && self::TYPE !== WC_Product_Factory::get_product_type( $id ) ) {
				$links[] = '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
			}
		}

		if ( ! $links ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Virtual Balloon Race: balloon entries now use the "Balloon race entry" product type. These products were marked as entries but aren\'t simple products, so they weren\'t converted and no longer create balloons:', 'virtual-balloon-race' );
		echo ' ' . wp_kses_post( implode( ', ', $links ) );
		echo '</p></div>';
	}

	/**
	 * Create a draft entry product locked to a race.
	 *
	 * @param object $race Race.
	 * @return int|WP_Error Product ID.
	 */
	public static function create_for_race( $race ) {
		$product = new WC_Product_Balloon_Entry();
		/* translators: %s: race name */
		$product->set_name( sprintf( __( '%s balloon entry', 'virtual-balloon-race' ), $race->name ) );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->update_meta_data( VBR_Race_Entries::PRODUCT_META, (int) $race->id );

		$id = $product->save();

		return $id ? $id : new WP_Error( 'vbr_product_not_created', __( 'The product could not be created.', 'virtual-balloon-race' ) );
	}

	/**
	 * Entry products locked to a race (0 = products set to "any race taking entries").
	 *
	 * @param int $race_id Race ID.
	 * @return WC_Product[]
	 */
	public static function products_for_race( $race_id ) {
		$products = wc_get_products(
			array(
				'type'   => self::TYPE,
				'status' => array( 'publish', 'draft', 'pending', 'private' ),
				'limit'  => -1,
			)
		);

		return array_values(
			array_filter(
				$products,
				static function ( $product ) use ( $race_id ) {
					return (int) $product->get_meta( VBR_Race_Entries::PRODUCT_META ) === (int) $race_id;
				}
			)
		);
	}
}
