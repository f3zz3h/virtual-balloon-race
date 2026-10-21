<?php
/**
 * Plugin screen links (Settings, Donate) and a small "support this plugin" note in Settings.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Links that help people find the settings and, optionally, support the plugin's development.
 */
class VBR_Support {

	/**
	 * Where donations go (Buy Me a Coffee, Ko-fi, Patreon, GitHub Sponsors…). Empty = no donate links anywhere.
	 * Keep in step with "Donate link:" in readme.txt and .github/FUNDING.yml.
	 */
	const DONATE_URL = '';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'plugin_action_links_' . plugin_basename( VBR_PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'vbr_settings_page_end', array( $this, 'render_settings_note' ), 30 );
	}

	/**
	 * Donation page URL (filter `vbr_donate_url`), or '' for none.
	 *
	 * @return string
	 */
	public static function donate_url() {
		return esc_url_raw( (string) apply_filters( 'vbr_donate_url', self::DONATE_URL ) );
	}

	/**
	 * "Settings" next to Deactivate on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . VBR_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'virtual-balloon-race' ) . '</a>' );
		return $links;
	}

	/**
	 * "Donate" under the plugin's description on the Plugins screen.
	 *
	 * @param string[] $meta Row links.
	 * @param string   $file Plugin file.
	 * @return string[]
	 */
	public function row_meta( $meta, $file ) {
		$url = self::donate_url();

		if ( plugin_basename( VBR_PLUGIN_FILE ) === $file && $url ) {
			$meta[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'virtual-balloon-race' ) . '</a>';
		}

		return $meta;
	}

	/**
	 * A short, unobtrusive note at the foot of Settings (only when there's a donation page).
	 */
	public function render_settings_note() {
		$url = self::donate_url();

		if ( ! $url ) {
			return;
		}
		?>
		<section class="vbr-admin-panel vbr-support">
			<h2><?php esc_html_e( 'Support this plugin', 'virtual-balloon-race' ); ?></h2>
			<p><?php esc_html_e( 'Virtual Balloon Race is free, and made to help schools and charities raise money. If it’s helped your fundraiser, a small donation towards its upkeep is very welcome — but never expected.', 'virtual-balloon-race' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Donate', 'virtual-balloon-race' ); ?></a></p>
		</section>
		<?php
	}
}
