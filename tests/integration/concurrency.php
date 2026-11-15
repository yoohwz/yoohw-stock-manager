<?php
/** Worker for cross-process stock tests; only a disposable opt-in store. */
if ( ! get_option( 'ysm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable opt-in required.' );
}
global $wpdb;
wp_set_current_user( 1 );
$mode = getenv( 'YSM_RACE_MODE' );
$id = (int) getenv( 'YSM_RACE_ID' );
if ( 'create' === $mode ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'YSM concurrency fixture' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 100 );
	$p->save();
	echo $p->get_id();
} elseif ( 'adjust' === $mode ) {
	$r = YSM_Store::adjust( $id, 'increase', '1', 'correction', '', getenv( 'YSM_RACE_UUID' ) );
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( $r->get_error_message() );
	}
	echo $r;
} elseif ( 'external' === $mode ) {
	wc_update_product_stock( $id, 1, 'decrease' );
} elseif ( 'verify' === $mode ) {
	YSM_Store::forget( $id );
	$stock = (float) wc_get_product( $id )->get_stock_quantity();
	if ( 102.0 !== $stock ) {
		throw new RuntimeException( 'Concurrent stock expected 102, got ' . $stock );
	}
	$count = YSM_Store::history( [ 'product_id' => $id, 'source' => 'free' ], 25, 0, 0, true );
	if ( 3 !== $count ) {
		throw new RuntimeException( 'Concurrent replay created duplicate or missing Free history.' );
	}
	echo 'PASS cross-process replay, additive changes and Woo external writer';
} elseif ( 'cleanup' === $mode ) {
	wp_delete_post( $id, true );
	$wpdb->delete( YSM_Store::table(), [ 'product_id' => $id ] );
}
