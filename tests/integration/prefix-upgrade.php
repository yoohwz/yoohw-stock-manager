<?php
/** Isolated upgrade scenarios, orchestrated by prefix-upgrade.py on an opted-in database. */
defined( 'ABSPATH' ) || exit;
if ( ! get_option( 'yoohwsm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable test opt-in required.' );
}
global $wpdb;
$mode = getenv( 'YOOHWSM_UPGRADE_MODE' );
$new = $wpdb->prefix . 'yoohwsm_movements';
$old = $wpdb->prefix . 'ysm_movements';
$backup = $wpdb->prefix . 'yoohwsm_upgrade_backup';
$fixture = get_option( 'yoohwsm_upgrade_fixture' );
function yoohwsm_upgrade_check( $value, $message ) {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}
if ( 'setup' === $mode ) {
	yoohwsm_upgrade_check( ! $fixture && ! in_array( $old, yoohwsm_storage_tables(), true ), 'Unexpected pre-existing upgrade fixture/legacy table.' );
	wp_set_current_user( 1 );
	$p = new WC_Product_Simple();
	$p->set_name( 'YoOhwSM upgrade fixture' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 10 );
	$p->save();
	$uuid = wp_generate_uuid4();
	yoohwsm_upgrade_check( 'success' === YOOHWSM_Store::adjust( $p->get_id(), 'increase', '1', 'correction', 'Existing receipt', $uuid ), 'Upgrade receipt setup failed.' );
	$fixture = [ 'id' => $p->get_id(), 'uuid' => $uuid, 'rows' => $wpdb->get_results( "SELECT * FROM $new ORDER BY id", ARRAY_A ), 'error' => get_option( 'ysm_history_error', false ), 'marker' => get_option( 'ysm_schema_version', false ) ];
	update_option( 'yoohwsm_upgrade_fixture', $fixture, false );
	update_option( 'ysm_history_error', '2026-11-20 00:00:00', false );
	set_transient( 'ysm_notice_1', [ 'counts' => [ 'success' => 1, 'failed' => 0, 'skipped' => 0 ], 'errors' => [] ], 600 );
}
if ( in_array( $mode, [ 'legacy', 'rename_failure' ], true ) ) {
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $new, $old ) );
	delete_option( 'yoohwsm_schema_version' );
	update_option( 'ysm_schema_version', '1', false );
	if ( 'rename_failure' === $mode ) {
		$fail = static function ( $sql ) { return 0 === strpos( $sql, 'RENAME TABLE' ) ? 'INVALID UPGRADE RENAME' : $sql; };
		add_filter( 'query', $fail );
		$quiet = $wpdb->suppress_errors( true );
		yoohwsm_upgrade_check( false === yoohwsm_install(), 'Failed rename enabled storage.' );
		$wpdb->suppress_errors( $quiet );
		remove_filter( 'query', $fail );
		yoohwsm_upgrade_check( [ $old ] === yoohwsm_storage_tables(), 'Failed rename created a replacement ledger.' );
	}
} elseif ( 'verify' === $mode ) {
	yoohwsm_upgrade_check( YOOHWSM_STORAGE_READY && [ $new ] === yoohwsm_storage_tables(), 'Upgrade bootstrap not ready.' );
	yoohwsm_upgrade_check( $fixture['rows'] === $wpdb->get_results( "SELECT * FROM $new ORDER BY id", ARRAY_A ), 'Upgrade changed a movement row or receipt.' );
	yoohwsm_upgrade_check( 11.0 === (float) wc_get_product( $fixture['id'] )->get_stock_quantity(), 'Upgrade changed Woo stock.' );
	wp_set_current_user( 1 );
	yoohwsm_upgrade_check( 'skipped' === YOOHWSM_Store::adjust( $fixture['id'], 'increase', '1', 'correction', 'Existing receipt', $fixture['uuid'] ), 'Legacy receipt replay not skipped.' );
	yoohwsm_upgrade_check( '2026-11-20 00:00:00' === get_option( 'ysm_history_error' ) && false !== get_transient( 'ysm_notice_1' ), 'Legacy warning/notice lost.' );
} elseif ( 'conflict' === $mode ) {
	$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $old, $new ) );
} elseif ( 'missing' === $mode ) {
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $new, $backup ) );
} elseif ( 'blocked' === $mode ) {
	yoohwsm_upgrade_check( ! YOOHWSM_STORAGE_READY && ! class_exists( 'YOOHWSM_Store' ) && ! isset( $GLOBALS['yoohwsm_tracker'] ), 'Unsafe bootstrap enabled stock runtime.' );
	require_once dirname( __DIR__, 2 ) . '/inc/class-yoohwsm-store.php';
	wp_set_current_user( 1 );
	yoohwsm_upgrade_check( is_wp_error( YOOHWSM_Store::adjust( $fixture['id'], 'increase', '1', 'correction', '', wp_generate_uuid4() ) ), 'Direct service bypassed storage readiness.' );
} elseif ( 'recover_conflict' === $mode ) {
	yoohwsm_upgrade_check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $old" ), 'Conflict fixture is not empty.' );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $old ) );
	yoohwsm_upgrade_check( yoohwsm_install(), 'Conflict recovery failed.' );
} elseif ( 'recover_missing' === $mode ) {
	yoohwsm_upgrade_check( [] === yoohwsm_storage_tables(), 'Missing ledger replaced with empty table.' );
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $backup, $new ) );
	yoohwsm_upgrade_check( yoohwsm_install(), 'Missing ledger recovery failed.' );
} elseif ( 'lookup_failure' === $mode ) {
	$fail = static function ( $sql ) { return false !== strpos( $sql, 'SELECT TABLE_NAME FROM information_schema.TABLES' ) ? 'INVALID UPGRADE LOOKUP' : $sql; };
	add_filter( 'query', $fail );
	yoohwsm_upgrade_check( null === yoohwsm_storage_tables() && false === yoohwsm_install(), 'DB lookup failure treated as missing table.' );
	remove_filter( 'query', $fail );
} elseif ( 'cleanup' === $mode && $fixture ) {
	wp_delete_post( $fixture['id'], true );
	$wpdb->delete( $new, [ 'product_id' => $fixture['id'] ] );
	foreach ( [ 'ysm_history_error' => 'error', 'ysm_schema_version' => 'marker' ] as $key => $saved ) {
		if ( false === $fixture[ $saved ] ) {
			delete_option( $key );
		} else {
			update_option( $key, $fixture[ $saved ], false );
		}
	}
	delete_transient( 'ysm_notice_1' );
	delete_option( 'yoohwsm_upgrade_fixture' );
}
echo 'PASS prefix upgrade ' . $mode . "\n";
