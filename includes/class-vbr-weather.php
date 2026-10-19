<?php
/**
 * Open-Meteo wind with a grid-cell transient cache.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wind lookups. Positions are rounded to 0.1° cells (~11 km) so nearby
 * balloons share one cached value, and uncached cells are fetched in batches.
 */
class VBR_Weather {

	const API         = 'https://api.open-meteo.com/v1/forecast';
	const CELL_DEG    = 0.1;
	const BATCH_SIZE  = 50;
	const MAX_BATCHES = 4;
	const MISS_TTL    = 2 * MINUTE_IN_SECONDS;

	/**
	 * Per-request memo of cell => wind|false.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Cell key for a position.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string
	 */
	public static function cell_key( $lat, $lng ) {
		$step = self::CELL_DEG;

		return sprintf( '%.1f,%.1f', round( $lat / $step ) * $step, round( $lng / $step ) * $step );
	}

	/**
	 * How long a cell's wind is cached: half the update interval, 10–60 minutes.
	 *
	 * @return int
	 */
	private static function ttl() {
		$interval = VBR_Settings::interval_seconds();

		return (int) min( HOUR_IN_SECONDS, max( 10 * MINUTE_IN_SECONDS, $interval / 2 ) );
	}

	/**
	 * Transient name for a cell.
	 *
	 * @param string $cell Cell key.
	 * @return string
	 */
	private static function transient( $cell ) {
		return 'vbr_wind_' . md5( $cell );
	}

	/**
	 * Fetch and cache wind for any of these cells not already cached.
	 *
	 * @param string[] $cells Cell keys.
	 */
	public static function prefetch( array $cells ) {
		$missing = array();

		foreach ( array_unique( $cells ) as $cell ) {
			if ( array_key_exists( $cell, self::$memo ) ) {
				continue;
			}

			$cached = get_transient( self::transient( $cell ) );

			if ( false !== $cached ) {
				self::$memo[ $cell ] = is_array( $cached ) ? $cached : false;
				continue;
			}

			$missing[] = $cell;
		}

		$chunks = array_slice( array_chunk( $missing, self::BATCH_SIZE ), 0, self::MAX_BATCHES );

		foreach ( $chunks as $chunk ) {
			$results = self::fetch_batch( $chunk );

			foreach ( $chunk as $i => $cell ) {
				$wind = isset( $results[ $i ] ) ? $results[ $i ] : null;

				if ( $wind ) {
					set_transient( self::transient( $cell ), $wind, self::ttl() );
					self::$memo[ $cell ] = $wind;
				} else {
					// Remember the miss briefly so a slow API doesn't stall every tick.
					set_transient( self::transient( $cell ), 'miss', self::MISS_TTL );
					self::$memo[ $cell ] = false;
				}
			}
		}
	}

	/**
	 * Wind for a cell (after prefetch), or null.
	 *
	 * @param string $cell Cell key.
	 * @return array{speed: float, dir: float}|null
	 */
	public static function for_cell( $cell ) {
		if ( ! array_key_exists( $cell, self::$memo ) ) {
			self::prefetch( array( $cell ) );
		}

		return ! empty( self::$memo[ $cell ] ) ? self::$memo[ $cell ] : null;
	}

	/**
	 * Wind at a position.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return array{speed: float, dir: float}|null
	 */
	public static function at( $lat, $lng ) {
		return self::for_cell( self::cell_key( $lat, $lng ) );
	}

	/**
	 * Call Open-Meteo for several cells in one request.
	 *
	 * @param string[] $cells Cell keys.
	 * @return array Index => wind array|null, in the same order as $cells.
	 */
	private static function fetch_batch( array $cells ) {
		$lats = array();
		$lngs = array();

		foreach ( $cells as $cell ) {
			list( $lat, $lng ) = array_map( 'floatval', explode( ',', $cell ) );
			$lats[]            = $lat;
			$lngs[]            = $lng;
		}

		$url = add_query_arg(
			array(
				'latitude'        => implode( ',', $lats ),
				'longitude'       => implode( ',', $lngs ),
				'current'         => 'wind_speed_10m,wind_direction_10m',
				'wind_speed_unit' => 'kmh',
			),
			self::API
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'VirtualBalloonRace/' . VBR_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return array();
		}

		// One location returns an object; several return a list.
		if ( isset( $body['current'] ) ) {
			$body = array( $body );
		}

		$out = array();
		foreach ( $cells as $i => $cell ) {
			$current = isset( $body[ $i ]['current'] ) ? $body[ $i ]['current'] : null;

			if ( ! $current || ! isset( $current['wind_speed_10m'], $current['wind_direction_10m'] ) ) {
				$out[ $i ] = null;
				continue;
			}

			$out[ $i ] = array(
				'speed' => (float) $current['wind_speed_10m'],
				'dir'   => (float) $current['wind_direction_10m'],
			);
		}

		return $out;
	}
}
