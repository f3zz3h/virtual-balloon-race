<?php
/**
 * Public REST endpoint and the [balloon_map] shortcode.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Live Leaflet map of the race.
 */
class VBR_Map {

	const REST_NS = 'vbr/v1';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'balloon_map', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Hex colours for balloon colour names.
	 *
	 * @return array
	 */
	public static function color_hex() {
		return apply_filters(
			'vbr_color_hex',
			array(
				'Red'    => '#d64545',
				'Blue'   => '#2b6cb0',
				'Green'  => '#2f855a',
				'Yellow' => '#d4a800',
				'Purple' => '#6b46c1',
			)
		);
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/balloons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_balloons' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /vbr/v1/balloons — public fields only (no emails or order data).
	 *
	 * @return WP_REST_Response
	 */
	public function rest_balloons() {
		$response = rest_ensure_response( self::public_payload() );

		// Positions change every tick: keep page/edge caches from serving stale data.
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'vbr live balloon positions' );

		return $response;
	}

	/**
	 * Public race data.
	 *
	 * @return array
	 */
	public static function public_payload() {
		global $wpdb;

		$table = VBR_Balloons::table();
		$rows  = $wpdb->get_results( "SELECT id, owner_name, balloon_name, balloon_color, helium_level, distance_km, is_burst, latitude, longitude, start_lat, start_lng, logo_attachment_id, trail_json FROM {$table} ORDER BY distance_km DESC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hex   = self::color_hex();
		$last  = get_option( 'vbr_last_tick_summary', array() );

		$balloons = array();
		$rank     = 0;

		foreach ( (array) $rows as $row ) {
			if ( null === $row->latitude || null === $row->longitude ) {
				continue;
			}

			$logo = '';
			if ( (int) $row->logo_attachment_id ) {
				$src  = wp_get_attachment_image_src( (int) $row->logo_attachment_id, 'thumbnail' );
				$logo = $src ? $src[0] : '';
			}

			$balloons[] = array(
				'id'       => (int) $row->id,
				'rank'     => $row->is_burst ? null : ++$rank,
				'name'     => $row->balloon_name,
				'owner'    => apply_filters( 'vbr_public_owner_name', $row->owner_name, $row ),
				'color'    => $row->balloon_color,
				'hex'      => isset( $hex[ $row->balloon_color ] ) ? $hex[ $row->balloon_color ] : '#555555',
				'helium'   => $row->helium_level,
				'distance' => round( (float) $row->distance_km, 2 ),
				'burst'    => (bool) $row->is_burst,
				'lat'      => (float) $row->latitude,
				'lng'      => (float) $row->longitude,
				'logo'     => $logo,
				'trail'    => VBR_Balloons::decode_trail( $row->trail_json ),
			);
		}

		return array(
			'launch'     => VBR_Balloons::launch_point(),
			'updated_at' => ! empty( $last['time'] ) ? gmdate( 'c', (int) $last['time'] ) : null,
			'next_at'    => VBR_Cron::next_run() ? gmdate( 'c', VBR_Cron::next_run() ) : null,
			'balloons'   => $balloons,
		);
	}

	/**
	 * Register map assets.
	 */
	public function register_assets() {
		VBR_Settings::register_leaflet();

		wp_register_script( 'vbr-map', VBR_PLUGIN_URL . 'assets/js/map.js', array( 'vbr-leaflet' ), VBR_Balloons::asset_version( 'assets/js/map.js' ), true );
	}

	/**
	 * Render [balloon_map height="520px"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'height' => '520px',
				'list'   => 'yes',
			),
			$atts,
			'balloon_map'
		);

		$height = preg_match( '/^\d+(\.\d+)?(px|vh|rem|em|%)$/', (string) $atts['height'] ) ? $atts['height'] : '520px';

		VBR_Settings::register_leaflet();
		wp_enqueue_style( 'vbr-leaflet' );
		wp_enqueue_style( 'vbr-frontend', VBR_PLUGIN_URL . 'assets/css/frontend.css', array(), VBR_Balloons::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'vbr-map' );

		$config = array(
			'endpoint' => esc_url_raw( rest_url( self::REST_NS . '/balloons' ) ),
			'zoom'     => (int) VBR_Settings::get( 'map_zoom' ),
			'refresh'  => (int) VBR_Settings::get( 'map_refresh' ),
			'initial'  => self::public_payload(),
			'i18n'     => array(
				'owner'    => __( 'Owner', 'virtual-balloon-race' ),
				'distance' => __( 'Distance', 'virtual-balloon-race' ),
				'status'   => __( 'Status', 'virtual-balloon-race' ),
				'active'   => __( 'Flying', 'virtual-balloon-race' ),
				'burst'    => __( 'Burst', 'virtual-balloon-race' ),
				'launch'   => __( 'Launch point', 'virtual-balloon-race' ),
				'search'   => __( 'Find a balloon…', 'virtual-balloon-race' ),
				'updated'  => __( 'Last update', 'virtual-balloon-race' ),
				'empty'    => __( 'No balloons in the sky yet.', 'virtual-balloon-race' ),
				'km'       => __( 'km', 'virtual-balloon-race' ),
			),
		);

		$id = 'vbr-map-' . wp_unique_id();

		ob_start();
		?>
		<div class="vbr-map<?php echo 'no' === $atts['list'] ? ' vbr-map--no-list' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" data-vbr-map>
			<div class="vbr-map__canvas" style="height: <?php echo esc_attr( $height ); ?>"></div>
			<?php if ( 'no' !== $atts['list'] ) : ?>
				<aside class="vbr-map__side" style="max-height: <?php echo esc_attr( $height ); ?>">
					<input type="search" class="vbr-map__search" placeholder="<?php echo esc_attr( $config['i18n']['search'] ); ?>" aria-label="<?php echo esc_attr( $config['i18n']['search'] ); ?>" />
					<ol class="vbr-map__list"></ol>
				</aside>
			<?php endif; ?>
			<p class="vbr-map__meta" aria-live="polite"></p>
			<script type="application/json" class="vbr-map__config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
		</div>
		<?php
		return ob_get_clean();
	}
}
