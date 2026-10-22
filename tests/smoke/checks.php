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

// Capture mail instead of sending it (the test server has no mail program).
$GLOBALS['vbr_smoke_mail'] = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['vbr_smoke_mail'][] = $atts;
		return true;
	},
	10,
	2
);

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

	// Refunds, cancellations and hand withdrawals take balloons out of the race and back again.
	case 'refunds':
		global $wpdb;

		$product = VBR_Product_Type::all_products()[0];
		$race    = VBR_Races::save(
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
			$item->add_meta_data( '_vbr_race_id', (string) $race, true );
			$order->add_item( $item );
		}
		$order->set_billing_first_name( 'Rae' );
		$order->set_billing_last_name( 'Fund' );
		$order->set_billing_email( 'rae.fund@example.com' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'processing' );

		$balloon = static function ( $name ) use ( $wpdb, $order ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VBR_Balloons::table() . ' WHERE order_id = %d AND balloon_name = %s', $order->get_id(), $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		$public  = static function () use ( $race ) {
			$request = new WP_REST_Request( 'GET', '/vbr/v1/balloons' );
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
		$check( false === strpos( do_shortcode( '[balloon_leaderboard race="' . $race . '"]' ), 'Refund Me' ), 'leaderboard hides the withdrawn balloon' );

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
		VBR_Balloons::withdraw( array( (int) $balloon( 'Keep Me' )->id ), 'admin' );
		$order->update_status( 'completed' );
		$check( ! $active( 'Keep Me' ) && 'admin' === $balloon( 'Keep Me' )->withdrawn_reason, 'a hand-withdrawn balloon stays out when the order changes' );
		$check( null === VBR_Balloons::rank( $balloon( 'Keep Me' ) ), 'withdrawn balloons have no position' );

		// Withdrawn balloons don't move.
		$wpdb->update( VBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $race ) );
		VBR_Races::flush();
		VBR_Cron::tick( 6, array( 'race_id' => $race, 'source' => 'cli' ) );
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
					'hook'     => VBR_Race_Emails::SEND_HOOK,
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
		VBR_Race_Emails::check();
		$run_queue();

		$race = VBR_Races::save(
			array(
				'name'       => 'Smoke Email Race',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$add  = static function ( $name, $email, $owner ) use ( $wpdb, $race ) {
			$wpdb->insert(
				VBR_Balloons::table(),
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
		VBR_Balloons::withdraw( array( $gone ), 'refunded' );

		$mine = static function () {
			return array_values(
				array_filter(
					$GLOBALS['vbr_smoke_mail'],
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

		VBR_Race_Emails::check();
		$run_queue();
		$check( array() === $mine(), 'no emails before launch' );

		// Launch.
		$wpdb->update( VBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $race ) );
		VBR_Races::flush();
		VBR_Race_Emails::check();
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

		VBR_Race_Emails::check();
		$check( 0 === $run_queue() && 2 === count( $mine() ), 'launch emails are sent only once' );

		// Finish.
		VBR_Cron::tick( 3, array( 'race_id' => $race, 'source' => 'cli' ) );
		VBR_Races::set_time( $race, 'end_at', time() - 30 );
		VBR_Race_Emails::check();
		$run_queue();
		$results = array_slice( $mine(), 2 );
		$check( 2 === count( $results ) && false !== strpos( $results[0]['subject'], 'Final results: Smoke Email Race' ), 'results email to each entrant when the race finishes' );
		$check( (bool) preg_match( '/#\d+ of 3/', implode( ' ', wp_list_pluck( $results, 'message' ) ) ), 'results show each balloon\'s position out of 3' );
		$check( false !== strpos( implode( ' ', wp_list_pluck( $results, 'message' ) ), 'km from launch' ), 'results show distance from launch' );

		// Switched off in WooCommerce → Settings → Emails.
		update_option( 'woocommerce_vbr_balloon_launched_settings', array( 'enabled' => 'no' ) );
		$off = VBR_Races::save(
			array(
				'name'       => 'Smoke Email Race Off',
				'launch_lat' => 51.4885,
				'launch_lng' => -2.58,
				'launch_at'  => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);
		$wpdb->update( VBR_Balloons::table(), array( 'race_id' => $off ), array( 'balloon_name' => 'Charlie' ) );
		$before = count( $GLOBALS['vbr_smoke_mail'] );
		WC()->mailer()->init(); // Reload email settings, as on a new request.
		VBR_Race_Emails::check();
		$run_queue();
		$check( count( $GLOBALS['vbr_smoke_mail'] ) === $before, 'no launch email when it is switched off in WooCommerce' );

		$screen = $render_race_screen( VBR_Races::get( $race ) );
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
			update_post_meta( $id, '_vbr_logo_upload', $tag );
			return (int) $id;
		};
		$old_cart     = $logo( 'cart', 8 );
		$new_cart     = $logo( 'cart', 2 );
		$old_ordered  = $logo( 'ordered', 30 );
		$deleted      = VBR_Housekeeping::delete_abandoned_logos();
		$check( 1 === $deleted && ! get_post( $old_cart ), 'a week-old logo from an abandoned basket is deleted' );
		$check( (bool) get_post( $new_cart ), 'a recent basket logo is kept' );
		$check( (bool) get_post( $old_ordered ), 'an ordered logo is kept, however old' );
		$check( (bool) wp_next_scheduled( VBR_Housekeeping::HOOK ), 'the daily clean-up is scheduled' );

		// A basket entry whose logo has gone is removed, with a notice.
		wc_load_cart();
		WC()->cart->empty_cart();
		$product = VBR_Product_Type::all_products()[0];
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'vbr_balloon_name' => 'Logo Gone', 'vbr_logo_id' => $old_cart, 'vbr_unique_key' => 'a' ) );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'vbr_balloon_name' => 'No Logo', 'vbr_unique_key' => 'b' ) );
		$check( 2 === count( WC()->cart->get_cart() ), 'two balloons in the basket' );
		do_action( 'woocommerce_check_cart_items' );
		$left = wp_list_pluck( WC()->cart->get_cart(), 'vbr_balloon_name' );
		$check( array( 'No Logo' ) === array_values( $left ), 'the balloon whose logo was cleaned up is taken out of the basket' );
		$check( false !== strpos( wp_json_encode( wc_get_notices( 'error' ) ), 'Logo Gone' ), 'the buyer is told to add it again' );
		wc_clear_notices();
		WC()->cart->empty_cart();
		break;

	// Entry limits: places taken (paid and unpaid), sold out, basket limits.
	case 'limits':
		global $wpdb;

		$product = VBR_Product_Type::all_products()[0];
		$races   = new VBR_Race_Entries();

		// Only this race takes entries during this stage.
		foreach ( VBR_Races::open_for_entries() as $other ) {
			$wpdb->update( VBR_Races::table(), array( 'launch_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $other->id ) );
		}
		VBR_Races::flush();

		$race_id = VBR_Races::save(
			array(
				'name'        => 'Smoke Limited Race',
				'launch_lat'  => 51.4885,
				'launch_lng'  => -2.58,
				'launch_at'   => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
				'entry_limit' => 3,
			)
		);
		$race    = static function () use ( $race_id ) {
			VBR_Races::flush();
			VBR_Race_Entries::flush_counts();
			return VBR_Races::get( $race_id );
		};
		$paid    = static function ( $name ) use ( $wpdb, $race_id ) {
			$wpdb->insert(
				VBR_Balloons::table(),
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

		$check( 3 === VBR_Race_Entries::places_left( $race() ), 'a new race with a limit of 3 has 3 places' );
		$first = $paid( 'Limit One' );
		$check( 2 === VBR_Race_Entries::places_left( $race() ), 'a paid balloon takes a place' );

		// An unpaid bank-transfer order holds a place.
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->add_meta_data( '_vbr_race_id', (string) $race_id, true );
		$order->add_item( $item );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'on-hold' );
		$check( 1 === VBR_Race_Entries::places_left( $race() ), 'an on-hold (unpaid) order holds a place' );
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
		$_POST['vbr_race_id'] = (string) $race_id;
		$check( true === $races->validate( true, $product->get_id() ), 'the last place can go in the basket' );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'vbr_balloon_name' => 'Last Place', 'vbr_race_id' => $race_id, 'vbr_unique_key' => 'l1' ) );
		$check( false === $races->validate( true, $product->get_id() ), 'a second entry is refused when only one place is left' );
		wc_clear_notices();

		// Someone else takes the last place: sold out.
		$paid( 'Limit Two' );
		$check( 0 === VBR_Race_Entries::places_left( $race() ), 'sold out' );
		$check( ! wc_get_product( $product->get_id() )->is_purchasable(), 'not purchasable when sold out' );
		ob_start();
		$GLOBALS['product'] = wc_get_product( $product->get_id() );
		$races->closed_message();
		$check( false !== strpos( (string) ob_get_clean(), 'Sorry, Smoke Limited Race is sold out.' ), 'product page says it is sold out' );
		do_action( 'woocommerce_check_cart_items' );
		$check( 0 === count( WC()->cart->get_cart() ), 'the basket entry is removed once the race sells out' );
		$check( false !== strpos( wp_json_encode( wc_get_notices( 'error' ) ), 'has sold out' ), 'the buyer is told it sold out' );
		wc_clear_notices();
		$check( false !== strpos( do_shortcode( '[balloon_races]' ), 'Sold out' ), 'race list shows Sold out' );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/vbr/v1/races' ) );
		$listed   = wp_list_filter( $response->get_data()['races'], array( 'id' => $race_id ) );
		$check( $listed && 0 === array_values( $listed )[0]['places_left'] && 3 === array_values( $listed )[0]['entry_limit'], 'REST shows the limit and places left' );

		// Cancelling the unpaid order frees its place.
		$order->update_status( 'cancelled' );
		$check( 1 === VBR_Race_Entries::places_left( $race() ), 'a cancelled unpaid order frees its place' );
		VBR_Balloons::withdraw( array( $first ), 'refunded' );
		$check( 2 === VBR_Race_Entries::places_left( $race() ), 'a withdrawn (refunded) balloon frees its place' );

		$check( false !== strpos( $render_race_screen( $race() ), '1 of 3 (2 left)' ), 'race screen shows entries of the limit' );
		$check( null === VBR_Race_Entries::places_left( VBR_Races::get( VBR_Races::save( array( 'name' => 'Smoke Unlimited', 'launch_lat' => 51.4, 'launch_lng' => -2.5, 'launch_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ) ) ), 'no limit means unlimited places' );
		unset( $_POST['vbr_race_id'] );
		WC()->cart->empty_cart();
		break;

	// Demo race: made-up balloons flown for three days; removed cleanly.
	case 'demo':
		global $wpdb;

		$race_id  = VBR_Demo::create( 12 );
		$race     = VBR_Races::get( $race_id );
		$balloons = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . VBR_Balloons::table() . ' WHERE race_id = %d', $race_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$check( $race && VBR_Races::FLYING === VBR_Races::status( $race ), 'demo race created and flying' );
		$check( 12 === count( $balloons ), 'demo race has the requested balloons' );
		$check( count( array_filter( wp_list_pluck( $balloons, 'from_start_km' ) ) ) >= 10, 'demo balloons have already flown' );
		$check( array( '' ) === array_values( array_unique( wp_list_pluck( $balloons, 'owner_email' ) ) ), 'demo entrants have no email addresses' );
		$check( array() === VBR_Race_Emails::recipients( $race_id ), 'nobody would be emailed about the demo race' );
		$check( array( $race_id ) === VBR_Demo::race_ids(), 'demo race is remembered for removal' );
		$check( false !== strpos( $render( array( new VBR_Demo(), 'render_panel' ) ), 'Remove demo races' ), 'Settings shows the demo race panel' );

		// Renewal: nothing while days are left; a fresh race (same size) replaces it near the end.
		$check( VBR_Demo::renews() && 0 === VBR_Demo::renew_if_due(), 'demo race with days left is not renewed' );
		VBR_Races::set_time( $race_id, 'end_at', time() + HOUR_IN_SECONDS );
		$renewed = VBR_Demo::renew_if_due();
		$check( $renewed && $renewed !== $race_id && ! VBR_Races::get( $race_id ), 'demo race near its end is replaced' );
		$check( array( $renewed ) === VBR_Demo::race_ids() && 12 === VBR_Races::balloon_count( $renewed ), 'the fresh demo race has as many balloons' );
		$check( VBR_Races::FLYING === VBR_Races::status( VBR_Races::get( $renewed ) ), 'the fresh demo race is flying' );
		update_option( VBR_Demo::RENEW_OPTION, 'no' );
		VBR_Races::set_time( $renewed, 'end_at', time() - HOUR_IN_SECONDS );
		$check( 0 === VBR_Demo::renew_if_due() && array( $renewed ) === VBR_Demo::race_ids(), 'no renewal when switched off' );
		delete_option( VBR_Demo::RENEW_OPTION );

		$check( 1 === VBR_Demo::remove() && ! VBR_Races::get( $renewed ), 'demo race removed' );
		$check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . VBR_Balloons::table() . ' WHERE race_id = %d', $renewed ) ), 'demo balloons removed' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		break;

	// Outside services: map tiles, place search and the weather API from Settings.
	case 'services':
		$saved = get_option( VBR_Settings::OPTION, null );
		$use   = static function ( $values ) {
			update_option( VBR_Settings::OPTION, array_merge( VBR_Settings::defaults(), $values ) );
			VBR_Settings::flush_cache();
		};
		$use( array() );
		$tiles = VBR_Services::tiles();
		$check( 'osm' === $tiles['provider'] && 0 === strpos( $tiles['url'], 'https://tile.openstreetmap.org/' ) && 'nominatim' === VBR_Services::search_provider(), 'defaults: OpenStreetMap tiles and Nominatim search' );
		$check( VBR_Weather::API === VBR_Services::weather_endpoint()['url'], 'defaults: the free Open-Meteo API' );

		VBR_Settings::register_leaflet();
		$scripts = wp_scripts();
		$check( in_array( 'vbr-services', $scripts->registered['vbr-leaflet']->deps, true ) && false !== strpos( implode( '', (array) $scripts->get_data( 'vbr-services', 'before' ) ), 'tile.openstreetmap.org' ), 'every map loads the services config with Leaflet' );

		$use( array( 'tile_provider' => 'maptiler', 'map_api_key' => 'abc123', 'search_provider' => 'maptiler' ) );
		$tiles = VBR_Services::tiles();
		$check( 'maptiler' === $tiles['provider'] && false !== strpos( $tiles['url'], 'key=abc123' ) && false !== strpos( $tiles['attribution'], 'MapTiler' ), 'MapTiler tiles use the key and credit MapTiler' );
		$config = VBR_Services::browser_config();
		$check( 'maptiler' === $config['search']['provider'] && 'abc123' === $config['search']['key'], 'MapTiler place search uses the same key' );

		$use( array( 'tile_provider' => 'maptiler', 'map_api_key' => '' ) );
		$check( 'osm' === VBR_Services::tiles()['provider'], 'a keyed provider without a key falls back to OpenStreetMap' );
		$use( array( 'tile_provider' => 'stadia', 'map_api_key' => 'k', 'search_provider' => 'maptiler' ) );
		$check( 'nominatim' === VBR_Services::search_provider() && '' === VBR_Services::browser_config()['search']['key'], 'MapTiler search needs MapTiler tiles (no key leaks to another provider)' );
		$use( array( 'search_provider' => 'off' ) );
		$check( ! VBR_Services::search_enabled(), 'place search can be switched off' );

		$settings = new VBR_Settings();
		$clean    = $settings->sanitize( array_merge( VBR_Settings::defaults(), array( 'tile_provider' => 'custom', 'tile_url' => 'http://tiles.example.com/{z}/{x}/{y}.png', 'tile_attribution' => 'Tiles <a href="https://example.com" onclick="x()">Example</a><script>alert(1)</script>' ) ) );
		$check( '' === $clean['tile_url'], 'a custom tile URL must be https' );
		$check( 'Tiles <a href="https://example.com">Example</a>alert(1)' === $clean['tile_attribution'], 'attribution keeps text and links only' );
		$clean = $settings->sanitize( array_merge( VBR_Settings::defaults(), array( 'tile_provider' => 'custom', 'tile_url' => 'https://tiles.example.com/{z}/{x}/{y}.png?key=1', 'tile_attribution' => '© Example' ) ) );
		$use( $clean );
		$check( 'custom' === VBR_Services::tiles()['provider'] && 'https://tiles.example.com/{z}/{x}/{y}.png?key=1' === VBR_Services::tiles()['url'], 'a custom tile URL is used as given' );

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
		$wind = VBR_Weather::at( -33.9 + wp_rand( 0, 99 ) / 1000, 151.2 );
		remove_filter( 'pre_http_request', $spy, 10 );
		$check( 0 === strpos( $seen, 'https://customer-api.open-meteo.com/v1/forecast' ) && false !== strpos( $seen, 'apikey=om-key' ) && $wind && 12.5 === $wind['speed'], 'with a key, wind comes from Open-Meteo\'s customer API' );
		$check( false === strpos( wp_json_encode( VBR_Services::browser_config() ), 'om-key' ), 'the weather key never goes to browsers' );

		if ( null === $saved ) {
			delete_option( VBR_Settings::OPTION );
		} else {
			update_option( VBR_Settings::OPTION, $saved );
		}
		VBR_Settings::flush_cache();
		break;

	// Updates from GitHub releases, with GitHub's API answered by a fake release.
	case 'github-updates':
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$basename = plugin_basename( VBR_PLUGIN_FILE );
		$header   = get_plugin_data( VBR_PLUGIN_FILE, false, false );
		$check( class_exists( 'VBR_GitHub_Updater' ) && 'https://github.com/f3zz3h/virtual-balloon-race' === $header['UpdateURI'], 'the GitHub build has the updater and its Update URI' );

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
		VBR_GitHub_Updater::forget();

		$update = apply_filters( 'update_plugins_github.com', false, $header, $basename, array() );
		$check( is_array( $update ) && '99.1.0' === $update['version'] && false !== strpos( $update['package'], 'virtual-balloon-race-99.1.0.zip' ), 'the latest release is offered as an update, with its zip' );
		$check( false === apply_filters( 'update_plugins_github.com', false, $header, 'other-plugin/other-plugin.php', array() ), 'other plugins on GitHub are left alone' );

		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'virtual-balloon-race' ) );
		$check( is_object( $info ) && '99.1.0' === $info->version && false !== strpos( $info->sections['changelog'], 'Faster balloons' ), '"View details" shows the release notes' );
		$check( 1 === $requests, 'the release is fetched once and cached' );

		VBR_GitHub_Updater::forget();
		$release = null;
		$check( false === apply_filters( 'update_plugins_github.com', false, $header, $basename, array() ) && false === apply_filters( 'update_plugins_github.com', false, $header, $basename, array() ) && 2 === $requests, 'a failed check offers nothing and is not retried straight away' );

		// An update unpacked under another folder name goes back into the plugin's own folder.
		WP_Filesystem();
		$remote = trailingslashit( get_temp_dir() ) . 'vbr-upgrade-' . wp_generate_password( 6, false );
		wp_mkdir_p( $remote . '/unpacked-elsewhere' );
		$updater = new VBR_GitHub_Updater();
		$other   = $updater->keep_folder_name( $remote . '/unpacked-elsewhere/', $remote, null, array( 'plugin' => 'another-plugin/another-plugin.php' ) );
		$check( $remote . '/unpacked-elsewhere/' === $other, 'other plugins\' upgrades are left alone' );
		$moved = $updater->keep_folder_name( $remote . '/unpacked-elsewhere/', $remote, null, array( 'plugin' => $basename ) );
		$check( trailingslashit( $remote ) . dirname( $basename ) . '/' === $moved && is_dir( $moved ) && ! is_dir( $remote . '/unpacked-elsewhere' ), 'the update installs into the plugin\'s own folder' );
		global $wp_filesystem;
		$wp_filesystem->delete( $remote, true );

		remove_filter( 'pre_http_request', $fake, 10 );
		VBR_GitHub_Updater::forget();
		break;

	// After deleting the plugin with "Delete all race data" off: everything stays.
	case 'uninstalled-kept':
		global $wpdb;
		$check( $wpdb->prefix . 'vbr_races' === $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}vbr_races'" ), 'race tables kept' );
		// A fresh install stores these; vbr_settings only exists once Settings has been saved.
		$check( false !== get_option( 'vbr_db_version' ) && false !== get_option( 'vbr_tracking_page_id' ), 'plugin options kept' );
		$check( ! wp_next_scheduled( 'vbr_check_race_emails' ) && ! wp_next_scheduled( 'vbr_daily_cleanup' ), 'scheduled jobs stopped' );
		break;

	// After deleting the plugin with "Delete all race data" on.
	case 'uninstalled-removed':
		global $wpdb;
		foreach ( array( 'vbr_races', 'vbr_balloons', 'vbr_teams' ) as $table ) {
			$check( null === $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ), "{$table} table removed" );
		}
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'vbr\\_%' OR option_name LIKE 'woocommerce\\_vbr\\_%'" );
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
