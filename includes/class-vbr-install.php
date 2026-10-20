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
		add_action( 'init', array( __CLASS__, 'run_pending' ), 99 );
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
		$tables[] = VBR_Races::table();

		return $tables;
	}

	/**
	 * Create or update the table for the current site.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$previous = (string) get_option( 'vbr_db_version', '0' );

		$table_name      = VBR_Balloons::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			race_id bigint(20) unsigned NOT NULL DEFAULT 0,
			team_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
			has_custom_start tinyint(1) NOT NULL DEFAULT 0,
			logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			trail_json longtext DEFAULT NULL,
			last_moved_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY race_id (race_id),
			KEY team_id (team_id),
			KEY order_id (order_id),
			KEY user_id (user_id),
			KEY is_burst (is_burst),
			KEY distance_km (distance_km)
		) {$charset_collate};";

		dbDelta( $sql );

		$races = VBR_Races::table();

		dbDelta(
			"CREATE TABLE {$races} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			slug varchar(200) NOT NULL DEFAULT '',
			launch_lat decimal(10,7) NOT NULL DEFAULT 0,
			launch_lng decimal(10,7) NOT NULL DEFAULT 0,
			launch_label varchar(255) NOT NULL DEFAULT '',
			sales_open_at datetime DEFAULT NULL,
			launch_at datetime NOT NULL,
			end_at datetime DEFAULT NULL,
			last_tick_at datetime DEFAULT NULL,
			team_scoring varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY launch_at (launch_at)
		) {$charset_collate};"
		);

		$teams = VBR_Teams::table();

		dbDelta(
			"CREATE TABLE {$teams} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			race_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL DEFAULT '',
			color varchar(7) NOT NULL DEFAULT '',
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY race_id (race_id)
		) {$charset_collate};"
		);

		self::seed_positions();
		self::migrate_to_races();

		// Work that needs WooCommerce's post types or rewrite rules runs on the next `init`.
		$tasks = array( 'pages', 'rewrite' );
		if ( '0' === $previous ) {
			// Fresh install only: never add a product to an existing shop on upgrade.
			$tasks[] = 'entry_product';
		}
		if ( '0' !== $previous && version_compare( $previous, '1.3.0', '<' ) ) {
			$tasks[] = 'products';
		}
		if ( '0' !== $previous && version_compare( $previous, '1.4.0', '<' ) ) {
			$tasks[] = 'pages_1_4';
		}
		self::add_pending( $tasks );

		update_option( 'vbr_db_version', VBR_DB_VERSION );
	}

	/**
	 * Queue tasks for run_pending().
	 *
	 * @param string[] $tasks Task keys.
	 */
	public static function add_pending( $tasks ) {
		$pending = (array) get_option( 'vbr_pending_setup', array() );
		update_option( 'vbr_pending_setup', array_values( array_unique( array_merge( $pending, $tasks ) ) ) );
	}

	/**
	 * Run queued setup tasks once WordPress and WooCommerce are fully loaded.
	 */
	public static function run_pending() {
		$pending = (array) get_option( 'vbr_pending_setup', array() );

		if ( ! $pending ) {
			return;
		}

		delete_option( 'vbr_pending_setup' );

		// Product tasks wait until WooCommerce is active.
		$retry = array();

		if ( in_array( 'products', $pending, true ) ) {
			if ( VBR_Product_Type::available() ) {
				VBR_Product_Type::migrate_legacy_products();
			} else {
				$retry[] = 'products';
			}
		}

		if ( in_array( 'pages', $pending, true ) ) {
			VBR_Pages::create_missing();
		}

		if ( in_array( 'pages_1_4', $pending, true ) ) {
			VBR_Pages::upgrade_default_content();
		}

		if ( in_array( 'entry_product', $pending, true ) ) {
			if ( VBR_Product_Type::available() ) {
				VBR_Product_Type::create_default_product();
			} else {
				$retry[] = 'entry_product';
			}
		}

		if ( $retry ) {
			self::add_pending( $retry );
		}

		if ( in_array( 'rewrite', $pending, true ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * 1.2.0: put balloons that predate races into an auto-created first race.
	 */
	private static function migrate_to_races() {
		global $wpdb;

		$table    = VBR_Balloons::table();
		$orphans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE race_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$races    = VBR_Races::all();

		if ( ! $orphans ) {
			return;
		}

		if ( $races ) {
			$race_id = (int) VBR_Races::current()->id;
		} else {
			$settings = get_option( VBR_Settings::OPTION, array() );
			$launch   = VBR_Balloons::launch_point();
			$first    = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$first_ts = $first ? strtotime( get_gmt_from_date( $first ) . ' UTC' ) : time();
			$end_gmt  = ! empty( $settings['race_end'] ) ? VBR_Races::local_input_to_gmt( $settings['race_end'] ) : null;

			// Keep a future end time only; an old one would freeze the race at once.
			if ( $end_gmt && VBR_Races::ts( $end_gmt ) <= $first_ts ) {
				$end_gmt = null;
			}

			$race_id = VBR_Races::save(
				array(
					'name'         => __( 'Balloon Race', 'virtual-balloon-race' ),
					'launch_lat'   => $launch['lat'],
					'launch_lng'   => $launch['lng'],
					'launch_label' => $launch['label'],
					'launch_at'    => gmdate( 'Y-m-d H:i:s', $first_ts ),
					'end_at'       => $end_gmt,
				)
			);

			if ( is_wp_error( $race_id ) ) {
				return;
			}

			$last_tick = (int) get_option( 'vbr_last_tick', 0 );
			if ( $last_tick ) {
				VBR_Races::set_time( $race_id, 'last_tick_at', $last_tick );
			}
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET race_id = %d WHERE race_id = 0", $race_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
