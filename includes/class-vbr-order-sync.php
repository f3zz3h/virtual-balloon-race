<?php
/**
 * Keeps balloons in step with their orders: refunds, cancellations and failed payments withdraw them.
 *
 * @package Virtual_Balloon_Race
 */

defined( 'ABSPATH' ) || exit;

/**
 * Withdraws and restores an order's balloons from the order's current state.
 */
class VBR_Order_Sync {

	/**
	 * Order statuses that withdraw every balloon in the order (the status is the reason).
	 */
	const WITHDRAW_STATUSES = array( 'cancelled', 'refunded', 'failed' );

	/**
	 * Order statuses that keep balloons in the race (unless their line was refunded).
	 */
	const ACTIVE_STATUSES = array( 'processing', 'completed' );

	/**
	 * Register hooks. They run after balloons are created on processing / completed.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refund' ), 20, 1 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'on_refund_deleted' ), 20, 2 );
	}

	/**
	 * Order status changed.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_status_changed( $order_id ) {
		self::sync( $order_id );
	}

	/**
	 * A refund was created (whole order or some lines).
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_refund( $order_id ) {
		self::sync( $order_id );
	}

	/**
	 * A refund was deleted: its lines' balloons come back.
	 *
	 * @param int $refund_id Refund ID.
	 * @param int $order_id  Order ID.
	 */
	public function on_refund_deleted( $refund_id, $order_id ) {
		self::sync( $order_id );
	}

	/**
	 * Withdraw or restore an order's balloons to match the order now.
	 *
	 * - Cancelled, refunded or failed: every balloon is withdrawn, with the status as the reason.
	 * - Processing or completed: balloons whose order line is fully refunded are withdrawn; the rest
	 *   are restored, unless an organiser withdrew them by hand.
	 * - Any other status (pending, on hold): left as they are.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 * @return array{withdrawn: int, restored: int}
	 */
	public static function sync( $order ) {
		$result = array(
			'withdrawn' => 0,
			'restored'  => 0,
		);
		$order  = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order instanceof WC_Order ) {
			return $result;
		}

		$balloons = VBR_Balloons::for_order( $order->get_id() );
		$status   = $order->get_status();

		if ( ! $balloons ) {
			return $result;
		}

		if ( in_array( $status, self::WITHDRAW_STATUSES, true ) ) {
			$result['withdrawn'] = VBR_Balloons::withdraw( wp_list_pluck( $balloons, 'id' ), $status );
			return $result;
		}

		if ( ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
			return $result;
		}

		$withdraw = array();
		$restore  = array();

		foreach ( $balloons as $balloon ) {
			$refunded = (int) $balloon->order_item_id && self::line_refunded( $order, (int) $balloon->order_item_id );

			if ( $refunded ) {
				$withdraw[] = (int) $balloon->id;
			} elseif ( null !== $balloon->withdrawn_at && 'admin' !== $balloon->withdrawn_reason ) {
				$restore[] = (int) $balloon->id;
			}
		}

		$result['withdrawn'] = VBR_Balloons::withdraw( $withdraw, 'refunded' );
		$result['restored']  = VBR_Balloons::restore( $restore );

		return $result;
	}

	/**
	 * Whether an order line has been fully refunded (all of its quantity, or all of its value).
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Order item ID.
	 * @return bool
	 */
	public static function line_refunded( $order, $item_id ) {
		$item = $order->get_item( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		$quantity = (int) $item->get_quantity();

		if ( $quantity > 0 && abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) >= $quantity ) {
			return true;
		}

		$total = (float) $item->get_total();

		return $total > 0 && (float) $order->get_total_refunded_for_item( $item_id ) >= $total - 0.005;
	}
}
