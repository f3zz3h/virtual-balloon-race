<?php
/**
 * Housekeeping: tidy up logo uploads from baskets that never became orders.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily clean-up of abandoned logo uploads, and a basket check for logos that have gone.
 */
class VBR_Housekeeping {

	const HOOK = 'vbr_daily_cleanup';

	/**
	 * Days an unordered logo upload is kept. Long enough for a saved basket to come back to.
	 */
	const ABANDONED_LOGO_DAYS = 7;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_logos' ) );
	}

	/**
	 * Run the clean-up once a day.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Stop the daily clean-up (plugin deactivated).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The daily hook's callback.
	 */
	public static function run_daily() {
		self::delete_abandoned_logos();
	}

	/**
	 * Delete logo uploads still tagged as "in a basket" (never ordered) after a week.
	 *
	 * Uploads are tagged `_vbr_logo_upload = cart` when added to a basket and `ordered` at checkout.
	 * Filter `vbr_abandoned_logo_days` changes how long they're kept.
	 *
	 * @return int Uploads deleted.
	 */
	public static function delete_abandoned_logos() {
		$days = max( 1, (int) apply_filters( 'vbr_abandoned_logo_days', self::ABANDONED_LOGO_DAYS ) );
		$ids  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'meta_key'       => '_vbr_logo_upload', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => 'cart', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
						'column' => 'post_date_gmt',
					),
				),
			)
		);

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_attachment( (int) $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * A basket entry whose logo upload has been cleaned up can't be bought as it was:
	 * remove it and ask the buyer to add the balloon again (so they aren't charged for a missing logo).
	 */
	public function check_cart_logos() {
		/** @var WC_Cart|null $cart Not set outside the shop front end. */
		$cart = WC()->cart;

		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['vbr_logo_id'] ) || wp_attachment_is_image( (int) $item['vbr_logo_id'] ) ) {
				continue;
			}

			$cart->remove_cart_item( $key );

			wc_add_notice(
				sprintf(
					/* translators: %s: balloon name */
					__( 'The logo for “%s” was uploaded too long ago and has been removed, so we’ve taken that balloon out of your basket. Please add it again with your logo.', 'virtual-balloon-race' ),
					isset( $item['vbr_balloon_name'] ) ? $item['vbr_balloon_name'] : __( 'your balloon', 'virtual-balloon-race' )
				),
				'error'
			);
		}
	}
}
