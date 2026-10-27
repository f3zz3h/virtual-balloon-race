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
class LVBR_Install {

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
		self::migrate_from_vbr();

		if ( version_compare( (string) get_option( 'lvbr_db_version', '0' ), LVBR_DB_VERSION, '<' ) ) {
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

		if ( ! is_plugin_active_for_network( plugin_basename( LVBR_PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
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
		$tables[] = LVBR_Balloons::table();
		$tables[] = LVBR_Races::table();
		$tables[] = LVBR_Teams::table();
		$tables[] = LVBR_Sponsors::table();
		$tables[] = LVBR_Prizes::table();
		$tables[] = LVBR_Sponsor_Stats::table();

		return $tables;
	}

	/**
	 * Create or update the table for the current site.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::migrate_from_vbr();

		$previous = (string) get_option( 'lvbr_db_version', '0' );

		$table_name      = LVBR_Balloons::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			race_id bigint(20) unsigned NOT NULL DEFAULT 0,
			team_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			owner_name varchar(255) NOT NULL DEFAULT '',
			owner_email varchar(255) NOT NULL DEFAULT '',
			balloon_name varchar(255) NOT NULL DEFAULT '',
			balloon_color varchar(50) NOT NULL DEFAULT '',
			helium_level varchar(50) NOT NULL DEFAULT '',
			distance_km decimal(10,2) NOT NULL DEFAULT 0.00,
			from_start_km decimal(10,2) NOT NULL DEFAULT 0.00,
			flight_hours decimal(10,3) NOT NULL DEFAULT 0.000,
			is_burst tinyint(1) NOT NULL DEFAULT 0,
			withdrawn_at datetime DEFAULT NULL,
			withdrawn_reason varchar(20) NOT NULL DEFAULT '',
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
			KEY order_item_id (order_item_id),
			KEY withdrawn_at (withdrawn_at),
			KEY user_id (user_id),
			KEY is_burst (is_burst),
			KEY distance_km (distance_km),
			KEY from_start_km (from_start_km)
		) {$charset_collate};";

		dbDelta( $sql );

		$races = LVBR_Races::table();

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
			ranking varchar(20) NOT NULL DEFAULT '',
			flight_mode varchar(20) NOT NULL DEFAULT '',
			movement text,
			launch_email_sent_at datetime DEFAULT NULL,
			results_email_sent_at datetime DEFAULT NULL,
			entry_limit int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY launch_at (launch_at)
		) {$charset_collate};"
		);

		$teams = LVBR_Teams::table();

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

		$sponsors = LVBR_Sponsors::table();

		dbDelta(
			"CREATE TABLE {$sponsors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			race_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL DEFAULT '',
			url varchar(255) NOT NULL DEFAULT '',
			logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tier varchar(20) NOT NULL DEFAULT '',
			placements varchar(255) NOT NULL DEFAULT '',
			balloon_style varchar(20) NOT NULL DEFAULT '',
			color1 varchar(7) NOT NULL DEFAULT '',
			color2 varchar(7) NOT NULL DEFAULT '',
			band_text varchar(20) NOT NULL DEFAULT '',
			latitude decimal(10,7) DEFAULT NULL,
			longitude decimal(10,7) DEFAULT NULL,
			trail_json longtext,
			flight_hours decimal(10,3) NOT NULL DEFAULT 0,
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY race_id (race_id)
		) {$charset_collate};"
		);

		$prizes = LVBR_Prizes::table();

		dbDelta(
			"CREATE TABLE {$prizes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			race_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(20) NOT NULL DEFAULT '',
			label varchar(100) NOT NULL DEFAULT '',
			description varchar(255) NOT NULL DEFAULT '',
			sponsor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY race_id (race_id)
		) {$charset_collate};"
		);

		$sponsor_stats = LVBR_Sponsor_Stats::table();

		dbDelta(
			"CREATE TABLE {$sponsor_stats} (
			sponsor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			placement varchar(30) NOT NULL DEFAULT '',
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (sponsor_id,placement)
		) {$charset_collate};"
		);

		self::seed_positions();
		self::migrate_to_races();

		if ( version_compare( $previous, '1.5.0', '<' ) ) {
			LVBR_Balloons::backfill_from_start();
		}

		if ( version_compare( $previous, '1.6.0', '<' ) ) {
			LVBR_Balloons::backfill_flight_hours();
		}

		if ( version_compare( $previous, '1.7.0', '<' ) ) {
			LVBR_Balloons::backfill_order_items();
		}

		if ( '0' !== $previous && version_compare( $previous, '1.8.0', '<' ) ) {
			LVBR_Race_Emails::mark_past_races_sent();
		}

		// Work that needs WooCommerce's post types or rewrite rules runs on the next `init`.
		$tasks = array( 'pages', 'rewrite' );
		if ( '0' === $previous ) {
			// Fresh install only: never add a product to an existing shop on upgrade.
			$tasks[] = 'entry_product';
		}
		if ( '0' !== $previous && version_compare( $previous, '1.3.0', '<' ) ) {
			$tasks[] = 'products';
		}
		// Tracking pages still on an earlier default content get the current one (team standings, then prizes).
		if ( '0' !== $previous && version_compare( $previous, '1.14.0', '<' ) ) {
			$tasks[] = 'pages_1_4';
		}
		self::add_pending( $tasks );

		update_option( 'lvbr_db_version', LVBR_DB_VERSION );
	}

	/**
	 * Move a site from the plugin's old "vbr" names to "lvbr" (version 2.0.0): tables, options,
	 * meta keys, the entry product type, shortcodes in content and scheduled jobs. Runs once, only
	 * where the old names are in use and the new ones aren't yet.
	 */
	public static function migrate_from_vbr() {
		global $wpdb;

		if ( false !== get_option( 'lvbr_db_version' ) || false === get_option( 'vbr_db_version' ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off rename of the plugin's own tables and keys.
		foreach ( array( 'balloons', 'races', 'teams', 'sponsors', 'prizes', 'sponsor_stats' ) as $table ) {
			$old = $wpdb->prefix . 'vbr_' . $table;
			$new = $wpdb->prefix . 'lvbr_' . $table;
			if ( $old === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) && $new !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) ) {
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			}
		}

		// Options (settings, versions, state, WooCommerce's settings for the race emails); cached transients are dropped.
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'vbr\\_%' OR option_name LIKE 'woocommerce\\_vbr\\_%'" );
		foreach ( $names as $name ) {
			$new = preg_replace( '/^(woocommerce_)?vbr_/', '$1lvbr_', $name );
			if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ) ) {
				$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $name ) );
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_vbr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_vbr\\_%'" );

		// Meta keys on posts, products, attachments, orders and order items.
		$meta_tables = array( $wpdb->postmeta, $wpdb->usermeta, $wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->prefix . 'wc_orders_meta' );
		foreach ( $meta_tables as $meta_table ) {
			if ( $meta_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_table ) ) ) {
				$wpdb->query( "UPDATE {$meta_table} SET meta_key = CONCAT( '_lvbr_', SUBSTRING( meta_key, 6 ) ) WHERE meta_key LIKE '\\_vbr\\_%'" );
			}
		}

