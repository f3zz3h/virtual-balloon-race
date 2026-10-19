<?php
/**
 * Schema install and upgrades.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the balloons table.
 */
class VBR_Install {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Create or update the table for the current site.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = VBR_Balloons::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_name varchar(255) NOT NULL DEFAULT '',
			owner_email varchar(255) NOT NULL DEFAULT '',
			balloon_name varchar(255) NOT NULL DEFAULT '',
			balloon_color varchar(50) NOT NULL DEFAULT '',
			helium_level varchar(50) NOT NULL DEFAULT '',
			distance_km decimal(10,2) NOT NULL DEFAULT 0.00,
			is_burst tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY user_id (user_id),
			KEY is_burst (is_burst),
			KEY distance_km (distance_km)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'vbr_db_version', VBR_DB_VERSION );
	}
}
