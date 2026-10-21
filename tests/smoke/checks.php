<?php
/**
 * Fresh-install smoke test checks, run by run.sh as `wp eval-file checks.php <stage>`.
 *
 * Each stage prints ok/FAIL lines and exits non-zero if anything failed.
 *
 * @package Virtual_Balloon_Race
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions -- test script.

$stage    = isset( $args[0] ) ? $args[0] : '';
$failures = array();

$check = static function ( $ok, $what ) use ( &$failures ) {
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $what, "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	if ( ! $ok ) {
		$failures[] = $what;
	}
};

$render = static function ( $callable ) {
	ob_start();
	call_user_func( $callable );
	return (string) ob_get_clean();
};

// Render the race edit screen for a race (private method, so via reflection).
$render_race_screen = static function ( $race ) use ( $render ) {
	$method = new ReflectionMethod( 'VBR_Admin_Races', 'render_form' );
	$method->setAccessible( true );
	return $render(
		static function () use ( $method, $race ) {
			$method->invoke( new VBR_Admin_Races(), $race );
		}
	);
};

$settings = static function ( $changes ) {
	update_option( VBR_Settings::OPTION, array_merge( (array) get_option( VBR_Settings::OPTION, array() ), $changes ) );
	VBR_Settings::flush_cache();
};

wp_set_current_user( 1 );

switch ( $stage ) {
	// Plugin activated on a fresh site before WooCommerce.
	case 'woo-inactive':
		$check( ! class_exists( 'WooCommerce' ), 'WooCommerce is not active yet' );
		$check( VBR_DB_VERSION === get_option( 'vbr_db_version' ), 'database installed at ' . VBR_DB_VERSION );
		$page = VBR_Pages::tracking_page_id();
		$check( $page > 0, 'tracking page created' );
		$check( 'balloon-race' === get_post_field( 'post_name', $page ), 'tracking page slug is balloon-race' );
		$check( in_array( 'entry_product', (array) get_option( 'vbr_pending_setup', array() ), true ), 'entry product waits for WooCommerce' );
		$check( false !== strpos( $render( array( new VBR_Pages(), 'render_panel' ) ), 'id="vbr-pages"' ), 'Settings → Pages and products renders' );

		$race_id = VBR_Races::save(
			array(
				'name'       => 'Smoke Screen Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		$check( is_int( $race_id ), 'race can be created without WooCommerce' );
		$check( false !== strpos( $render_race_screen( VBR_Races::get( $race_id ) ), 'Race details' ), 'race screen renders without WooCommerce' );
		VBR_Races::delete( $race_id );

		$check( false !== strpos( do_shortcode( '[balloon_map]' ), 'data-vbr-map' ), '[balloon_map] renders' );
		$check( false !== strpos( do_shortcode( '[balloon_leaderboard]' ), 'data-vbr-leaderboard' ), '[balloon_leaderboard] renders' );
		break;

	// After WooCommerce is active: exactly one ready-to-sell entry product.
	case 'fresh-product':
		$check( class_exists( 'WooCommerce' ), 'WooCommerce is active' );
		$check( array() === array_values( (array) get_option( 'vbr_pending_setup', array() ) ), 'no setup tasks left waiting' );
		$products = VBR_Product_Type::all_products();
		$check( 1 === count( $products ), 'exactly one entry product (found ' . count( $products ) . ')' );
		if ( $products ) {
			$product = $products[0];
			$check( 'Balloon Race Entry' === $product->get_name(), 'named Balloon Race Entry' );
			$check( 'publish' === $product->get_status(), 'published' );
			$check( '3' === (string) $product->get_price(), 'priced at 3' );
			$check( $product->is_virtual(), 'virtual' );
			$check( $product->is_sold_individually(), 'one balloon per basket line' );
			$check( VBR_Product_Type::TYPE === $product->get_type(), 'Balloon race entry product type' );
		}
		$check( false !== strpos( $render( array( new VBR_Pages(), 'render_panel' ) ), 'vbr-entry-products' ), 'Settings lists the entry product' );
		break;

	// After deactivating and reactivating the plugin.
	case 'one-product':
		$check( 1 === count( VBR_Product_Type::all_products() ), 'still exactly one entry product after reactivation' );
		$check( 1 === count( get_posts( array( 'post_type' => 'page', 'name' => 'balloon-race', 'post_status' => 'any' ) ) ), 'still exactly one tracking page' );
		break;

	// A race end to end: shop, order → balloon, movement, REST, team standings.
	case 'race-flow':
		global $wpdb;

		$settings( array( 'weather_enabled' => 0 ) ); // No network calls: balloons follow the default heading.
		$product = VBR_Product_Type::all_products()[0];

		$check( ! wc_get_product( $product->get_id() )->is_purchasable(), 'entry product not purchasable with no race open' );

		$open = VBR_Races::save(
			array(
				'name'       => 'Smoke Open Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		$check( wc_get_product( $product->get_id() )->is_purchasable(), 'entry product purchasable while a race takes entries' );

		// Order → balloon.
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( 'Balloon Name', 'Smoke Signal', true );
		$item->add_meta_data( 'Balloon Color', 'Red', true );
		$item->add_meta_data( 'Helium Fill Strategy', 'Balanced', true );
		$item->add_meta_data( '_vbr_race_id', $open, true );
		$order->add_item( $item );
		$order->set_billing_first_name( 'Sam' );
		$order->set_billing_last_name( 'Smoke' );
		$order->set_billing_email( 'sam.smoke@example.com' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$balloon = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VBR_Balloons::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( (bool) $balloon, 'paid order creates a balloon' );
		$check( $balloon && (int) $balloon->race_id === $open, 'balloon is in the race it was bought for' );
		$check( $balloon && 'Sam S.' === VBR_Balloons::public_owner_name( $balloon ), 'public owner name is first name + initial' );

		// Movement in a flying race.
		$flying = VBR_Races::save(
			array(
				'name'        => 'Smoke Flying Race',
				'launch_lat'  => 51.4885,
				'launch_lng'  => -2.58,
				'launch_at'   => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'flight_mode' => VBR_Flight_Modes::JETSTREAM,
			)
		);
		$wpdb->insert(
			VBR_Balloons::table(),
			array(
				'race_id'       => $flying,
				'owner_name'    => 'Pat Test',
				'owner_email'   => 'pat@example.com',
				'balloon_name'  => 'Up And Away',
				'balloon_color' => 'Blue',
				'helium_level'  => 'Balanced',
				'latitude'      => 51.4885,
				'longitude'     => -2.58,
				'start_lat'     => 51.4885,
				'start_lng'     => -2.58,
				'trail_json'    => '[[51.4885,-2.58]]',
				'created_at'    => current_time( 'mysql' ),
			)
		);
		$id      = (int) $wpdb->insert_id;
		$summary = VBR_Cron::tick( 6, array( 'race_id' => $flying, 'source' => 'cli' ) );
		$moved   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VBR_Balloons::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( 1 === $summary['advanced'] || 1 === $summary['burst'], 'tick moves the flying balloon' );
		$check( (float) $moved->distance_km > 0, 'trail distance grows' );
		$check( (float) $moved->from_start_km > 0, 'distance from launch grows' );
		$check( abs( (float) $moved->flight_hours - 6 ) < 0.01, 'flight hours recorded' );

		// REST.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/vbr/v1/races' ) );
		$check( 200 === $response->get_status(), 'GET /vbr/v1/races responds' );
		$request = new WP_REST_Request( 'GET', '/vbr/v1/balloons' );
		$request->set_param( 'race', $flying );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$check( 200 === $response->get_status() && 1 === count( $data['balloons'] ), 'GET /vbr/v1/balloons returns the race\'s balloon' );
		$check( isset( $data['ranking'], $data['balloons'][0]['from_start'], $data['balloons'][0]['travelled'] ), 'REST includes ranking and both distances' );
		$check( ! isset( $data['balloons'][0]['owner_email'] ) && false === strpos( wp_json_encode( $data ), 'pat@example.com' ), 'REST never exposes emails' );

		// Teams.
		$settings( array( 'teams_enabled' => 1 ) );
		VBR_Teams::save_for_race(
			$flying,
			VBR_Teams::clean_rows(
				array(
					array( 'name' => 'Oak', 'color' => '#2f855a' ),
					array( 'name' => 'Ash', 'color' => '#2b6cb0' ),
				)
			)
		);
		$check( 2 === count( VBR_Teams::for_race( $flying ) ), 'race teams saved' );
		$check( false !== strpos( do_shortcode( '[balloon_teams race="' . $flying . '"]' ), 'Team standings' ), '[balloon_teams] renders standings' );
		$settings( array( 'teams_enabled' => 0 ) );
		$check( '' === do_shortcode( '[balloon_teams race="' . $flying . '"]' ), 'teams hidden when switched off' );

		$check( false !== strpos( $render_race_screen( VBR_Races::get( $flying ) ), 'vbr-race-products' ), 'race screen lists entry products' );
		break;

	default:
		WP_CLI::error( "Unknown stage: {$stage}" );
}

if ( $failures ) {
	WP_CLI::error( "{$stage}: " . count( $failures ) . ' check(s) failed' );
}

WP_CLI::success( "{$stage}: all checks passed" );
