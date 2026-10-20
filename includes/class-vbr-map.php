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
				'args'                => array(
					'race' => array(
						'description'       => __( 'Race ID, slug or "current".', 'virtual-balloon-race' ),
						'type'              => 'string',
						'default'           => 'current',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/races',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_races' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /vbr/v1/races — all races with their stage.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_races() {
		$current  = VBR_Races::current();
		$response = rest_ensure_response(
			array(
				'current' => $current ? (int) $current->id : null,
				'races'   => array_map( array( 'VBR_Races', 'to_public' ), VBR_Races::all() ),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'vbr race list' );

		return $response;
	}

	/**
	 * GET /vbr/v1/balloons?race=… — public fields only (no emails or order data).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_balloons( $request ) {
		$race = VBR_Races::resolve( $request->get_param( 'race' ) );

		if ( ! $race && 'current' !== $request->get_param( 'race' ) ) {
			return new WP_Error( 'vbr_race_not_found', __( 'Race not found.', 'virtual-balloon-race' ), array( 'status' => 404 ) );
		}

		$response = rest_ensure_response( self::public_payload( $race ) );

		// Positions change every tick: keep page/edge caches from serving stale data.
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'vbr live balloon positions' );

		return $response;
	}

	/**
	 * Public data for one race.
	 *
	 * @param object|null $race Race (null → no balloons).
	 * @return array
	 */
	public static function public_payload( $race ) {
		global $wpdb;

		$table = VBR_Balloons::table();
		$rows  = $race ? $wpdb->get_results( $wpdb->prepare( "SELECT id, owner_name, balloon_name, balloon_color, helium_level, distance_km, is_burst, latitude, longitude, start_lat, start_lng, logo_attachment_id, trail_json FROM {$table} WHERE race_id = %d ORDER BY distance_km DESC, id ASC", (int) $race->id ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hex   = self::color_hex();
		$last  = $race ? VBR_Cron::last_summary( $race->id ) : array();
		$next  = $race && VBR_Races::FLYING === VBR_Races::status( $race ) ? VBR_Cron::next_run() : 0;

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
				'owner'    => VBR_Balloons::public_owner_name( $row ),
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
			'race'       => VBR_Races::to_public( $race ),
			'launch'     => VBR_Races::launch_point( $race ),
			'updated_at' => ! empty( $last['time'] ) ? gmdate( 'c', (int) $last['time'] ) : null,
			'next_at'    => $next ? gmdate( 'c', $next ) : null,
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
		$ref  = VBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'height' => '520px',
				'list'   => 'yes',
				'race'   => 'current',
			),
			$atts,
			'balloon_map'
		);

		$atts['race'] = $ref;

		$height = preg_match( '/^\d+(\.\d+)?(px|vh|rem|em|%)$/', (string) $atts['height'] ) ? $atts['height'] : '520px';

		VBR_Settings::register_leaflet();
		wp_enqueue_style( 'vbr-leaflet' );
		wp_enqueue_style( 'vbr-frontend', VBR_PLUGIN_URL . 'assets/css/frontend.css', array(), VBR_Balloons::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'vbr-map' );

		$race = VBR_Races::resolve( $atts['race'] );

		if ( ! $race && 'current' !== $atts['race'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Balloon map: race not found.', 'virtual-balloon-race' ) . '</p>' : '';
		}

		$config = array(
			'endpoint' => esc_url_raw( add_query_arg( 'race', $race ? (int) $race->id : 'current', rest_url( self::REST_NS . '/balloons' ) ) ),
			'zoom'     => (int) VBR_Settings::get( 'map_zoom' ),
			'refresh'  => (int) VBR_Settings::get( 'map_refresh' ),
			'initial'  => self::public_payload( $race ),
			// Page caches normally skip logged-in visitors, so the viewer's own balloons can go in the HTML.
			'mine'     => $race && is_user_logged_in() ? array_map( 'intval', wp_list_pluck( VBR_Balloons::owned_by( get_current_user_id(), (int) $race->id ), 'id' ) ) : array(),
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
				'stage'    => array(
					/* translators: %s: date */
					'upcoming' => __( 'Entries open %s', 'virtual-balloon-race' ),
					/* translators: %s: date */
					'on_sale'  => __( 'Taking entries · launches %s', 'virtual-balloon-race' ),
					'flying'   => __( 'Flying now', 'virtual-balloon-race' ),
					/* translators: %s: date */
					'finished' => __( 'Finished %s', 'virtual-balloon-race' ),
				),
				'waiting'  => __( 'Waiting for launch', 'virtual-balloon-race' ),
				'noRace'   => __( 'No race scheduled yet.', 'virtual-balloon-race' ),
				'km'       => __( 'km', 'virtual-balloon-race' ),
				'mine'     => __( 'My balloons', 'virtual-balloon-race' ),
				'all'      => __( 'All balloons', 'virtual-balloon-race' ),
				'yours'    => __( 'Yours', 'virtual-balloon-race' ),
			),
		);

		$id = 'vbr-map-' . wp_unique_id();

		ob_start();
		?>
		<div class="vbr-map<?php echo 'no' === $atts['list'] ? ' vbr-map--no-list' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" data-vbr-map>
			<div class="vbr-map__canvas" style="height: <?php echo esc_attr( $height ); ?>"></div>
			<?php if ( 'no' !== $atts['list'] ) : ?>
				<aside class="vbr-map__side" style="max-height: <?php echo esc_attr( $height ); ?>">
					<?php if ( $config['mine'] ) : ?>
						<div class="vbr-map__filter vbr-toggle" role="group" aria-label="<?php esc_attr_e( 'Show', 'virtual-balloon-race' ); ?>">
							<button type="button" class="vbr-toggle__btn" data-vbr-show="all" aria-pressed="true"><?php echo esc_html( $config['i18n']['all'] ); ?></button>
							<button type="button" class="vbr-toggle__btn" data-vbr-show="mine" aria-pressed="false"><?php echo esc_html( $config['i18n']['mine'] ); ?></button>
						</div>
					<?php endif; ?>
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