		// The entry product type.
		$old_term = $wpdb->get_row( "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_type' WHERE t.slug = 'balloon_entry'" );
		$new_term = $wpdb->get_row( "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_type' WHERE t.slug = 'lvbr_balloon_entry'" );
		if ( $old_term && ! $new_term ) {
			$wpdb->update( $wpdb->terms, array( 'name' => 'lvbr_balloon_entry', 'slug' => 'lvbr_balloon_entry' ), array( 'term_id' => (int) $old_term->term_id ) );
		} elseif ( $old_term && $new_term ) {
			$wpdb->update( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $new_term->term_taxonomy_id ), array( 'term_taxonomy_id' => (int) $old_term->term_taxonomy_id ) );
		}

		// Shortcodes in pages, posts and block templates.
		foreach ( array( 'map', 'leaderboard', 'races', 'teams', 'prizes', 'sponsors' ) as $tag ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s", '[balloon_' . $tag, '[lvbr_' . $tag, '%' . $wpdb->esc_like( '[balloon_' . $tag ) . '%' ) );
		}
		// phpcs:enable

		foreach ( array( 'widget_block', 'widget_text' ) as $widgets ) {
			$value = get_option( $widgets );
			if ( is_array( $value ) ) {
				update_option( $widgets, self::rename_shortcodes( $value ) );
			}
		}

		wp_cache_flush();

		// Interval names stored as values. Sites from before 2.0.0 already used the outside services and
		// counted sponsor views, so both stay on for them (new sites start with both off).
		$settings = get_option( 'lvbr_settings' );
		$settings = is_array( $settings ) ? $settings : array();
		if ( isset( $settings['interval'] ) && 0 === strpos( (string) $settings['interval'], 'vbr_' ) ) {
			$settings['interval'] = 'l' . $settings['interval'];
		}
		update_option( 'lvbr_settings', array_merge( array( 'external_services' => 1, 'sponsor_stats' => 1 ), $settings ) );
		$races = LVBR_Races::table();
		if ( $races === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $races ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off check for the plugin's own table.
			$wpdb->query( "UPDATE {$races} SET movement = REPLACE( movement, '\"vbr_', '\"lvbr_' ) WHERE movement LIKE '%\"vbr\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		}
		delete_option( 'lvbr_scheduled_interval' );

		// Jobs scheduled under the old names; the new ones are scheduled on the next request.
		foreach ( array( 'vbr_tick', 'vbr_daily_cleanup', 'vbr_check_race_emails', 'vbr_send_race_email' ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		self::add_pending( array( 'unschedule_vbr' ) );

		LVBR_Settings::flush_cache();
		LVBR_Races::flush();
	}

	/**
	 * Rename the old shortcodes in widget content.
	 *
	 * @param mixed $value Widget option or part of it.
	 * @return mixed
	 */
	private static function rename_shortcodes( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'rename_shortcodes' ), $value );
		}

		return is_string( $value ) ? preg_replace( '/\[balloon_(map|leaderboard|races|teams|prizes|sponsors)\b/', '[lvbr_$1', $value ) : $value;
	}

	/**
	 * Queue tasks for run_pending().
	 *
	 * @param string[] $tasks Task keys.
	 */
	public static function add_pending( $tasks ) {
		$pending = (array) get_option( 'lvbr_pending_setup', array() );
		update_option( 'lvbr_pending_setup', array_values( array_unique( array_merge( $pending, $tasks ) ) ) );
	}

	/**
	 * Run queued setup tasks once WordPress and WooCommerce are fully loaded.
	 */
	public static function run_pending() {
		$pending = (array) get_option( 'lvbr_pending_setup', array() );

		if ( ! $pending ) {
			return;
		}

		delete_option( 'lvbr_pending_setup' );

		// Product tasks wait until WooCommerce is active.
		$retry = array();

		if ( in_array( 'products', $pending, true ) ) {
			if ( LVBR_Product_Type::available() ) {
				LVBR_Product_Type::migrate_legacy_products();
			} else {
				$retry[] = 'products';
			}
		}

		if ( in_array( 'pages', $pending, true ) ) {
			LVBR_Pages::create_missing();
		}

		// Action Scheduler is only ready by `init`, so the old jobs there are removed now.
		if ( in_array( 'unschedule_vbr', $pending, true ) && function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'vbr_tick', 'vbr_daily_cleanup', 'vbr_check_race_emails', 'vbr_send_race_email' ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		if ( in_array( 'pages_1_4', $pending, true ) ) {
			LVBR_Pages::upgrade_default_content();
		}

		if ( in_array( 'entry_product', $pending, true ) ) {
			if ( LVBR_Product_Type::available() ) {
				LVBR_Product_Type::create_default_product();
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

		$table    = LVBR_Balloons::table();
		$orphans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE race_id = 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$races    = LVBR_Races::all();

		if ( ! $orphans ) {
			return;
		}

		if ( $races ) {
			$race_id = (int) LVBR_Races::current()->id;
		} else {
			$settings = get_option( LVBR_Settings::OPTION, array() );
			$launch   = LVBR_Balloons::launch_point();
			$first    = $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			$first_ts = $first ? strtotime( get_gmt_from_date( $first ) . ' UTC' ) : time();
			$end_gmt  = ! empty( $settings['race_end'] ) ? LVBR_Races::local_input_to_gmt( $settings['race_end'] ) : null;

			// Keep a future end time only; an old one would freeze the race at once.
			if ( $end_gmt && LVBR_Races::ts( $end_gmt ) <= $first_ts ) {
				$end_gmt = null;
			}

			$race_id = LVBR_Races::save(
				array(
					'name'         => __( 'Balloon Race', 'lumaha-virtual-balloon-race' ),
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

			$last_tick = (int) get_option( 'lvbr_last_tick', 0 );
			if ( $last_tick ) {
				LVBR_Races::set_time( $race_id, 'last_tick_at', $last_tick );
			}
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET race_id = %d WHERE race_id = 0", $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
	}

	/**
	 * Place balloons without coordinates at their start point (or the launch point).
	 */
	private static function seed_positions() {
		global $wpdb;

		$table  = LVBR_Balloons::table();
		$launch = LVBR_Balloons::launch_point();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
			$wpdb->prepare(
				"UPDATE {$table} SET start_lat = %f, start_lng = %f WHERE start_lat IS NULL OR start_lng IS NULL",
				$launch['lat'],
				$launch['lng']
			)
		);

		$rows = $wpdb->get_results( "SELECT id, start_lat, start_lng FROM {$table} WHERE latitude IS NULL OR longitude IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$lat = (float) $row->start_lat;
			$lng = (float) $row->start_lng;

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own table.
				$table,
				array(
					'latitude'   => $lat,
					'longitude'  => $lng,
					'trail_json' => LVBR_Balloons::encode_trail( array( array( $lat, $lng ) ) ),
				),
				array( 'id' => (int) $row->id ),
				array( '%f', '%f', '%s' ),
				array( '%d' )
			);
		}
	}
}
