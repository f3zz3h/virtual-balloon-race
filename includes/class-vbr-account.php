<?php
/**
 * WooCommerce customer pages: My Account → My balloons, and Track your balloon links on orders.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows customers their balloons: in My Account, on order pages and in order emails.
 */
class VBR_Account {

	const ENDPOINT = 'my-balloons';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );

		// Order received / view order pages, and customer emails (guests have no account to look in).
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_links' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_links' ), 10, 3 );
	}

	/**
	 * Register the endpoint with WooCommerce (it adds the rewrite rule).
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Add "My balloons" after Orders.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public function menu_item( $items ) {
		$out = array();

		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = $this->title();
			}
		}

		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = $this->title();
		}

		return $out;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'My balloons', 'virtual-balloon-race' );
	}

	/**
	 * Endpoint content.
	 */
	public function render() {
		wp_enqueue_style( 'vbr-frontend' );

		$balloons = VBR_Balloons::owned_by( get_current_user_id(), 0, true );

		if ( ! $balloons ) {
			echo '<p>' . esc_html__( 'You haven\'t entered a balloon yet.', 'virtual-balloon-race' ) . '</p>';
			return;
		}

		$hex      = VBR_Map::color_hex();
		$teams_on = VBR_Teams::enabled() && array_filter( wp_list_pluck( $balloons, 'team_id' ) );
		?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive vbr-account-balloons">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Balloon', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Race', 'virtual-balloon-race' ); ?></th>
					<?php if ( $teams_on ) : ?>
						<th><?php esc_html_e( 'Team', 'virtual-balloon-race' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Position', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Distance', 'virtual-balloon-race' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Track', 'virtual-balloon-race' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $balloons as $balloon ) : ?>
					<?php
					$race  = VBR_Races::get( (int) $balloon->race_id );
					$rank  = VBR_Balloons::rank( $balloon );
					$url   = VBR_Pages::tracking_url( $race, (int) $balloon->id );
					$color = isset( $hex[ $balloon->balloon_color ] ) ? $hex[ $balloon->balloon_color ] : '#555555';

					if ( null !== $balloon->withdrawn_at ) {
						$position = VBR_Balloons::withdrawn_label( $balloon->withdrawn_reason );
						$url      = '';
					} elseif ( (int) $balloon->is_burst ) {
						$position = __( 'Burst', 'virtual-balloon-race' );
					} elseif ( $race && in_array( VBR_Races::status( $race ), array( VBR_Races::UPCOMING, VBR_Races::ON_SALE ), true ) ) {
						$position = __( 'Waiting for launch', 'virtual-balloon-race' );
					} else {
						/* translators: %s: leaderboard position */
						$position = sprintf( __( '#%s', 'virtual-balloon-race' ), number_format_i18n( (int) $rank ) );
					}
					?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Balloon', 'virtual-balloon-race' ); ?>">
							<span class="vbr-color-dot" style="background: <?php echo esc_attr( $color ); ?>"></span><?php echo esc_html( $balloon->balloon_name ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Race', 'virtual-balloon-race' ); ?>">
							<?php echo esc_html( $race ? $race->name . ' · ' . VBR_Races::status_label( $race ) : '—' ); ?>
						</td>
						<?php if ( $teams_on ) : ?>
							<td data-title="<?php esc_attr_e( 'Team', 'virtual-balloon-race' ); ?>">
								<?php
								$team = VBR_Teams::get( (int) $balloon->team_id );
								if ( $team ) {
									VBR_Frontend::team_badge( $team->name, $team->color );
								}
								?>
							</td>
						<?php endif; ?>
						<td data-title="<?php esc_attr_e( 'Position', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $position ); ?></td>
						<td data-title="<?php esc_attr_e( 'Distance', 'virtual-balloon-race' ); ?>">
							<?php
							/* translators: %s: distance in km */
							echo esc_html( sprintf( __( '%s km', 'virtual-balloon-race' ), number_format_i18n( VBR_Balloons::ranked_distance( $balloon, $race ), 1 ) ) );
							?>
						</td>
						<td>
							<?php if ( $url ) : ?>
								<a class="woocommerce-button button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Track', 'virtual-balloon-race' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Track links for an order's balloons.
	 *
	 * @param WC_Order|mixed $order Order (hooks can pass anything).
	 * @return array[] Each `[ name, url ]`; empty when the order has no balloon entries or there's no tracking page.
	 */
	private static function order_balloon_links( $order ) {
		if ( ! $order instanceof WC_Order || ! VBR_Pages::tracking_page_id() ) {
			return array();
		}

		$links = array();

		foreach ( VBR_Balloons::for_order( $order->get_id() ) as $balloon ) {
			if ( null !== $balloon->withdrawn_at ) {
				continue;
			}
			$links[] = array( $balloon->balloon_name, VBR_Pages::tracking_url( VBR_Races::get( (int) $balloon->race_id ), (int) $balloon->id ) );
		}

		return $links;
	}

	/**
	 * Whether an order includes balloon entries (balloons only exist once it's paid).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function has_entries( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && VBR_WooCommerce::is_balloon_product( $item->get_product() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Order received / view order: Track your balloon.
	 *
	 * @param WC_Order|mixed $order Order (hooks can pass anything).
	 */
	public function order_links( $order ) {
		if ( ! $order instanceof WC_Order || ! VBR_Pages::tracking_page_id() || ! self::has_entries( $order ) ) {
			return;
		}

		$links = self::order_balloon_links( $order );
		?>
		<section class="vbr-order-balloons">
			<h2><?php echo esc_html( _n( 'Track your balloon', 'Track your balloons', max( 1, count( $links ) ), 'virtual-balloon-race' ) ); ?></h2>
			<?php if ( $links ) : ?>
				<ul>
					<?php foreach ( $links as $link ) : ?>
						<li><a href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Your balloon appears on the race map once payment is confirmed.', 'virtual-balloon-race' ); ?>
					<a href="<?php echo esc_url( VBR_Pages::tracking_url() ); ?>"><?php esc_html_e( 'See the race', 'virtual-balloon-race' ); ?></a>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Customer emails: Track your balloon links.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the shop admin.
	 * @param bool     $plain_text    Plain-text email.
	 */
	public function email_links( $order, $sent_to_admin, $plain_text ) {
		if ( $sent_to_admin ) {
			return;
		}

		$links = self::order_balloon_links( $order );

		if ( ! $links ) {
			return;
		}

		$title = _n( 'Track your balloon', 'Track your balloons', count( $links ), 'virtual-balloon-race' );

		if ( $plain_text ) {
			echo "\n" . esc_html( mb_strtoupper( $title ) ) . "\n\n";
			foreach ( $links as $link ) {
				echo esc_html( $link[0] ) . ': ' . esc_url_raw( $link[1] ) . "\n";
			}
			echo "\n";
			return;
		}

		echo '<h2>' . esc_html( $title ) . '</h2><ul>';
		foreach ( $links as $link ) {
			echo '<li><a href="' . esc_url( $link[1] ) . '">' . esc_html( $link[0] ) . '</a></li>';
		}
		echo '</ul>';
	}
}
