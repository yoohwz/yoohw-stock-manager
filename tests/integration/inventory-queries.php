<?php
/** Inventory scopes, Woo thresholds, pagination and both CSV streams. */
defined( 'ABSPATH' ) || exit;
if ( ! get_option( 'yoohwsm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable opt-in required.' );
}
global $wpdb, $failures;
wp_set_current_user( 1 );
$ids = [];
$terms = [];
$failures = [];
$prefix = 'YSM Query ' . substr( wp_generate_uuid4(), 0, 8 );
$old_timezone = get_option( 'timezone_string' );
$old_threshold = get_option( 'woocommerce_notify_low_stock_amount' );
function yoohwsm_query_check( $value, $message ) {
	global $failures;
	if ( ! $value ) {
		$failures[] = $message;
	}
}
try {
	update_option( 'woocommerce_notify_low_stock_amount', 2 );
	$cat = wp_insert_term( $prefix, 'product_cat' );
	$terms[] = $cat['term_id'];
	$child_cat = wp_insert_term( $prefix . ' Child', 'product_cat', [ 'parent' => $cat['term_id'] ] );
	$terms[] = $child_cat['term_id'];
	for ( $index = 0; $index < 261; ++$index ) {
		$p = new WC_Product_Simple();
		$p->set_name( $prefix . ' ' . sprintf( '%03d', $index ) );
		$p->set_sku( 'yoohwsm-query-' . str_replace( ' ', '-', $prefix ) . '-' . $index );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $index < 3 ? $index : 10 + $index );
		$p->set_category_ids( [ $child_cat['term_id'] ] );
		$p->save();
		$ids[] = $p->get_id();
	}
	$parent = new WC_Product_Variable();
	$parent->set_name( $prefix . ' Parent' );
	$parent->set_manage_stock( true );
	$parent->set_stock_quantity( 8 );
	$parent->set_category_ids( [ $child_cat['term_id'] ] );
	$parent->save();
	$ids[] = $parent->get_id();
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent->get_id() );
	$v->set_manage_stock( false );
	$v->set_regular_price( 10 );
	$v->set_sku( 'yoohwsm-shared-' . wp_generate_uuid4() );
	$v->save();
	$ids[] = $v->get_id();
	$shared_id = $v->get_id();
	$shared_sku = $v->get_sku();
	yoohwsm_query_check( [ $parent->get_id() ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $shared_sku ] ) ), 'Shared SKU owner scope' );
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent->get_id() );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 6 );
	$v->set_regular_price( 10 );
	$v->set_sku( 'yoohwsm-own-' . wp_generate_uuid4() );
	$v->save();
	$ids[] = $v->get_id();
	$own_id = $v->get_id();
	$own_sku = $v->get_sku();
	$expect_count = 263;
	yoohwsm_query_check( $expect_count === YOOHWSM_Store::inventory( [ 's' => $prefix ], 25, 0, true ), 'Inventory owner count' );
	yoohwsm_query_check( [ $own_id ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $own_sku ] ) ), 'Own-stock SKU incorrectly includes parent' );
	yoohwsm_query_check( [ $own_id ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => '#' . $own_id ] ) ), 'Own-stock ID incorrectly includes parent' );
	$numeric_sku_product = wc_get_product( $ids[2] );
	$numeric_sku_product->set_sku( $own_id . 'ABC' );
	$numeric_sku_product->save();
	yoohwsm_query_check( [ $ids[2] ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $own_id . 'ABC' ] ) ), 'Numeric-leading SKU incorrectly treated as product ID' );
	yoohwsm_query_check( $expect_count === YOOHWSM_Store::inventory( [ 'category' => $cat['term_id'] ], 25, 0, true ), 'Category descendants/variation category inheritance' );
	$page1 = array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $prefix, 'orderby' => 'id' ], 25 ) );
	$page2 = array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $prefix, 'orderby' => 'id' ], 25, 25 ) );
	yoohwsm_query_check( 25 === count( $page1 ) && 25 === count( $page2 ) && ! array_intersect( $page1, $page2 ), 'Inventory page overlap/size' );
	$stock_asc = YOOHWSM_Store::inventory( [ 's' => $prefix, 'orderby' => 'stock', 'order' => 'asc' ], 3 );
	$stock_desc = YOOHWSM_Store::inventory( [ 's' => $prefix, 'orderby' => 'stock', 'order' => 'desc' ], 1 );
	yoohwsm_query_check( $ids[0] === (int) $stock_asc[0] && $ids[260] === (int) $stock_desc[0], 'Quantity sorting' );
	yoohwsm_query_check( 2 === YOOHWSM_Store::inventory( [ 's' => $prefix, 'status' => 'low' ], 25, 0, true ), 'Low-stock summary' );
	yoohwsm_query_check( [ $ids[0] ] === array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $prefix, 'status' => 'outofstock' ] ) ), 'Out-of-stock scope' );
	// Woo supports fractional quantities, but its effective low-stock helper returns int.
	remove_filter( 'woocommerce_stock_amount', 'intval' );
	add_filter( 'woocommerce_stock_amount', 'floatval' );
	$p = wc_get_product( $ids[3] );
	$p->set_stock_quantity( 2.5 );
	$p->set_low_stock_amount( 2.9 );
	$p->save();
	yoohwsm_query_check( 2 === wc_get_low_stock_amount( $p ), 'Woo effective fractional threshold fixture' );
	yoohwsm_query_check( ! in_array( $ids[3], array_map( 'intval', YOOHWSM_Store::inventory( [ 's' => $prefix, 'status' => 'low' ] ) ), true ), 'Low-stock filter disagrees with Woo effective threshold' );
	remove_filter( 'woocommerce_stock_amount', 'floatval' );
	add_filter( 'woocommerce_stock_amount', 'intval' );
	require_once dirname( __DIR__, 2 ) . '/inc/class-yoohwsm-admin.php';
	$stream = fopen( 'php://temp', 'w+' );
	YOOHWSM_Admin::write_csv( $stream, false, [ 's' => $prefix ] );
	rewind( $stream );
	fgetcsv( $stream, 0, ',', '"', '' );
	$csv_ids = [];
	while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
		$csv_ids[] = (int) $row[0];
	}
	fclose( $stream );
	yoohwsm_query_check( $expect_count === count( $csv_ids ) && count( $csv_ids ) === count( array_unique( $csv_ids ) ), 'Inventory CSV chunk count/duplicates' );
	$stream = fopen( 'php://temp', 'w+' );
	YOOHWSM_Admin::write_csv( $stream, false, [ 's' => $own_sku ] );
	rewind( $stream );
	fgetcsv( $stream, 0, ',', '"', '' );
	$csv_own = [];
	while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
		$csv_own[] = (int) $row[0];
	}
	fclose( $stream );
	yoohwsm_query_check( [ $own_id ] === $csv_own, 'Inventory CSV exported parent outside own SKU scope' );
	update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' );
	$p = wc_get_product( $ids[0] );
	foreach ( [ '2026-11-15 16:59:59', '2026-11-15 17:00:00', '2026-11-16 16:59:59', '2026-11-16 17:00:00' ] as $date ) {
		YOOHWSM_Store::record( $p, 1, 2, [ 'created_at_gmt' => $date, 'source' => 'date_fixture', 'reason' => 'correction' ] );
	}
	$history_filter = [ 'product_id' => $p->get_id(), 'source' => 'date_fixture', 'reason' => 'correction', 'date_from' => '2026-11-16', 'date_to' => '2026-11-16' ];
	yoohwsm_query_check( 2 === YOOHWSM_Store::history( $history_filter, 25, 0, 0, true ), 'History date boundaries in site timezone' );
	yoohwsm_query_check( 0 === YOOHWSM_Store::history( array_merge( $history_filter, [ 'reason' => 'inventory_count' ] ), 25, 0, 0, true ), 'History reason scope' );
	wp_trash_post( $shared_id );
	yoohwsm_query_check( [] === YOOHWSM_Store::inventory( [ 's' => $shared_sku ] ), 'Trashed variation SKU still matches parent' );
	if ( $failures ) {
		throw new RuntimeException( implode( '; ', $failures ) );
	}
	echo "PASS inventory search/owner/category/status/sort/pages, Woo low thresholds, CSV 250-row boundary and history timezone filters\n";
} finally {
	remove_filter( 'woocommerce_stock_amount', 'floatval' );
	if ( ! has_filter( 'woocommerce_stock_amount', 'intval' ) ) {
		add_filter( 'woocommerce_stock_amount', 'intval' );
	}
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
		$wpdb->delete( YOOHWSM_Store::table(), [ 'product_id' => $id ] );
	}
	foreach ( array_reverse( $terms ) as $term_id ) {
		wp_delete_term( $term_id, 'product_cat' );
	}
	update_option( 'timezone_string', $old_timezone );
	update_option( 'woocommerce_notify_low_stock_amount', $old_threshold );
}
