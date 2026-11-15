<?php
/** Disposable HTTP-boundary fixtures, never loaded by the plugin. */
defined( 'ABSPATH' ) || exit;
if ( ! get_option( 'ysm_disposable_tests' ) ) {
	throw new RuntimeException( 'Disposable test opt-in required.' );
}
global $wpdb;
$mode = getenv( 'YSM_HTTP_MODE' );
if ( 'setup' === $mode ) {
	$ids = [];
	foreach ( [ true, false ] as $managed ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'YSM HTTP fixture ' . wp_generate_uuid4() );
		$p->set_manage_stock( $managed );
		if ( $managed ) {
			$p->set_stock_quantity( 10 );
		}
		$p->save();
		$ids[] = $p->get_id();
	}
	$users = [];
	foreach ( [ 'administrator', 'subscriber' ] as $role ) {
		$login = 'ysm_' . $role . '_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
		$id = wp_create_user( $login, getenv( 'YSM_HTTP_PASSWORD' ) );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'HTTP fixture user failed.' );
		}
		$user = new WP_User( $id );
		$user->set_role( $role );
		$users[] = [ 'id' => $id, 'login' => $login ];
	}
	$data = [ 'products' => $ids, 'users' => $users ];
	update_option( 'ysm_http_fixture', $data, false );
	echo wp_json_encode( $data );
} elseif ( 'verify' === $mode ) {
	$data = get_option( 'ysm_http_fixture' );
	$id = $data['products'][0];
	YSM_Store::forget( $id );
	if ( 11.0 !== (float) wc_get_product( $id )->get_stock_quantity() || 1 !== YSM_Store::history( [ 'product_id' => $id, 'source' => 'free' ], 25, 0, 0, true ) ) {
		throw new RuntimeException( 'HTTP replay/denied request changed stock or duplicated history.' );
	}
} elseif ( 'cleanup' === $mode ) {
	$data = get_option( 'ysm_http_fixture' );
	foreach ( $data['products'] as $id ) {
		wp_delete_post( $id, true );
		$wpdb->delete( YSM_Store::table(), [ 'product_id' => $id ] );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $data['users'] as $user ) {
		wp_delete_user( $user['id'] );
	}
	delete_option( 'ysm_http_fixture' );
}
