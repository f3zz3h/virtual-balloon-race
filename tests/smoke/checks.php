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
	$method = new ReflectionMethod( 'LVBR_Admin_Races', 'render_form' );
	$method->setAccessible( true );
	return $render(
		static function () use ( $method, $race ) {
			$method->invoke( new LVBR_Admin_Races(), $race );
		}
	);
};

$settings = static function ( $changes ) {
	update_option( LVBR_Settings::OPTION, array_merge( (array) get_option( LVBR_Settings::OPTION, array() ), $changes ) );
	LVBR_Settings::flush_cache();
};

wp_set_current_user( 1 );

// Capture mail instead of sending it (the test server has no mail program).
$GLOBALS['lvbr_smoke_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['lvbr_smoke_mail'][] = $atts;
		return true;
	},
	10,
	2
);

switch ( $stage ) {
	// Plugin activated on a fresh site before WooCommerce.
	case 'woo-inactive':
		$check( ! class_exists( 'WooCommerce' ), 'WooCommerce is not active yet' );
		$check( LVBR_DB_VERSION === get_option( 'lvbr_db_version' ), 'database installed at ' . LVBR_DB_VERSION );
		$page = LVBR_Pages::tracking_page_id();
		$check( $page > 0, 'tracking page created' );
		$check( 'balloon-race' === get_post_field( 'post_name', $page ), 'tracking page slug is balloon-race' );
		$check( in_array( 'entry_product', (array) get_option( 'lvbr_pending_setup', array() ), true ), 'entry product waits for WooCommerce' );
		$check( false !== strpos( $render( array( new LVBR_Pages(), 'render_panel' ) ), 'id="lvbr-pages"' ), 'Settings → Pages and products renders' );

		$race_id = LVBR_Races::save(
			array(
				'name'       => 'Smoke Screen Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);
		$check( is_int( $race_id ), 'race can be created without WooCommerce' );
		$check( false !== strpos( $render_race_screen( LVBR_Races::get( $race_id ) ), 'Race details' ), 'race screen renders without WooCommerce' );
		LVBR_Races::delete( $race_id );

		$check( false !== strpos( do_shortcode( '[lvbr_map]' ), 'data-lvbr-map' ), '[lvbr_map] renders' );
		$check( false !== strpos( do_shortcode( '[lvbr_leaderboard]' ), 'data-lvbr-leaderboard' ), '[lvbr_leaderboard] renders' );
		break;

	// After WooCommerce is active: exactly one ready-to-sell entry product.
	case 'fresh-product':
		$check( class_exists( 'WooCommerce' ), 'WooCommerce is active' );
		$check( array() === array_values( (array) get_option( 'lvbr_pending_setup', array() ) ), 'no setup tasks left waiting' );
		$products = LVBR_Product_Type::all_products();
		$check( 1 === count( $products ), 'exactly one entry product (found ' . count( $products ) . ')' );
		if ( $products ) {
			$product = $products[0];
			$check( 'Balloon Race Entry' === $product->get_name(), 'named Balloon Race Entry' );
			$check( 'publish' === $product->get_status(), 'published' );
			$check( '3' === (string) $product->get_price(), 'priced at 3' );
			$check( $product->is_virtual(), 'virtual' );
			$check( $product->is_sold_individually(), 'one balloon per basket line' );
			$check( LVBR_Product_Type::TYPE === $product->get_type(), 'Balloon race entry product type' );
		}
		$check( false !== strpos( $render( array( new LVBR_Pages(), 'render_panel' ) ), 'lvbr-entry-products' ), 'Settings lists the entry product' );
		break;

	// After deactivating and reactivating the plugin.
	case 'one-product':
		$check( 1 === count( LVBR_Product_Type::all_products() ), 'still exactly one entry product after reactivation' );
		$check( 1 === count( get_posts( array( 'post_type' => 'page', 'name' => 'balloon-race', 'post_status' => 'any' ) ) ), 'still exactly one tracking page' );
		break;

	// A race end to end: shop, order → balloon, movement, REST, team standings.
	case 'race-flow':
		global $wpdb;

		$settings( array( 'weather_enabled' => 0 ) ); // No network calls: balloons follow the default heading.
		$product = LVBR_Product_Type::all_products()[0];

		$check( ! wc_get_product( $product->get_id() )->is_purchasable(), 'entry product not purchasable with no race open' );

		$open = LVBR_Races::save(
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
		$item->add_meta_data( '_lvbr_race_id', $open, true );
		$order->add_item( $item );
		$order->set_billing_first_name( 'Sam' );
		$order->set_billing_last_name( 'Smoke' );
		$order->set_billing_email( 'sam.smoke@example.com' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$balloon = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LVBR_Balloons::table() . ' WHERE order_id = %d', $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( (bool) $balloon, 'paid order creates a balloon' );
		$check( $balloon && (int) $balloon->race_id === $open, 'balloon is in the race it was bought for' );
		$check( $balloon && 'Sam S.' === LVBR_Balloons::public_owner_name( $balloon ), 'public owner name is first name + initial' );

		// Movement in a flying race.
		$flying = LVBR_Races::save(
			array(
				'name'        => 'Smoke Flying Race',
				'launch_lat'  => 51.4885,
				'launch_lng'  => -2.58,
				'launch_at'   => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ),
				'flight_mode' => LVBR_Flight_Modes::JETSTREAM,
			)
		);
		$wpdb->insert(
			LVBR_Balloons::table(),
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
		$summary = LVBR_Cron::tick( 6, array( 'race_id' => $flying, 'source' => 'cli' ) );
		$moved   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LVBR_Balloons::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( 1 === $summary['advanced'] || 1 === $summary['burst'], 'tick moves the flying balloon' );
		$check( (float) $moved->distance_km > 0, 'trail distance grows' );
		$check( (float) $moved->from_start_km > 0, 'distance from launch grows' );
		$check( abs( (float) $moved->flight_hours - 6 ) < 0.01, 'flight hours recorded' );

		// REST.
		$response = rest_do_request( new WP_REST_Request( 'GET', '/lvbr/v1/races' ) );
		$check( 200 === $response->get_status(), 'GET /lvbr/v1/races responds' );
		$request = new WP_REST_Request( 'GET', '/lvbr/v1/balloons' );
		$request->set_param( 'race', $flying );
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$check( 200 === $response->get_status() && 1 === count( $data['balloons'] ), 'GET /lvbr/v1/balloons returns the race\'s balloon' );
		$check( isset( $data['ranking'], $data['balloons'][0]['from_start'], $data['balloons'][0]['travelled'] ), 'REST includes ranking and both distances' );
		$check( ! isset( $data['balloons'][0]['owner_email'] ) && false === strpos( wp_json_encode( $data ), 'pat@example.com' ), 'REST never exposes emails' );

		// Teams.
		$settings( array( 'teams_enabled' => 1 ) );
		LVBR_Teams::save_for_race(
			$flying,
			LVBR_Teams::clean_rows(
				array(
					array( 'name' => 'Oak', 'color' => '#2f855a' ),
					array( 'name' => 'Ash', 'color' => '#2b6cb0' ),
				)
			)
		);
		$check( 2 === count( LVBR_Teams::for_race( $flying ) ), 'race teams saved' );
		$check( false !== strpos( do_shortcode( '[lvbr_teams race="' . $flying . '"]' ), 'Team standings' ), '[lvbr_teams] renders standings' );
		$settings( array( 'teams_enabled' => 0 ) );
		$check( '' === do_shortcode( '[lvbr_teams race="' . $flying . '"]' ), 'teams hidden when switched off' );

		$check( false !== strpos( $render_race_screen( LVBR_Races::get( $flying ) ), 'lvbr-race-products' ), 'race screen lists entry products' );
		break;

	// Refunds, cancellations and hand withdrawals take balloons out of the race and back again.
	case 'refunds':
		global $wpdb;

		$product = LVBR_Product_Type::all_products()[0];
		$race    = LVBR_Races::save(
			array(
				'name'       => 'Smoke Refund Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);

		// Two balloons in one order.
		$order = wc_create_order();
		foreach ( array( 'Refund Me', 'Keep Me' ) as $name ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( 1 );
			$item->set_subtotal( 3 );
			$item->set_total( 3 );
			$item->add_meta_data( 'Balloon Name', $name, true );
			$item->add_meta_data( 'Balloon Color', 'Red', true );
			$item->add_meta_data( 'Helium Fill Strategy', 'Balanced', true );
			$item->add_meta_data( '_lvbr_race_id', (string) $race, true );
			$order->add_item( $item );
		}
		$order->set_billing_first_name( 'Rae' );
		$order->set_billing_last_name( 'Fund' );
		$order->set_billing_email( 'rae.fund@example.com' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$balloon = static function ( $name ) use ( $wpdb, $order ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LVBR_Balloons::table() . ' WHERE order_id = %d AND balloon_name = %s', $order->get_id(), $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		$public  = static function () use ( $race ) {
			$request = new WP_REST_Request( 'GET', '/lvbr/v1/balloons' );
			$request->set_param( 'race', $race );
			return wp_list_pluck( rest_do_request( $request )->get_data()['balloons'], 'name' );
		};
		$active  = static function ( $name ) use ( $balloon ) {
			$row = $balloon( $name );
			return $row && null === $row->withdrawn_at;
		};

		$check( $active( 'Refund Me' ) && $active( 'Keep Me' ), 'paid order with two entries makes two balloons' );
		$check( (int) $balloon( 'Refund Me' )->order_item_id > 0, 'balloons remember their order line' );

		// Refund one line.
		$lines   = array_values( $order->get_items() );
		$refund  = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 3,
				'line_items' => array(
					$lines[0]->get_id() => array(
						'qty'          => 1,
						'refund_total' => 3,
					),
				),
			)
		);
		$check( ! is_wp_error( $refund ), 'line refund created' );
		$check( ! $active( 'Refund Me' ) && 'refunded' === $balloon( 'Refund Me' )->withdrawn_reason, 'refunded line\'s balloon is withdrawn (refunded)' );
		$check( $active( 'Keep Me' ), 'the other balloon stays in the race' );
		$check( array( 'Keep Me' ) === $public(), 'map/REST shows only the balloon still in the race' );
		$check( false === strpos( do_shortcode( '[lvbr_leaderboard race="' . $race . '"]' ), 'Refund Me' ), 'leaderboard hides the withdrawn balloon' );

		// Delete the refund: the balloon comes back.
		if ( ! is_wp_error( $refund ) ) {
			$refund_id = $refund->get_id();
			$refund->delete( true );
			do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() ); // As WooCommerce's admin does.
		}
		$check( $active( 'Refund Me' ), 'deleting the refund reinstates the balloon' );

		// A money-only refund (no lines) leaves balloons alone.
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1,
			)
		);
		$check( $active( 'Refund Me' ) && $active( 'Keep Me' ), 'a goodwill refund with no lines withdraws nothing' );

		// Cancel, then back to processing.
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );
		$check( ! $active( 'Refund Me' ) && ! $active( 'Keep Me' ) && 'cancelled' === $balloon( 'Keep Me' )->withdrawn_reason, 'cancelling the order withdraws both (cancelled)' );
		$check( array() === $public(), 'map/REST shows none of them' );
		$order->update_status( 'processing' );
		$check( $active( 'Refund Me' ) && $active( 'Keep Me' ), 'reopening the order reinstates both' );

		// Withdrawn by an organiser: stays out even when the order changes.
		LVBR_Balloons::withdraw( array( (int) $balloon( 'Keep Me' )->id ), 'admin' );
		$order->update_status( 'completed' );
		$check( ! $active( 'Keep Me' ) && 'admin' === $balloon( 'Keep Me' )->withdrawn_reason, 'a hand-withdrawn balloon stays out when the order changes' );
		$check( null === LVBR_Balloons::rank( $balloon( 'Keep Me' ) ), 'withdrawn balloons have no position' );

		// Withdrawn balloons don't move.
		$wpdb->update( LVBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $race ) );
		LVBR_Races::flush();
		LVBR_Cron::tick( 6, array( 'race_id' => $race, 'source' => 'cli' ) );
		$check( 0.0 === (float) $balloon( 'Keep Me' )->distance_km, 'a withdrawn balloon doesn\'t move' );
		$check( (float) $balloon( 'Refund Me' )->distance_km > 0, 'the active balloon still moves' );

		// Failed payment.
		$order->update_status( 'failed' );
		$check( ! $active( 'Refund Me' ) && 'failed' === $balloon( 'Refund Me' )->withdrawn_reason, 'a failed payment withdraws the balloon (failed)' );
		break;

	// Launch and results emails to entrants.
	case 'emails':
		global $wpdb;

		// Run queued background actions now (Action Scheduler), as WP-Cron would.
		$run_queue = static function () {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => LVBR_Race_Emails::SEND_HOOK,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			);
			foreach ( $ids as $id ) {
				ActionScheduler::runner()->process_action( $id );
			}
			return count( $ids );
		};

		// Earlier stages' races may have emails queued; send those first so only this race's emails are counted.
		LVBR_Race_Emails::check();
		$run_queue();

		$race = LVBR_Races::save(
			array(
				'name'       => 'Smoke Email Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$add  = static function ( $name, $email, $owner ) use ( $wpdb, $race ) {
			$wpdb->insert(
				LVBR_Balloons::table(),
				array(
					'race_id'       => $race,
					'owner_name'    => $owner,
					'owner_email'   => $email,
					'balloon_name'  => $name,
					'balloon_color' => 'Green',
					'helium_level'  => 'Balanced',
					'latitude'      => 51.4885,
					'longitude'     => -2.58,
					'start_lat'     => 51.4885,
					'start_lng'     => -2.58,
					'trail_json'    => '[[51.4885,-2.58]]',
					'created_at'    => current_time( 'mysql' ),
				)
			);
			return (int) $wpdb->insert_id;
		};
		$add( 'Alpha', 'ann.entrant@example.com', 'Ann Entrant' );
		$add( 'Bravo', 'Ann.Entrant@example.com', 'Ann Entrant' );
		$add( 'Charlie', 'bob.entrant@example.com', 'Bob Entrant' );
		$gone = $add( 'Withdrawn One', 'cat.entrant@example.com', 'Cat Entrant' );
		LVBR_Balloons::withdraw( array( $gone ), 'refunded' );

		$mine = static function () {
			return array_values(
				array_filter(
					$GLOBALS['lvbr_smoke_mail'],
					static function ( $mail ) {
						return false !== strpos( $mail['subject'], 'Smoke Email Race' );
					}
				)
			);
		};
		$to   = static function ( $mails ) {
			$list = array();
			foreach ( $mails as $mail ) {
				$list[] = strtolower( implode( ',', (array) $mail['to'] ) );
			}
			sort( $list );
			return $list;
		};

		LVBR_Race_Emails::check();
		$run_queue();
		$check( array() === $mine(), 'no emails before launch' );

		// Launch.
		$wpdb->update( LVBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $race ) );
		LVBR_Races::flush();
		LVBR_Race_Emails::check();
		$queued = $run_queue();
		$mails  = $mine();
		$check( 2 === $queued, 'launch emails queued in the background, one per person (' . $queued . ')' );
		$check( array( 'ann.entrant@example.com', 'bob.entrant@example.com' ) === $to( $mails ), 'launch email to each entrant once; none for the withdrawn balloon' );
		$ann = array(
			'message' => '',
			'subject' => '',
		);
		foreach ( $mails as $mail ) {
			if ( false !== stripos( implode( ',', (array) $mail['to'] ), 'ann.' ) ) {
				$ann = $mail;
			}
		}
		$check( 'Your balloon has launched in Smoke Email Race!' === $ann['subject'], 'launch subject' );
		$check( false !== strpos( $ann['message'], 'Alpha' ) && false !== strpos( $ann['message'], 'Bravo' ) && false !== strpos( $ann['message'], 'Hi Ann' ), 'one email lists both of Ann\'s balloons and greets her' );
		$check( false !== strpos( $ann['message'], 'balloon=' ), 'launch email links to each balloon on the map' );
		$check( false === strpos( implode( ' ', wp_list_pluck( $mails, 'message' ) ), 'Withdrawn One' ), 'withdrawn balloon not mentioned' );

		LVBR_Race_Emails::check();
		$check( 0 === $run_queue() && 2 === count( $mine() ), 'launch emails are sent only once' );

		// Finish.
		LVBR_Cron::tick( 3, array( 'race_id' => $race, 'source' => 'cli' ) );
		LVBR_Races::set_time( $race, 'end_at', time() - 30 );
		LVBR_Race_Emails::check();
		$run_queue();
		$results = array_slice( $mine(), 2 );
		$check( 2 === count( $results ) && false !== strpos( $results[0]['subject'], 'Final results: Smoke Email Race' ), 'results email to each entrant when the race finishes' );
		$check( (bool) preg_match( '/#\d+ of 3/', implode( ' ', wp_list_pluck( $results, 'message' ) ) ), 'results show each balloon\'s position out of 3' );
		$check( false !== strpos( implode( ' ', wp_list_pluck( $results, 'message' ) ), 'km from launch' ), 'results show distance from launch' );

		// Switched off in WooCommerce → Settings → Emails.
		update_option( 'woocommerce_lvbr_balloon_launched_settings', array( 'enabled' => 'no' ) );
		$off = LVBR_Races::save(
			array(
				'name'       => 'Smoke Email Race Off',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		$wpdb->update( LVBR_Balloons::table(), array( 'race_id' => $off ), array( 'balloon_name' => 'Charlie' ) );
		$before = count( $GLOBALS['lvbr_smoke_mail'] );
		WC()->mailer()->init(); // Reload email settings, as on a new request.
		LVBR_Race_Emails::check();
		$run_queue();
		$check( count( $GLOBALS['lvbr_smoke_mail'] ) === $before, 'no launch email when it is switched off in WooCommerce' );

		$screen = $render_race_screen( LVBR_Races::get( $race ) );
		$check( false !== strpos( $screen, 'Results email: sent to 2 people' ), 'race screen shows the email status' );
		break;

	// Abandoned logo uploads and the basket check.
	case 'housekeeping':
		$logo = static function ( $tag, $days_old ) {
			$id = wp_insert_attachment(
				array(
					'post_title'     => "Smoke logo {$tag} {$days_old}d",
					'post_mime_type' => 'image/png',
					'post_status'    => 'inherit',
					'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS ),
					'post_date'      => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS ) ),
				)
			);
			update_post_meta( $id, '_lvbr_logo_upload', $tag );
			return (int) $id;
		};
		$old_cart     = $logo( 'cart', 8 );
		$new_cart     = $logo( 'cart', 2 );
		$old_ordered  = $logo( 'ordered', 30 );
		$deleted      = LVBR_Housekeeping::delete_abandoned_logos();
		$check( 1 === $deleted && ! get_post( $old_cart ), 'a week-old logo from an abandoned basket is deleted' );
		$check( (bool) get_post( $new_cart ), 'a recent basket logo is kept' );
		$check( (bool) get_post( $old_ordered ), 'an ordered logo is kept, however old' );
		$check( (bool) wp_next_scheduled( LVBR_Housekeeping::HOOK ), 'the daily clean-up is scheduled' );

		// A basket entry whose logo has gone is removed, with a notice.
		wc_load_cart();
		WC()->cart->empty_cart();
		$product = LVBR_Product_Type::all_products()[0];
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'lvbr_balloon_name' => 'Logo Gone', 'lvbr_logo_id' => $old_cart, 'lvbr_unique_key' => 'a' ) );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'lvbr_balloon_name' => 'No Logo', 'lvbr_unique_key' => 'b' ) );
		$check( 2 === count( WC()->cart->get_cart() ), 'two balloons in the basket' );
		do_action( 'woocommerce_check_cart_items' );
		$left = wp_list_pluck( WC()->cart->get_cart(), 'lvbr_balloon_name' );
		$check( array( 'No Logo' ) === array_values( $left ), 'the balloon whose logo was cleaned up is taken out of the basket' );
		$check( false !== strpos( wp_json_encode( wc_get_notices( 'error' ) ), 'Logo Gone' ), 'the buyer is told to add it again' );
		wc_clear_notices();
		WC()->cart->empty_cart();
		break;

	// Entry limits: places taken (paid and unpaid), sold out, basket limits.
	case 'limits':
		global $wpdb;

		$product = LVBR_Product_Type::all_products()[0];
		$races   = new LVBR_Race_Entries();

		// Only this race takes entries during this stage.
		foreach ( LVBR_Races::open_for_entries() as $other ) {
			$wpdb->update( LVBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $other->id ) );
		}
		LVBR_Races::flush();

		$race_id = LVBR_Races::save(
			array(
				'name'        => 'Smoke Limited Race',
				'launch_lat'  => 51.4885,
				'launch_lng'  => -2.58,
				'launch_at'   => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
				'entry_limit' => 3,
			)
		);
		$race    = static function () use ( $race_id ) {
			LVBR_Races::flush();
			LVBR_Race_Entries::flush_counts();
			return LVBR_Races::get( $race_id );
		};
		$paid    = static function ( $name ) use ( $wpdb, $race_id ) {
			$wpdb->insert(
				LVBR_Balloons::table(),
				array(
					'race_id'       => $race_id,
					'owner_name'    => 'Lim Ited',
					'owner_email'   => 'limited@example.com',
					'balloon_name'  => $name,
					'balloon_color' => 'Red',
					'helium_level'  => 'Balanced',
					'latitude'      => 51.4885,
					'longitude'     => -2.58,
					'start_lat'     => 51.4885,
					'start_lng'     => -2.58,
					'trail_json'    => '[[51.4885,-2.58]]',
					'created_at'    => current_time( 'mysql' ),
				)
			);
			return (int) $wpdb->insert_id;
		};

		$check( 3 === LVBR_Race_Entries::places_left( $race() ), 'a new race with a limit of 3 has 3 places' );
		$first = $paid( 'Limit One' );
		$check( 2 === LVBR_Race_Entries::places_left( $race() ), 'a paid balloon takes a place' );

		// An unpaid bank-transfer order holds a place.
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( '_lvbr_race_id', (string) $race_id, true );
		$order->add_item( $item );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'on-hold' );
		$check( 1 === LVBR_Race_Entries::places_left( $race() ), 'an on-hold (unpaid) order holds a place' );
		$check( wc_get_product( $product->get_id() )->is_purchasable(), 'still purchasable with 1 place left' );
		$field = $render(
			static function () use ( $races, $product ) {
				$races->output_race_field( $product );
			}
		);
		$check( false !== strpos( $field, 'Only 1 place left!' ), 'product page says "Only 1 place left!"' );

		// Basket: one entry fits, a second is refused.
		wc_load_cart();
		WC()->cart->empty_cart();
		$_POST['lvbr_race_id'] = (string) $race_id;
		$check( true === $races->validate( true, $product->get_id() ), 'the last place can go in the basket' );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'lvbr_balloon_name' => 'Last Place', 'lvbr_race_id' => $race_id, 'lvbr_unique_key' => 'l1' ) );
		$check( false === $races->validate( true, $product->get_id() ), 'a second entry is refused when only one place is left' );
		wc_clear_notices();

		// Someone else takes the last place: sold out.
		$paid( 'Limit Two' );
		$check( 0 === LVBR_Race_Entries::places_left( $race() ), 'sold out' );
		$check( ! wc_get_product( $product->get_id() )->is_purchasable(), 'not purchasable when sold out' );
		ob_start();
		$GLOBALS['product'] = wc_get_product( $product->get_id() );
		$races->closed_message();
		$check( false !== strpos( (string) ob_get_clean(), 'Sorry, Smoke Limited Race is sold out.' ), 'product page says it is sold out' );
		do_action( 'woocommerce_check_cart_items' );
		$check( 0 === count( WC()->cart->get_cart() ), 'the basket entry is removed once the race sells out' );
		$check( false !== strpos( wp_json_encode( wc_get_notices( 'error' ) ), 'has sold out' ), 'the buyer is told it sold out' );
		wc_clear_notices();
		$check( false !== strpos( do_shortcode( '[lvbr_races]' ), 'Sold out' ), 'race list shows Sold out' );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/lvbr/v1/races' ) );
		$listed   = wp_list_filter( $response->get_data()['races'], array( 'id' => $race_id ) );
		$check( $listed && 0 === array_values( $listed )[0]['places_left'] && 3 === array_values( $listed )[0]['entry_limit'], 'REST shows the limit and places left' );

		// Cancelling the unpaid order frees its place.
		$order->update_status( 'cancelled' );
		$check( 1 === LVBR_Race_Entries::places_left( $race() ), 'a cancelled unpaid order frees its place' );
		LVBR_Balloons::withdraw( array( $first ), 'refunded' );
		$check( 2 === LVBR_Race_Entries::places_left( $race() ), 'a withdrawn (refunded) balloon frees its place' );

		$check( false !== strpos( $render_race_screen( $race() ), '1 of 3 (2 left)' ), 'race screen shows entries of the limit' );
		$check( null === LVBR_Race_Entries::places_left( LVBR_Races::get( LVBR_Races::save( array( 'name' => 'Smoke Unlimited', 'launch_lat' => 51.4, 'launch_lng' => -2.5, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ) ) ), 'no limit means unlimited places' );
		unset( $_POST['lvbr_race_id'] );
		WC()->cart->empty_cart();
		break;

	// Demo race: made-up balloons flown for three days; removed cleanly.
	case 'demo':
		global $wpdb;

		$race_id  = LVBR_Demo::create( 12 );
		$race     = LVBR_Races::get( $race_id );
		$balloons = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LVBR_Balloons::table() . ' WHERE race_id = %d', $race_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( $race && LVBR_Races::FLYING === LVBR_Races::status( $race ), 'demo race created and flying' );
		$check( 12 === count( $balloons ), 'demo race has the requested balloons' );
		$check( count( array_filter( wp_list_pluck( $balloons, 'from_start_km' ) ) ) >= 10, 'demo balloons have already flown' );
		$check( array( '' ) === array_values( array_unique( wp_list_pluck( $balloons, 'owner_email' ) ) ), 'demo entrants have no email addresses' );
		$check( array() === LVBR_Race_Emails::recipients( $race_id ), 'nobody would be emailed about the demo race' );
		$check( array( $race_id ) === LVBR_Demo::race_ids(), 'demo race is remembered for removal' );
		$check( false !== strpos( $render( array( new LVBR_Demo(), 'render_panel' ) ), 'Remove demo races' ), 'Settings shows the demo race panel' );

		// Renewal: nothing while days are left; a fresh race (same size) replaces it near the end.
		$check( LVBR_Demo::renews() && 0 === LVBR_Demo::renew_if_due(), 'demo race with days left is not renewed' );
		LVBR_Races::set_time( $race_id, 'end_at', time() + HOUR_IN_SECONDS );
		$renewed = LVBR_Demo::renew_if_due();
		$check( $renewed && $renewed !== $race_id && ! LVBR_Races::get( $race_id ), 'demo race near its end is replaced' );
		$check( array( $renewed ) === LVBR_Demo::race_ids() && 12 === LVBR_Races::balloon_count( $renewed ), 'the fresh demo race has as many balloons' );
		$check( LVBR_Races::FLYING === LVBR_Races::status( LVBR_Races::get( $renewed ) ), 'the fresh demo race is flying' );
		update_option( LVBR_Demo::RENEW_OPTION, 'no' );
		LVBR_Races::set_time( $renewed, 'end_at', time() - HOUR_IN_SECONDS );
		$check( 0 === LVBR_Demo::renew_if_due() && array( $renewed ) === LVBR_Demo::race_ids(), 'no renewal when switched off' );
		delete_option( LVBR_Demo::RENEW_OPTION );

		$check( 1 === LVBR_Demo::remove() && ! LVBR_Races::get( $renewed ), 'demo race removed' );
		$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LVBR_Balloons::table() . ' WHERE race_id = %d', $renewed ) ), 'demo balloons removed' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		break;

	// The product editor: entry products get their tabs without hiding anything from other product types.
	case 'product-editor':
		require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-product-data.php';
		$method = new ReflectionMethod( 'WC_Meta_Box_Product_Data', 'get_product_data_tabs' );
		$method->setAccessible( true );
		$tabs  = $method->invoke( null );
		$class = static function ( $tab ) use ( $tabs ) {
			return isset( $tabs[ $tab ]['class'] ) ? (array) $tabs[ $tab ]['class'] : array();
		};
		$check( ! array_filter( $class( 'general' ), static function ( $c ) { return 0 === strpos( $c, 'show_if_' ); } ), 'the General tab (prices) still shows for every product type' );
		$check( in_array( 'show_if_simple', $class( 'inventory' ), true ) && in_array( 'show_if_lvbr_balloon_entry', $class( 'inventory' ), true ), 'the Inventory tab shows for simple products and entries' );
		$check( in_array( 'hide_if_lvbr_balloon_entry', $class( 'shipping' ), true ) && ! in_array( 'show_if_simple', $class( 'shipping' ), true ), 'Shipping is hidden for entries only' );
		$check( isset( $tabs['lvbr_balloon_race'] ) && array( 'show_if_lvbr_balloon_entry' ) === $class( 'lvbr_balloon_race' ), 'the Balloon race tab shows for entries only' );
		break;

	// The entry product only shows in the shop once a race is planned for it.
	case 'planned-visibility':
		global $wpdb;

		// No race planned: everything has launched (or finished).
		foreach ( LVBR_Races::all() as $existing ) {
			if ( in_array( LVBR_Races::status( $existing ), array( LVBR_Races::UPCOMING, LVBR_Races::ON_SALE ), true ) ) {
				$wpdb->update( LVBR_Races::table(), array( 'sales_open_at' => null, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $existing->id ) );
			}
		}
		LVBR_Races::flush();

		$product = LVBR_Product_Type::all_products()[0];
		$pid     = $product->get_id();
		$entries = new LVBR_Race_Entries();
		// Is the product in each place that lists products to visitors?
		$listed = static function () use ( $pid, $entries, $product ) {
			$shop = new WP_Query();
			$shop->set( 'post_type', 'product' );
			$entries->hide_from_query( $shop );

			$search          = new WP_Query();
			$main            = $GLOBALS['wp_the_query'];
			$GLOBALS['wp_the_query'] = $search; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$search->query( array( 's' => $product->get_name(), 'post_type' => 'any', 'fields' => 'ids', 'post_status' => 'publish' ) );
			$GLOBALS['wp_the_query'] = $main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			return array(
				'visible'   => wc_get_product( $pid )->is_visible(),
				'shop'      => ! in_array( $pid, (array) $shop->get( 'post__not_in' ), true ),
				'search'    => in_array( $pid, array_map( 'intval', $search->posts ), true ),
				'shortcode' => ! in_array( $pid, (array) ( apply_filters( 'woocommerce_shortcode_products_query', array( 'post_type' => 'product' ), array(), 'products' )['post__not_in'] ?? array() ), true ),
				'blocks'    => ! in_array( $pid, (array) ( apply_filters( 'query_loop_block_query_vars', array( 'post_type' => 'product' ), null, 1 )['post__not_in'] ?? array() ), true ),
				'sitemap'   => ! in_array( $pid, (array) ( apply_filters( 'wp_sitemaps_posts_query_args', array(), 'product' )['post__not_in'] ?? array() ), true ),
			);
		};
		$none = array_fill_keys( array( 'visible', 'shop', 'search', 'shortcode', 'blocks', 'sitemap' ), false );
		$all  = array_fill_keys( array_keys( $none ), true );

		$check( $none === $listed(), 'with no race planned, the entry product isn\'t listed anywhere (shop, search, [products], blocks, sitemap)' );
		$check( $product->get_id() === LVBR_Product_Type::all_products()[0]->get_id() && ! wc_get_product( $pid )->is_purchasable(), 'the plugin still finds it (no duplicate product), and it can\'t be bought' );

		// An upcoming race (entries not open yet): listed, not purchasable.
		$upcoming = LVBR_Races::save( array( 'name' => 'Smoke Planned Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'sales_open_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		LVBR_Races::flush();
		$check( $all === $listed() && ! wc_get_product( $pid )->is_purchasable(), 'once a race is planned it\'s listed everywhere, but not for sale until entries open' );

		// Entries open: for sale.
		$wpdb->update( LVBR_Races::table(), array( 'sales_open_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $upcoming ) );
		LVBR_Races::flush();
		$check( wc_get_product( $pid )->is_visible() && wc_get_product( $pid )->is_purchasable(), 'when entries open it can be bought' );

		// A product locked to a race that has launched stays hidden, even with another race open.
		$launched = LVBR_Races::save( array( 'name' => 'Smoke Launched Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		update_post_meta( $pid, LVBR_Race_Entries::PRODUCT_META, $launched );
		LVBR_Races::flush();
		$check( $none === $listed(), 'a product locked to a launched race is hidden, even while another race is open' );

		update_post_meta( $pid, LVBR_Race_Entries::PRODUCT_META, 0 );
		LVBR_Races::delete( $upcoming );
		LVBR_Races::delete( $launched );
		LVBR_Races::flush();
		break;

	// Two races taking entries at once: when one launches, only its entries leave the basket.
	case 'two-races':
		global $wpdb;

		$product = LVBR_Product_Type::all_products()[0];
		$pid     = $product->get_id();

		foreach ( LVBR_Races::open_for_entries() as $other ) {
			$wpdb->update( LVBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $other->id ) );
		}
		LVBR_Races::flush();

		$open = static function ( $name, $days ) {
			return LVBR_Races::save(
				array(
					'name'       => $name,
					'launch_lat' => 51.4885,
					'launch_lng' => -2.58,
					'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
				)
			);
		};
		$first  = $open( 'Smoke Morning Race', 1 );
		$second = $open( 'Smoke Evening Race', 2 );
		$check( 2 === count( LVBR_Race_Entries::open_races_for( $pid ) ), 'both races take entries for the product' );

		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
		WC()->cart->add_to_cart( $pid, 1, 0, array(), array( 'lvbr_balloon_name' => 'Morning Glory', 'lvbr_race_id' => $first, 'lvbr_unique_key' => 't1' ) );
		WC()->cart->add_to_cart( $pid, 1, 0, array(), array( 'lvbr_balloon_name' => 'Evening Star', 'lvbr_race_id' => $second, 'lvbr_unique_key' => 't2' ) );
		do_action( 'woocommerce_check_cart_items' );
		$check( 2 === count( WC()->cart->get_cart() ) && ! wc_get_notices( 'error' ), 'an entry for each open race stays in the basket' );

		// The first race launches.
		LVBR_Races::set_time( $first, 'launch_at', time() - 60 );
		do_action( 'woocommerce_check_cart_items' );
		$left    = array_values( wp_list_pluck( WC()->cart->get_cart(), 'lvbr_balloon_name' ) );
		$notices = wp_json_encode( wc_get_notices( 'error' ) );
		$check( array( 'Evening Star' ) === $left, 'only the launched race\'s entry leaves the basket' );
		$check( false !== strpos( $notices, 'Smoke Morning Race' ) && false === strpos( $notices, 'Smoke Evening Race' ), 'the notice names only the race that launched' );
		wc_clear_notices();

		$check( wc_get_product( $pid )->is_purchasable(), 'the product can still be bought for the race that is open' );
		$_POST['lvbr_race_id'] = (string) $first;
		$check( null === LVBR_Race_Entries::posted_race( $pid ), 'the launched race can no longer be chosen' );
		$_POST['lvbr_race_id'] = (string) $second;
		$check( $second === (int) LVBR_Race_Entries::posted_race( $pid )->id, 'the open race can still be chosen' );
		unset( $_POST['lvbr_race_id'] );

		update_post_meta( $pid, LVBR_Race_Entries::PRODUCT_META, $first );
		$check( ! wc_get_product( $pid )->is_purchasable(), 'a product locked to the launched race can\'t be bought, even with another race open' );
		update_post_meta( $pid, LVBR_Race_Entries::PRODUCT_META, 0 );

		// The second race launches too.
		LVBR_Races::set_time( $second, 'launch_at', time() - 60 );
		do_action( 'woocommerce_check_cart_items' );
		$check( 0 === count( WC()->cart->get_cart() ) && ! wc_get_product( $pid )->is_purchasable(), 'once both have launched, the basket is empty and entries are closed' );

		wc_clear_notices();
		WC()->cart->empty_cart();
		LVBR_Races::delete( $first );
		LVBR_Races::delete( $second );
		break;

	// The balloon preview replaces the product images, in a block theme as well as a classic one.
	case 'product-preview':
		$products = wc_get_products( array( 'type' => 'lvbr_balloon_entry', 'status' => 'publish', 'limit' => 1 ) );
		$check( (bool) $products, 'an entry product exists for the preview' );
		if ( ! $products ) {
			break;
		}
		if ( ! wp_is_block_theme() ) {
			$check( true, 'skipped: the active theme is not a block theme (' . get_stylesheet() . ')' );
			break;
		}
		$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $products[0]->get_id(), 'post_type' => 'product' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$preview = new LVBR_Product_Preview();
		$preview->maybe_replace_gallery();
		$gallery = apply_filters( 'render_block_woocommerce/product-image-gallery', '<div class="woocommerce-product-gallery">placeholder</div>', array() );
		$check( false !== strpos( $gallery, 'lvbr-product-preview' ) && false === strpos( $gallery, 'placeholder' ), 'block theme (' . get_stylesheet() . '): the preview replaces the product image block' );
		$check( false === has_action( 'woocommerce_before_single_product_summary', array( $preview, 'render' ) ), 'block theme: the preview is not printed a second time by the classic hook' );
		wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query
		break;

	// A logo the page says was chosen but that never arrived (themes that add to the basket with AJAX).
	case 'logo-upload':
		$before = get_option( LVBR_Settings::OPTION, array() );
		$settings( array( 'enable_logo' => 1 ) );
		$check_upload = new ReflectionMethod( 'LVBR_Monetization', 'check_logo_upload' );
		$check_upload->setAccessible( true );
		$monetization = new LVBR_Monetization();

		ob_start();
		$monetization->output_fields();
		$fields = ob_get_clean();
		$check( false !== strpos( $fields, 'name="lvbr_logo_chosen"' ), 'the logo field comes with the "picture chosen" flag' );

		$_FILES = array();
		$_POST  = array();
		$check( null === $check_upload->invoke( $monetization ), 'no picture chosen and none sent: nothing to check' );
		$_POST  = array( 'lvbr_logo_chosen' => '1' );
		$result = $check_upload->invoke( $monetization );
		$check( is_wp_error( $result ) && 'lvbr_logo_missing' === $result->get_error_code(), 'a picture chosen but not sent stops the add to cart with a message' );
		$_FILES = array( 'lvbr_logo' => array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ) );
		$check( is_wp_error( $check_upload->invoke( $monetization ) ), 'the same when the file field is sent empty' );

		$settings( array( 'enable_logo' => 0 ) );
		$check( null === $check_upload->invoke( $monetization ), 'logos switched off: the flag is ignored' );

		$_FILES = array();
		$_POST  = array();
		update_option( LVBR_Settings::OPTION, $before );
		LVBR_Settings::flush_cache();
		break;

	// Whose balloons are whose: logged-in purchases and orders on the account, not typed-in emails.
	case 'owners':
		global $wpdb;

		$alice   = wp_insert_user( array( 'user_login' => 'smoke_alice', 'user_email' => 'alice@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
		$mallory = wp_insert_user( array( 'user_login' => 'smoke_mallory', 'user_email' => 'mallory@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
		update_user_meta( $mallory, 'billing_email', 'alice@example.com' ); // Typed in, never verified.
		$race = LVBR_Races::save( array( 'name' => 'Smoke Owners Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );

		$order = static function ( $customer_id ) {
			$o = wc_create_order( array( 'customer_id' => $customer_id ) );
			$o->set_billing_email( 'alice@example.com' );
			$o->save();
			return $o;
		};
		$balloon = static function ( $name, $user_id, $order_id ) use ( $wpdb, $race ) {
			$wpdb->insert( LVBR_Balloons::table(), array_merge( array( 'race_id' => $race, 'user_id' => $user_id, 'order_id' => $order_id, 'owner_name' => 'Alice Smoke', 'owner_email' => 'alice@example.com', 'balloon_name' => $name, 'balloon_color' => 'Red', 'helium_level' => 'Balanced', 'created_at' => current_time( 'mysql' ) ), LVBR_Balloons::start_columns( 51.4885, -2.58 ) ) );
			return (int) $wpdb->insert_id;
		};
		$guest_order   = $order( 0 );
		$account_order = $order( $alice );
		$logged_in     = $balloon( 'Bought Logged In', $alice, 0 );
		$guest         = $balloon( 'Guest Order', 0, $guest_order->get_id() );
		$on_account    = $balloon( 'On The Account', 0, $account_order->get_id() );
		$names         = static function ( $user_id ) use ( $race ) {
			$found = wp_list_pluck( LVBR_Balloons::owned_by( $user_id, $race ), 'balloon_name' );
			sort( $found );
			return $found;
		};

		$check( array( 'Bought Logged In', 'On The Account' ) === $names( $alice ), 'a user\'s balloons: bought while logged in, or on an order on their account' );
		$check( array() === $names( $mallory ), 'typing someone else\'s email as your billing email claims nothing' );

		$guest_order->set_customer_id( $alice );
		$guest_order->save();
		$check( array( 'Bought Logged In', 'Guest Order', 'On The Account' ) === $names( $alice ), 'a guest order linked to the account counts' );
		$guest_order->set_customer_id( 0 );
		$guest_order->save();

		add_filter( 'lvbr_match_balloons_by_email', '__return_true' );
		$check( in_array( 'Guest Order', $names( $alice ), true ), 'lvbr_match_balloons_by_email turns email matching back on' );
		remove_filter( 'lvbr_match_balloons_by_email', '__return_true' );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LVBR_Balloons::table() . ' WHERE race_id = %d', $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		LVBR_Races::delete( $race );
		$guest_order->delete( true );
		$account_order->delete( true );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $alice );
		wp_delete_user( $mallory );
		break;

	// Prizes: cleaned on save, shown on the tracking page and product page, and with winners in the results email.
	case 'prizes':
		global $wpdb;

		$check( LVBR_Prizes::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', LVBR_Prizes::table() ) ), 'the prizes table exists' );
		$check( false !== strpos( LVBR_Pages::default_content(), '[lvbr_prizes]' ), 'the default tracking page includes the prizes' );

		// A tracking page still on the 1.4–1.13 default gets the prizes; an edited page is left alone.
		$tracking = LVBR_Pages::tracking_page_id();
		$before   = get_post_field( 'post_content', $tracking );
		$old      = "<!-- wp:shortcode -->\n[lvbr_map]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[lvbr_teams]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[lvbr_leaderboard]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[lvbr_races]\n<!-- /wp:shortcode -->";
		wp_update_post( array( 'ID' => $tracking, 'post_content' => $old ) );
		LVBR_Pages::upgrade_default_content();
		$check( false !== strpos( get_post_field( 'post_content', $tracking ), '[lvbr_prizes]' ), 'a tracking page on the previous default gets the prizes' );
		wp_update_post( array( 'ID' => $tracking, 'post_content' => $old . "\n\n<!-- wp:paragraph -->\n<p>Our own words</p>\n<!-- /wp:paragraph -->" ) );
		LVBR_Pages::upgrade_default_content();
		$check( false === strpos( get_post_field( 'post_content', $tracking ), '[lvbr_prizes]' ), 'an edited tracking page is left alone' );
		wp_update_post( array( 'ID' => $tracking, 'post_content' => $before ) );

		$race  = LVBR_Races::save( array( 'name' => 'Smoke Prize Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'end_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$other = LVBR_Races::save( array( 'name' => 'Smoke Other Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		LVBR_Sponsors::save_for_race( $race, LVBR_Sponsors::clean_rows( array( array( 'name' => 'Smoke Pub', 'url' => 'https://pub.example', 'tier' => 'headline' ) ) ) );
		LVBR_Sponsors::save_for_race( $other, LVBR_Sponsors::clean_rows( array( array( 'name' => 'Elsewhere Ltd', 'tier' => 'gold' ) ) ) );
		$pub       = (int) LVBR_Sponsors::for_race( $race )[0]->id;
		$elsewhere = (int) LVBR_Sponsors::for_race( $other )[0]->id;

		foreach ( array( array( 'First Flyer', 'Ann Smoke', 900 ), array( 'Second Flyer', 'Bob Smoke', 800 ), array( 'Third Flyer', 'Cat Smoke', 700 ) ) as $b ) {
			$wpdb->insert( LVBR_Balloons::table(), array_merge( array( 'race_id' => $race, 'balloon_name' => $b[0], 'owner_name' => $b[1], 'owner_email' => 'prize@example.com', 'balloon_color' => 'Red', 'helium_level' => 'Balanced', 'from_start_km' => $b[2], 'distance_km' => $b[2], 'created_at' => current_time( 'mysql' ) ), LVBR_Balloons::start_columns( 51.4885, -2.58 ) ) );
		}

		$rows = LVBR_Prizes::clean_rows(
			array(
				array( 'kind' => 'place_1', 'description' => 'Sunday lunch for four', 'sponsor_id' => $pub ),
				array( 'kind' => 'place_2', 'description' => 'Two match tickets', 'sponsor_id' => $elsewhere ),
				array( 'kind' => 'place_3', 'description' => '' ),
				array( 'kind' => 'draw', 'description' => 'Book token' ),
				array( 'kind' => 'made_up', 'label' => 'Furthest Eco-Glide', 'description' => 'Garden voucher' ),
			),
			$race
		);
		$check( 4 === count( $rows ) && 0 === $rows[1]['sponsor_id'] && 'other' === $rows[3]['kind'] && 'Furthest Eco-Glide' === $rows[3]['label'], 'empty prizes are dropped, another race\'s sponsor isn\'t allowed, unknown kinds become "other"' );
		LVBR_Prizes::save_for_race( $race, $rows );

		$prizes = LVBR_Prizes::for_race( $race );
		$check( array( '1st place', '2nd place', 'Random draw', 'Furthest Eco-Glide' ) === array_map( array( 'LVBR_Prizes', 'title' ), $prizes ) && $prizes[0]->sponsor && 'Smoke Pub' === $prizes[0]->sponsor->name, 'prizes are saved in order, with their sponsor' );

		$html = do_shortcode( '[lvbr_prizes race="' . $race . '"]' );
		$check( 4 === substr_count( $html, '<li class="lvbr-prizes__card' ) && 1 === preg_match( '#Donated by <a data-lvbr-sponsor="\d+" data-lvbr-placement="prize" href="https://pub.example" rel="sponsored noopener" target="_blank">Smoke Pub</a>#', $html ), '[lvbr_prizes] shows the prizes, with a sponsored link to the donor' );
		$check( '' === do_shortcode( '[lvbr_prizes race="' . $other . '"]' ), '[lvbr_prizes] shows nothing for a race without prizes' );
		$check( 0 === strpos( LVBR_Prizes::summary( $race ), '1st place: Sunday lunch for four · 2nd place: Two match tickets' ), 'the "Up for grabs" summary lists the prizes' );

		$results = LVBR_Prizes::results( LVBR_Races::get( $race ) );
		$check( 'First Flyer (Ann S.)' === $results[0]['winner'] && 'Second Flyer (Bob S.)' === $results[1]['winner'] && '' === $results[2]['winner'] && 'Smoke Pub' === $results[0]['donor'], 'winners are worked out from the final positions (public names only); draws are left to the organisers' );

		$mail = WC()->mailer()->get_emails()['LVBR_Email_Results'];
		$mail->race     = LVBR_Races::get( $race );
		$mail->balloons = LVBR_Race_Emails::balloons_for( $mail->race, 'prize@example.com' );
		$email_html     = $mail->get_content_html();
		$email_text     = $mail->get_content_plain();
		$check( false !== strpos( $email_html, 'First Flyer (Ann S.)' ) && false !== strpos( $email_html, 'Donated by Smoke Pub' ) && false !== strpos( $email_html, 'To be announced' ), 'the results email lists the prizes, donors and winners' );
		$check( false !== strpos( $email_text, '1st place: Sunday lunch for four (donated by Smoke Pub)' ), 'the plain-text results email lists them too' );

		$screen = $render_race_screen( LVBR_Races::get( $race ) );
		$check( false !== strpos( $screen, 'lvbr-prizes-editor' ) && false !== strpos( $screen, 'value="Sunday lunch for four"' ), 'the race screen shows the Prizes panel' );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LVBR_Balloons::table() . ' WHERE race_id = %d', $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		LVBR_Races::delete( $race );
		LVBR_Races::delete( $other );
		$check( array() === LVBR_Prizes::for_race( $race ), 'deleting the race removes its prizes' );
		break;

	// Where sponsors appear: presented by, map badge, leaderboard strip, the wall and the emails.
	case 'sponsor-placements':
		global $wpdb;

		$check( false !== strpos( LVBR_Pages::default_content(), '[lvbr_sponsors]' ), 'the default tracking page includes the sponsor wall' );

		foreach ( LVBR_Races::open_for_entries() as $open ) {
			$wpdb->update( LVBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $open->id ) );
		}
		LVBR_Races::flush();

		$race  = LVBR_Races::save( array( 'name' => 'Smoke Placement Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$plain = LVBR_Races::save( array( 'name' => 'Smoke Unsponsored Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'sales_open_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + 9 * DAY_IN_SECONDS ) ) );
		LVBR_Sponsors::save_for_race(
			$race,
			LVBR_Sponsors::clean_rows(
				array(
					array( 'name' => 'Smoke Pub', 'url' => 'https://pub.example', 'tier' => 'headline', 'placements' => array( 'presented_by', 'emails', 'wall' ) ),
					array( 'name' => 'Smoke FC', 'url' => 'https://fc.example', 'tier' => 'gold', 'placements' => array( 'emails', 'wall', 'map_badge' ) ),
					array( 'name' => 'Smoke Books', 'url' => 'https://books.example', 'tier' => 'silver', 'placements' => array( 'leaderboard_strip', 'wall' ) ),
					array( 'name' => 'Smoke Café', 'tier' => 'supporter', 'placements' => array( 'wall' ) ),
					array( 'name' => 'Smoke Hidden', 'tier' => 'supporter', 'placements' => array() ),
				)
			)
		);

		$map = do_shortcode( '[lvbr_map race="' . $race . '"]' );
		$check( false !== strpos( $map, 'lvbr-map__presented' ) && false !== strpos( $map, 'presented by' ) && false !== strpos( $map, 'Smoke Pub' ), 'the map shows "presented by" the headline sponsor' );
		$check( false !== strpos( $map, 'Map supported by' ) && false !== strpos( $map, 'href="https://fc.example" rel="sponsored noopener" target="_blank"' ), 'the map shows the map badge, linked as sponsored' );

		$board = do_shortcode( '[lvbr_leaderboard race="' . $race . '"]' );
		$check( false !== strpos( $board, 'lvbr-leaderboard__presented' ) && false !== strpos( $board, 'Leaderboard brought to you by' ) && false !== strpos( $board, 'Smoke Books' ), 'the leaderboard shows "presented by" and the strip' );

		$wall = do_shortcode( '[lvbr_sponsors race="' . $race . '"]' );
		$order = array_map(
			static function ( $name ) use ( $wall ) {
				return strpos( $wall, $name );
			},
			array( 'Smoke Pub', 'Smoke FC', 'Smoke Books', 'Smoke Café' )
		);
		$sorted = $order;
		sort( $sorted );
		$check( ! in_array( false, $order, true ) && $order === $sorted && false === strpos( $wall, 'Smoke Hidden' ), 'the sponsor wall lists sponsors by tier, leaving out those not on the wall' );
		$check( 1 === preg_match( '#<span data-lvbr-sponsor="\d+" data-lvbr-placement="wall">Smoke Café</span>#', $wall ), 'a sponsor without a website is shown without a link' );

		$check( 'This race was made possible by Smoke Pub and Smoke FC.' === LVBR_Sponsors::email_line( LVBR_Races::get( $race ) ), 'the email line names the sponsors ticked for emails' );
		foreach ( array( 'LVBR_Email_Launched', 'LVBR_Email_Results' ) as $class ) {
			$mail           = WC()->mailer()->get_emails()[ $class ];
			$mail->race     = LVBR_Races::get( $race );
			$mail->balloons = array();
			$check( false !== strpos( $mail->get_content_html(), 'This race was made possible by Smoke Pub and Smoke FC.' ) && false !== strpos( $mail->get_content_plain(), 'This race was made possible by Smoke Pub and Smoke FC.' ), $class . ' carries the sponsor line' );
		}

		$product = LVBR_Product_Type::all_products()[0];
		$entries = new LVBR_Race_Entries();
		$field   = $render(
			static function () use ( $entries, $product ) {
				$entries->output_race_field( $product );
			}
		);
		$check( false !== strpos( $field, 'lvbr-race-presented' ) && false !== strpos( $field, 'Smoke Pub' ), 'the product page names the sponsor presenting the race being entered' );

		$plain_map   = do_shortcode( '[lvbr_map race="' . $plain . '"]' );
		$plain_board = do_shortcode( '[lvbr_leaderboard race="' . $plain . '"]' );
		$check( false === strpos( $plain_map . $plain_board, 'lvbr-presented-by' ) && false === strpos( $plain_map, 'lvbr-map__badge' ) && false === strpos( $plain_board, 'lvbr-leaderboard__strip' ) && '' === do_shortcode( '[lvbr_sponsors race="' . $plain . '"]' ) && '' === LVBR_Sponsors::email_line( LVBR_Races::get( $plain ) ), 'a race without sponsors shows none of this' );

		LVBR_Races::delete( $race );
		LVBR_Races::delete( $plain );
		break;

	// Sponsor hot-air balloons fly with the race but are never ranked; the launch flag can be branded.
	case 'hot-air':
		global $wpdb;

		$settings( array( 'weather_enabled' => 0 ) ); // No network calls: balloons follow the default heading.
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . LVBR_Sponsors::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( ! array_diff( array( 'balloon_style', 'color1', 'color2', 'band_text', 'latitude', 'longitude', 'trail_json', 'flight_hours' ), $columns ), 'the sponsors table has the hot-air columns' );

		$clean = LVBR_Sponsors::clean_rows( array( array( 'name' => 'Odd One', 'balloon_style' => 'blimp', 'color1' => 'red', 'color2' => '#00ff00', 'band_text' => 'FAR TOO LONG A NAME' ) ) );
		$check( 'stripes' === $clean[0]['balloon_style'] && '#2b6cb0' === $clean[0]['color1'] && '#00ff00' === $clean[0]['color2'] && 'FAR TOO LONG' === $clean[0]['band_text'], 'hot-air style, colours and band text are cleaned' );

		$race = LVBR_Races::save( array( 'name' => 'Smoke Hot-Air Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		LVBR_Sponsors::save_for_race(
			$race,
			LVBR_Sponsors::clean_rows(
				array(
					array( 'name' => 'Smoke Pub Company', 'url' => 'https://pub.example', 'tier' => 'headline', 'placements' => array( 'hot_air' ), 'balloon_style' => 'full', 'color1' => '#c4851c', 'color2' => '#f2c14e' ),
					array( 'name' => 'Smoke FC', 'tier' => 'gold', 'placements' => array( 'hot_air', 'launch_flag' ), 'balloon_style' => 'stripes', 'band_text' => 'FC' ),
					array( 'name' => 'Smoke Café', 'tier' => 'supporter', 'placements' => array( 'wall' ) ),
				)
			)
		);
		$wpdb->insert( LVBR_Balloons::table(), array_merge( array( 'race_id' => $race, 'balloon_name' => 'Smoke Racer', 'owner_name' => 'Ray Smoke', 'balloon_color' => 'Red', 'helium_level' => 'Balanced', 'created_at' => current_time( 'mysql' ) ), LVBR_Balloons::start_columns( 51.4885, -2.58 ) ) );

		$payload = LVBR_Map::public_payload( LVBR_Races::get( $race ) );
		$hot     = $payload['sponsors'];
		$check( 2 === count( $hot ) && 'large' === $hot[0]['size'] && 'normal' === $hot[1]['size'], 'two hot-air balloons, the headline one large' );
		$check( 'stripes' === $hot[0]['style'] && 'SMOKE' === $hot[0]['band'] && 'FC' === $hot[1]['band'], 'a logo style without a logo falls back to stripes; the band defaults to the first word' );
		$check( abs( $hot[0]['lat'] - 51.4885 ) < 0.0001 && 1 === count( $hot[0]['trail'] ), 'before the race moves, they wait at the launch point' );
		$check( isset( $payload['launch']['sponsor'] ) && 'Smoke FC' === $payload['launch']['sponsor']['name'], 'the launch flag carries its sponsor' );

		LVBR_Cron::tick( 6, array( 'race_id' => $race ) );
		$moved = LVBR_Sponsors::with_placement( $race, 'hot_air' );
		$check( null !== $moved[0]->latitude && abs( (float) $moved[0]->latitude - 51.4885 ) + abs( (float) $moved[0]->longitude + 2.58 ) > 0.01 && 6.0 === (float) $moved[0]->flight_hours && 2 === count( LVBR_Balloons::decode_trail( $moved[0]->trail_json ) ), 'a tick moves the sponsor balloons on the same wind, with a trail' );

		$payload = LVBR_Map::public_payload( LVBR_Races::get( $race ) );
		$check( array( 'Smoke Racer' ) === wp_list_pluck( $payload['balloons'], 'name' ) && 1 === count( LVBR_Frontend::ranked_ids( LVBR_Races::get( $race ) ) ), 'sponsor balloons are not in the balloon list or the ranking' );
		$check( false === strpos( do_shortcode( '[lvbr_leaderboard race="' . $race . '"]' ), 'Smoke Pub Company' ), 'or on the leaderboard' );

		$screen = $render_race_screen( LVBR_Races::get( $race ) );
		$check( 2 === substr_count( $screen, '<fieldset class="lvbr-sponsors-editor__hot-air">' ) && 2 === substr_count( $screen, '<fieldset class="lvbr-sponsors-editor__hot-air" hidden>' ), 'the hot-air fields show only for sponsors with a hot-air balloon' );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LVBR_Balloons::table() . ' WHERE race_id = %d', $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		LVBR_Races::delete( $race );
		$settings( array( 'weather_enabled' => 1 ) );
		break;

	// Sponsor views and clicks: totals only, checked against what each sponsor really has.
	case 'sponsor-stats':
		global $wpdb;

		$check( LVBR_Sponsor_Stats::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', LVBR_Sponsor_Stats::table() ) ), 'the sponsor stats table exists' );
		$check( 0 === LVBR_Sponsor_Stats::record( 'view', array( '1:wall' ) ), 'nothing is counted until counting is turned on' );
		$settings( array( 'sponsor_stats' => 1 ) );

		$race = LVBR_Races::save( array( 'name' => 'Smoke Stats Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		LVBR_Sponsors::save_for_race(
			$race,
			LVBR_Sponsors::clean_rows(
				array(
					array( 'name' => 'Smoke Pub', 'url' => 'https://pub.example', 'tier' => 'headline', 'placements' => array( 'presented_by', 'wall' ) ),
					array( 'name' => 'Smoke FC', 'url' => 'https://fc.example', 'tier' => 'gold', 'placements' => array( 'wall' ) ),
				)
			)
		);
		list( $pub, $fc ) = array_map( 'intval', wp_list_pluck( LVBR_Sponsors::for_race( $race ), 'id' ) );

		$counted = LVBR_Sponsor_Stats::record( 'view', array( "$pub:presented_by", "$pub:wall", "$pub:wall", "$fc:wall", "$fc:presented_by", "$fc:prize", '999999:wall', "$pub:made_up", 'junk' ) );
		$check( 4 === $counted, 'views count once per placement per report, only for placements the sponsor has (prize credits always)' );

		$request = new WP_REST_Request( 'POST', '/lvbr/v1/sponsor-stats' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'type' => 'click', 'items' => array( "$pub:presented_by" ) ) ) );
		$response = rest_do_request( $request );
		$check( 200 === $response->get_status() && 1 === $response->get_data()['counted'], 'the browser reports clicks through the REST endpoint' );

		$too_many = new WP_REST_Request( 'POST', '/lvbr/v1/sponsor-stats' );
		$too_many->set_header( 'Content-Type', 'application/json' );
		$too_many->set_body( wp_json_encode( array( 'type' => 'view', 'items' => array_fill( 0, 51, "$pub:wall" ) ) ) );
		$check( 400 === rest_do_request( $too_many )->get_status(), 'a report with more than 50 items is refused' );

		$stats = LVBR_Sponsor_Stats::for_race( $race );
		$check( array( 'views' => 2, 'clicks' => 1 ) === LVBR_Sponsor_Stats::totals( $stats[ $pub ] ) && array( 'views' => 1, 'clicks' => 1 ) === $stats[ $pub ]['presented_by'] && 1 === $stats[ $fc ]['prize']['views'], 'totals per sponsor and placement add up' );

		$html = do_shortcode( '[lvbr_sponsors race="' . $race . '"]' );
		$check( false !== strpos( $html, 'data-lvbr-sponsor="' . $pub . '" data-lvbr-placement="wall"' ) && wp_script_is( 'lvbr-sponsor-stats', 'enqueued' ), 'sponsor links carry their placement, and the counting script is loaded' );

		$screen = $render_race_screen( LVBR_Races::get( $race ) );
		$check( false !== strpos( $screen, 'Shown 2 times · 1 clicks' ) && false !== strpos( $screen, 'action=lvbr_sponsor_stats_csv' ), 'the Sponsors panel shows each sponsor\'s totals and the CSV download' );

		LVBR_Sponsors::save_for_race( $race, LVBR_Sponsors::clean_rows( array( array( 'id' => $pub, 'name' => 'Smoke Pub', 'tier' => 'headline', 'placements' => array( 'wall' ) ) ) ) );
		$check( ! isset( LVBR_Sponsor_Stats::for_race( $race )[ $fc ] ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LVBR_Sponsor_Stats::table() . ' WHERE sponsor_id = %d', $fc ) ), 'removing a sponsor removes its counts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		LVBR_Races::delete( $race );
		$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LVBR_Sponsor_Stats::table() . ' WHERE sponsor_id = %d', $pub ) ), 'deleting the race removes its sponsors\' counts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		break;

	// Race sponsors: cleaned on save, one-per-race placements, kept with their race.
	case 'sponsors':
		global $wpdb;

		$check( LVBR_Sponsors::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', LVBR_Sponsors::table() ) ), 'the sponsors table exists' );

		$race = LVBR_Races::save( array( 'name' => 'Smoke Sponsored Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$logo = wp_insert_attachment( array( 'post_title' => 'Smoke logo', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'smoke-logo.png' );
		update_post_meta( $logo, '_wp_attached_file', 'smoke-logo.png' );
		$page = wp_insert_post( array( 'post_title' => 'Not an image', 'post_type' => 'page', 'post_status' => 'draft' ) );

		$rows = LVBR_Sponsors::clean_rows(
			array(
				array( 'name' => 'Smoke Pub', 'url' => 'https://pub.example', 'logo_id' => $logo, 'tier' => 'headline', 'placements' => array( 'presented_by', 'map_badge', 'wall', 'made_up' ) ),
				array( 'name' => 'Smoke Bowling', 'url' => 'javascript:alert(1)', 'logo_id' => $page, 'tier' => 'gold', 'placements' => array( 'map_badge', 'hot_air' ) ),
				array( 'name' => '   ', 'tier' => 'silver' ),
				array( 'name' => 'Smoke Café', 'tier' => 'nonsense' ),
			)
		);
		$check( 3 === count( $rows ) && 'supporter' === $rows[2]['tier'], 'rows without a name are dropped and unknown tiers become Supporter' );
		$check( '' === $rows[1]['url'] && 0 === $rows[1]['logo_id'] && $logo === $rows[0]['logo_id'], 'only http(s) links and image logos are kept' );
		$check( array( 'presented_by', 'map_badge', 'wall' ) === $rows[0]['placements'], 'unknown placements are dropped' );

		$messages = LVBR_Sponsors::save_for_race( $race, $rows );
		$saved    = LVBR_Sponsors::for_race( $race );
		$check( array( 'Smoke Pub', 'Smoke Bowling', 'Smoke Café' ) === wp_list_pluck( $saved, 'name' ), 'sponsors are saved in order' );
		$check( array( 'hot_air' ) === $saved[1]->placements && 1 === count( $messages ) && false !== strpos( $messages[0], 'Smoke Pub keeps the “Map badge” spot' ), 'a one-per-race placement stays with one sponsor, and the organiser is told' );

		// A lower tier listed first doesn't take a spot from a higher tier.
		$clash = LVBR_Sponsors::save_for_race(
			$race,
			LVBR_Sponsors::clean_rows(
				array(
					array( 'name' => 'Smoke Silver', 'tier' => 'silver', 'placements' => array( 'presented_by' ) ),
					array( 'name' => 'Smoke Headline', 'tier' => 'headline', 'placements' => array( 'presented_by' ) ),
				)
			)
		);
		$check( array( 'Smoke Headline' ) === wp_list_pluck( LVBR_Sponsors::with_placement( $race, 'presented_by' ), 'name' ) && false !== strpos( $clash[0], 'Smoke Headline keeps' ), 'the higher tier keeps a one-per-race spot, even when listed second' );
		LVBR_Sponsors::save_for_race( $race, $rows );
		$saved = LVBR_Sponsors::for_race( $race );
		$check( array( 'Smoke Bowling' ) === wp_list_pluck( LVBR_Sponsors::with_placement( $race, 'hot_air' ), 'name' ), 'sponsors can be looked up by placement' );

		// Reorder (Bowling first) and remove the café: ids are kept.
		$ids = wp_list_pluck( $saved, 'id' );
		LVBR_Sponsors::save_for_race(
			$race,
			LVBR_Sponsors::clean_rows(
				array(
					array( 'id' => $ids[1], 'name' => 'Smoke Bowling', 'tier' => 'gold', 'placements' => array( 'hot_air' ) ),
					array( 'id' => $ids[0], 'name' => 'Smoke Pub', 'tier' => 'headline', 'placements' => array( 'presented_by', 'map_badge', 'wall' ) ),
				)
			)
		);
		$saved = LVBR_Sponsors::for_race( $race );
		$check( array( (int) $ids[1], (int) $ids[0] ) === array_map( 'intval', wp_list_pluck( $saved, 'id' ) ), 'reordering keeps each sponsor\'s id, and removed sponsors are deleted' );

		$screen = $render_race_screen( LVBR_Races::get( $race ) );
		$check( false !== strpos( $screen, 'lvbr-sponsors-editor' ) && false !== strpos( $screen, 'value="Smoke Bowling"' ), 'the race screen shows the Sponsors panel with its sponsors' );

		LVBR_Races::delete( $race );
		$check( array() === LVBR_Sponsors::for_race( $race ), 'deleting the race removes its sponsors' );
		wp_delete_attachment( $logo, true );
		wp_delete_post( $page, true );
		break;

	// Outside services: map tiles, place search and the weather API from Settings.
	case 'services':
		$saved = get_option( LVBR_Settings::OPTION, null );
		$use   = static function ( $values, $allowed = 1 ) {
			update_option( LVBR_Settings::OPTION, array_merge( LVBR_Settings::defaults(), array( 'external_services' => $allowed ), $values ) );
			LVBR_Settings::flush_cache();
		};

		// New sites: nothing outside is contacted until the admin allows it.
		$use( array(), 0 );
		$check( ! LVBR_Settings::defaults()['external_services'] && ! LVBR_Settings::defaults()['sponsor_stats'], 'outside services and sponsor counting start off' );
		$check( '' === LVBR_Services::tiles()['url'] && ! LVBR_Services::search_enabled(), 'until allowed: no map tiles and no place search' );
		$calls = 0;
		$count = static function ( $pre, $args, $url ) use ( &$calls ) {
			if ( false !== strpos( $url, 'open-meteo.com' ) ) {
				++$calls;
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $count, 10, 3 );
		$wind = LVBR_Weather::at( 12.3 + wp_rand( 0, 99 ) / 1000, 45.6 );
		remove_filter( 'pre_http_request', $count, 10 );
		$check( 0 === $calls && null === $wind, 'until allowed: no wind requests' );
		set_current_screen( 'toplevel_page_lvbr-admin' );
		ob_start();
		LVBR_Services::prompt();
		$prompt = ob_get_clean();
		set_current_screen( 'dashboard' );
		ob_start();
		LVBR_Services::prompt();
		$elsewhere = ob_get_clean();
		$check( false !== strpos( $prompt, 'lvbr_allow_services' ) && '' === $elsewhere, 'the "allow external services" prompt shows on the plugin\'s screens only' );

		$use( array() );
		$tiles = LVBR_Services::tiles();
		$check( 'osm' === $tiles['provider'] && 0 === strpos( $tiles['url'], 'https://tile.openstreetmap.org/' ) && 'nominatim' === LVBR_Services::search_provider(), 'defaults: OpenStreetMap tiles and Nominatim search' );
		$check( LVBR_Weather::API === LVBR_Services::weather_endpoint()['url'], 'defaults: the free Open-Meteo API' );

		LVBR_Settings::register_leaflet();
		$scripts = wp_scripts();
		$check( in_array( 'lvbr-services', $scripts->registered['lvbr-leaflet']->deps, true ) && false !== strpos( implode( '', (array) $scripts->get_data( 'lvbr-services', 'before' ) ), 'tile.openstreetmap.org' ), 'every map loads the services config with Leaflet' );

		$use( array( 'tile_provider' => 'maptiler', 'map_api_key' => 'abc123', 'search_provider' => 'maptiler' ) );
		$tiles = LVBR_Services::tiles();
		$check( 'maptiler' === $tiles['provider'] && false !== strpos( $tiles['url'], 'key=abc123' ) && false !== strpos( $tiles['attribution'], 'MapTiler' ), 'MapTiler tiles use the key and credit MapTiler' );
		$config = LVBR_Services::browser_config();
		$check( 'maptiler' === $config['search']['provider'] && 'abc123' === $config['search']['key'], 'MapTiler place search uses the same key' );

		$use( array( 'tile_provider' => 'maptiler', 'map_api_key' => '' ) );
		$check( 'osm' === LVBR_Services::tiles()['provider'], 'a keyed provider without a key falls back to OpenStreetMap' );
		$use( array( 'tile_provider' => 'stadia', 'map_api_key' => 'k', 'search_provider' => 'maptiler' ) );
		$check( 'nominatim' === LVBR_Services::search_provider() && '' === LVBR_Services::browser_config()['search']['key'], 'MapTiler search needs MapTiler tiles (no key leaks to another provider)' );
		$use( array( 'search_provider' => 'off' ) );
		$check( ! LVBR_Services::search_enabled(), 'place search can be switched off' );

		$settings = new LVBR_Settings();
		$clean    = $settings->sanitize( array_merge( LVBR_Settings::defaults(), array( 'tile_provider' => 'custom', 'tile_url' => 'http://tiles.example.com/{z}/{x}/{y}.png', 'tile_attribution' => 'Tiles <a href="https://example.com" onclick="x()">Example</a><script>alert(1)</script>' ) ) );
		$check( '' === $clean['tile_url'], 'a custom tile URL must be https' );
		$check( 'Tiles <a href="https://example.com">Example</a>alert(1)' === $clean['tile_attribution'], 'attribution keeps text and links only' );
		$clean = $settings->sanitize( array_merge( LVBR_Settings::defaults(), array( 'external_services' => 1, 'tile_provider' => 'custom', 'tile_url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key=1', 'tile_attribution' => '© Example' ) ) );
		$use( $clean );
		$check( 'custom' === LVBR_Services::tiles()['provider'] && 'https://tiles.example.com/{z}/{x}/{y}.png?key=1' === LVBR_Services::tiles()['url'], 'a custom tile URL is used as given' );

		// With an Open-Meteo key, wind comes from the customer API.
		$use( array( 'weather_api_key' => 'om-key' ) );
		$seen = '';
		$spy  = static function ( $pre, $args, $url ) use ( &$seen ) {
			if ( false !== strpos( $url, 'open-meteo.com' ) ) {
				$seen = $url;
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'current' => array( 'wind_speed_10m' => 12.5, 'wind_direction_10m' => 250 ) ) ), 'headers' => array(), 'cookies' => array() );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );
		$wind = LVBR_Weather::at( -33.9 + wp_rand( 0, 99 ) / 1000, 151.2 );
		remove_filter( 'pre_http_request', $spy, 10 );
		$check( 0 === strpos( $seen, 'https://customer-api.open-meteo.com/v1/forecast' ) && false !== strpos( $seen, 'apikey=om-key' ) && $wind && 12.5 === $wind['speed'], 'with a key, wind comes from Open-Meteo\'s customer API' );
		$check( false === strpos( wp_json_encode( LVBR_Services::browser_config() ), 'om-key' ), 'the weather key never goes to browsers' );

		if ( null === $saved ) {
			delete_option( LVBR_Settings::OPTION );
		} else {
			update_option( LVBR_Settings::OPTION, $saved );
		}
		LVBR_Settings::flush_cache();
		break;

	// Big races: the leaderboard is paged and searched on the server, without exposing surnames.
	case 'big-leaderboard':
		global $wpdb;

		$race   = LVBR_Races::save( array( 'name' => 'Smoke Big Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$table  = LVBR_Balloons::table();
		$values = array();
		for ( $i = 1; $i <= 600; $i++ ) {
			$owner    = 7 === $i ? 'Jo Smithers' : 'Owner ' . $i . ' Person';
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %f, %f, %s)', $race, sprintf( 'Balloon %03d', $i ), $owner, 'Red', 'Balanced', 1000 - $i, 1000 - $i, current_time( 'mysql' ) );
		}
		$wpdb->query( "INSERT INTO {$table} (race_id, balloon_name, owner_name, balloon_color, helium_level, from_start_km, distance_km, created_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- each row prepared above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_burst = 1 WHERE race_id = %d AND balloon_name = 'Balloon 002'", $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET withdrawn_at = NOW() WHERE race_id = %d AND balloon_name = 'Balloon 003'", $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$html = do_shortcode( '[lvbr_leaderboard race="' . $race . '" per_page="10"]' );
		$check( false !== strpos( $html, 'data-lvbr-remote=' ) && 10 === substr_count( $html, '<tr data-lvbr-search=' ), 'a big race puts only the first page in the page' );
		$check( false !== strpos( $html, 'data-lvbr-total="598"' ) && false === strpos( $html, '>All</option>' ), 'burst and withdrawn balloons are left out, and there\'s no "All" option' );

		$page       = static function ( $params ) use ( $race ) {
			$request = new WP_REST_Request( 'GET', '/lvbr/v1/leaderboard' );
			$request->set_query_params( array_merge( array( 'race' => (string) $race ), $params ) );
			return rest_do_request( $request )->get_data();
		};
		$first_rank = static function ( $row_html ) {
			return preg_match( '#<td data-label="Rank">([\d,]+)</td>#', $row_html, $m ) ? (int) str_replace( ',', '', $m[1] ) : 0;
		};

		$third = $page( array( 'page' => 3, 'per_page' => 25 ) );
		$check( 25 === substr_count( $third['html'], '<tr ' ) && 51 === $first_rank( $third['html'] ) && 24 === $third['pages'] && 598 === $third['total'], 'page 3 at 25 a page starts at rank 51 (24 pages, 598 balloons)' );

		$found = $page( array( 'search' => 'Balloon 123' ) );
		$check( 1 === $found['total'] && 121 === $first_rank( $found['html'] ), 'search finds a balloon by name and shows its real rank' );
		$check( 0 === $page( array( 'search' => 'Smithers' ) )['total'], 'searching a surname finds nothing (only first name and initial are public)' );
		$check( 1 === $page( array( 'search' => 'Jo S.' ) )['total'], 'searching the public name works' );
		$check( 0 === $page( array( 'search' => 'Balloon 002' ) )['total'], 'burst balloons aren\'t found' );

		$user = wp_insert_user( array( 'user_login' => 'smoke_bigrace', 'user_email' => 'bigrace@example.com', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = %d WHERE race_id = %d AND balloon_name IN ('Balloon 400', 'Balloon 500')", $user, $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$check( 0 === $page( array( 'mine' => true ) )['total'], '"My balloons" is empty for visitors who aren\'t logged in' );
		wp_set_current_user( $user );
		$mine = $page( array( 'mine' => true ) );
		$check( 2 === $mine['total'] && 2 === substr_count( $mine['html'], 'data-lvbr-mine' ), '"My balloons" pages the logged-in user\'s balloons on the server' );
		wp_set_current_user( 0 );

		add_filter(
			'lvbr_leaderboard_server_paging_from',
			static function () {
				return 1000;
			}
		);
		$small = do_shortcode( '[lvbr_leaderboard race="' . $race . '" per_page="10"]' );
		$check( false === strpos( $small, 'data-lvbr-remote=' ) && 598 === substr_count( $small, '<tr data-lvbr-search=' ), 'below the threshold every row is in the page, as before' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE race_id = %d", $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		LVBR_Races::delete( $race );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );
		break;

	// Each race's own movement settings: speed, bursts and update interval, falling back to Settings.
	case 'race-movement':
		global $wpdb;

		$before = get_option( LVBR_Settings::OPTION, array() );
		$settings( array( 'weather_enabled' => 0, 'interval' => 'off', 'base_kmh' => 35, 'variance_pct' => 0, 'mult_balanced' => 1 ) );

		$clean = LVBR_Settings::clean_movement( array( 'base_kmh' => '999', 'variance_pct' => '', 'interval' => 'weekly', 'burst_max_daily' => '2.5', 'flight_mode' => 'drift', 'map_zoom' => 3 ) );
		$check( array( 'base_kmh' => 300.0, 'burst_max_daily' => 2.5 ) === $clean, 'overrides are clamped to the Settings limits; blanks, unknown intervals and other settings are dropped' );

		$launched = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$fast     = LVBR_Races::save( array( 'name' => 'Smoke Fast Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => $launched, 'flight_mode' => 'jetstream', 'movement' => array( 'base_kmh' => '100', 'interval' => 'lvbr_30min' ) ) );
		$plain    = LVBR_Races::save( array( 'name' => 'Smoke Plain Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => $launched, 'flight_mode' => 'jetstream', 'movement' => array( 'base_kmh' => '' ) ) );
		$hourly   = LVBR_Races::save( array( 'name' => 'Smoke Hourly Race', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => $launched, 'flight_mode' => 'jetstream', 'movement' => array( 'interval' => 'hourly' ) ) );

		$check( 100.0 === (float) LVBR_Races::movement( LVBR_Races::get( $fast ) )['base_kmh'] && 35.0 === (float) LVBR_Races::movement( LVBR_Races::get( $plain ) )['base_kmh'], 'a race uses its own speed, another the site\'s' );
		$check( array() === LVBR_Races::movement_overrides( LVBR_Races::get( $plain ) ), 'a race left blank stores no overrides' );

		$table = LVBR_Balloons::table();
		foreach ( array( $fast, $plain ) as $race_id ) {
			$wpdb->insert( $table, array( 'race_id' => $race_id, 'balloon_name' => 'Mover', 'owner_name' => 'Smoke Person', 'balloon_color' => 'Red', 'helium_level' => 'Balanced', 'latitude' => 51.4885, 'longitude' => -2.58, 'start_lat' => 51.4885, 'start_lng' => -2.58, 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LVBR_Cron::tick( 1, array( 'race_id' => $race_id ) );
		}
		$moved = static function ( $race_id ) use ( $wpdb, $table ) {
			return (float) $wpdb->get_var( $wpdb->prepare( "SELECT distance_km FROM {$table} WHERE race_id = %d", $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		$check( abs( $moved( $fast ) - 100 ) < 1 && abs( $moved( $plain ) - 35 ) < 1, 'an hour moves the fast race 100 km and the other 35 km (' . $moved( $fast ) . ' / ' . $moved( $plain ) . ')' );

		$check( 30 * MINUTE_IN_SECONDS === LVBR_Cron::schedule_seconds() && 'lvbr_30min' === get_option( 'lvbr_scheduled_interval' ), 'with the site set to manual, updates are scheduled every 30 minutes for the race that asks' );
		$check( LVBR_Cron::next_for_race( LVBR_Races::get( $fast ) ) > 0 && 0 === LVBR_Cron::next_for_race( LVBR_Races::get( $plain ) ), 'the race on the site setting (manual) has no next update' );

		LVBR_Races::set_time( $fast, 'last_tick_at', time() - 20 * MINUTE_IN_SECONDS );
		LVBR_Races::set_time( $hourly, 'last_tick_at', time() - 20 * MINUTE_IN_SECONDS );
		LVBR_Races::flush();
		$ran = LVBR_Cron::run_scheduled();
		$check( isset( $ran[ $fast ] ) && ! isset( $ran[ $hourly ] ) && ! isset( $ran[ $plain ] ), 'a scheduled run moves only races whose own interval is due' );

		foreach ( array( $fast, $plain, $hourly ) as $race_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE race_id = %d", $race_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			LVBR_Races::delete( $race_id );
		}
		update_option( LVBR_Settings::OPTION, $before );
		LVBR_Settings::flush_cache();
		LVBR_Cron::reschedule();
		$check( LVBR_Settings::interval_seconds() === LVBR_Cron::schedule_seconds(), 'with the races gone, the schedule follows the site setting again' );
		break;

	// Big races send the map only the leaders' trails; the rest load on demand.
	case 'light-map':
		global $wpdb;

		$race   = LVBR_Races::save( array( 'name' => 'Smoke Light Map', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$other  = LVBR_Races::save( array( 'name' => 'Smoke Light Map Other', 'launch_lat' => 51.4885, 'launch_lng' => -2.58, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$table  = LVBR_Balloons::table();
		$values = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %f, %f, %f, %f, %s, %s)', $race, 'Light ' . $i, 'Owner ' . $i . ' Person', 'Red', 'Balanced', 100 - $i, 100 - $i, 51.123456789, -1.987654321, '[[51.4885,-2.58],[51.3,-2.2],[51.123456789,-1.987654321]]', current_time( 'mysql' ) );
		}
		$wpdb->query( "INSERT INTO {$table} (race_id, balloon_name, owner_name, balloon_color, helium_level, from_start_km, distance_km, latitude, longitude, trail_json, created_at) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- each row prepared above.
		$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE race_id = %d ORDER BY id", $race ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = end( $ids );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET withdrawn_at = NOW() WHERE id = %d", $ids[30] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$balloons   = static function ( $params = array(), $headers = array() ) use ( $race ) {
			$request = new WP_REST_Request( 'GET', '/lvbr/v1/balloons' );
			$request->set_query_params( array_merge( array( 'race' => (string) $race ), $params ) );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
			return rest_do_request( $request );
		};
		$with_trail = static function ( $data ) {
			return array_map( 'intval', wp_list_pluck( array_filter( $data['balloons'], static fn( $b ) => isset( $b['trail'] ) ), 'id' ) );
		};

		$small = $balloons()->get_data();
		$check( 'all' === $small['trails'] && 39 === count( $with_trail( $small ) ), 'a small race sends every trail, as before' );
		$check( 51.12346 === $small['balloons'][0]['lat'] && -1.98765 === $small['balloons'][0]['lng'], 'positions are rounded to 5 decimals (about a metre)' );

		$under = static function () {
			return 10;
		};
		add_filter( 'lvbr_map_all_trails_up_to', $under );
		$big = $balloons()->get_data();
		$check( 'some' === $big['trails'] && 39 === count( $big['balloons'] ) && 25 === count( $with_trail( $big ) ) && ! in_array( $last, $with_trail( $big ), true ), 'above the limit only the top 25 trails are sent' );
		$asked = $with_trail( $balloons( array( 'trails' => $last . ',' . $ids[30] ) )->get_data() );
		$check( 26 === count( $asked ) && in_array( $last, $asked, true ), 'trails asked for (the viewer\'s, the selected balloon) are added' );

		$etag = $balloons()->get_headers()['ETag'] ?? '';
		$check( '' !== $etag && 304 === $balloons( array(), array( 'If-None-Match' => $etag ) )->get_status(), 'an unchanged race answers 304 "not modified"' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET distance_km = distance_km + 1 WHERE id = %d", $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$check( 200 === $balloons( array(), array( 'If-None-Match' => $etag ) )->get_status(), 'after the balloons move it sends the new data' );

		$trail = static function ( $race_id, $id ) {
			$request = new WP_REST_Request( 'GET', '/lvbr/v1/trail' );
			$request->set_query_params( array( 'race' => (string) $race_id, 'id' => (string) $id ) );
			return rest_do_request( $request );
		};
		$one = $trail( $race, $last );
		$check( 200 === $one->get_status() && $last === $one->get_data()['id'] && 3 === count( $one->get_data()['trail'] ), 'one balloon\'s trail loads on its own' );
		$check( 404 === $trail( $race, $ids[30] )->get_status() && 404 === $trail( $other, $last )->get_status(), 'withdrawn balloons and other races\' balloons aren\'t found' );

		$page = html_entity_decode( do_shortcode( '[lvbr_map race="' . $race . '"]' ), ENT_QUOTES );
		$check( false !== strpos( $page, 'trailUrl' ) && false !== strpos( $page, '"trails":"some"' ), 'the map page has the trail address and starts with the light data' );
		remove_filter( 'lvbr_map_all_trails_up_to', $under );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE race_id = %d", $race ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		LVBR_Races::delete( $race );
		LVBR_Races::delete( $other );
		break;

	// Updates from GitHub releases, with GitHub's API answered by a fake release.
	case 'github-updates':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$basename = plugin_basename( LVBR_PLUGIN_FILE );
		$header   = get_plugin_data( LVBR_PLUGIN_FILE, false, false );
		$check( class_exists( 'LVBR_GitHub_Updater' ) && 'https://github.com/f3zz3h/virtual-balloon-race' === $header['UpdateURI'], 'the GitHub build has the updater and its Update URI' );

		$requests = 0;
		$release  = array(
			'tag_name'     => 'v99.1.0',
			'html_url'     => 'https://github.com/f3zz3h/virtual-balloon-race/releases/tag/v99.1.0',
			'body'         => "## What's changed\n* Faster balloons",
			'published_at' => '2026-10-22T12:00:00Z',
			'assets'       => array(
				array( 'name' => 'virtual-balloon-race-99.1.0.zip', 'browser_download_url' => 'https://github.com/f3zz3h/virtual-balloon-race/releases/download/v99.1.0/virtual-balloon-race-99.1.0.zip' ),
			),
		);
		$fake = static function ( $pre, $args, $url ) use ( &$requests, &$release ) {
			if ( false === strpos( $url, 'api.github.com/repos/f3zz3h/virtual-balloon-race/releases/latest' ) ) {
				return $pre;
			}
			++$requests;
			return $release ? array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $release ), 'headers' => array(), 'cookies' => array() ) : array( 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $fake, 10, 3 );
		LVBR_GitHub_Updater::forget();

		$update = apply_filters( 'update_plugins_github.com', false, $header, $basename, array() );
		$check( is_array( $update ) && '99.1.0' === $update['version'] && false !== strpos( $update['package'], 'virtual-balloon-race-99.1.0.zip' ), 'the latest release is offered as an update, with its zip' );
		$check( false === apply_filters( 'update_plugins_github.com', false, $header, 'other-plugin/other-plugin.php', array() ), 'other plugins on GitHub are left alone' );

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'virtual-balloon-race' ) );
		$check( is_object( $info ) && '99.1.0' === $info->version && false !== strpos( $info->sections['changelog'], 'Faster balloons' ), '"View details" shows the release notes' );
		$check( 1 === $requests, 'the release is fetched once and cached' );

		LVBR_GitHub_Updater::forget();
		$release = null;
		$check( false === apply_filters( 'update_plugins_github.com', false, $header, $basename, array() ) && false === apply_filters( 'update_plugins_github.com', false, $header, $basename, array() ) && 2 === $requests, 'a failed check offers nothing and is not retried straight away' );

		// An update unpacked under another folder name goes back into the plugin's own folder.
		WP_Filesystem();
		$remote = trailingslashit( get_temp_dir() ) . 'lvbr-upgrade-' . wp_generate_password( 6, false );
		wp_mkdir_p( $remote . '/unpacked-elsewhere' );
		$updater = new LVBR_GitHub_Updater();
		$other   = $updater->keep_folder_name( $remote . '/unpacked-elsewhere/', $remote, null, array( 'plugin' => 'another-plugin/another-plugin.php' ) );
		$check( $remote . '/unpacked-elsewhere/' === $other, 'other plugins\' upgrades are left alone' );
		$moved = $updater->keep_folder_name( $remote . '/unpacked-elsewhere/', $remote, null, array( 'plugin' => $basename ) );
		$check( trailingslashit( $remote ) . dirname( $basename ) . '/' === $moved && is_dir( $moved ) && ! is_dir( $remote . '/unpacked-elsewhere' ), 'the update installs into the plugin\'s own folder' );
		global $wp_filesystem;
		$wp_filesystem->delete( $remote, true );

		// "Check again" on Dashboard → Updates asks GitHub again, before WordPress's own check runs.
		$release = array(
			'tag_name' => 'v99.2.0',
			'html_url' => 'https://github.com/f3zz3h/virtual-balloon-race/releases/tag/v99.2.0',
			'assets'   => array( array( 'name' => 'virtual-balloon-race-99.2.0.zip', 'browser_download_url' => 'https://example.com/virtual-balloon-race-99.2.0.zip' ) ),
		);
		set_site_transient( LVBR_GitHub_Updater::CACHE, array( 'version' => '99.1.0', 'package' => 'https://example.com/old.zip', 'url' => '', 'notes' => '', 'date' => '' ), HOUR_IN_SECONDS );
		$seen_in_check = '';
		$watch         = static function () use ( &$seen_in_check ) {
			$latest        = LVBR_GitHub_Updater::latest();
			$seen_in_check = $latest ? $latest['version'] : '';
		};
		add_action( 'load-update-core.php', $watch, 10 );
		wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0] );
		$_GET['force-check'] = '1';
		do_action( 'load-update-core.php' );
		unset( $_GET['force-check'] );
		remove_action( 'load-update-core.php', $watch, 10 );
		$check( '99.2.0' === $seen_in_check, '"Check again" asks GitHub afresh before WordPress checks for updates' );

		set_site_transient( LVBR_GitHub_Updater::CACHE, array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );
		$check( '99.2.0' === ( LVBR_GitHub_Updater::latest() ? LVBR_GitHub_Updater::latest()['version'] : '' ), 'an incomplete cached release is fetched again' );

		remove_filter( 'pre_http_request', $fake, 10 );
		LVBR_GitHub_Updater::forget();
		break;

	// After deleting the plugin with "Delete all race data" off: everything stays.
	case 'uninstalled-kept':
		global $wpdb;
		$check( $wpdb->prefix . 'lvbr_races' === $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}lvbr_races'" ), 'race tables kept' );
		// A fresh install stores these; lvbr_settings only exists once Settings has been saved.
		$check( false !== get_option( 'lvbr_db_version' ) && false !== get_option( 'lvbr_tracking_page_id' ), 'plugin options kept' );
		$check( ! wp_next_scheduled( 'lvbr_check_race_emails' ) && ! wp_next_scheduled( 'lvbr_daily_cleanup' ), 'scheduled jobs stopped' );
		break;

	// After deleting the plugin with "Delete all race data" on.
	case 'uninstalled-removed':
		global $wpdb;
		foreach ( array( 'lvbr_races', 'lvbr_balloons', 'lvbr_teams' ) as $table ) {
			$check( null === $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ), "{$table} table removed" );
		}
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'lvbr\\_%' OR option_name LIKE 'woocommerce\\_lvbr\\_%'" );
		$check( 0 === $left, 'plugin options removed (' . $left . ' left)' );
		$check( 1 === count( get_posts( array( 'post_type' => 'page', 'name' => 'balloon-race', 'post_status' => 'any' ) ) ), 'the tracking page (site content) is kept' );
		$check( 0 < count( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids' ) ) ), 'products (site content) are kept' );
		break;

	default:
		WP_CLI::error( "Unknown stage: {$stage}" );
}

if ( $failures ) {
	WP_CLI::error( "{$stage}: " . count( $failures ) . ' check(s) failed' );
}

WP_CLI::success( "{$stage}: all checks passed" );
