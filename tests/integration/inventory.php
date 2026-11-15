<?php
/** Disposable-store functional and integrity tests. */
if ( ! defined( 'ABSPATH' ) || ! get_option( 'ysm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable test site opt-in required.' );
}
function ysm_assert( $ok, $message ) {
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
	$p->set_sku( 'ysm-' . wp_generate_uuid4() );
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
	ysm_assert( [ $id ] === array_map( 'intval', YSM_Store::inventory( [ 'status' => 'custom_stock' ] ) ), 'Woo custom stock status filter ignored' );
	$wpdb->update( $wpdb->prefix . 'wc_product_meta_lookup', [ 'stock_status' => 'instock' ], [ 'product_id' => $id ] );
	remove_filter( 'woocommerce_product_stock_status_options', $custom_status );
	$key = wp_generate_uuid4();
	$adjust = static function ( $op, $qty, $uuid = null, $pid = null ) use ( $id ) {
		return YSM_Store::adjust( $pid ?: $id, $op, $qty, 'inventory_count', 'Fixture note', $uuid ?: wp_generate_uuid4() );
	};
	$r = $adjust( 'increase', '5', $key );
	ysm_assert( 'success' === $r, 'Increase failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : $r ) );
	ysm_assert( 17.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Increase balance' );
	ysm_assert( 'skipped' === $adjust( 'increase', '5', $key ), 'Replay not skipped' );
	ysm_assert( is_wp_error( $adjust( 'increase', '6', $key ) ), 'Payload conflict accepted' );
	ysm_assert( 'success' === $adjust( 'decrease', '3' ), 'Decrease' );
	ysm_assert( 'success' === $adjust( 'set', '-2' ), 'Set/backorder balance' );
	$noop = wp_generate_uuid4();
	ysm_assert( 'skipped' === $adjust( 'set', '-2', $noop ), 'No-op receipt' );
	$adjust( 'increase', '2' );
	ysm_assert( 'skipped' === $adjust( 'set', '-2', $noop ), 'No-op replay changed stock after later operation' );
	ysm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'No-op replay balance' );
	ysm_assert( is_wp_error( $adjust( 'increase', 'bad' ) ), 'Invalid quantity' );
	wp_set_current_user( 0 );
	ysm_assert( is_wp_error( $adjust( 'increase', '3' ) ), 'Unauthorized adjustment' );
	wp_set_current_user( 1 );
	// SQL write failure must roll back stock and not leave observer records.
	$before_count = YSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true );
	$fail = static function ( $query ) { return false !== strpos( $query, 'INSERT INTO `' . YSM_Store::table() . '`' ) ? 'INVALID YSM TEST SQL' : $query; };
	$old = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail );
	$result = $adjust( 'increase', '4' );
	remove_filter( 'query', $fail );
	$wpdb->suppress_errors( $old );
	ysm_assert( is_wp_error( $result ), 'Failure injection not rejected' );
	ysm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Stock not rolled back' );
	ysm_assert( $before_count === YSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true ), 'Rollback observer leaked' );
	// Keep status unchanged across the failed write so stale lookup cache cannot
	// be accidentally refreshed by an out-of-stock -> in-stock transition.
	ysm_assert( 'success' === $adjust( 'set', '10' ), 'Lookup failure setup' );
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
	ysm_assert( is_wp_error( $result ), 'Lookup failure committed' );
	ysm_assert( 10.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Lookup failure stock not rolled back' );
	// Retry the same balance without flushing the process cache: Woo caches lookup
	// data even when its SQL write fails, and must not skip the retry's SQL write.
	ysm_assert( 'success' === $adjust( 'increase', '6' ), 'Lookup rollback cache prevented retry' );
	ysm_assert( 'success' === $adjust( 'decrease', '16' ), 'Lookup retry cleanup' );
	$before_count += 2;
	$fail_status = static function ( $query ) use ( $wpdb ) {
		return preg_match( '/^UPDATE.*' . preg_quote( $wpdb->postmeta, '/' ) . '.*meta_value.*instock/i', $query ) ? 'INVALID YSM STATUS TEST SQL' : $query;
	};
	$old = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_status );
	$result = $adjust( 'increase', '7' );
	remove_filter( 'query', $fail_status );
	$wpdb->suppress_errors( $old );
	ysm_assert( is_wp_error( $result ), 'Status failure committed' );
	ysm_assert( 0.0 === (float) wc_get_product( $id )->get_stock_quantity(), 'Status failure stock not rolled back' );
	// Existing caller transactions must survive refused adjustments.
	$wpdb->query( 'START TRANSACTION' );
	ysm_assert( is_wp_error( $adjust( 'increase', '4' ) ), 'Nested transaction accepted' );
	$wpdb->query( 'ROLLBACK' );
	// A standard product save is observed once.
	$p = wc_get_product( $id );
	$p->set_stock_quantity( 20 );
	$p->save();
	ysm_assert( $before_count + 1 === YSM_Store::history( [ 'product_id' => $id ], 25, 0, 0, true ), 'Product save history duplicate/missing' );
	$order = wc_create_order();
	$orders[] = $order;
	$item_id = $order->add_product( wc_get_product( $id ), 2 );
	$order->calculate_totals();
	wc_reduce_stock_levels( $order );
	$rows = YSM_Store::history( [ 'product_id' => $id ] );
	ysm_assert( 'order_sale' === $rows[0]['reason'] && (int) $rows[0]['source_id'] === $order->get_id(), 'Order reduction source' );
	$count = count( $rows );
	wc_reduce_stock_levels( $order );
	ysm_assert( $count === count( YSM_Store::history( [ 'product_id' => $id ] ) ), 'Repeated order duplicate' );
	wc_increase_stock_levels( $order );
	$rows = YSM_Store::history( [ 'product_id' => $id ] );
	ysm_assert( 'order_restore' === $rows[0]['reason'] && $count + 1 === count( $rows ), 'Order restore source/duplicate' );
	wc_reduce_stock_levels( $order );
	wc_restock_refunded_items( $order, [ $item_id => [ 'qty' => 1 ] ] );
	$rows = YSM_Store::history( [ 'product_id' => $id ] );
	ysm_assert( 'refund_return' === $rows[0]['reason'] && 'refund' === $rows[0]['source'], 'Refund source' );
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
	$v->set_sku( 'ysm-child-' . wp_generate_uuid4() );
	$v->save();
	$test_ids[] = $v->get_id();
	ysm_assert( $parent->get_id() === ysm_owner( $v->get_id() )->get_id(), 'Parent owner' );
	ysm_assert( 'success' === $adjust( 'increase', '2', null, $v->get_id() ), 'Shared parent adjustment' );
	$ids = array_map( 'intval', YSM_Store::inventory( [ 's' => $v->get_sku() ] ) );
	ysm_assert( in_array( $parent->get_id(), $ids, true ) && ! in_array( $v->get_id(), $ids, true ), 'Shared stock inventory duplication/search' );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 7 );
	$v->save();
	ysm_assert( 'success' === $adjust( 'decrease', '2', null, $v->get_id() ), 'Own-stock variation' );
	ysm_assert( 5.0 === (float) wc_get_product( $v->get_id() )->get_stock_quantity(), 'Variation balance' );
	remove_filter( 'woocommerce_stock_amount', 'intval' );
	add_filter( 'woocommerce_stock_amount', 'floatval' );
	ysm_assert( 'success' === $adjust( 'set', '2.125' ), 'Decimal set' );
	ysm_assert( 'success' === $adjust( 'increase', '0.125' ), 'Decimal increase' );
	ysm_assert( 2.25 === (float) wc_get_product( $id )->get_stock_quantity(), 'Decimal balance' );
	remove_filter( 'woocommerce_stock_amount', 'floatval' );
	add_filter( 'woocommerce_stock_amount', 'intval' );
	require_once dirname( __DIR__, 2 ) . '/inc/class-ysm-admin.php';
	for ( $index = 0; $index < 260; ++$index ) {
		YSM_Store::record( wc_get_product( $id ), 1, 2, [ 'source' => 'chunk_fixture', 'reason' => 'correction' ] );
	}
	$chunk_stream = fopen( 'php://temp', 'w+' );
	YSM_Admin::write_csv( $chunk_stream, true, [ 'product_id' => $id, 'source' => 'chunk_fixture' ] );
	rewind( $chunk_stream );
	$chunk_rows = 0;
	while ( false !== fgetcsv( $chunk_stream, 0, ',', '"', '' ) ) {
		++$chunk_rows;
	}
	fclose( $chunk_stream );
	ysm_assert( 261 === $chunk_rows, 'CSV cursor skipped or duplicated rows across chunks' );
	$stream = fopen( 'php://temp', 'w+' );
	YSM_Admin::write_csv( $stream, true, [ 'product_id' => $id, 'source' => 'refund' ] );
	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );
	ysm_assert( false !== strpos( $csv, 'refund_return' ) && false === strpos( $csv, 'order_sale' ), 'History CSV filter scope' );
	echo "PASS adjustments, replay, rollback, auth, order/refund, product save, parent/variation, decimals, scoped CSV\n";
} finally {
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
	foreach ( array_reverse( $test_ids ) as $pid ) {
		wp_delete_post( $pid, true );
		$wpdb->delete( YSM_Store::table(), [ 'product_id' => $pid ] );
	}
}
