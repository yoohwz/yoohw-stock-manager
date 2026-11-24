<?php
/** Observe standard Woo stock hooks without handling stock a second time. @package YoOhwStockDesk */
defined( 'ABSPATH' ) || exit;

class YOOHWSM_Tracker {
	private $before = [];

	public function __construct() {
		foreach ( [ 'product', 'variation' ] as $type ) {
			add_action( "woocommerce_{$type}_before_set_stock", [ $this, 'before' ], 1 );
			add_action( "woocommerce_{$type}_set_stock", [ $this, 'after' ], 999 );
		}
		add_action( 'woocommerce_after_product_object_save', [ $this, 'discard' ], 1000 );
	}

	/** Source belongs to the actual stock call, not a later order status guess. */
	private function context() {
		$context = [ 'source' => 'woocommerce', 'reason' => 'stock_adjustment' ];
		foreach ( debug_backtrace( 0, 24 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Bounded attribution; never logged or persisted.
			$name = $frame['function'];
			if ( in_array( $name, [ 'wc_reduce_stock_levels', 'wc_increase_stock_levels', 'wc_restock_refunded_items' ], true ) ) {
				$order = wc_get_order( $frame['args'][0] ?? 0 );
				if ( $order ) {
					$context = [ 'source' => 'wc_restock_refunded_items' === $name ? 'refund' : 'order', 'source_id' => $order->get_id(), 'reason' => [ 'wc_reduce_stock_levels' => 'order_sale', 'wc_increase_stock_levels' => 'order_restore', 'wc_restock_refunded_items' => 'refund_return' ][ $name ] ];
				}
				break;
			}
		}
		return $context;
	}

	public function before( $product ) {
		global $wpdb;
		if ( YOOHWSM_Store::$adjusting || yoohwsm_premium_active() || ! $product->get_id() || ! $product->managing_stock() ) {
			return;
		}
		$id = $product->get_stock_managed_by_id();
		$stock = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Observe the actual pre-write Woo balance; internal table and prepared ID; product/meta cache may be stale.
		// The first product save has no previous tracked balance.
		$this->before[ $id ] = null === $stock || '' === $stock ? null : [ 'quantity' => (float) $stock, 'context' => $this->context() ];
	}

	public function after( $product ) {
		global $wpdb;
		$id = $product->get_stock_managed_by_id();
		$before = $this->before[ $id ] ?? null;
		unset( $this->before[ $id ] );
		if ( ! $before || YOOHWSM_Store::$adjusting || yoohwsm_premium_active() ) {
			return;
		}
		$after = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Observe the actual post-write Woo balance; internal table and prepared ID; product/meta cache may be stale.
		if ( null !== $after && abs( (float) $after - $before['quantity'] ) > 0.0000005 ) {
			if ( ! YOOHWSM_Store::record( yoohwsm_owner( $id ), $before['quantity'], (float) $after, $before['context'] ) ) {
				update_option( 'yoohwsm_history_error', current_time( 'mysql', true ), false );
			}
		}
	}

	public function discard( $product ) {
		// Woo's additive update wraps product->save(); preserve that outer capture.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Bounded hook pairing only.
			if ( 'wc_update_product_stock' === $frame['function'] ) {
				return;
			}
		}
		unset( $this->before[ $product->get_stock_managed_by_id() ] );
	}
}
