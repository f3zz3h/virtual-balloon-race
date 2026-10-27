<?php
/**
 * Outside services: map tiles, place search and the weather API, as chosen in Settings.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * The free services (OpenStreetMap tiles, Nominatim, Open-Meteo) suit light, non-commercial use.
 * Busier or commercial sites can switch to keyed providers here without code changes.
 */
class LVBR_Services {

	const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

	/**
	 * Map tile providers: label, URL template ({key} is replaced) and attribution.
	 *
	 * @return array<string, array{label: string, url: string, attribution: string, max_zoom: int}>
	 */
	public static function tile_providers() {
		return array(
			'osm'      => array(
				'label'       => __( 'OpenStreetMap (free, light use only)', 'lumaha-virtual-balloon-race' ),
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => self::OSM_ATTRIBUTION,
				'max_zoom'    => 19,
			),
			'maptiler' => array(
				'label'       => __( 'MapTiler (needs an API key)', 'lumaha-virtual-balloon-race' ),
				'url'         => 'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key={key}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> ' . self::OSM_ATTRIBUTION,
				'max_zoom'    => 20,
			),
			'stadia'   => array(
				'label'       => __( 'Stadia Maps (needs an API key)', 'lumaha-virtual-balloon-race' ),
				'url'         => 'https://tiles.stadiamaps.com/tiles/osm_bright/{z}/{x}/{y}{r}.png?api_key={key}',
				'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> ' . self::OSM_ATTRIBUTION,
				'max_zoom'    => 20,
			),
			'custom'   => array(
				'label'       => __( 'Custom tile URL', 'lumaha-virtual-balloon-race' ),
				'url'         => '',
				'attribution' => '',
				'max_zoom'    => 19,
			),
		);
	}

	/**
	 * Labels for the map tile setting.
	 *
	 * @return array<string, string>
	 */
	public static function tile_labels() {
		return wp_list_pluck( self::tile_providers(), 'label' );
	}

