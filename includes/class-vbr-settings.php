<?php
/**
 * Race settings (Balloon Race → Settings).
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores all race settings in one `vbr_settings` option.
 */
class VBR_Settings {

	const OPTION = 'vbr_settings';
	const PAGE   = 'vbr-settings';

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
			'vbr_5min'   => 5 * MINUTE_IN_SECONDS,
			'vbr_30min'  => 30 * MINUTE_IN_SECONDS,
			'hourly'     => HOUR_IN_SECONDS,
			'vbr_6hours' => 6 * HOUR_IN_SECONDS,
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
			'off'        => __( 'Off (manual only)', 'virtual-balloon-race' ),
			'vbr_5min'   => __( 'Every 5 minutes', 'virtual-balloon-race' ),
			'vbr_30min'  => __( 'Every 30 minutes', 'virtual-balloon-race' ),
			'hourly'     => __( 'Every hour', 'virtual-balloon-race' ),
			'vbr_6hours' => __( 'Every 6 hours', 'virtual-balloon-race' ),
			'daily'      => __( 'Daily', 'virtual-balloon-race' ),
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
			'interval'          => array( 'section' => 'race', 'type' => 'select', 'default' => 'off', 'label' => __( 'Auto-update interval', 'virtual-balloon-race' ), 'options' => self::interval_labels(), 'help' => __( 'How often balloons move. Scheduled updates run via WooCommerce Action Scheduler (or WP-Cron), which only fires when the site gets traffic. For accurate 5-minute updates, set up a real system cron.', 'virtual-balloon-race' ) ),
			'base_kmh'          => array( 'section' => 'race', 'type' => 'number', 'default' => 35, 'min' => 0, 'max' => 300, 'step' => 0.1, 'label' => __( 'Base speed (km/h)', 'virtual-balloon-race' ), 'help' => __( 'Average drift speed. Distance per update = speed × hours since the last update × helium × wind × variance.', 'virtual-balloon-race' ) ),
			'mult_eco'          => array( 'section' => 'race', 'type' => 'number', 'default' => 0.7, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Eco-Glide multiplier', 'virtual-balloon-race' ) ),
			'mult_balanced'     => array( 'section' => 'race', 'type' => 'number', 'default' => 1.0, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Balanced multiplier', 'virtual-balloon-race' ) ),
			'mult_max'          => array( 'section' => 'race', 'type' => 'number', 'default' => 1.35, 'min' => 0, 'max' => 5, 'step' => 0.01, 'label' => __( 'Max Height multiplier', 'virtual-balloon-race' ) ),
			'variance_pct'      => array( 'section' => 'race', 'type' => 'number', 'default' => 15, 'min' => 0, 'max' => 90, 'step' => 1, 'label' => __( 'Random variance (±%)', 'virtual-balloon-race' ), 'help' => __( 'Stops balloons moving in lock-step.', 'virtual-balloon-race' ) ),

			// Launch and weather.
			'launch_label'      => array( 'section' => 'launch', 'type' => 'text', 'default' => 'Horfield, Bristol', 'label' => __( 'Launch name', 'virtual-balloon-race' ) ),
			'launch_lat'        => array( 'section' => 'launch', 'type' => 'number', 'default' => 51.4885, 'min' => -90, 'max' => 90, 'step' => 0.0000001, 'label' => __( 'Launch latitude', 'virtual-balloon-race' ) ),
			'launch_lng'        => array( 'section' => 'launch', 'type' => 'number', 'default' => -2.5800, 'min' => -180, 'max' => 180, 'step' => 0.0000001, 'label' => __( 'Launch longitude', 'virtual-balloon-race' ), 'help' => __( 'Used for new races. Search for a place above or click the map. Each race can have its own launch point.', 'virtual-balloon-race' ) ),
			'weather_enabled'   => array( 'section' => 'launch', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Use live wind (Open-Meteo)', 'virtual-balloon-race' ), 'help' => __( 'Free, no API key. When off, balloons follow the default heading at base speed.', 'virtual-balloon-race' ) ),
			'per_balloon_wind'  => array( 'section' => 'launch', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Per-balloon wind', 'virtual-balloon-race' ), 'help' => __( 'Fetch wind for each balloon\'s ~11 km area so paths diverge as they spread. Turn off to use launch-point wind only.', 'virtual-balloon-race' ) ),
			'wind_weight'       => array( 'section' => 'launch', 'type' => 'number', 'default' => 0.6, 'min' => 0, 'max' => 1, 'step' => 0.05, 'label' => __( 'Wind influence (0–1)', 'virtual-balloon-race' ), 'help' => __( '0 = wind only sets direction; 1 = wind speed fully drives distance.', 'virtual-balloon-race' ) ),
			'default_heading'   => array( 'section' => 'launch', 'type' => 'number', 'default' => 70, 'min' => 0, 'max' => 359, 'step' => 1, 'label' => __( 'Default heading (°)', 'virtual-balloon-race' ), 'help' => __( 'Direction of travel when no wind data is available. 0 = north, 90 = east.', 'virtual-balloon-race' ) ),

			// Bursts.
			'burst_max_daily'   => array( 'section' => 'burst', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 100, 'step' => 0.1, 'label' => __( 'Max Height burst chance (% per day)', 'virtual-balloon-race' ), 'help' => __( 'Scaled to the update interval, so frequent updates do not raise the overall risk.', 'virtual-balloon-race' ) ),
			'storm_kmh'         => array( 'section' => 'burst', 'type' => 'number', 'default' => 45, 'min' => 0, 'max' => 300, 'step' => 1, 'label' => __( 'Storm wind threshold (km/h)', 'virtual-balloon-race' ) ),
			'burst_storm_daily' => array( 'section' => 'burst', 'type' => 'number', 'default' => 10, 'min' => 0, 'max' => 100, 'step' => 0.1, 'label' => __( 'Storm burst chance (% per day)', 'virtual-balloon-race' ), 'help' => __( 'Applies to Balanced and Max Height balloons in storm winds. Eco-Glide is immune.', 'virtual-balloon-race' ) ),

			// Map.
			'map_zoom'          => array( 'section' => 'map', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 18, 'step' => 1, 'label' => __( 'Default map zoom', 'virtual-balloon-race' ) ),
			'map_refresh'       => array( 'section' => 'map', 'type' => 'number', 'default' => 60, 'min' => 15, 'max' => 3600, 'step' => 1, 'label' => __( 'Map refresh (seconds)', 'virtual-balloon-race' ) ),
			'trail_cap'         => array( 'section' => 'map', 'type' => 'number', 'default' => 80, 'min' => 2, 'max' => 500, 'step' => 1, 'label' => __( 'Trail length (points)', 'virtual-balloon-race' ) ),

			// Shop add-ons.
			/* translators: %s: currency symbol */
			'price_strategy'    => array( 'section' => 'shop', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Tactical helium strategy (%s)', 'virtual-balloon-race' ), $currency ), 'help' => __( 'Added when a buyer picks Max Height or Eco-Glide. Balanced is always free.', 'virtual-balloon-race' ) ),
			'enable_launch'     => array( 'section' => 'shop', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Offer custom launch point', 'virtual-balloon-race' ) ),
			/* translators: %s: currency symbol */
			'price_launch'      => array( 'section' => 'shop', 'type' => 'number', 'default' => 2, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Custom launch point (%s)', 'virtual-balloon-race' ), $currency ) ),
			'enable_logo'       => array( 'section' => 'shop', 'type' => 'checkbox', 'default' => 1, 'label' => __( 'Offer logo / image upload', 'virtual-balloon-race' ) ),
			/* translators: %s: currency symbol */
			'price_logo'        => array( 'section' => 'shop', 'type' => 'number', 'default' => 2, 'min' => 0, 'max' => 1000, 'step' => 0.01, 'label' => sprintf( __( 'Logo upload (%s)', 'virtual-balloon-race' ), $currency ) ),

			// Teams.
			'teams_enabled'     => array( 'section' => 'teams', 'type' => 'checkbox', 'default' => 0, 'label' => __( 'Enable teams', 'virtual-balloon-race' ), 'help' => __( 'Buyers pick a team for each balloon, and a team leaderboard ranks the teams. When off, teams are hidden everywhere but kept.', 'virtual-balloon-race' ) ),
			'team_scoring'      => array( 'section' => 'teams', 'type' => 'select', 'default' => 'average', 'label' => __( 'Default team scoring', 'virtual-balloon-race' ), 'options' => VBR_Teams::scoring_labels(), 'help' => __( 'Average is fairest between big and small teams. Each race can choose its own.', 'virtual-balloon-race' ) ),
			'default_teams'     => array( 'section' => 'teams', 'type' => 'teams', 'default' => array(), 'label' => __( 'Default teams', 'virtual-balloon-race' ), 'help' => __( 'New races start with these teams. Change a race’s own teams on its race screen.', 'virtual-balloon-race' ) ),
		);
	}

	/**
	 * Settings sections.
	 *
	 * @return array
	 */
	private static function sections() {
		return array(
			'race'   => __( 'Race movement', 'virtual-balloon-race' ),
			'launch' => __( 'Default launch point and wind', 'virtual-balloon-race' ),
			'burst'  => __( 'Bursts', 'virtual-balloon-race' ),
			'map'    => __( 'Map', 'virtual-balloon-race' ),
			'shop'   => __( 'Shop add-ons', 'virtual-balloon-race' ),
			'teams'  => __( 'Teams', 'virtual-balloon-race' ),
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
			'vbr-admin',
			__( 'Balloon Race Settings', 'virtual-balloon-race' ),
			__( 'Settings', 'virtual-balloon-race' ),
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
			'vbr_settings_group',
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

				case 'teams':
					$output[ $key ] = array_map(
						static function ( $team ) {
							return array(
								'name'  => $team['name'],
								'color' => $team['color'],
							);
						},
						VBR_Teams::clean_rows( $raw )
					);
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
		if ( wp_style_is( 'vbr-leaflet', 'registered' ) ) {
			return;
		}

		wp_register_style( 'vbr-leaflet', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'vbr-leaflet', 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		// Subresource integrity for the CDN files (WordPress doesn't print these itself).
		$sri = array(
			'css' => 'sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H',
			'js'  => 'sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH',
		);

		add_filter(
			'style_loader_tag',
			static function ( $tag, $handle ) use ( $sri ) {
				return 'vbr-leaflet' === $handle ? str_replace( ' href=', ' integrity="' . $sri['css'] . '" crossorigin="anonymous" href=', $tag ) : $tag;
			},
			10,
			2
		);

		add_filter(
			'script_loader_tag',
			static function ( $tag, $handle ) use ( $sri ) {
				return 'vbr-leaflet' === $handle ? str_replace( ' src=', ' integrity="' . $sri['js'] . '" crossorigin="anonymous" src=', $tag ) : $tag;
			},
			10,
			2
		);
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

		wp_enqueue_style( 'vbr-admin', VBR_PLUGIN_URL . 'assets/css/admin.css', array( 'vbr-leaflet' ), VBR_Balloons::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'vbr-admin-settings', VBR_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'vbr-leaflet' ), VBR_Balloons::asset_version( 'assets/js/admin-settings.js' ), true );
		wp_enqueue_script( 'vbr-admin-teams', VBR_PLUGIN_URL . 'assets/js/admin-teams.js', array(), VBR_Balloons::asset_version( 'assets/js/admin-teams.js' ), true );
		wp_localize_script(
			'vbr-admin-settings',
			'vbrSettings',
			array(
				'i18n' => array(
					'searching' => __( 'Searching…', 'virtual-balloon-race' ),
					'noResults' => __( 'No places found.', 'virtual-balloon-race' ),
					'error'     => __( 'Search failed. Try again or enter coordinates.', 'virtual-balloon-race' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'virtual-balloon-race' ) );
		}

		$values = self::all();
		$fields = self::fields();
		?>
		<div class="wrap vbr-admin vbr-settings">
			<h1><?php esc_html_e( 'Balloon Race Settings', 'virtual-balloon-race' ); ?></h1>

			<?php $this->render_schedule_status(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'vbr_settings_group' ); ?>

				<?php foreach ( self::sections() as $section => $title ) : ?>
					<section class="vbr-admin-panel">
						<h2><?php echo esc_html( $title ); ?></h2>

						<?php if ( 'launch' === $section ) : ?>
							<div class="vbr-launch-picker">
								<div class="vbr-launch-search">
									<label for="vbr-place-search" class="screen-reader-text"><?php esc_html_e( 'Search for a place', 'virtual-balloon-race' ); ?></label>
									<input type="search" id="vbr-place-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search for a place, e.g. Horfield, Bristol', 'virtual-balloon-race' ); ?>" />
									<button type="button" class="button" id="vbr-place-search-btn"><?php esc_html_e( 'Search', 'virtual-balloon-race' ); ?></button>
									<ul class="vbr-place-results" id="vbr-place-results" hidden></ul>
								</div>
								<div id="vbr-launch-map" class="vbr-launch-map" data-zoom="<?php echo esc_attr( (string) $values['map_zoom'] ); ?>"></div>
								<p class="description"><?php esc_html_e( 'Place search uses OpenStreetMap Nominatim.', 'virtual-balloon-race' ); ?></p>
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

			<?php do_action( 'vbr_settings_page_end' ); ?>
		</div>
		<?php
	}

	/**
	 * Show when the next scheduled update runs.
	 */
	private function render_schedule_status() {
		$interval = self::interval_seconds();
		$next     = (int) apply_filters( 'vbr_next_tick_timestamp', 0 );

		echo '<div class="notice notice-info inline"><p>';

		if ( ! $interval ) {
			esc_html_e( 'Automatic updates are off. Use “Advance now” on the Balloon Race page to move flying balloons manually.', 'virtual-balloon-race' );
		} elseif ( $next ) {
			echo esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic update: %s', 'virtual-balloon-race' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				)
			);
		} else {
			esc_html_e( 'Automatic updates are on but not yet scheduled. Save settings to schedule them.', 'virtual-balloon-race' );
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
		$id   = 'vbr_' . $key;
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
						VBR_Teams::render_editor( $name, (array) $value );
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
