<?php
/**
 * Race settings (Balloon Race → Settings).
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores all race settings in one `lvbr_settings` option.
 */
class LVBR_Settings {

	const OPTION = 'lvbr_settings';
	const PAGE   = 'lvbr-settings';

	/**
	 * Cached merged settings for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Auto-update interval choices: schedule slug => seconds (0 = off).
	 *
	 * @return array
	 */
	public static function intervals() {
		return array(
			'off'        => 0,
			'lvbr_5min'   => 5 * MINUTE_IN_SECONDS,
			'lvbr_30min'  => 30 * MINUTE_IN_SECONDS,
			'hourly'     => HOUR_IN_SECONDS,
			'lvbr_6hours' => 6 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);
	}

	/**
	 * Labels for interval choices.
	 *
	 * @return array
	 */
	public static function interval_labels() {
		return array(
			'off'        => __( 'Off (manual only)', 'lumaha-virtual-balloon-race' ),
			'lvbr_5min'   => __( 'Every 5 minutes', 'lumaha-virtual-balloon-race' ),
			'lvbr_30min'  => __( 'Every 30 minutes', 'lumaha-virtual-balloon-race' ),
			'hourly'     => __( 'Every hour', 'lumaha-virtual-balloon-race' ),
			'lvbr_6hours' => __( 'Every 6 hours', 'lumaha-virtual-balloon-race' ),
			'daily'      => __( 'Daily', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Field definitions: drive rendering, defaults and sanitisation.
	 *
	 * @return array
	 */
	public static function fields() {
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '£';

		return array(
			// Race movement.
			'flight_mode'       => array( 'section' => 'race', 'type' => 'modes', 'default' => 'soar', 'label' => __( 'Flight mode', 'lumaha-virtual-balloon-race' ), 'help' => __( 'How quickly balloons lose lift, and so how far a race goes. Each race can choose its own.', 'lumaha-virtual-balloon-race' ) ),
			'interval'          => array( 'section' => 'race', 'type' => 'select', 'default' => 'off', 'label' => __( 'Auto-update interval', 'lumaha-virtual-balloon-race' ), 'options' => self::interval_labels(), 'help' => __( 'How often balloons move. Scheduled updates run via WooCommerce Action Scheduler (or WP-Cron), which only fires when the site gets traffic. For accurate 5-minute updates, set up a real system cron. This and the settings below are defaults: each race can change them on its race screen, under Movement.', 'lumaha-virtual-balloon-race' ) ),
			'base_kmh'          => array( 'section' => 'race', 'type' => 'number', 'default' => 35, 'min' => 0, 'max' => 300, 'step' => 0.1, 'label' => __( 'Base speed (km/h)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Average drift speed. Distance per update = speed × hours since the last update × helium × wind × variance.', 'lumaha-virtual-balloon-race' ) ),
			'mult_eco'          => array( 'section' => 'race', 'type' => 'number', 'default' => 0.7, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Eco-Glide multiplier', 'lumaha-virtual-balloon-race' ) ),
			'mult_balanced'     => array( 'section' => 'race', 'type' => 'number', 'default' => 1.0, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Balanced multiplier', 'lumaha-virtual-balloon-race' ) ),
			'mult_max'          => array( 'section' => 'race', 'type' => 'number', 'default' => 1.35, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Max Height multiplier', 'lumaha-virtual-balloon-race' ) ),
			'variance_pct'      => array( 'section' => 'race', 'type' => 'number', 'default' => 15, 'min' => 0, 'max' => 90, 'step' => 1, 'label' => __( 'Random variance (±%)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Stops balloons moving in lock-step.', 'lumaha-virtual-balloon-race' ) ),

			// Launch and weather.
			'launch_label'      => array( 'section' => 'launch', 'type' => 'text', 'default' => 'Horfield, Bristol', 'label' => __( 'Launch name', 'lumaha-virtual-balloon-race' ) ),
			'launch_lat'        => array( 'section' => 'launch', 'type' => 'number', 'default' => 51.4885, 'min' => -90, 'max' => 90, 'step' => 0.0000001, 'label' => __( 'Launch latitude', 'lumaha-virtual-balloon-race' ) ),
			'launch_lng'        => array( 'section' => 'launch', 'type' => 'number', 'default' => -2.5800, 'min' => -180, 'max' => 180, 'step' => 0.0000001, 'label' => __( 'Launch longitude', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Used for new races. Search for a place above or click the map. Each race can have its own launch point.', 'lumaha-virtual-balloon-race' ) ),
			'weather_enabled'   => array( 'section' => 'launch', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Use live wind (Open-Meteo)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Free for non-commercial use, no key needed (see Maps, weather and place search for an API key). When off, balloons follow the default heading at base speed.', 'lumaha-virtual-balloon-race' ) ),
			'per_balloon_wind'  => array( 'section' => 'launch', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Per-balloon wind', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Fetch wind for each balloon\'s ~11 km area so paths diverge as they spread. Turn off to use launch-point wind only.', 'lumaha-virtual-balloon-race' ) ),
			'wind_weight'       => array( 'section' => 'launch', 'type' => 'number', 'default' => 0.6, 'min' => 0, 'max' => 1, 'step' => 0.05, 'label' => __( 'Wind influence (0–1)', 'lumaha-virtual-balloon-race' ), 'help' => __( '0 = wind only sets direction; 1 = wind speed fully drives distance.', 'lumaha-virtual-balloon-race' ) ),
			'default_heading'   => array( 'section' => 'launch', 'type' => 'number', 'default' => 70, 'min' => 0, 'max' => 359, 'step' => 1, 'label' => __( 'Default heading (°)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Direction of travel when no wind data is available. 0 = north, 90 = east.', 'lumaha-virtual-balloon-race' ) ),

			// Bursts.
			'burst_max_daily'   => array( 'section' => 'burst', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 100, 'step' => 0.1, 'label' => __( 'Max Height burst chance (% per day)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Scaled to the update interval, so frequent updates do not raise the overall risk.', 'lumaha-virtual-balloon-race' ) ),
			'storm_kmh'         => array( 'section' => 'burst', 'type' => 'number', 'default' => 45, 'min' => 0, 'max' => 300, 'step' => 1, 'label' => __( 'Storm wind threshold (km/h)', 'lumaha-virtual-balloon-race' ) ),
			'burst_storm_daily' => array( 'section' => 'burst', 'type' => 'number', 'default' => 10, 'min' => 0, 'max' => 100, 'step' => 0.1, 'label' => __( 'Storm burst chance (% per day)', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Applies to Balanced and Max Height balloons in storm winds. Eco-Glide is immune.', 'lumaha-virtual-balloon-race' ) ),

			// Map.
			'map_zoom'          => array( 'section' => 'map', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 18, 'step' => 1, 'label' => __( 'Default map zoom', 'lumaha-virtual-balloon-race' ) ),
			'map_refresh'       => array( 'section' => 'map', 'type' => 'number', 'default' => 60, 'min' => 15, 'max' => 3600, 'step' => 1, 'label' => __( 'Map refresh (seconds)', 'lumaha-virtual-balloon-race' ) ),
			'leaderboard_per_page' => array( 'section' => 'map', 'type' => 'select', 'default' => '10', 'label' => __( 'Leaderboard entries per page', 'lumaha-virtual-balloon-race' ), 'options' => array( '10' => '10', '25' => '25', '50' => '50', '100' => '100' ), 'help' => __( 'How many balloons each leaderboard page shows at first. Visitors can change it with the dropdown under the leaderboard; the admin leaderboard uses it too.', 'lumaha-virtual-balloon-race' ) ),
			'trail_cap'         => array( 'section' => 'map', 'type' => 'number', 'default' => 80, 'min' => 2, 'max' => 500, 'step' => 1, 'label' => __( 'Trail length (points)', 'lumaha-virtual-balloon-race' ) ),

			// Outside services.
			'external_services' => array( 'section' => 'services', 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Allow external services', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Off until you turn it on. When on, visitors\' browsers load map tiles from the provider below (which sees their IP address), the site fetches live wind from Open-Meteo for balloon positions, and launch-point searches go to the place search below. When off, maps show balloons without a background map, balloons follow the default heading, and place search is hidden.', 'lumaha-virtual-balloon-race' ) ),
			'tile_provider'     => array( 'section' => 'services', 'type' => 'select', 'default' => 'osm', 'label' => __( 'Map tiles', 'lumaha-virtual-balloon-race' ), 'options' => LVBR_Services::tile_labels(), 'help' => __( 'OpenStreetMap\'s own tile servers are free but only for light use. For a busy site, use a provider with an API key. Without a key (or a custom URL), maps fall back to OpenStreetMap.', 'lumaha-virtual-balloon-race' ) ),
			'map_api_key'       => array( 'section' => 'services', 'type' => 'text', 'default' => '', 'label' => __( 'Map API key', 'lumaha-virtual-balloon-race' ), 'help' => __( 'For MapTiler or Stadia Maps. Visitors\' browsers load the tiles with it, so anyone can see it: restrict it to your site\'s address in the provider\'s dashboard.', 'lumaha-virtual-balloon-race' ) ),
			'tile_url'          => array( 'section' => 'services', 'type' => 'tile_url', 'default' => '', 'label' => __( 'Custom tile URL', 'lumaha-virtual-balloon-race' ), 'help' => __( 'For "Custom tile URL": an https address with {z}, {x} and {y}, e.g. https://tile.example.com/{z}/{x}/{y}.png?key=…', 'lumaha-virtual-balloon-race' ) ),
			'tile_attribution'  => array( 'section' => 'services', 'type' => 'attribution', 'default' => '', 'label' => __( 'Custom tile attribution', 'lumaha-virtual-balloon-race' ), 'help' => __( 'The credit the tile provider requires, shown in the map corner. Text and links only.', 'lumaha-virtual-balloon-race' ) ),
			'search_provider'   => array( 'section' => 'services', 'type' => 'select', 'default' => 'nominatim', 'label' => __( 'Place search', 'lumaha-virtual-balloon-race' ), 'options' => LVBR_Services::search_labels(), 'help' => __( 'Used to find launch points by name, in Settings, on race screens and for buyers choosing a custom launch point. Searches run when the Search button is pressed, at most one a second. MapTiler search needs MapTiler map tiles.', 'lumaha-virtual-balloon-race' ) ),
			'weather_api_key'   => array( 'section' => 'services', 'type' => 'text', 'default' => '', 'label' => __( 'Open-Meteo API key', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Optional. Open-Meteo\'s free API is for non-commercial use; for commercial use, get a key from open-meteo.com. With a key, wind requests go to its customer API. Kept on the server, never sent to browsers.', 'lumaha-virtual-balloon-race' ) ),

			// Shop add-ons.
			/* translators: %s: currency symbol */
			'price_strategy'    => array( 'section' => 'shop', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Tactical helium strategy (%s)', 'lumaha-virtual-balloon-race' ), $currency ), 'help' => __( 'Added when a buyer picks Max Height or Eco-Glide. Balanced is always free.', 'lumaha-virtual-balloon-race' ) ),
			'enable_launch'     => array( 'section' => 'shop', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Offer custom launch point', 'lumaha-virtual-balloon-race' ) ),
			/* translators: %s: currency symbol */
			'price_launch'      => array( 'section' => 'shop', 'type' => 'number', 'default' => 2, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Custom launch point (%s)', 'lumaha-virtual-balloon-race' ), $currency ) ),
			'enable_logo'       => array( 'section' => 'shop', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Offer logo / image upload', 'lumaha-virtual-balloon-race' ) ),
			/* translators: %s: currency symbol */
			'price_logo'        => array( 'section' => 'shop', 'type' => 'number', 'default' => 2, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Logo upload (%s)', 'lumaha-virtual-balloon-race' ), $currency ) ),

			// Teams.
			'teams_enabled'     => array( 'section' => 'teams', 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Enable teams', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Buyers pick a team for each balloon, and a team leaderboard ranks the teams. When off, teams are hidden everywhere but kept.', 'lumaha-virtual-balloon-race' ) ),
			'team_scoring'      => array( 'section' => 'teams', 'type' => 'select', 'default' => 'average', 'label' => __( 'Default team scoring', 'lumaha-virtual-balloon-race' ), 'options' => LVBR_Teams::scoring_labels(), 'help' => __( 'Average is fairest between big and small teams. Each race can choose its own.', 'lumaha-virtual-balloon-race' ) ),
			'default_teams'     => array( 'section' => 'teams', 'type' => 'teams', 'default' => array(), 'label' => __( 'Default teams', 'lumaha-virtual-balloon-race' ), 'help' => __( 'New races start with these teams. Change a race’s own teams on its race screen.', 'lumaha-virtual-balloon-race' ) ),

			// Data.
			'sponsor_stats'     => array( 'section' => 'data', 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Count sponsor views and clicks', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Anonymous totals for your sponsor report, kept on this site: no cookies, IP addresses or personal data, and nothing is sent elsewhere.', 'lumaha-virtual-balloon-race' ) ),
			'delete_data_on_uninstall' => array( 'section' => 'data', 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Delete all race data when the plugin is deleted', 'lumaha-virtual-balloon-race' ), 'help' => __( 'Off by default, so deleting and reinstalling the plugin (or replacing a hand-copied folder) keeps everything. When ticked, deleting the plugin from Plugins removes its races, balloons, teams and settings. Pages, products, orders and uploaded logos are kept either way. Deactivating never deletes anything.', 'lumaha-virtual-balloon-race' ) ),
		);
	}

	/**
	 * Settings sections.
	 *
	 * @return array
	 */
	private static function sections() {
		return array(
			'race'   => __( 'Race movement', 'lumaha-virtual-balloon-race' ),
			'launch' => __( 'Default launch point and wind', 'lumaha-virtual-balloon-race' ),
			'burst'  => __( 'Bursts', 'lumaha-virtual-balloon-race' ),
			'map'    => __( 'Map and leaderboard', 'lumaha-virtual-balloon-race' ),
			'services' => __( 'Maps, weather and place search', 'lumaha-virtual-balloon-race' ),
			'shop'   => __( 'Shop add-ons', 'lumaha-virtual-balloon-race' ),
			'teams'  => __( 'Teams', 'lumaha-virtual-balloon-race' ),
			'data'   => __( 'Data', 'lumaha-virtual-balloon-race' ),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return wp_list_pluck( self::fields(), 'default' );
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Seconds between scheduled updates (0 = off).
	 *
	 * @return int
	 */
	public static function interval_seconds() {
		$intervals = self::intervals();
		$slug      = self::get( 'interval' );

		return isset( $intervals[ $slug ] ) ? (int) $intervals[ $slug ] : 0;
	}

	/**
	 * Settings each race can override: Race movement and Bursts (flight mode has its own race field).
	 *
	 * @return string[]
	 */
	public static function movement_keys() {
		$keys = array();

		foreach ( self::fields() as $key => $field ) {
			if ( in_array( $field['section'], array( 'race', 'burst' ), true ) && 'flight_mode' !== $key ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Clean a race's movement overrides: blank or invalid values are dropped (they use the site setting).
	 *
	 * @param mixed $input Key => value.
	 * @return array
	 */
	public static function clean_movement( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$fields = self::fields();
		$output = array();

		foreach ( self::movement_keys() as $key ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				continue;
			}

			$field = $fields[ $key ];
			$raw   = $input[ $key ];

			if ( 'select' === $field['type'] ) {
				$raw = sanitize_key( (string) $raw );
				if ( array_key_exists( $raw, $field['options'] ) ) {
					$output[ $key ] = $raw;
				}
			} elseif ( is_numeric( $raw ) ) {
				$output[ $key ] = min( (float) $field['max'], max( (float) $field['min'], (float) $raw ) );
			}
		}

		return $output;
	}

	/**
	 * Forget cached settings after save.
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Add Settings submenu under Balloon Race.
	 */
	public function register_menu() {
		add_submenu_page(
			'lvbr-admin',
			__( 'Balloon Race Settings', 'lumaha-virtual-balloon-race' ),
			__( 'Settings', 'lumaha-virtual-balloon-race' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			'lvbr_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitise and clamp submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		foreach ( self::fields() as $key => $field ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$output[ $key ] = empty( $raw ) ? 0 : 1;
					break;

				case 'select':
					$raw            = sanitize_key( (string) $raw );
					$output[ $key ] = array_key_exists( $raw, $field['options'] ) ? $raw : $field['default'];
					break;

				case 'number':
					if ( null === $raw || '' === $raw || ! is_numeric( $raw ) ) {
						$output[ $key ] = $field['default'];
						break;
					}
					$output[ $key ] = min( (float) $field['max'], max( (float) $field['min'], (float) $raw ) );
					break;

				case 'modes':
					$raw            = sanitize_key( (string) $raw );
					$output[ $key ] = array_key_exists( $raw, LVBR_Flight_Modes::all() ) ? $raw : $field['default'];
					break;

				case 'teams':
					$output[ $key ] = array_map(
						static function ( $team ) {
							return array(
								'name'  => $team['name'],
								'color' => $team['color'],
							);
						},
						LVBR_Teams::clean_rows( $raw )
					);
					break;

				case 'tile_url':
					$output[ $key ] = LVBR_Services::sanitize_tile_url( $raw );
					break;

				case 'attribution':
					$output[ $key ] = LVBR_Services::sanitize_attribution( $raw );
					break;

				case 'datetime':
					$raw            = sanitize_text_field( (string) $raw );
					$output[ $key ] = date_create_immutable_from_format( 'Y-m-d\TH:i', $raw ) ? $raw : '';
					break;

				default:
					$output[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		return $output;
	}

	/**
	 * Register Leaflet (shared by admin and frontend maps).
	 */
	public static function register_leaflet() {
		if ( wp_style_is( 'lvbr-leaflet', 'registered' ) ) {
			return;
		}

		// Leaflet 1.9.4 ships with the plugin (assets/vendor/leaflet, BSD-2-Clause): no third-party CDN.
		// Every map gets lvbr-services with it: the tiles and place search chosen in Settings.
		wp_register_script( 'lvbr-services', LVBR_PLUGIN_URL . 'assets/js/services.js', array(), LVBR_Balloons::asset_version( 'assets/js/services.js' ), true );
		wp_add_inline_script( 'lvbr-services', 'window.lvbrServicesConfig = ' . wp_json_encode( LVBR_Services::browser_config() ) . ';', 'before' );
		wp_register_style( 'lvbr-leaflet', LVBR_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'lvbr-leaflet', LVBR_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', array( 'lvbr-services' ), '1.9.4', true );
	}


	/**
	 * Enqueue settings page assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		self::register_leaflet();

		wp_enqueue_style( 'lvbr-admin', LVBR_PLUGIN_URL . 'assets/css/admin.css', array( 'lvbr-leaflet' ), LVBR_Balloons::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'lvbr-admin-settings', LVBR_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'lvbr-leaflet' ), LVBR_Balloons::asset_version( 'assets/js/admin-settings.js' ), true );
		wp_enqueue_script( 'lvbr-admin-teams', LVBR_PLUGIN_URL . 'assets/js/admin-teams.js', array(), LVBR_Balloons::asset_version( 'assets/js/admin-teams.js' ), true );
		wp_localize_script(
			'lvbr-admin-settings',
			'lvbrSettings',
			array(
				'i18n' => array(
					'searching' => __( 'Searching…', 'lumaha-virtual-balloon-race' ),
					'noResults' => __( 'No places found.', 'lumaha-virtual-balloon-race' ),
					'error'     => __( 'Search failed. Try again or enter coordinates.', 'lumaha-virtual-balloon-race' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lumaha-virtual-balloon-race' ) );
		}

		$values = self::all();
		$fields = self::fields();
		?>
		<div class="wrap lvbr-admin lvbr-settings">
			<h1><?php esc_html_e( 'Balloon Race Settings', 'lumaha-virtual-balloon-race' ); ?></h1>

			<?php $this->render_schedule_status(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'lvbr_settings_group' ); ?>

				<?php foreach ( self::sections() as $section => $title ) : ?>
					<section class="lvbr-admin-panel">
						<h2><?php echo esc_html( $title ); ?></h2>

						<?php if ( 'launch' === $section ) : ?>
							<div class="lvbr-launch-picker">
								<div class="lvbr-launch-search"<?php echo LVBR_Services::search_enabled() ? '' : ' hidden'; ?>>
									<label for="lvbr-place-search" class="screen-reader-text"><?php esc_html_e( 'Search for a place', 'lumaha-virtual-balloon-race' ); ?></label>
									<input type="search" id="lvbr-place-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search for a place, e.g. Horfield, Bristol', 'lumaha-virtual-balloon-race' ); ?>" />
									<button type="button" class="button" id="lvbr-place-search-btn"><?php esc_html_e( 'Search', 'lumaha-virtual-balloon-race' ); ?></button>
									<ul class="lvbr-place-results" id="lvbr-place-results" hidden></ul>
								</div>
								<div id="lvbr-launch-map" class="lvbr-launch-map" data-zoom="<?php echo esc_attr( (string) $values['map_zoom'] ); ?>"></div>
								<?php if ( LVBR_Services::search_enabled() ) : ?>
									<p class="description"><?php echo esc_html( LVBR_Services::search_credit() ); ?></p>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<table class="form-table" role="presentation">
							<?php foreach ( $fields as $key => $field ) : ?>
								<?php
								if ( $field['section'] !== $section ) {
									continue;
								}
								$this->render_field( $key, $field, $values[ $key ] );
								?>
							<?php endforeach; ?>
						</table>
					</section>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>

			<?php do_action( 'lvbr_settings_page_end' ); ?>
		</div>
		<?php
	}

	/**
	 * Show when the next scheduled update runs.
	 */
	private function render_schedule_status() {
		$interval = self::interval_seconds();
		$next     = (int) apply_filters( 'lvbr_next_tick_timestamp', 0 );

		echo '<div class="notice notice-info inline"><p>';

		if ( ! $interval ) {
			esc_html_e( 'Automatic updates are off. Use “Advance now” on the Balloon Race page to move flying balloons manually.', 'lumaha-virtual-balloon-race' );
		} elseif ( $next ) {
			echo esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic update: %s', 'lumaha-virtual-balloon-race' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				)
			);
		} else {
			esc_html_e( 'Automatic updates are on but not yet scheduled. Save settings to schedule them.', 'lumaha-virtual-balloon-race' );
		}

		echo '</p></div>';
	}

	/**
	 * Render one field row.
	 *
	 * @param string $key   Setting key.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	private function render_field( $key, $field, $value ) {
		$id   = 'lvbr_' . $key;
		$name = self::OPTION . '[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
			<td>
				<?php
				switch ( $field['type'] ) {
					case 'select':
						echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
						foreach ( $field['options'] as $option => $label ) {
							echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $label ) . '</option>';
						}
						echo '</select>';
						break;

					case 'checkbox':
						echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
						echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (int) $value, 1, false ) . ' />';
						break;

					case 'number':
						printf(
							'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="%6$s" />',
							esc_attr( $id ),
							esc_attr( $name ),
							esc_attr( (string) $value ),
							esc_attr( (string) $field['min'] ),
							esc_attr( (string) $field['max'] ),
							esc_attr( (string) $field['step'] )
						);
						break;

					case 'teams':
						LVBR_Teams::render_editor( $name, (array) $value );
						break;

					case 'modes':
						LVBR_Flight_Modes::render_cards( $name, (string) $value );
						break;

					case 'tile_url':
					case 'attribution':
						echo '<input type="text" class="large-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
						break;

					case 'datetime':
						echo '<input type="datetime-local" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
						break;

					default:
						echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
				}

				if ( ! empty( $field['help'] ) ) {
					echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}
}
