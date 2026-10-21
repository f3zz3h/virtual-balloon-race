<?php
/**
 * Plugin Name:       Virtual Balloon Race
 * Description:       Run a virtual balloon race fundraiser: sell entries through WooCommerce and watch the balloons drift across a live map on real winds.
 * Version:           1.10.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Virtual Balloon Race
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       virtual-balloon-race
 * Domain Path:       /languages
 * WC requires at least: 7.0
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

define( 'VBR_VERSION', '1.10.0' );
define( 'VBR_DB_VERSION', '1.10.0' );
define( 'VBR_PLUGIN_FILE', __FILE__ );
define( 'VBR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VBR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VBR_TABLE_SUFFIX', 'vbr_balloons' );

require_once VBR_PLUGIN_DIR . 'includes/class-vbr-balloons.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-races.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-teams.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-flight-modes.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-install.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-settings.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-geo.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-weather.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-cron.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-admin.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-admin-races.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-frontend.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-map.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-pages.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-woocommerce.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-monetization.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-race-entries.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-team-entries.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-order-sync.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-race-emails.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-housekeeping.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-support.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-product-preview.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-product-type.php';
require_once VBR_PLUGIN_DIR . 'includes/class-vbr-account.php';

/**
 * Main plugin bootstrap.
 */
final class Virtual_Balloon_Race {

	/**
	 * Singleton instance.
	 *
	 * @var Virtual_Balloon_Race|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Virtual_Balloon_Race
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
		register_activation_hook( VBR_PLUGIN_FILE, array( 'VBR_Install', 'activate' ) );
		register_deactivation_hook( VBR_PLUGIN_FILE, array( 'VBR_Cron', 'unschedule' ) );
		register_deactivation_hook( VBR_PLUGIN_FILE, array( 'VBR_Race_Emails', 'unschedule' ) );
		register_deactivation_hook( VBR_PLUGIN_FILE, array( 'VBR_Housekeeping', 'unschedule' ) );
		VBR_Install::init();

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
	}

	/**
	 * Initialise components once WordPress (and optionally WooCommerce) is ready.
	 */
	public function init() {
		load_plugin_textdomain( 'virtual-balloon-race', false, dirname( plugin_basename( VBR_PLUGIN_FILE ) ) . '/languages' );

		new VBR_Admin();
		new VBR_Admin_Races();
		new VBR_Settings();
		new VBR_Cron();
		new VBR_Frontend();
		new VBR_Map();
		new VBR_Pages();
		new VBR_Housekeeping();
		new VBR_Support();

		if ( $this->is_woocommerce_active() ) {
			new VBR_Product_Type();
			new VBR_WooCommerce();
			new VBR_Monetization();
			new VBR_Race_Entries();
			new VBR_Team_Entries();
			new VBR_Order_Sync();
			new VBR_Race_Emails();
			new VBR_Product_Preview();
			new VBR_Account();
		}
	}

	/**
	 * Fully qualified custom table name (kept for backwards compatibility).
	 *
	 * @return string
	 */
	public function get_table_name() {
		return VBR_Balloons::table();
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
		if ( $this->is_woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Virtual Balloon Race requires WooCommerce to be installed and active in order to sell balloon entries.', 'virtual-balloon-race' );
		echo '</p></div>';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once VBR_PLUGIN_DIR . 'includes/class-vbr-cli.php';
	WP_CLI::add_command( 'vbr', 'VBR_CLI' );
}

/**
 * Kick off the plugin.
 *
 * @return Virtual_Balloon_Race
 */
function vbr() {
	return Virtual_Balloon_Race::instance();
}

vbr();
