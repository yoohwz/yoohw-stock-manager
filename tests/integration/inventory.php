<?php
/** Disposable-store functional and integrity tests. */
if ( ! defined( 'ABSPATH' ) || ! get_option( 'yoohwsm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable test site opt-in required.' );
}
function yoohwsm_assert( $ok, $message ) {
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
}
global $wpdb;
wp_set_current_user( 1 );
$test_ids = [];
$orders = [];
try {
	$p = new WC_Product_Simple();
	$p->set_name( 'YSM Test Simple' );
	$p->set_sku( 'yoohwsm-' . wp_generate_uuid4() );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 12 );
	$p->set_regular_price( 10 );
	$p->save();
	$test_ids[] = $p->get_id();
	$id = $p->get_id();
	// Read-only filtering must honor statuses supplied by existing Woo extensions.
	$custom_status = static function ( $options ) { return $options + [ 'custom_stock' => 'Custom stock' ]; };
	add_filter( 'woocommerce_product_stock_status_options', $custom_status );
	$wpdb->update( $wpdb->prefix . 'wc_product_meta_lookup', [ 'stock_status' => 'custom_stock' ], [ 'product_id' => $id ] );
	yoohwsm_assert( [ $id ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 'status' => 'custom_stock' ] ) ), 'Woo custom stock status filter ignored' );
	$wpdb->update( $wpdb->prefix . 'wc_product_meta_lookup', [ 'stock_status' => 'instock' ], [ 'product_id' => $id ] );
	remove_filter( 'woocommerce_product_stock_status_options', $custom_status );
	$key = wp_generate_uuid4();
	$adjust = static function ( $op, $qty, $uuid = null, $pid = null ) use ( $id ) {
		return YOOHWSM_Store::adjust( $pid ?: $id, $op, $qty, 'inventory_count', 'Fixture note', $uuid ?: wp_generate_uuid4() );
	};
	$r = $adjust( 'increase', '5', $key );
	yoohwsm_assert( 'success' === $r, 'Increase failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : $r ) );
	yoohwsm_assert( 17.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Increase balance' );
	yoohwsm_assert( 'skipped' === $adjust( 'increase', '5', $key ), 'Replay not skipped' );
	yoohwsm_assert( is_wp_error( $adjust( 'increase', '6', $key ) ), 'Payload conflict accepted' );
	yoohwsm_assert( 'success' === $adjust( 'decrease', '3' ), 'Decrease' );
	yoohwsm_assert( 'success' === $adjust( 'set', '-2' ), 'Set/backorder balance' );
	$noop = wp_generate_uuid4();
	yoohwsm_assert( 'skipped' === $adjust( 'set', '-2', $noop ), 'No-op receipt' );
	$adjust( 'increase', '2' );
	yoohwsm_assert( 'skipped' === $adjust( 'set', '-2', $noop ), 'No-op replay changed stock after later operation' );
	yoohwsm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'No-op replay balance' );
	yoohwsm_assert( is_wp_error( $adjust( 'increase', 'bad' ) ), 'Invalid quantity' );
	wp_set_current_user( 0 );
	yoohwsm_assert( is_wp_error( $adjust( 'increase', '3' ) ), 'Unauthorized adjustment' );
	wp_set_current_user( 1 );
	// SQL write failure must roll back stock and not leave observer records.
	$before_count = YOOHWSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true );
	$fail = static function ( $query ) { return false !== strpos( $query, 'INSERT INTO `' . YOOHWSM_Store::table() . '`' ) ? 'INVALID YSM TEST SQL' : $query; };
	$old = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail );
	$result = $adjust( 'increase', '4' );
	remove_filter( 'query', $fail );
	$wpdb->suppress_errors( $old );
	yoohwsm_assert( is_wp_error( $result ), 'Failure injection not rejected' );
	yoohwsm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Stock not rolled back' );
	yoohwsm_assert( $before_count === YOOHWSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true ), 'Rollback observer leaked' );
	// Keep status unchanged across the failed write so stale lookup cache cannot
	// be accidentally refreshed by an out-of-stock -> in-stock transition.
	yoohwsm_assert( 'success' === $adjust( 'set', '10' ), 'Lookup failure setup' );
	++$before_count;
	// Woo API also ignores lookup write failures; verify and roll back the whole change.
	$fail_lookup = static function ( $query ) use ( $wpdb ) {
		return preg_match( '/^(?:INSERT|REPLACE|UPDATE).*' . preg_quote( $wpdb->prefix . 'wc_product_meta_lookup', '/' ) . '/i', $query ) ? 'INVALID YSM LOOKUP TEST SQL' : $query;
	};
	$old = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_lookup );
	$result = $adjust( 'increase', '6' );
	remove_filter( 'query', $fail_lookup );
	$wpdb->suppress_errors( $old );
	yoohwsm_assert( is_wp_error( $result ), 'Lookup failure committed' );
	yoohwsm_assert( 10.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Lookup failure stock not rolled back' );
	// Retry the same balance without flushing the process cache: Woo caches lookup
	// data even when its SQL write fails, and must not skip the retry's SQL write.
	yoohwsm_assert( 'success' === $adjust( 'increase', '6' ), 'Lookup rollback cache prevented retry' );
	yoohwsm_assert( 'success' === $adjust( 'decrease', '16' ), 'Lookup retry cleanup' );
	$before_count += 2;
	$fail_status = static function ( $query ) use ( $wpdb ) {
		return preg_match( '/^UPDATE.*' . preg_quote( $wpdb->postmeta, '/' ) . '.*meta_value.*instock/i', $query ) ? 'INVALID YSM STATUS TEST SQL' : $query;
	};
	$old = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_status );
	$result = $adjust( 'increase', '7' );
	remove_filter( 'query', $fail_status );
	$wpdb->suppress_errors( $old );
	yoohwsm_assert( is_wp_error( $result ), 'Status failure committed' );
	yoohwsm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Status failure stock not rolled back' );
	// Existing caller transactions must survive refused adjustments.
	$wpdb->query( 'START TRANSACTION' );
	$wpdb->query( 'SAVEPOINT yoohwsm_test_caller' );
	yoohwsm_assert( is_wp_error( $adjust( 'increase', '4' ) ), 'Nested transaction accepted' );
	$quiet = $wpdb->suppress_errors( true );
	$wpdb->query( 'ROLLBACK TO SAVEPOINT yoohwsm_test_absent' );
	$wpdb->suppress_errors( $quiet );
	$cancel_probe = static function ( $sql ) {
		return 0 === strpos( $sql, 'ROLLBACK TO SAVEPOINT `yoohwsm_probe_' ) ? '' : $sql;
	};
	add_filter( 'query', $cancel_probe );
	try {
		yoohwsm_assert( is_wp_error( $adjust( 'increase', '4' ) ), 'Cancelled probe accepted stale error' );
	} finally {
		remove_filter( 'query', $cancel_probe );
	}
	yoohwsm_assert( false !== $wpdb->query( 'ROLLBACK TO SAVEPOINT yoohwsm_test_caller' ), 'Caller transaction was committed' );
	$wpdb->query( 'ROLLBACK' );
	foreach ( [ 'ROLLBACK TO SAVEPOINT `yoohwsm_probe_', 'SHOW ERRORS LIMIT 2' ] as $prefix ) {
		$fail_probe = static function ( $sql ) use ( $prefix ) {
			return 0 === strpos( $sql, $prefix ) ? 'INVALID YOOHWSM PROBE SQL' : $sql;
		};
		add_filter( 'query', $fail_probe );
		try {
			yoohwsm_assert( is_wp_error( $adjust( 'increase', '4' ) ), 'Unexpected probe or diagnostic failure accepted' );
		} finally {
			remove_filter( 'query', $fail_probe );
		}
	}
	yoohwsm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Failed transaction probe changed stock' );
	// A standard product save is observed once.
	$p = wc_get_product( $id );
	$p->set_stock_quantity( 20 );
	$p->save();
	yoohwsm_assert( $before_count + 1 === YOOHWSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true ), 'Product save history duplicate/missing' );
	$order = wc_create_order();
	$orders[] = $order;
	$item_id = $order->add_product( wc_get_product( $id ), 2 );
	$order->calculate_totals();
	wc_reduce_stock_levels( $order );
	$rows = YOOHWSM_Store::history( [ 'product_id' => $id ] );
	yoohwsm_assert( 'order_sale' === $rows[0]['reason'] && (int) $rows[0]['source_id'] === $order->get_id(), 'Order reduction source' );
	$count = count( $rows );
	wc_reduce_stock_levels( $order );
	yoohwsm_assert( $count === count( YOOHWSM_Store::history( [ 'product_id' => $id ] ) ), 'Repeated order duplicate' );
	wc_increase_stock_levels( $order );
	$rows = YOOHWSM_Store::history( [ 'product_id' => $id ] );
	yoohwsm_assert( 'order_restore' === $rows[0]['reason'] && $count + 1 === count( $rows ), 'Order restore source/duplicate' );
	wc_reduce_stock_levels( $order );
	wc_restock_refunded_items( $order, [ $item_id => [ 'qty' => 1 ] ] );
	$rows = YOOHWSM_Store::history( [ 'product_id' => $id ] );
	yoohwsm_assert( 'refund_return' === $rows[0]['reason'] && 'refund' === $rows[0]['source'], 'Refund source' );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'YSM Shared Parent' );
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 9 );
	$parent->save();
	$test_ids[] = $parent->get_id();
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent->get_id() );
	$v->set_manage_stock( false );
	$v->set_regular_price( 10 );
	$v->set_sku( 'yoohwsm-child-' . wp_generate_uuid4() );
	$v->save();
	$test_ids[] = $v->get_id();
	yoohwsm_assert( $parent->get_id() === yoohwsm_owner( $v->get_id() )->get_id(), 'Parent owner' );
	yoohwsm_assert( 'success' === $adjust( 'increase', '2', null, $v->get_id() ), 'Shared parent adjustment' );
	$ids = array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $v->get_sku() ] ) );
	yoohwsm_assert( in_array( $parent->get_id(), $ids, true ) && ! in_array( $v->get_id(), $ids, true ), 'Shared stock inventory duplication/search' );
	$shared_order = wc_create_order();
	$orders[] = $shared_order;
	$shared_item = $shared_order->add_product( wc_get_product( $v->get_id() ), 2 );
	$shared_order->calculate_totals();
	$shared_count = YOOHWSM_Store::history( [ 'product_id' => $parent->get_id() ], 25, 0, 0, true );
	wc_reduce_stock_levels( $shared_order );
	yoohwsm_assert( 9.0 === (float) wc_get_product( $parent->get_id() )->get_stock_quantity(), 'Shared-parent order reduction' );
	wc_restock_refunded_items( $shared_order, [ $shared_item => [ 'qty' => 1 ] ] );
	yoohwsm_assert( 10.0 === (float) wc_get_product( $parent->get_id() )->get_stock_quantity(), 'Shared-parent refund return' );
	wc_increase_stock_levels( $shared_order );
	yoohwsm_assert( 11.0 === (float) wc_get_product( $parent->get_id() )->get_stock_quantity(), 'Shared-parent remaining order restore' );
	yoohwsm_assert( $shared_count + 3 === YOOHWSM_Store::history( [ 'product_id' => $parent->get_id() ], 25, 0, 0, true ), 'Shared-parent order/refund duplicate or missing history' );
	yoohwsm_assert( 0 === YOOHWSM_Store::history( [ 'product_id' => $v->get_id() ], 25, 0, 0, true ), 'Shared-parent history attributed to inherited variation' );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 7 );
	$v->save();
	yoohwsm_assert( 'success' === $adjust( 'decrease', '2', null, $v->get_id() ), 'Own-stock variation' );
	yoohwsm_assert( 5.0 === (float) wc_get_product( $v->get_id() )->get_stock_quantity(), 'Variation balance' );
	$own_order = wc_create_order();
	$orders[] = $own_order;
	$own_order->add_product( wc_get_product( $v->get_id() ), 2 );
	$own_order->calculate_totals();
	$own_count = YOOHWSM_Store::history( [ 'product_id' => $v->get_id() ], 25, 0, 0, true );
	wc_reduce_stock_levels( $own_order );
	yoohwsm_assert( 3.0 === (float) wc_get_product( $v->get_id() )->get_stock_quantity(), 'Own-stock variation order reduction' );
	wc_increase_stock_levels( $own_order );
	yoohwsm_assert( 5.0 === (float) wc_get_product( $v->get_id() )->get_stock_quantity(), 'Own-stock variation order restore' );
	yoohwsm_assert( $own_count + 2 === YOOHWSM_Store::history( [ 'product_id' => $v->get_id() ], 25, 0, 0, true ), 'Own-stock variation order history duplicate/missing' );
	remove_filter( 'woocommerce_stock_amount', 'intval' );
	add_filter( 'woocommerce_stock_amount', 'floatval' );
	yoohwsm_assert( 'success' === $adjust( 'set', '2.125' ), 'Decimal set' );
	yoohwsm_assert( 'success' === $adjust( 'increase', '0.125' ), 'Decimal increase' );
	yoohwsm_assert( 2.25 === (float) wc_get_product( $id )->get_stock_quantity(), 'Decimal balance' );
	remove_filter( 'woocommerce_stock_amount', 'floatval' );
	add_filter( 'woocommerce_stock_amount', 'intval' );
	require_once dirname( __DIR__, 2 ) . '/inc/class-yoohwsm-admin.php';
	for ( $index = 0; $index < 260; ++$index ) {
		YOOHWSM_Store::record( wc_get_product( $id ), 1, 2, [ 'source' => 'chunk_fixture', 'reason' => 'correction' ] );
	}
	$chunk_stream = fopen( 'php://temp', 'w+' );
	YOOHWSM_Admin::write_csv( $chunk_stream, true, [ 'product_id' => $id, 'source' => 'chunk_fixture' ] );
	rewind( $chunk_stream );
	$chunk_rows = 0;
	while ( false !== fgetcsv( $chunk_stream, 0, ',', '"', '' ) ) {
		++$chunk_rows;
	}
	fclose( $chunk_stream );
	yoohwsm_assert( 261 === $chunk_rows, 'CSV cursor skipped or duplicated rows across chunks' );
	$stream = fopen( 'php://temp', 'w+' );
	YOOHWSM_Admin::write_csv( $stream, true, [ 'product_id' => $id, 'source' => 'refund' ] );
	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );
	yoohwsm_assert( false !== strpos( $csv, 'refund_return' ) && false === strpos( $csv, 'order_sale' ), 'History CSV filter scope' );
	echo "PASS adjustments, replay, rollback, auth, order/refund, product save, parent/variation, decimals, scoped CSV\n";
} finally {
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
	foreach ( array_reverse( $test_ids ) as $pid ) {
		wp_delete_post( $pid, true );
		$wpdb->delete( YOOHWSM_Store::table(), [ 'product_id' => $pid ] );
	}
}
