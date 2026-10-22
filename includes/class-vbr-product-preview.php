<?php
/**
 * Live balloon preview in place of the product gallery on balloon entry products.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Swaps the product images for a balloon that follows the buyer's choices (drawn by product.js).
 */
class VBR_Product_Preview {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_replace_gallery' ) );
	}

	/**
	 * On balloon entry product pages, replace the gallery with the preview.
	 *
	 * Filter `vbr_product_preview` returning false keeps the normal product images.
	 */
	public function maybe_replace_gallery() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product || ! VBR_WooCommerce::is_balloon_product( $product ) || ! apply_filters( 'vbr_product_preview', true, $product ) ) {
			return;
		}

		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );

		// Block themes draw the images with a block (Twenty Twenty-Five and other block themes): swap the
		// preview in for that block. Classic themes, and block themes using WooCommerce's classic template, use the hook.
		if ( self::uses_block_template() ) {
			add_filter( 'render_block_woocommerce/product-image-gallery', array( $this, 'replace_block' ) );
			add_filter( 'render_block_woocommerce/product-gallery', array( $this, 'replace_block' ) );
		} else {
			add_action( 'woocommerce_before_single_product_summary', array( $this, 'render' ), 20 );
		}
	}

	/**
	 * Whether the product page comes from a block template (not WooCommerce's classic template block).
	 *
	 * @return bool
	 */
	private static function uses_block_template() {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || ! function_exists( 'get_block_template' ) ) {
			return false;
		}

		$template = get_block_template( get_stylesheet() . '//single-product' );

		return $template && false === strpos( (string) $template->content, 'woocommerce/legacy-template' );
	}

	/**
	 * The preview in place of the product image block.
	 *
	 * @return string
	 */
	public function replace_block() {
		ob_start();
		$this->render();

		return '<div class="wp-block-woocommerce-product-image-gallery vbr-product-preview-block">' . ob_get_clean() . '</div>';
	}

	/**
	 * Preview markup; product.js draws the balloon and fills in the details.
	 */
	public function render() {
		?>
		<div class="images vbr-product-preview" data-vbr-preview>
			<div class="vbr-product-preview__sky">
				<span class="vbr-product-preview__cloud vbr-product-preview__cloud--one" aria-hidden="true"></span>
				<span class="vbr-product-preview__cloud vbr-product-preview__cloud--two" aria-hidden="true"></span>
				<?php // Sized inline so the balloon stays small even if the stylesheet arrives late (e.g. a cache rebuilding it). ?>
				<span class="vbr-product-preview__balloon" style="display:block;width:clamp(110px,30vw,160px);aspect-ratio:2/3;margin:2rem auto" role="img" aria-label="<?php esc_attr_e( 'Preview of your balloon', 'virtual-balloon-race' ); ?>"></span>
			</div>
			<div class="vbr-product-preview__caption">
				<p class="vbr-product-preview__name"><?php esc_html_e( 'Your balloon', 'virtual-balloon-race' ); ?></p>
				<p class="vbr-product-preview__meta" aria-live="polite"><?php esc_html_e( 'Pick a colour to see your balloon.', 'virtual-balloon-race' ); ?></p>
			</div>
		</div>
		<?php
	}
}
