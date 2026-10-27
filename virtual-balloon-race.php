<?php
/**
 * Plugin Name:       Lumaha Virtual Balloon Race
 * Plugin URI:        https://www.lumaha.uk/balloonrace/
 * Description:       Run a virtual balloon race fundraiser: sell entries through WooCommerce and watch the balloons drift across a live map on real winds.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Luke Hart
 * Author URI:        https://www.lumaha.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lumaha-virtual-balloon-race
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * Update URI:        https://github.com/f3zz3h/virtual-balloon-race
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

define( 'LVBR_VERSION', '2.0.0' );
define( 'LVBR_DB_VERSION', '1.17.0' );
define( 'LVBR_PLUGIN_FILE', __FILE__ );
define( 'LVBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LVBR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LVBR_TABLE_SUFFIX', 'lvbr_balloons' );

require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-balloons.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-races.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-teams.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-sponsors.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-prizes.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-sponsor-stats.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-flight-modes.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-install.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-settings.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-services.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-geo.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-weather.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-cron.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-admin.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-admin-races.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-frontend.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-map.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-pages.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-woocommerce.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-monetization.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-race-entries.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-team-entries.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-order-sync.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-race-emails.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-housekeeping.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-support.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-demo.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-product-preview.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-product-type.php';
require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-account.php';

// Updates from GitHub releases (starts itself). Not in the WordPress.org build, which updates from WordPress.org.
if ( file_exists( LVBR_PLUGIN_DIR . 'includes/class-lvbr-github-updater.php' ) ) {
	require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-github-updater.php';
}

/**
 * Main plugin bootstrap.
 */
final class LVBR_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var LVBR_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return LVBR_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( LVBR_PLUGIN_FILE, array( 'LVBR_Install', 'activate' ) );
		register_deactivation_hook( LVBR_PLUGIN_FILE, array( 'LVBR_Cron', 'unschedule' ) );
		register_deactivation_hook( LVBR_PLUGIN_FILE, array( 'LVBR_Race_Emails', 'unschedule' ) );
		register_deactivation_hook( LVBR_PLUGIN_FILE, array( 'LVBR_Housekeeping', 'unschedule' ) );
		LVBR_Install::init();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
	}

	/**
	 * Initialise components once WordPress (and optionally WooCommerce) is ready.
	 */
	public function init() {
		// Translations load on their own (WordPress 4.6+), from wp-content/languages/plugins.
		new LVBR_Admin();
		new LVBR_Admin_Races();
		new LVBR_Settings();
		new LVBR_Cron();
		new LVBR_Frontend();
		new LVBR_Map();
		new LVBR_Pages();
		new LVBR_Housekeeping();
		new LVBR_Support();
		new LVBR_Demo();
		new LVBR_Prizes();
		new LVBR_Sponsors();
		new LVBR_Sponsor_Stats();
		LVBR_Services::init();

		if ( $this->is_woocommerce_active() ) {
			new LVBR_Product_Type();
			new LVBR_WooCommerce();
			new LVBR_Monetization();
			new LVBR_Race_Entries();
			new LVBR_Team_Entries();
			new LVBR_Order_Sync();
			new LVBR_Race_Emails();
			new LVBR_Product_Preview();
			new LVBR_Account();
		}
	}

	/**
	 * Fully qualified custom table name (kept for backwards compatibility).
	 *
	 * @return string
	 */
	public function get_table_name() {
		return LVBR_Balloons::table();
	}

	/**
	 * Whether WooCommerce is available.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function woocommerce_missing_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// Only on the Plugins screen and the plugin's own screens.
		if ( $this->is_woocommerce_active() || ! current_user_can( 'activate_plugins' ) || ! $screen || ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'lvbr-' ) ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Lumaha Virtual Balloon Race requires WooCommerce to be installed and active in order to sell balloon entries.', 'lumaha-virtual-balloon-race' );
		echo '</p></div>';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LVBR_PLUGIN_DIR . 'includes/class-lvbr-cli.php';
	WP_CLI::add_command( 'lvbr', 'LVBR_CLI' );
}

/**
 * Kick off the plugin.
 *
 * @return LVBR_Plugin
 */
function lvbr() {
	return LVBR_Plugin::instance();
}

lvbr();
