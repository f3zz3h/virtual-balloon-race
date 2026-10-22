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
class VBR_Services {

	const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

	/**
	 * Map tile providers: label, URL template ({key} is replaced) and attribution.
	 *
	 * @return array<string, array{label: string, url: string, attribution: string, max_zoom: int}>
	 */
	public static function tile_providers() {
		return array(
			'osm'      => array(
				'label'       => __( 'OpenStreetMap (free, light use only)', 'virtual-balloon-race' ),
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => self::OSM_ATTRIBUTION,
				'max_zoom'    => 19,
			),
			'maptiler' => array(
				'label'       => __( 'MapTiler (needs an API key)', 'virtual-balloon-race' ),
				'url'         => 'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key={key}',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> ' . self::OSM_ATTRIBUTION,
				'max_zoom'    => 20,
			),
			'stadia'   => array(
				'label'       => __( 'Stadia Maps (needs an API key)', 'virtual-balloon-race' ),
				'url'         => 'https://tiles.stadiamaps.com/tiles/osm_bright/{z}/{x}/{y}{r}.png?api_key={key}',
				'attribution' => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> ' . self::OSM_ATTRIBUTION,
				'max_zoom'    => 20,
			),
			'custom'   => array(
				'label'       => __( 'Custom tile URL', 'virtual-balloon-race' ),
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
			'nominatim' => __( 'OpenStreetMap Nominatim (free, light use only)', 'virtual-balloon-race' ),
			'maptiler'  => __( 'MapTiler (uses the map API key)', 'virtual-balloon-race' ),
			'off'       => __( 'Off (pick launch points on the map or by coordinates)', 'virtual-balloon-race' ),
		);
	}

	/**
	 * The map tiles in use. Falls back to OpenStreetMap when a keyed provider has no key,
	 * or a custom URL isn't set, so maps never come up blank.
	 *
	 * @return array{provider: string, url: string, attribution: string, max_zoom: int}
	 */
	public static function tiles() {
		$providers = self::tile_providers();
		$provider  = (string) VBR_Settings::get( 'tile_provider' );
		$key       = trim( (string) VBR_Settings::get( 'map_api_key' ) );
		$tiles     = isset( $providers[ $provider ] ) ? $providers[ $provider ] : $providers['osm'];

		if ( 'custom' === $provider ) {
			$tiles['url']         = (string) VBR_Settings::get( 'tile_url' );
			$tiles['attribution'] = (string) VBR_Settings::get( 'tile_attribution' );
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
		$search = (string) VBR_Settings::get( 'search_provider' );

		if ( 'off' === $search ) {
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
			? __( 'Place search uses MapTiler.', 'virtual-balloon-race' )
			: __( 'Place search uses OpenStreetMap Nominatim.', 'virtual-balloon-race' );
	}

	/**
	 * Settings for the maps and place search in the browser (window.vbrServicesConfig).
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
				'key'      => 'maptiler' === $search ? (string) VBR_Settings::get( 'map_api_key' ) : '',
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
		$key = trim( (string) VBR_Settings::get( 'weather_api_key' ) );

		if ( '' === $key ) {
			return array(
				'url'  => VBR_Weather::API,
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
