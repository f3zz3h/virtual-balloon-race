<?php
/**
 * WooCommerce My Account → My balloons.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists the logged-in customer's balloons across every race.
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

		$balloons = VBR_Balloons::owned_by( get_current_user_id() );

		if ( ! $balloons ) {
			echo '<p>' . esc_html__( 'You haven\'t entered a balloon yet.', 'virtual-balloon-race' ) . '</p>';
			return;
		}

		$hex = VBR_Map::color_hex();
		?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive vbr-account-balloons">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Balloon', 'virtual-balloon-race' ); ?></th>
					<th><?php esc_html_e( 'Race', 'virtual-balloon-race' ); ?></th>
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

					if ( (int) $balloon->is_burst ) {
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
						<td data-title="<?php esc_attr_e( 'Position', 'virtual-balloon-race' ); ?>"><?php echo esc_html( $position ); ?></td>
						<td data-title="<?php esc_attr_e( 'Distance', 'virtual-balloon-race' ); ?>">
							<?php
							/* translators: %s: distance in km */
							echo esc_html( sprintf( __( '%s km', 'virtual-balloon-race' ), number_format_i18n( (float) $balloon->distance_km, 1 ) ) );
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
}
