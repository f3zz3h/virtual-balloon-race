<?php
/**
 * Runs when the plugin is deleted from Plugins (not when it's deactivated).
 *
 * Always stops the plugin's scheduled jobs. Removes the plugin's data only if
 * Settings → Data → "Delete all race data when the plugin is deleted" is ticked
 * (per site on Multisite): its tables (races, balloons, teams, sponsors, prizes, sponsor counts), settings and
 * cached data. Pages, products, orders and uploaded logos are the site's own
 * content and are kept either way.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up one site.
 */
function vbr_uninstall_site() {
	global $wpdb;

	foreach ( array( 'vbr_tick', 'vbr_check_race_emails', 'vbr_daily_cleanup' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'vbr_tick' );
		as_unschedule_all_actions( 'vbr_send_race_email' );
	}

	$settings = get_option( 'vbr_settings', array() );

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	foreach ( array( 'vbr_balloons', 'vbr_races', 'vbr_teams', 'vbr_sponsors', 'vbr_prizes', 'vbr_sponsor_stats' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall.
	}

	// The plugin's options and cached wind data all start with vbr_; its two WooCommerce emails' settings with woocommerce_vbr_.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vbr\_%' OR option_name LIKE '\_transient\_vbr\_%' OR option_name LIKE '\_transient\_timeout\_vbr\_%' OR option_name LIKE 'woocommerce\_vbr\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		vbr_uninstall_site();
		restore_current_blog();
	}
} else {
	vbr_uninstall_site();
}
