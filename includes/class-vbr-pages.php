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
class VBR_Pages {

	const OPTION = 'vbr_tracking_page_id';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'vbr_settings_page_end', array( $this, 'render_panel' ) );
		add_action( 'admin_post_vbr_pages', array( $this, 'handle_post' ) );
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
		$blocks = array( '[balloon_map]', '[balloon_leaderboard]', '[balloon_races]' );

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
				'post_title'     => __( 'Balloon Race', 'virtual-balloon-race' ),
				'post_name'      => 'balloon-race',
				'post_content'   => self::default_content(),
				'comment_status' => 'closed',
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
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
			$states['vbr_tracking'] = __( 'Balloon Race tracking page', 'virtual-balloon-race' );
		}
		return $states;
	}

	/**
	 * Save / create / regenerate from the Settings screen.
	 */
	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'virtual-balloon-race' ) );
		}

		check_admin_referer( 'vbr_pages' );

		$do     = isset( $_POST['vbr_do'] ) ? sanitize_key( wp_unslash( $_POST['vbr_do'] ) ) : 'save';
		$notice = 'saved';

		switch ( $do ) {
			case 'create':
				$notice = self::tracking_page_id() ? 'exists' : ( self::create_missing() ? 'created' : 'failed' );
				break;

			case 'regenerate':
				$notice = self::regenerate() ? 'regenerated' : 'failed';
				break;

			default:
				$page = isset( $_POST['vbr_tracking_page'] ) ? absint( $_POST['vbr_tracking_page'] ) : 0;
				update_option( self::OPTION, $page && 'page' === get_post_type( $page ) ? $page : 0 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => VBR_Settings::PAGE, 'vbr_pages' => $notice ), admin_url( 'admin.php' ) ) . '#vbr-pages' );
		exit;
	}

	/**
	 * Pages panel on the Settings screen.
	 */
	public function render_panel() {
		$id       = self::tracking_page_id();
		$notice   = isset( $_GET['vbr_pages'] ) ? sanitize_key( $_GET['vbr_pages'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'       => __( 'Tracking page saved.', 'virtual-balloon-race' ),
			'created'     => __( 'Tracking page created.', 'virtual-balloon-race' ),
			'exists'      => __( 'The tracking page already exists, so nothing was created.', 'virtual-balloon-race' ),
			'regenerated' => __( 'Tracking page content reset to the default.', 'virtual-balloon-race' ),
			'failed'      => __( 'The page could not be created.', 'virtual-balloon-race' ),
		);
		?>
		<section class="vbr-admin-panel" id="vbr-pages">
			<h2><?php esc_html_e( 'Pages', 'virtual-balloon-race' ); ?></h2>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice <?php echo 'failed' === $notice ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vbr_pages" />
				<?php wp_nonce_field( 'vbr_pages' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vbr_tracking_page"><?php esc_html_e( 'Race tracking page', 'virtual-balloon-race' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'vbr_tracking_page',
									'id'                => 'vbr_tracking_page',
									'selected'          => $id,
									'show_option_none'  => __( '— None —', 'virtual-balloon-race' ),
									'option_none_value' => 0,
									'post_status'       => array( 'publish', 'private', 'draft' ),
								)
							);
							?>
							<button type="submit" class="button" name="vbr_do" value="save"><?php esc_html_e( 'Save', 'virtual-balloon-race' ); ?></button>
							<?php if ( $id ) : ?>
								<a class="button-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'virtual-balloon-race' ); ?></a>
								&middot; <a class="button-link" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'virtual-balloon-race' ); ?></a>
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Shows the live map, leaderboard and a list of races. Add ?race=<slug> to the address for a particular race. Leaderboard names link here, and order emails use it for "Track your balloon" links.', 'virtual-balloon-race' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p>
					<?php if ( ! $id ) : ?>
						<button type="submit" class="button button-primary" name="vbr_do" value="create"><?php esc_html_e( 'Create tracking page', 'virtual-balloon-race' ); ?></button>
					<?php else : ?>
						<button type="submit" class="button" name="vbr_do" value="regenerate" onclick="return confirm('<?php echo esc_js( __( 'Replace the tracking page content with the default map, leaderboard and race list? Your edits to that page will be lost.', 'virtual-balloon-race' ) ); ?>');"><?php esc_html_e( 'Regenerate page content', 'virtual-balloon-race' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</section>
		<?php
	}
}
