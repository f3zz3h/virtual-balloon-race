<?php
/**
 * Default pages: the race tracking page, with Settings controls to pick, create or regenerate it.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and looks up the plugin's front-end pages.
 */
class LVBR_Pages {

	const OPTION = 'lvbr_tracking_page_id';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'lvbr_settings_page_end', array( $this, 'render_panel' ) );
		add_action( 'admin_post_lvbr_pages', array( $this, 'handle_post' ) );
		add_filter( 'display_post_states', array( $this, 'post_state' ), 10, 2 );
	}

	/**
	 * Tracking page ID, or 0 when unset, trashed or deleted.
	 *
	 * @return int
	 */
	public static function tracking_page_id() {
		$id   = (int) get_option( self::OPTION, 0 );
		$page = $id ? get_post( $id ) : null;

		return $page && 'page' === $page->post_type && 'trash' !== $page->post_status ? $id : 0;
	}

	/**
	 * Tracking page URL, optionally for one race and balloon.
	 *
	 * @param object|null $race    Race.
	 * @param int         $balloon Balloon ID.
	 * @return string Empty when there's no tracking page.
	 */
	public static function tracking_url( $race = null, $balloon = 0 ) {
		$id = self::tracking_page_id();

		if ( ! $id ) {
			return '';
		}

		$args = array();
		if ( $race ) {
			$args['race'] = $race->slug;
		}
		if ( $balloon ) {
			$args['balloon'] = (int) $balloon;
		}

		return add_query_arg( $args, get_permalink( $id ) );
	}

	/**
	 * Default tracking page content: shortcode blocks admins can edit.
	 *
	 * @return string
	 */
	public static function default_content() {
		return self::shortcode_blocks( array( '[lvbr_map]', '[lvbr_prizes]', '[lvbr_teams]', '[lvbr_leaderboard]', '[lvbr_sponsors]', '[lvbr_races]' ) );
	}

	/**
	 * Shortcodes as shortcode blocks.
	 *
	 * @param string[] $blocks Shortcodes.
	 * @return string
	 */
	private static function shortcode_blocks( $blocks ) {
		return implode(
			"\n\n",
			array_map(
				static function ( $shortcode ) {
					return "<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->";
				},
				$blocks
			)
		);
	}

	/**
	 * A tracking page still on an earlier default gets the current one (team standings in 1.4.0, prizes in
	 * 1.14.0). Pages the organiser has changed are left alone.
	 */
	public static function upgrade_default_content() {
		$id   = self::tracking_page_id();
		$page = $id ? get_post( $id ) : null;
		$old  = array(
			self::shortcode_blocks( array( '[lvbr_map]', '[lvbr_leaderboard]', '[lvbr_races]' ) ),
			self::shortcode_blocks( array( '[lvbr_map]', '[lvbr_teams]', '[lvbr_leaderboard]', '[lvbr_races]' ) ),
		);

		if ( $page && in_array( trim( $page->post_content ), $old, true ) ) {
			self::regenerate();
		}
	}

	/**
	 * Create the tracking page if none is set.
	 *
	 * @return int Page ID.
	 */
	public static function create_missing() {
		$id = self::tracking_page_id();

		if ( $id ) {
			return $id;
		}

		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => __( 'Balloon Race', 'lumaha-virtual-balloon-race' ),
				'post_name'      => 'balloon-race',
				'post_content'   => self::default_content(),
				'comment_status' => 'closed',
			)
		);

		// Without $wp_error, wp_insert_post() returns 0 on failure.
		if ( $id ) {
			update_option( self::OPTION, (int) $id );
			return (int) $id;
		}

		return 0;
	}

	/**
	 * Reset the tracking page content to the default (creating the page if needed).
	 *
	 * @return int Page ID.
	 */
	public static function regenerate() {
		$id = self::tracking_page_id();

		if ( ! $id ) {
			return self::create_missing();
		}

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => self::default_content(),
			)
		);

		return $id;
	}

	/**
	 * Label the tracking page in the Pages list.
	 *
	 * @param string[] $states States.
	 * @param WP_Post  $post   Post.
	 * @return string[]
	 */
	public function post_state( $states, $post ) {
		if ( (int) $post->ID === self::tracking_page_id() ) {
			$states['lvbr_tracking'] = __( 'Balloon Race tracking page', 'lumaha-virtual-balloon-race' );
		}
		return $states;
	}

	/**
	 * Save / create / regenerate from the Settings screen.
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lumaha-virtual-balloon-race' ) );
		}

		check_admin_referer( 'lvbr_pages' );

		$do     = isset( $_POST['lvbr_do'] ) ? sanitize_key( wp_unslash( $_POST['lvbr_do'] ) ) : 'save';
		$notice = 'saved';

		switch ( $do ) {
			case 'create':
				$notice = self::tracking_page_id() ? 'exists' : ( self::create_missing() ? 'created' : 'failed' );
				break;

			case 'regenerate':
				$notice = self::regenerate() ? 'regenerated' : 'failed';
				break;

			case 'create_product':
				$notice = LVBR_Product_Type::available() && ! LVBR_Product_Type::all_products() && LVBR_Product_Type::create_default_product() ? 'product_created' : 'product_failed';
				break;

			default:
				$page = isset( $_POST['lvbr_tracking_page'] ) ? absint( $_POST['lvbr_tracking_page'] ) : 0;
				update_option( self::OPTION, $page && 'page' === get_post_type( $page ) ? $page : 0 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => LVBR_Settings::PAGE, 'lvbr_pages' => $notice ), admin_url( 'admin.php' ) ) . '#lvbr-pages' );
		exit;
	}

	/**
	 * Entry products, or a button to create the default one (WooCommerce only).
	 */
	private function render_products_row() {
		if ( ! LVBR_Product_Type::available() ) {
			return;
		}

		$products = LVBR_Product_Type::all_products();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Entry products', 'lumaha-virtual-balloon-race' ); ?></th>
				<td>
					<?php if ( $products ) : ?>
						<ul class="lvbr-entry-products">
							<?php foreach ( $products as $product ) : ?>
								<li>
									<a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
									&middot; <?php echo wp_kses_post( '' !== $product->get_price() ? wc_price( (float) $product->get_price() ) : __( 'no price', 'lumaha-virtual-balloon-race' ) ); ?>
									&middot; <?php echo esc_html( 'publish' === $product->get_status() ? __( 'Published', 'lumaha-virtual-balloon-race' ) : __( 'Not published', 'lumaha-virtual-balloon-race' ) ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<button type="submit" class="button" name="lvbr_do" value="create_product"><?php esc_html_e( 'Create entry product', 'lumaha-virtual-balloon-race' ); ?></button>
					<?php endif; ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: price, e.g. £3.00 */
								__( 'Products of type "Balloon race entry". A fresh install creates one called Balloon Race Entry at %s; change its name, price and picture like any product.', 'lumaha-virtual-balloon-race' ),
								wp_strip_all_tags( wc_price( 3 ) )
							)
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Pages panel on the Settings screen.
	 */
	public function render_panel() {
		$id       = self::tracking_page_id();
		$notice   = isset( $_GET['lvbr_pages'] ) ? sanitize_key( $_GET['lvbr_pages'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'           => __( 'Tracking page saved.', 'lumaha-virtual-balloon-race' ),
			'created'         => __( 'Tracking page created.', 'lumaha-virtual-balloon-race' ),
			'exists'          => __( 'The tracking page already exists, so nothing was created.', 'lumaha-virtual-balloon-race' ),
			'regenerated'     => __( 'Tracking page content reset to the default.', 'lumaha-virtual-balloon-race' ),
			'failed'          => __( 'The page could not be created.', 'lumaha-virtual-balloon-race' ),
			'product_created' => __( 'Entry product created and published. It can be bought whenever a race is taking entries.', 'lumaha-virtual-balloon-race' ),
			'product_failed'  => __( 'The entry product could not be created.', 'lumaha-virtual-balloon-race' ),
		);
		?>
		<section class="lvbr-admin-panel" id="lvbr-pages">
			<h2><?php esc_html_e( 'Pages and products', 'lumaha-virtual-balloon-race' ); ?></h2>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo in_array( $notice, array( 'failed', 'product_failed' ), true ) ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lvbr_pages" />
				<?php wp_nonce_field( 'lvbr_pages' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lvbr_tracking_page"><?php esc_html_e( 'Race tracking page', 'lumaha-virtual-balloon-race' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'lvbr_tracking_page',
									'id'                => 'lvbr_tracking_page',
									'selected'          => absint( $id ),
									'show_option_none'  => esc_html__( '— None —', 'lumaha-virtual-balloon-race' ),
									'option_none_value' => '0',
									'post_status'       => array( 'publish', 'private', 'draft' ),
								)
							);
							?>
							<button type="submit" class="button" name="lvbr_do" value="save"><?php esc_html_e( 'Save', 'lumaha-virtual-balloon-race' ); ?></button>
							<?php if ( $id ) : ?>
								<a class="button-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'lumaha-virtual-balloon-race' ); ?></a>
								&middot; <a class="button-link" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'lumaha-virtual-balloon-race' ); ?></a>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Shows the live map, team standings (when teams are on), the leaderboard and a list of races. Add ?race=<slug> to the address for a particular race. Leaderboard names link here, and order emails use it for "Track your balloon" links.', 'lumaha-virtual-balloon-race' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php $this->render_products_row(); ?>

				<p>
					<?php if ( ! $id ) : ?>
						<button type="submit" class="button button-primary" name="lvbr_do" value="create"><?php esc_html_e( 'Create tracking page', 'lumaha-virtual-balloon-race' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button" name="lvbr_do" value="regenerate" onclick="return confirm('<?php echo esc_js( __( 'Replace the tracking page content with the default map, team standings, leaderboard and race list? Your edits to that page will be lost.', 'lumaha-virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Regenerate page content', 'lumaha-virtual-balloon-race' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</section>
		<?php
	}
}
