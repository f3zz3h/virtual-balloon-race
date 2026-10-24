<?php
/**
 * Sponsor views and clicks: running totals per sponsor and placement, for the organiser's report.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts how often each sponsor placement was shown and clicked. The browser reports them
 * (assets/js/sponsor-stats.js), because race pages are usually page-cached; only totals are kept:
 * no cookies, no IP addresses, nothing about who saw or clicked what. Emails aren't counted.
 */
class VBR_Sponsor_Stats {

	/**
	 * Most sponsor placements one report may count (a page rarely shows more than a dozen).
	 */
	const MAX_ITEMS = 50;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ), 5 );
		add_action( 'admin_post_vbr_sponsor_stats_csv', array( $this, 'download_csv' ) );
	}

	/**
	 * Stats table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'vbr_sponsor_stats';
	}

	/**
	 * Placements that can be counted: the sponsor placements, plus prize credits.
	 *
	 * @return string[]
	 */
	public static function placements() {
		return array_merge( array_keys( VBR_Sponsors::placement_labels() ), array( 'prize' ) );
	}

	/**
	 * Register the reporting script (enqueued by VBR_Sponsors when a placement is shown).
	 */
	public static function register_script() {
		if ( wp_script_is( 'vbr-sponsor-stats', 'registered' ) ) {
			return;
		}

		wp_register_script( 'vbr-sponsor-stats', VBR_PLUGIN_URL . 'assets/js/sponsor-stats.js', array(), VBR_Balloons::asset_version( 'assets/js/sponsor-stats.js' ), true );
		wp_add_inline_script( 'vbr-sponsor-stats', 'window.vbrSponsorStats = ' . wp_json_encode( array( 'url' => rest_url( VBR_Map::REST_NS . '/sponsor-stats' ) ) ) . ';', 'before' );
	}

	/**
	 * Make sure the reporting script loads on this page.
	 */
	public static function enqueue() {
		if ( is_admin() ) {
			return;
		}

		self::register_script();
		wp_enqueue_script( 'vbr-sponsor-stats' );
	}

	/**
	 * POST /vbr/v1/sponsor-stats { type: view|click, items: ["<sponsor id>:<placement>", …] }.
	 */
	public function register_route() {
		register_rest_route(
			VBR_Map::REST_NS,
			'/sponsor-stats',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_record' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type'  => array(
						'type'     => 'string',
						'enum'     => array( 'view', 'click' ),
						'required' => true,
					),
					'items' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => self::MAX_ITEMS,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Count a report from the browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_record( $request ) {
		$response = rest_ensure_response( array( 'counted' => self::record( (string) $request->get_param( 'type' ), (array) $request->get_param( 'items' ) ) ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Add one view or click to each "<sponsor id>:<placement>" pair. Pairs for sponsors that don't exist, or
	 * placements a sponsor doesn't have, are ignored; each pair counts once per report.
	 *
	 * @param string   $type  view or click.
	 * @param string[] $items Pairs.
	 * @return int Pairs counted.
	 */
	public static function record( $type, $items ) {
		global $wpdb;

		$column = 'click' === $type ? 'clicks' : 'views';
		$pairs  = array();

		foreach ( array_slice( array_unique( array_map( 'strval', $items ) ), 0, self::MAX_ITEMS ) as $item ) {
			if ( preg_match( '/^(\d+):([a-z_]+)$/', $item, $m ) && in_array( $m[2], self::placements(), true ) ) {
				$pairs[] = array( (int) $m[1], $m[2] );
			}
		}

		if ( ! $pairs ) {
			return 0;
		}

		$ids      = array_unique( wp_list_pluck( $pairs, 0 ) );
		$table    = VBR_Sponsors::table();
		$sponsors = $wpdb->get_results( "SELECT id, placements FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')', OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs.
		$stats    = self::table();
		$counted  = 0;

		foreach ( $pairs as $pair ) {
			list( $id, $placement ) = $pair;

			if ( ! isset( $sponsors[ $id ] ) ) {
				continue;
			}
			// Prize credits aren't a ticked placement; everything else must be ticked for the sponsor.
			if ( 'prize' !== $placement && ! in_array( $placement, explode( ',', (string) $sponsors[ $id ]->placements ), true ) ) {
				continue;
			}

			$wpdb->query( $wpdb->prepare( "INSERT INTO {$stats} (sponsor_id, placement, {$column}) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1", $id, $placement ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
			++$counted;
		}

		return $counted;
	}

	/**
	 * A race's totals: sponsor id => placement => [views, clicks].
	 *
	 * @param int $race_id Race ID.
	 * @return array<int, array<string, array{views: int, clicks: int}>>
	 */
	public static function for_race( $race_id ) {
		global $wpdb;

		$stats    = self::table();
		$sponsors = VBR_Sponsors::table();
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.sponsor_id, s.placement, s.views, s.clicks FROM {$stats} s INNER JOIN {$sponsors} p ON p.id = s.sponsor_id WHERE p.race_id = %d", (int) $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$out      = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row->sponsor_id ][ $row->placement ] = array(
				'views'  => (int) $row->views,
				'clicks' => (int) $row->clicks,
			);
		}

		return $out;
	}

	/**
	 * A sponsor's totals across placements.
	 *
	 * @param array $by_placement placement => [views, clicks].
	 * @return array{views: int, clicks: int}
	 */
	public static function totals( $by_placement ) {
		return array(
			'views'  => (int) array_sum( wp_list_pluck( $by_placement, 'views' ) ),
			'clicks' => (int) array_sum( wp_list_pluck( $by_placement, 'clicks' ) ),
		);
	}

	/**
	 * Forget a sponsor's counts (when the sponsor is removed).
	 *
	 * @param int[] $sponsor_ids Sponsor IDs.
	 */
	public static function delete_for_sponsors( $sponsor_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', (array) $sponsor_ids ) );

		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE sponsor_id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integer IDs.
		}
	}

	/**
	 * The race's views and clicks as a CSV download (admin-post, from the Sponsors panel).
	 */
	public function download_csv() {
		$race_id = isset( $_GET['race'] ) ? absint( $_GET['race'] ) : 0;

		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vbr_sponsor_stats_csv_' . $race_id ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'virtual-balloon-race' ) );
		}

		$race = VBR_Races::get( $race_id );

		if ( ! $race ) {
			wp_die( esc_html__( 'Race not found.', 'virtual-balloon-race' ) );
		}

		$labels = array_merge( VBR_Sponsors::placement_labels(), array( 'prize' => __( 'Prize credit', 'virtual-balloon-race' ) ) );
		$tiers  = VBR_Sponsors::tier_labels();
		$stats  = self::for_race( $race_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $race->slug . '-sponsor-views-clicks.csv' ) . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( __( 'Sponsor', 'virtual-balloon-race' ), __( 'Tier', 'virtual-balloon-race' ), __( 'Placement', 'virtual-balloon-race' ), __( 'Views', 'virtual-balloon-race' ), __( 'Clicks', 'virtual-balloon-race' ) ) );

		foreach ( VBR_Sponsors::for_race( $race_id ) as $sponsor ) {
			$rows = isset( $stats[ (int) $sponsor->id ] ) ? $stats[ (int) $sponsor->id ] : array();
			if ( ! $rows ) {
				fputcsv( $out, array( self::csv_safe( $sponsor->name ), $tiers[ $sponsor->tier ] ?? $sponsor->tier, '', 0, 0 ) );
				continue;
			}
			foreach ( $rows as $placement => $counts ) {
				fputcsv( $out, array( self::csv_safe( $sponsor->name ), $tiers[ $sponsor->tier ] ?? $sponsor->tier, $labels[ $placement ] ?? $placement, $counts['views'], $counts['clicks'] ) );
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Keep a spreadsheet from reading a cell as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		return preg_match( '/^[=+\-@\t\r]/', (string) $value ) ? "'" . $value : (string) $value;
	}
}
