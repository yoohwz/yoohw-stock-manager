<?php
/** Shared Free helpers. @package YoOhwStockManager */
defined( 'ABSPATH' ) || exit;

function ysm_premium_active() {
	return defined( 'WCESS_VERSION' ) || class_exists( 'WC_Extended_Stock_Status' ) || function_exists( 'wcess_inventory_service' );
}

function ysm_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = $wpdb->prefix . 'ysm_movements';
	$collate = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		operation_key varchar(64) NOT NULL,
		payload_hash varchar(64) NOT NULL DEFAULT '',
		product_id bigint(20) unsigned NOT NULL,
		product_name text NOT NULL,
		sku varchar(255) NOT NULL DEFAULT '',
		stock_before decimal(24,6) NOT NULL,
		stock_after decimal(24,6) NOT NULL,
		quantity_delta decimal(24,6) NOT NULL,
		source varchar(32) NOT NULL,
		source_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		reason varchar(64) NOT NULL,
		note text NOT NULL,
		created_at_gmt datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY operation_key (operation_key),
		KEY product_date (product_id,created_at_gmt),
		KEY created_id (created_at_gmt,id)
	) ENGINE=InnoDB $collate;" );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
		update_option( 'ysm_schema_version', '1', false );
	}
}

/** Strict input: do not silently truncate exponent, invalid or excessive precision. */
function ysm_quantity( $value ) {
	if ( ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) || ! preg_match( '/^-?\d{1,12}(?:\.\d{1,6})?$/D', (string) $value ) ) {
		return null;
	}
	return (float) $value;
}

function ysm_reasons() {
	return [
		'manual_adjustment' => __( 'Manual adjustment', 'yoohw-stock-manager' ),
		'inventory_count' => __( 'Inventory count', 'yoohw-stock-manager' ),
		'correction' => __( 'Correction', 'yoohw-stock-manager' ),
		'goods_received' => __( 'Goods received', 'yoohw-stock-manager' ),
	];
}

function ysm_csv_row( array $row ) {
	return array_map( static function ( $value ) {
		$value = (string) $value;
		return preg_match( '/^[\s\x00-\x20]*[=+\-@]/u', $value ) ? "'" . $value : $value;
	}, $row );
}

function ysm_owner( $id ) {
	$product = wc_get_product( absint( $id ) );
	return $product ? wc_get_product( $product->get_stock_managed_by_id() ) : false;
}

function ysm_can_edit( $product ) {
	return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
}