	/**
	 * Labels for the place search setting.
	 *
	 * @return array<string, string>
	 */
	public static function search_labels() {
		return array(
			'nominatim' => __( 'OpenStreetMap Nominatim (free, light use only)', 'lumaha-virtual-balloon-race' ),
			'maptiler'  => __( 'MapTiler (uses the map API key)', 'lumaha-virtual-balloon-race' ),
			'off'       => __( 'Off (pick launch points on the map or by coordinates)', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Whether the site owner has allowed outside services (map tiles, live wind, place search).
	 * Off on new sites until turned on in Settings or from the prompt on the plugin's screens.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return (bool) LVBR_Settings::get( 'external_services' );
	}

	/**
	 * Register the prompt and its handler.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'prompt' ) );
		add_action( 'admin_post_lvbr_allow_services', array( __CLASS__, 'allow' ) );
	}

	/**
	 * On the plugin's own screens only, ask once to allow outside services.
	 */
	public static function prompt() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( self::allowed() || ! current_user_can( 'manage_options' ) || ! $screen || false === strpos( (string) $screen->id, 'lvbr-' ) ) {
			return;
		}
		?>
		<div class="notice notice-info lvbr-services-prompt">
			<p><strong><?php esc_html_e( 'Allow external services for the race map?', 'lumaha-virtual-balloon-race' ); ?></strong></p>
			<p><?php esc_html_e( 'Balloon Race uses outside services only when you allow them: map tiles from OpenStreetMap (or the provider you choose in Settings), which visitors\' browsers load, so the provider sees their IP address; live wind from Open-Meteo, fetched by your site for balloon positions (coordinates only); and place search from OpenStreetMap Nominatim when you or a buyer search for a launch point. Until then, maps show balloons without a background map and balloons follow the default heading.', 'lumaha-virtual-balloon-race' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lvbr_allow_services" />
				<?php wp_nonce_field( 'lvbr_allow_services' ); ?>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Allow external services', 'lumaha-virtual-balloon-race' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LVBR_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'lumaha-virtual-balloon-race' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Turn outside services on from the prompt.
	 */
	public static function allow() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lvbr_allow_services' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'lumaha-virtual-balloon-race' ) );
		}

		update_option( LVBR_Settings::OPTION, array_merge( (array) get_option( LVBR_Settings::OPTION, array() ), array( 'external_services' => 1 ) ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . LVBR_Settings::PAGE ) );
		exit;
	}

	/**
	 * The map tiles in use. Falls back to OpenStreetMap when a keyed provider has no key,
	 * or a custom URL isn't set, so maps never come up blank.
	 *
	 * @return array{provider: string, url: string, attribution: string, max_zoom: int}
	 */
	public static function tiles() {
		if ( ! self::allowed() ) {
			return array(
				'provider'    => 'none',
				'url'         => '',
				'attribution' => '',
				'max_zoom'    => 19,
			);
		}

		$providers = self::tile_providers();
		$provider  = (string) LVBR_Settings::get( 'tile_provider' );
		$key       = trim( (string) LVBR_Settings::get( 'map_api_key' ) );
		$tiles     = isset( $providers[ $provider ] ) ? $providers[ $provider ] : $providers['osm'];

		if ( 'custom' === $provider ) {
			$tiles['url']         = (string) LVBR_Settings::get( 'tile_url' );
			$tiles['attribution'] = (string) LVBR_Settings::get( 'tile_attribution' );
		} elseif ( false !== strpos( $tiles['url'], '{key}' ) ) {
			$tiles['url'] = $key ? str_replace( '{key}', rawurlencode( $key ), $tiles['url'] ) : '';
		}

		if ( '' === $tiles['url'] ) {
			$provider = 'osm';
			$tiles    = $providers['osm'];
		}

		return array(
			'provider'    => $provider,
			'url'         => $tiles['url'],
			'attribution' => $tiles['attribution'],
			'max_zoom'    => (int) $tiles['max_zoom'],
		);
	}

	/**
	 * The place search in use: 'nominatim', 'maptiler' (only with MapTiler tiles and a key) or 'off'.
	 *
	 * @return string
	 */
	public static function search_provider() {
		$search = (string) LVBR_Settings::get( 'search_provider' );

		if ( 'off' === $search || ! self::allowed() ) {
			return 'off';
		}

		if ( 'maptiler' === $search && 'maptiler' === self::tiles()['provider'] ) {
			return 'maptiler';
		}

		return 'nominatim';
	}

	/**
	 * Whether launch points can be searched by place name.
	 *
	 * @return bool
	 */
	public static function search_enabled() {
		return 'off' !== self::search_provider();
	}

	/**
	 * Who answers place searches, for the note under the search box.
	 *
	 * @return string
	 */
	public static function search_credit() {
		return 'maptiler' === self::search_provider()
			? __( 'Place search uses MapTiler.', 'lumaha-virtual-balloon-race' )
			: __( 'Place search uses OpenStreetMap Nominatim.', 'lumaha-virtual-balloon-race' );
	}

	/**
	 * Settings for the maps and place search in the browser (window.lvbrServicesConfig).
	 * The map key is in it: every browser map key is public, so it should be restricted to the site's domain.
	 *
	 * @return array
	 */
	public static function browser_config() {
		$tiles  = self::tiles();
		$search = self::search_provider();

		return array(
			'tiles'  => array(
				'url'     => $tiles['url'],
				'options' => array(
					'maxZoom'     => $tiles['max_zoom'],
					'attribution' => $tiles['attribution'],
				),
			),
			'search' => array(
				'provider' => $search,
				'key'      => 'maptiler' === $search ? (string) LVBR_Settings::get( 'map_api_key' ) : '',
			),
		);
	}

	/**
	 * Open-Meteo forecast endpoint and any extra query arguments. With an API key (their commercial plan),
	 * requests go to the customer endpoint.
	 *
	 * @return array{url: string, args: array<string, string>}
	 */
	public static function weather_endpoint() {
		$key = trim( (string) LVBR_Settings::get( 'weather_api_key' ) );

		if ( '' === $key ) {
			return array(
				'url'  => LVBR_Weather::API,
				'args' => array(),
			);
		}

		return array(
			'url'  => 'https://customer-api.open-meteo.com/v1/forecast',
			'args' => array( 'apikey' => $key ),
		);
	}

	/**
	 * Sanitise a custom tile URL template: https only, with {z}, {x} and {y}.
	 *
	 * @param mixed $raw Raw input.
	 * @return string URL template, or '' if it isn't usable.
	 */
	public static function sanitize_tile_url( $raw ) {
		$url = trim( (string) $raw );

		foreach ( array( '{z}', '{x}', '{y}' ) as $placeholder ) {
			if ( false === strpos( $url, $placeholder ) ) {
				return '';
			}
		}

		// esc_url_raw() would encode the braces, so check the scheme and characters instead.
		return 0 === strpos( $url, 'https://' ) && ! preg_match( '/[\s"\'<>\\\\]/', $url ) ? $url : '';
	}

	/**
	 * Sanitise attribution text: plain text and links only.
	 *
	 * @param mixed $raw Raw input.
	 * @return string
	 */
	public static function sanitize_attribution( $raw ) {
		return trim( wp_kses( (string) $raw, array( 'a' => array( 'href' => array() ) ) ) );
	}
}
