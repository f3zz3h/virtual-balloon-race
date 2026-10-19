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
			'Eco-Glide'  => __( 'Eco-Glide (Low)', 'virtual-balloon-race' ),
			'Low'        => __( 'Low', 'virtual-balloon-race' ),
			'Medium'     => __( 'Medium', 'virtual-balloon-race' ),
			'High'       => __( 'High', 'virtual-balloon-race' ),
		);

		return isset( $labels[ $strategy ] ) ? $labels[ $strategy ] : $strategy;
	}
}
