<?php
/**
 * Scheduled race updates ("ticks") and balloon movement.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the tick. The manual "Advance" button calls the same tick.
 */
class VBR_Cron {

	const HOOK  = 'vbr_tick';
	const GROUP = 'vbr';
	const LOCK  = 'vbr_tick_lock';

	/** Max random bearing change per tick, so balloons in the same cell fan out. */
	const JITTER_DEG = 12;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::HOOK, array( __CLASS__, 'on_scheduled_hook' ) );
		add_action( 'update_option_' . VBR_Settings::OPTION, array( __CLASS__, 'reschedule' ), 20 );
		add_action( 'add_option_' . VBR_Settings::OPTION, array( __CLASS__, 'reschedule' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
		add_filter( 'vbr_next_tick_timestamp', array( __CLASS__, 'next_run' ) );
	}

	/**
	 * Custom WP-Cron intervals.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		$schedules['vbr_5min']   = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'virtual-balloon-race' ),
		);
		$schedules['vbr_30min']  = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'virtual-balloon-race' ),
		);
		$schedules['vbr_6hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'virtual-balloon-race' ),
		);

		return $schedules;
	}

	/**
	 * Whether WooCommerce Action Scheduler is available.
	 *
	 * @return bool
	 */
	private static function use_action_scheduler() {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Remove every scheduled tick (both schedulers).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );

		if ( self::use_action_scheduler() ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}

		delete_option( 'vbr_scheduled_interval' );
	}

	/**
	 * Schedule ticks at the configured interval (after settings save).
	 */
	public static function reschedule() {
		VBR_Settings::flush_cache();

		self::unschedule();

		$slug    = (string) VBR_Settings::get( 'interval' );
		$seconds = VBR_Settings::interval_seconds();

		if ( ! $seconds ) {
			return;
		}

		$first = time() + $seconds;

		if ( self::use_action_scheduler() ) {
			as_schedule_recurring_action( $first, $seconds, self::HOOK, array(), self::GROUP, true );
		} else {
			wp_schedule_event( $first, $slug, self::HOOK );
		}

		update_option( 'vbr_scheduled_interval', $slug, false );
	}

	/**
	 * Repair the schedule if it is missing or out of date (e.g. after an update).
	 */
	public static function ensure_scheduled() {
		$slug = (string) VBR_Settings::get( 'interval' );

		if ( get_option( 'vbr_scheduled_interval', 'off' ) !== $slug || ( VBR_Settings::interval_seconds() && ! self::next_run() ) ) {
			self::reschedule();
		}
	}

	/**
	 * Next scheduled tick timestamp, or 0.
	 *
	 * @return int
	 */
	public static function next_run() {
		if ( self::use_action_scheduler() && function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( is_int( $next ) ) {
				return $next;
			}
			if ( true === $next ) {
				return time(); // Running now.
			}
		}

		return (int) wp_next_scheduled( self::HOOK );
	}

	/**
	 * The scheduled hook's callback (actions don't return values; WP-CLI calls run_scheduled() for the summaries).
	 */
	public static function on_scheduled_hook() {
		self::run_scheduled();
	}

	/**
	 * Scheduled tick: move each flying race by the real time since its last tick
	 * (counted from launch, stopping at the end time, capped at 3× the interval).
	 *
	 * @return array Summaries keyed by race ID.
	 */
	public static function run_scheduled() {
		$interval = VBR_Settings::interval_seconds();
		$results  = array();

		if ( ! $interval ) {
			return $results;
		}

		$now = time();

		foreach ( VBR_Races::all() as $race ) {
			$launch = VBR_Races::ts( $race->launch_at );
			$end    = VBR_Races::ts( $race->end_at );
			$last   = max( $launch, VBR_Races::ts( $race->last_tick_at ) );
			$until  = $end ? min( $now, $end ) : $now;

			// Not launched yet, or already caught up to the end.
			if ( ! $launch || $now < $launch || $until <= $last ) {
				continue;
			}

			$elapsed = min( $until - $last, 3 * $interval );
			$summary = self::tick(
				$elapsed / HOUR_IN_SECONDS,
				array(
					'race_id' => (int) $race->id,
					'source'  => 'scheduled',
				)
			);

			if ( empty( $summary['locked'] ) ) {
				VBR_Races::set_time( $race->id, 'last_tick_at', $until );
			}

			$results[ (int) $race->id ] = $summary;
		}

		return $results;
	}

	/**
	 * Last tick summary for a race.
	 *
	 * @param int $race_id Race ID.
	 * @return array
	 */
	public static function last_summary( $race_id ) {
		$all = get_option( 'vbr_tick_summaries', array() );

		return isset( $all[ (int) $race_id ] ) ? (array) $all[ (int) $race_id ] : array();
	}

	/**
	 * Move every active balloon in one race.
	 *
	 * @param float $hours Hours of drift to simulate.
	 * @param array $args  {
	 *     @type int        $race_id Race to move (required).
	 *     @type float|null $min_km Fixed range override (min), ignores speed settings.
	 *     @type float|null $max_km Fixed range override (max).
	 *     @type string     $source 'scheduled' | 'manual' | 'cli'.
	 * }
	 * @return array Summary: advanced, burst, storm_burst, wind_cells, locked.
	 */
	public static function tick( $hours, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'race_id' => 0,
				'min_km'  => null,
				'max_km'  => null,
				'source'  => 'manual',
			)
		);

		$race = VBR_Races::get( (int) $args['race_id'] );

		$summary = array(
			'advanced'    => 0,
			'burst'       => 0,
			'storm_burst' => 0,
			'wind_cells'  => 0,
			'race_id'     => $race ? (int) $race->id : 0,
			'hours'       => round( (float) $hours, 3 ),
			'source'      => $args['source'],
			'time'        => time(),
			'locked'      => false,
		);

		if ( ! $race ) {
			return $summary;
		}

		if ( ! self::acquire_lock() ) {
			$summary['locked'] = true;
			return $summary;
		}

		try {
			$s        = VBR_Settings::all();
			$table    = VBR_Balloons::table();
			$launch   = VBR_Races::launch_point( $race );
			$balloons = $wpdb->get_results( $wpdb->prepare( "SELECT id, helium_level, latitude, longitude, start_lat, start_lng, trail_json, flight_hours FROM {$table} WHERE is_burst = 0 AND withdrawn_at IS NULL AND race_id = %d", (int) $race->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Sponsor hot-air balloons fly with the race on the same wind; they never burst and aren't ranked.
			foreach ( VBR_Sponsors::with_placement( (int) $race->id, 'hot_air' ) as $sponsor ) {
				$balloons[] = (object) array(
					'id'           => (int) $sponsor->id,
					'sponsor'      => true,
					'helium_level' => 'Balanced',
					'latitude'     => $sponsor->latitude,
					'longitude'    => $sponsor->longitude,
					'start_lat'    => null,
					'start_lng'    => null,
					'trail_json'   => $sponsor->trail_json,
					'flight_hours' => $sponsor->flight_hours,
				);
			}

			$use_weather = ! empty( $s['weather_enabled'] );
			$per_balloon = $use_weather && ! empty( $s['per_balloon_wind'] );

			// Resolve each balloon's current position.
			foreach ( $balloons as $b ) {
				if ( null === $b->latitude || null === $b->longitude ) {
					$b->latitude  = null !== $b->start_lat ? $b->start_lat : $launch['lat'];
					$b->longitude = null !== $b->start_lng ? $b->start_lng : $launch['lng'];
				}
				$b->latitude  = (float) $b->latitude;
				$b->longitude = (float) $b->longitude;
				$b->cell      = VBR_Weather::cell_key( $b->latitude, $b->longitude );
			}

			// One batched weather fetch for every cell in use.
			$launch_wind = null;
			if ( $use_weather ) {
				$cells = array( VBR_Weather::cell_key( $launch['lat'], $launch['lng'] ) );
				if ( $per_balloon ) {
					$cells = array_merge( $cells, wp_list_pluck( $balloons, 'cell' ) );
				}
				$cells = array_values( array_unique( $cells ) );

				VBR_Weather::prefetch( $cells );
				$launch_wind           = VBR_Weather::for_cell( $cells[0] );
				$summary['wind_cells'] = count( $cells );
			}

			$base     = max( 0.0, (float) $s['base_kmh'] );
			$weight   = min( 1.0, max( 0.0, (float) $s['wind_weight'] ) );
			$variance = (float) $s['variance_pct'] / 100;
			$days     = (float) $hours / 24;
			$p_max    = self::per_tick_probability( (float) $s['burst_max_daily'] / 100, $days );
			$p_storm  = self::per_tick_probability( (float) $s['burst_storm_daily'] / 100, $days );
			$fixed    = null !== $args['min_km'] && null !== $args['max_km'];
			$now_sql  = current_time( 'mysql' );
			$mode     = VBR_Flight_Modes::for_race( $race );

			foreach ( $balloons as $b ) {
				$wind = $per_balloon ? VBR_Weather::for_cell( $b->cell ) : null;
				$wind = $wind ? $wind : $launch_wind;

				if ( $wind ) {
					// Open-Meteo gives where the wind comes FROM; balloons travel the opposite way.
					$bearing  = fmod( $wind['dir'] + 180, 360 );
					$wind_kmh = $wind['speed'];
				} else {
					$bearing  = (float) $s['default_heading'];
					$wind_kmh = $base;
				}

				$bearing += self::rand_float( -self::JITTER_DEG, self::JITTER_DEG );

				if ( $fixed ) {
					$step = self::rand_float( (float) $args['min_km'], (float) $args['max_km'] );
				} else {
					$wind_factor = $base > 0 ? ( 1 - $weight ) + $weight * ( $wind_kmh / $base ) : 1;
					$wind_factor = min( 2.5, max( 0.25, $wind_factor ) );
					// The flight mode slows balloons as they age, so use full-speed-equivalent hours.
					$flown = VBR_Flight_Modes::effective_hours( $mode, (float) $b->flight_hours, (float) $hours );
					if ( $flown <= 0 ) {
						continue; // Landed (Drift): it stays where it came down.
					}
					$step = $base * self::helium_multiplier( $b->helium_level, $s ) * $flown * $wind_factor * ( 1 + self::rand_float( -$variance, $variance ) );
				}

				$step = max( 0.0, round( $step, 2 ) );

				list( $lat, $lng ) = VBR_Geo::destination( $b->latitude, $b->longitude, $bearing, $step );

				// Straight-line distance from where this balloon started (its own launch point, else the race's).
				$from_start = VBR_Geo::distance_km(
					null !== $b->start_lat ? (float) $b->start_lat : $launch['lat'],
					null !== $b->start_lng ? (float) $b->start_lng : $launch['lng'],
					$lat,
					$lng
				);

				$trail   = VBR_Balloons::decode_trail( $b->trail_json );
				$trail   = $trail ? $trail : array( array( $b->latitude, $b->longitude ) );
				$trail[] = array( $lat, $lng );

				if ( ! empty( $b->sponsor ) ) {
					$wpdb->update(
						VBR_Sponsors::table(),
						array(
							'latitude'     => $lat,
							'longitude'    => $lng,
							'trail_json'   => VBR_Balloons::encode_trail( $trail ),
							'flight_hours' => (float) $b->flight_hours + (float) $hours,
						),
						array( 'id' => (int) $b->id )
					);
					continue;
				}

				// Burst rolls (after moving, so the balloon pops where it ended up).
				$burst = false;
				if ( self::helium_is_risky( $b->helium_level ) && self::roll( $p_max ) ) {
					$burst = true;
					$summary['burst']++;
				} elseif ( $wind && $wind_kmh >= (float) $s['storm_kmh'] && ! self::helium_is_eco( $b->helium_level ) && self::roll( $p_storm ) ) {
					$burst = true;
					$summary['burst']++;
					$summary['storm_burst']++;
				}

				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET distance_km = distance_km + %f, from_start_km = %f, flight_hours = flight_hours + %f, latitude = %f, longitude = %f, trail_json = %s, last_moved_at = %s, is_burst = %d WHERE id = %d AND is_burst = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$step,
						round( $from_start, 2 ),
						(float) $hours,
						$lat,
						$lng,
						VBR_Balloons::encode_trail( $trail ),
						$now_sql,
						$burst ? 1 : 0,
						(int) $b->id
					)
				);

				if ( ! $burst ) {
					$summary['advanced']++;
				}
			}

			$all                    = get_option( 'vbr_tick_summaries', array() );
			$all[ (int) $race->id ]    = $summary;
			update_option( 'vbr_tick_summaries', $all, false );
		} finally {
			self::release_lock();
		}

		/**
		 * Fires after balloons have moved.
		 *
		 * @param array $summary Tick summary.
		 */
		do_action( 'vbr_after_tick', $summary );

		return $summary;
	}

	/**
	 * Convert a daily probability into the chance over $days.
	 *
	 * @param float $daily Daily probability (0–1).
	 * @param float $days  Length of this tick in days.
	 * @return float
	 */
	public static function per_tick_probability( $daily, $days ) {
		$daily = min( 1.0, max( 0.0, $daily ) );

		return 1 - pow( 1 - $daily, max( 0.0, $days ) );
	}

	/**
	 * Speed multiplier for a helium strategy.
	 *
	 * @param string $helium   Helium level.
	 * @param array  $settings Settings.
	 * @return float
	 */
	private static function helium_multiplier( $helium, $settings ) {
		if ( self::helium_is_risky( $helium ) ) {
			return (float) $settings['mult_max'];
		}

		if ( self::helium_is_eco( $helium ) ) {
			return (float) $settings['mult_eco'];
		}

		return (float) $settings['mult_balanced'];
	}

	/**
	 * Max Height (or legacy High).
	 *
	 * @param string $helium Helium level.
	 * @return bool
	 */
	private static function helium_is_risky( $helium ) {
		return in_array( (string) $helium, array( 'Max Height', 'High' ), true );
	}

	/**
	 * Eco-Glide (or legacy Low).
	 *
	 * @param string $helium Helium level.
	 * @return bool
	 */
	private static function helium_is_eco( $helium ) {
		return in_array( (string) $helium, array( 'Eco-Glide', 'Low' ), true );
	}

	/**
	 * Random float in [min, max].
	 *
	 * @param float $min Min.
	 * @param float $max Max.
	 * @return float
	 */
	private static function rand_float( $min, $max ) {
		if ( $max <= $min ) {
			return (float) $min;
		}

		return $min + ( mt_rand() / mt_getrandmax() ) * ( $max - $min ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
	}

	/**
	 * True with probability $p.
	 *
	 * @param float $p Probability (0–1).
	 * @return bool
	 */
	private static function roll( $p ) {
		return $p > 0 && ( mt_rand() / mt_getrandmax() ) < $p; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
	}

	/**
	 * Take the tick lock (stale after 5 minutes).
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}

		$taken = (int) get_option( self::LOCK );
		if ( $taken && $taken < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}

		return false;
	}

	/**
	 * Release the tick lock.
	 */
	private static function release_lock() {
		delete_option( self::LOCK );
	}
}
