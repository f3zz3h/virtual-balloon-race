<?php
/**
 * Balloon race entry product.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * A simple, always-virtual product. Each basket line is one balloon with its own details, so it's sold individually (no quantity box).
 *
 * WooCommerce finds this class from the type name (`balloon_entry` → `WC_Product_Balloon_Entry`).
 */
class WC_Product_Balloon_Entry extends WC_Product_Simple {

	/**
	 * Product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return VBR_Product_Type::TYPE;
	}

	/**
	 * Entries never ship.
	 *
	 * @param string $context View or edit.
	 * @return bool
	 */
	public function get_virtual( $context = 'view' ) {
		return true;
	}

	/**
	 * One balloon per basket line: each has its own name, colour and picture.
	 *
	 * @param string $context View or edit.
	 * @return bool
	 */
	public function get_sold_individually( $context = 'view' ) {
		return true;
	}

	/**
	 * Entries are never downloads.
	 *
	 * @param string $context View or edit.
	 * @return bool
	 */
	public function get_downloadable( $context = 'view' ) {
		return false;
	}
}
