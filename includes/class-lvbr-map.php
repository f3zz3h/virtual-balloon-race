<?php
/**
 * Public REST endpoint and the [lvbr_map] shortcode.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Live Leaflet map of the race.
 */
class LVBR_Map {

	const REST_NS = 'lvbr/v1';

	/**
	 * Big races: trails are sent only for the leaders and the balloons asked for; others load on demand.
	 */
	const TOP_TRAILS = 25;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'lvbr_map', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Hex colours for balloon colour names.
	 *
	 * @return array
	 */
	public static function color_hex() {
		return apply_filters(
			'lvbr_color_hex',
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
					'race'   => array(
						'description'       => __( 'Race ID, slug or "current".', 'lumaha-virtual-balloon-race' ),
						'type'              => 'string',
						'default'           => 'current',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'trails' => array(
						'description'       => __( 'Big races: balloon IDs (comma-separated, up to 50) whose trails to include besides the leaders\'.', 'lumaha-virtual-balloon-race' ),
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/trail',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_trail' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'race' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/leaderboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( 'LVBR_Frontend', 'rest_leaderboard' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'race'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 100,
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mine'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'map_url'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'esc_url_raw',
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
	 * GET /lvbr/v1/races — all races with their stage.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_races() {
		$current  = LVBR_Races::current();
		$response = rest_ensure_response(
			array(
				'current' => $current ? (int) $current->id : null,
				'races'   => array_map( array( 'LVBR_Races', 'to_public' ), LVBR_Races::all() ),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'lvbr race list' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.

		return $response;
	}

	/**
	 * GET /lvbr/v1/balloons?race=… — public fields only (no emails or order data).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_balloons( $request ) {
		$race = LVBR_Races::resolve( $request->get_param( 'race' ) );

		if ( ! $race && 'current' !== $request->get_param( 'race' ) ) {
			return new WP_Error( 'lvbr_race_not_found', __( 'Race not found.', 'lumaha-virtual-balloon-race' ), array( 'status' => 404 ) );
		}

		$trails = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'trails' ) ) ) ), 0, 50 );
		$data   = self::public_payload( $race, $trails );

		// Positions only change on each tick, but maps refresh every minute: answer "not modified" until they do.
		$etag     = '"' . md5( (string) wp_json_encode( $data ) ) . '"';
		$match    = (string) $request->get_header( 'if_none_match' );
		$response = in_array( $etag, array_map( 'trim', explode( ',', $match ) ), true ) ? new WP_REST_Response( null, 304 ) : rest_ensure_response( $data );

		$response->header( 'ETag', $etag );
		// Positions change every tick: keep page/edge caches from serving stale data (browsers revalidate with the ETag).
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'lvbr live balloon positions' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.

		return $response;
	}

	/**
	 * GET /lvbr/v1/trail?race=…&id=… — one balloon's trail, for big races where the map loads trails on demand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_trail( $request ) {
		global $wpdb;

		$race  = LVBR_Races::resolve( $request->get_param( 'race' ) );
		$table = LVBR_Balloons::table();
		$row   = $race ? $wpdb->get_row( $wpdb->prepare( "SELECT id, trail_json FROM {$table} WHERE id = %d AND race_id = %d AND withdrawn_at IS NULL", (int) $request->get_param( 'id' ), (int) $race->id ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.

		if ( ! $row ) {
			return new WP_Error( 'lvbr_balloon_not_found', __( 'Balloon not found.', 'lumaha-virtual-balloon-race' ), array( 'status' => 404 ) );
		}

		$response = rest_ensure_response(
			array(
				'id'    => (int) $row->id,
				'trail' => LVBR_Balloons::decode_trail( $row->trail_json ),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		do_action( 'litespeed_control_set_nocache', 'lvbr balloon trail' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's hook.

		return $response;
	}

	/**
	 * Up to how many balloons every trail is sent (filter `lvbr_map_all_trails_up_to`, default 300).
	 *
	 * @return int
	 */
	public static function all_trails_up_to() {
		return max( 0, (int) apply_filters( 'lvbr_map_all_trails_up_to', 300 ) );
	}

	/**
	 * Public data for one race.
	 *
	 * @param object|null $race   Race (null → no balloons).
	 * @param int[]       $trails Big races: balloon IDs whose trails to include besides the leaders'.
	 * @return array
	 */
	public static function public_payload( $race, $trails = array() ) {
		global $wpdb;

		$table = LVBR_Balloons::table();
		$order = LVBR_Races::ranking_column( $race );
		$rows  = $race ? $wpdb->get_results( $wpdb->prepare( "SELECT id, team_id, owner_name, balloon_name, balloon_color, helium_level, distance_km, from_start_km, is_burst, latitude, longitude, start_lat, start_lng, logo_attachment_id, trail_json FROM {$table} WHERE race_id = %d AND withdrawn_at IS NULL ORDER BY {$order} DESC, id ASC", (int) $race->id ) ) : array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$hex   = self::color_hex();
		$last  = $race ? LVBR_Cron::last_summary( $race->id ) : array();
		$next  = $race && LVBR_Races::FLYING === LVBR_Races::status( $race ) ? LVBR_Cron::next_for_race( $race ) : 0;

		$balloons  = array();
		$rank      = 0;
		$teams     = LVBR_Teams::by_id( $race );
		$all_trail = count( (array) $rows ) <= self::all_trails_up_to();
		$wanted    = array_flip( array_map( 'intval', $trails ) );

		foreach ( (array) $rows as $row ) {
			if ( null === $row->latitude || null === $row->longitude ) {
				continue;
			}

			$logo = '';
			$fit  = 'cover';
			if ( (int) $row->logo_attachment_id ) {
				$src  = wp_get_attachment_image_src( (int) $row->logo_attachment_id, 'medium' );
				$logo = $src ? $src[0] : '';
				// Pictures much wider or taller than the balloon are shown whole rather than cropped.
				$ratio = $src && $src[2] ? $src[1] / $src[2] : 1;
				$fit   = $ratio > 1.6 || $ratio < 0.6 ? 'contain' : 'cover';
			}

			$balloon_rank = $row->is_burst ? null : ++$rank;
			$balloon      = array(
				'id'         => (int) $row->id,
				'rank'       => $balloon_rank,
				'name'       => $row->balloon_name,
				'owner'      => LVBR_Balloons::public_owner_name( $row ),
				'color'      => $row->balloon_color,
				'hex'        => isset( $hex[ $row->balloon_color ] ) ? $hex[ $row->balloon_color ] : '#555555',
				'helium'     => $row->helium_level,
				// `distance` is what the race ranks by; both measures are also given.
				'distance'   => round( LVBR_Balloons::ranked_distance( $row, $race ), 2 ),
				'from_start' => round( (float) $row->from_start_km, 2 ),
				'travelled'  => round( (float) $row->distance_km, 2 ),
				'burst'      => (bool) $row->is_burst,
				'lat'        => round( (float) $row->latitude, 5 ),
				'lng'        => round( (float) $row->longitude, 5 ),
				'logo'       => $logo,
				'logo_fit'   => $fit,
				'team'       => isset( $teams[ (int) $row->team_id ] ) ? (int) $row->team_id : null,
			);

			// Big races: trails for the leaders and the balloons asked for (the viewer's, the selected one); the map loads others on demand.
			if ( $all_trail || ( $balloon_rank && $balloon_rank <= self::TOP_TRAILS ) || isset( $wanted[ (int) $row->id ] ) ) {
				$balloon['trail'] = LVBR_Balloons::decode_trail( $row->trail_json );
			}

			$balloons[] = $balloon;
		}

		$launch = LVBR_Races::launch_point( $race );
		$flag   = $race ? LVBR_Sponsors::holder( (int) $race->id, 'launch_flag' ) : null;

		if ( $flag ) {
			$launch['sponsor'] = array(
				'id'   => (int) $flag->id,
				'name' => $flag->name,
				'url'  => $flag->url,
			);
		}

		return array(
			'race'       => LVBR_Races::to_public( $race ),
			'launch'     => $launch,
			'sponsors'   => $race ? LVBR_Sponsors::hot_air_payload( $race ) : array(),
			'updated_at' => ! empty( $last['time'] ) ? gmdate( 'c', (int) $last['time'] ) : null,
			'next_at'    => $next ? gmdate( 'c', $next ) : null,
			'ranking'    => LVBR_Races::ranking( $race ),
			// "all": every balloon has its trail; "some": a balloon without one has it loaded on demand.
			'trails'     => $all_trail ? 'all' : 'some',
			'balloons'   => $balloons,
			'teams'      => $teams ? LVBR_Teams::standings( $race ) : array(),
			'scoring'    => $teams ? LVBR_Teams::scoring( $race ) : null,
		);
	}

	/**
	 * Register map assets.
	 */
	public function register_assets() {
		LVBR_Settings::register_leaflet();
		self::register_balloon_script();

		wp_register_script( 'lvbr-map', LVBR_PLUGIN_URL . 'assets/js/map.js', array( 'lvbr-leaflet', 'lvbr-balloon' ), LVBR_Balloons::asset_version( 'assets/js/map.js' ), true );
	}

	/**
	 * Register the balloon drawing script shared by the map and the product page.
	 */
	public static function register_balloon_script() {
		if ( ! wp_script_is( 'lvbr-balloon', 'registered' ) ) {
			wp_register_script( 'lvbr-balloon', LVBR_PLUGIN_URL . 'assets/js/balloon.js', array(), LVBR_Balloons::asset_version( 'assets/js/balloon.js' ), true );
		}
	}

	/**
	 * Render [lvbr_map height="520px"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$ref  = LVBR_Races::ref_from_shortcode( $atts );
		$atts = shortcode_atts(
			array(
				'height' => '520px',
				'list'   => 'yes',
				'race'   => 'current',
			),
			$atts,
			'lvbr_map'
		);

		$atts['race'] = $ref;

		$height = preg_match( '/^\d+(\.\d+)?(px|vh|rem|em|%)$/', (string) $atts['height'] ) ? $atts['height'] : '520px';

		LVBR_Settings::register_leaflet();
		wp_enqueue_style( 'lvbr-leaflet' );
		wp_enqueue_style( 'lvbr-frontend', LVBR_PLUGIN_URL . 'assets/css/frontend.css', array(), LVBR_Balloons::asset_version( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'lvbr-map' );

		$race = LVBR_Races::resolve( $atts['race'] );

		if ( ! $race && 'current' !== $atts['race'] ) {
			return current_user_can( 'manage_options' ) ? '<p>' . esc_html__( 'Balloon map: race not found.', 'lumaha-virtual-balloon-race' ) . '</p>' : '';
		}

		$mine   = $race && is_user_logged_in() ? array_map( 'intval', wp_list_pluck( LVBR_Balloons::owned_by( get_current_user_id(), (int) $race->id ), 'id' ) ) : array();
		$config = array(
			'endpoint' => esc_url_raw( add_query_arg( 'race', $race ? (int) $race->id : 'current', rest_url( self::REST_NS . '/balloons' ) ) ),
			'zoom'     => (int) LVBR_Settings::get( 'map_zoom' ),
			'refresh'  => (int) LVBR_Settings::get( 'map_refresh' ),
			// Page caches normally skip logged-in visitors, so the viewer's own balloons can go in the HTML.
			'mine'     => $mine,
			'initial'  => self::public_payload( $race, $mine ),
			'trailUrl' => esc_url_raw( add_query_arg( 'race', $race ? (int) $race->id : 'current', rest_url( self::REST_NS . '/trail' ) ) ),
			'i18n' => array(
				'owner'    => __( 'Owner', 'lumaha-virtual-balloon-race' ),
				'distance'  => __( 'Distance', 'lumaha-virtual-balloon-race' ),
				'fromStart' => __( 'From launch', 'lumaha-virtual-balloon-race' ),
				'travelled' => __( 'Travelled', 'lumaha-virtual-balloon-race' ),
				'status'   => __( 'Status', 'lumaha-virtual-balloon-race' ),
				'active'   => __( 'Flying', 'lumaha-virtual-balloon-race' ),
				'burst'    => __( 'Burst', 'lumaha-virtual-balloon-race' ),
				'launch'   => __( 'Launch point', 'lumaha-virtual-balloon-race' ),
				'search'   => __( 'Find a balloon…', 'lumaha-virtual-balloon-race' ),
				'updated'  => __( 'Last update', 'lumaha-virtual-balloon-race' ),
				'empty'    => __( 'No balloons in the sky yet.', 'lumaha-virtual-balloon-race' ),
				'stage'    => array(
					/* translators: %s: date */
					'upcoming' => __( 'Entries open %s', 'lumaha-virtual-balloon-race' ),
					/* translators: %s: date */
					'on_sale'  => __( 'Taking entries · launches %s', 'lumaha-virtual-balloon-race' ),
					'flying'   => __( 'Flying now', 'lumaha-virtual-balloon-race' ),
					/* translators: %s: date */
					'finished' => __( 'Finished %s', 'lumaha-virtual-balloon-race' ),
				),
				'waiting'  => __( 'Waiting for launch', 'lumaha-virtual-balloon-race' ),
				'noRace'   => __( 'No race scheduled yet.', 'lumaha-virtual-balloon-race' ),
				'km'       => __( 'km', 'lumaha-virtual-balloon-race' ),
				'mine'     => __( 'My balloons', 'lumaha-virtual-balloon-race' ),
				'all'      => __( 'All balloons', 'lumaha-virtual-balloon-race' ),
				'yours'    => __( 'Yours', 'lumaha-virtual-balloon-race' ),
				'team'     => __( 'Team', 'lumaha-virtual-balloon-race' ),
				'sponsor'     => __( 'Sponsor', 'lumaha-virtual-balloon-race' ),
				'sponsorNote' => __( 'Sponsor balloon · flying with the race, not racing', 'lumaha-virtual-balloon-race' ),
				'visit'       => __( 'Visit their website', 'lumaha-virtual-balloon-race' ),
			),
		);

		$id = 'lvbr-map-' . wp_unique_id();

		ob_start();
		?>
		<div class="lvbr-map<?php echo 'no' === $atts['list'] ? ' lvbr-map--no-list' : ''; ?>" id="<?php echo esc_attr( $id ); ?>" data-lvbr-map data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php $presented = LVBR_Sponsors::presented_by( $race ); ?>
			<?php if ( $presented ) : ?>
				<p class="lvbr-map__presented"><strong><?php echo esc_html( $race->name ); ?></strong> <?php echo wp_kses_post( $presented ); ?></p>
			<?php endif; ?>
			<div class="lvbr-map__stage">
				<div class="lvbr-map__canvas" style="height: <?php echo esc_attr( $height ); ?>"></div>
				<?php echo wp_kses_post( LVBR_Sponsors::credit( $race, 'map_badge' ) ); ?>
			</div>
			<?php if ( 'no' !== $atts['list'] ) : ?>
				<aside class="lvbr-map__side" style="max-height: <?php echo esc_attr( $height ); ?>">
					<?php if ( $config['mine'] ) : ?>
						<div class="lvbr-map__filter lvbr-toggle" role="group" aria-label="<?php esc_attr_e( 'Show', 'lumaha-virtual-balloon-race' ); ?>">
							<button type="button" class="lvbr-toggle__btn" data-lvbr-show="all" aria-pressed="true"><?php echo esc_html( $config['i18n']['all'] ); ?></button>
							<button type="button" class="lvbr-toggle__btn" data-lvbr-show="mine" aria-pressed="false"><?php echo esc_html( $config['i18n']['mine'] ); ?></button>
						</div>
					<?php endif; ?>
					<?php if ( $config['initial']['teams'] ) : ?>
						<label class="lvbr-map__team-filter">
							<span class="screen-reader-text"><?php esc_html_e( 'Show team', 'lumaha-virtual-balloon-race' ); ?></span>
							<select class="lvbr-map__team">
								<option value=""><?php esc_html_e( 'All teams', 'lumaha-virtual-balloon-race' ); ?></option>
								<?php foreach ( $config['initial']['teams'] as $team ) : ?>
									<option value="<?php echo esc_attr( (string) $team['id'] ); ?>"><?php echo esc_html( $team['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<input type="search" class="lvbr-map__search" placeholder="<?php echo esc_attr( $config['i18n']['search'] ); ?>" aria-label="<?php echo esc_attr( $config['i18n']['search'] ); ?>" />
					<ol class="lvbr-map__list"></ol>
				</aside>
			<?php endif; ?>
			<p class="lvbr-map__meta" aria-live="polite"></p>
			<?php if ( ! LVBR_Services::allowed() && current_user_can( 'manage_options' ) ) : ?>
				<p class="lvbr-map__note"><?php esc_html_e( 'Only you see this: the background map and live wind are off until you allow external services in Balloon Race → Settings.', 'lumaha-virtual-balloon-race' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
