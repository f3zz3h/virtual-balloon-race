<?php
/**
 * Balloon table access and shared balloon vocabulary.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for the balloons table.
 */
class VBR_Balloons {

	/**
	 * Fully qualified custom table name (per site on Multisite).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . VBR_TABLE_SUFFIX;
	}

	/**
	 * Cache-busting version for a plugin asset (changes whenever the file does).
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$mtime = @filemtime( VBR_PLUGIN_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $mtime ? VBR_VERSION . '.' . $mtime : VBR_VERSION;
	}

	/**
	 * Allowed balloon colours.
	 *
	 * @return string[]
	 */
	public static function colors() {
		return array( 'Red', 'Blue', 'Green', 'Yellow', 'Purple' );
	}

	/**
	 * Helium fill strategies (stored in helium_level).
	 *
	 * @return string[]
	 */
	public static function helium_strategies() {
		return array( 'Balanced', 'Max Height', 'Eco-Glide' );
	}

	/**
	 * Human-readable helium strategy labels.
	 *
	 * @param string $strategy Strategy name.
	 * @return string
	 */
	public static function helium_label( $strategy ) {
		$labels = array(
			'Balanced'   => __( 'Balanced (Medium)', 'virtual-balloon-race' ),
			'Max Height' => __( 'Max Height (High) — faster, small burst risk', 'virtual-balloon-race' ),
			'Eco-Glide'  => __( 'Eco-Glide (Low) — slower, immune to storm bursts', 'virtual-balloon-race' ),
			'Low'        => __( 'Low', 'virtual-balloon-race' ),
			'Medium'     => __( 'Medium', 'virtual-balloon-race' ),
			'High'       => __( 'High', 'virtual-balloon-race' ),
		);

		return isset( $labels[ $strategy ] ) ? $labels[ $strategy ] : $strategy;
	}

	/**
	 * Configured launch point.
	 *
	 * @return array{lat: float, lng: float, label: string}
	 */
	public static function launch_point() {
		return array(
			'lat'   => (float) VBR_Settings::get( 'launch_lat' ),
			'lng'   => (float) VBR_Settings::get( 'launch_lng' ),
			'label' => (string) VBR_Settings::get( 'launch_label' ),
		);
	}

	/**
	 * Decode a stored trail into [[lat, lng], ...].
	 *
	 * @param string|null $json Stored JSON.
	 * @return array
	 */
	public static function decode_trail( $json ) {
		$points = json_decode( (string) $json, true );

		if ( ! is_array( $points ) ) {
			return array();
		}

		$clean = array();
		foreach ( $points as $point ) {
			if ( is_array( $point ) && 2 === count( $point ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
				$clean[] = array( (float) $point[0], (float) $point[1] );
			}
		}

		return $clean;
	}

	/**
	 * Encode a trail, keeping only the most recent points.
	 *
	 * @param array    $points [[lat, lng], ...].
	 * @param int|null $cap    Max points (defaults to the trail length setting).
	 * @return string
	 */
	public static function encode_trail( $points, $cap = null ) {
		$cap    = null === $cap ? (int) VBR_Settings::get( 'trail_cap' ) : (int) $cap;
		$points = array_slice( array_values( $points ), -max( 2, $cap ) );

		$rounded = array_map(
			static function ( $p ) {
				return array( round( (float) $p[0], 5 ), round( (float) $p[1], 5 ) );
			},
			$points
		);

		return wp_json_encode( $rounded );
	}

	/**
	 * Position columns for a brand new balloon starting at a point.
	 *
	 * @param float $lat Start latitude.
	 * @param float $lng Start longitude.
	 * @return array Column => value.
	 */
	public static function start_columns( $lat, $lng ) {
		return array(
			'latitude'   => (float) $lat,
			'longitude'  => (float) $lng,
			'start_lat'  => (float) $lat,
			'start_lng'  => (float) $lng,
			'trail_json' => self::encode_trail( array( array( $lat, $lng ) ) ),
		);
	}
}
