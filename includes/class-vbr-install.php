<?php
/**
 * Schema install and upgrades (Multisite aware).
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the balloons table.
 */
class VBR_Install {

	/**
	 * Register hooks that must run on every request.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_action( 'wp_initialize_site', array( __CLASS__, 'install_new_site' ), 20 );
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_site_table' ), 10, 2 );
	}

	/**
	 * Activation hook. On a network activation, install on every site.
	 *
	 * @param bool $network_wide Whether this is a network activation.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Run the schema update when the stored DB version is older.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'vbr_db_version', '0' ), VBR_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * New site on a network where the plugin is network-active.
	 *
	 * @param WP_Site $site New site.
	 */
	public static function install_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( VBR_PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( $site->blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Drop our table when a site is deleted.
	 *
	 * @param string[] $tables  Tables to drop.
	 * @param int      $site_id Site ID.
	 * @return string[]
	 */
	public static function drop_site_table( $tables, $site_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$tables[] = VBR_Balloons::table();

		return $tables;
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
			latitude decimal(10,7) DEFAULT NULL,
			longitude decimal(10,7) DEFAULT NULL,
			start_lat decimal(10,7) DEFAULT NULL,
			start_lng decimal(10,7) DEFAULT NULL,
			logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			trail_json longtext DEFAULT NULL,
			last_moved_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY user_id (user_id),
			KEY is_burst (is_burst),
			KEY distance_km (distance_km)
		) {$charset_collate};";

		dbDelta( $sql );

		self::seed_positions();

		update_option( 'vbr_db_version', VBR_DB_VERSION );
	}

	/**
	 * Place balloons without coordinates at their start point (or the launch point).
	 */
	private static function seed_positions() {
		global $wpdb;

		$table  = VBR_Balloons::table();
		$launch = VBR_Balloons::launch_point();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET start_lat = %f, start_lng = %f WHERE start_lat IS NULL OR start_lng IS NULL",
				$launch['lat'],
				$launch['lng']
			)
		);

		$rows = $wpdb->get_results( "SELECT id, start_lat, start_lng FROM {$table} WHERE latitude IS NULL OR longitude IS NULL" );
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$lat = (float) $row->start_lat;
			$lng = (float) $row->start_lng;

			$wpdb->update(
				$table,
				array(
					'latitude'   => $lat,
					'longitude'  => $lng,
					'trail_json' => VBR_Balloons::encode_trail( array( array( $lat, $lng ) ) ),
				),
				array( 'id' => (int) $row->id ),
				array( '%f', '%f', '%s' ),
				array( '%d' )
			);
		}
	}
}
