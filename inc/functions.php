<?php
/** Shared Free helpers. @package YoOhwStockDesk */
defined( 'ABSPATH' ) || exit;

function yoohwsm_premium_active() {
	return defined( 'WCESS_VERSION' ) || class_exists( 'WC_Extended_Stock_Status' ) || function_exists( 'wcess_inventory_service' );
}

/** Inspect both ledger names; a failed lookup must never look like a new install. */
function yoohwsm_storage_tables() {
	global $wpdb;
	$quiet = $wpdb->suppress_errors( true );
	$tables = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', $wpdb->prefix . 'ysm_movements', $wpdb->prefix . 'yoohwsm_movements' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live schema check before atomic upgrade; both internal table names are prepared values.
	$failed = '' !== $wpdb->last_error;
	$wpdb->suppress_errors( $quiet );
	return $failed ? null : $tables;
}

/** Rename the existing ledger in place; never rebuild lost receipts or overwrite a ledger. */
function yoohwsm_install() {
	global $wpdb;
	$table = $wpdb->prefix . 'yoohwsm_movements';
	$legacy = $wpdb->prefix . 'ysm_movements'; // Pre-prefix-update storage, only used to preserve existing installations.
	$tables = yoohwsm_storage_tables();
	if ( null === $tables || ( in_array( $legacy, $tables, true ) && in_array( $table, $tables, true ) ) ) {
		return false;
	}
	if ( in_array( $legacy, $tables, true ) ) {
		$quiet = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic ledger rename preserves row IDs, receipt keys and all data; internal identifiers escaped with %i.
		$wpdb->suppress_errors( $quiet );
		// Another bootstrap may have completed the same rename while we waited.
		$tables = yoohwsm_storage_tables();
		if ( null === $tables || in_array( $legacy, $tables, true ) || ! in_array( $table, $tables, true ) ) {
			return false;
		}
	}
	if ( in_array( $table, $tables, true ) ) {
		update_option( 'yoohwsm_schema_version', '1', false );
		return true;
	}
	// A marker with no ledger means recovery is needed, not an empty fresh ledger.
	if ( false !== get_option( 'ysm_schema_version', false ) || false !== get_option( 'yoohwsm_schema_version', false ) ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
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
	$tables = yoohwsm_storage_tables();
	if ( null === $tables || in_array( $legacy, $tables, true ) || ! in_array( $table, $tables, true ) ) {
		return false;
	}
	update_option( 'yoohwsm_schema_version', '1', false );
	return true;
}

/** Strict input: do not silently truncate exponent, invalid or excessive precision. */
function yoohwsm_quantity( $value ) {
	if ( ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) || ! preg_match( '/^-?\d{1,12}(?:\.\d{1,6})?$/D', (string) $value ) ) {
		return null;
	}
	return (float) $value;
}

function yoohwsm_reasons() {
	return [
		'manual_adjustment' => __( 'Manual adjustment', 'yoohw-stock-desk' ),
		'inventory_count' => __( 'Inventory count', 'yoohw-stock-desk' ),
		'correction' => __( 'Correction', 'yoohw-stock-desk' ),
		'goods_received' => __( 'Goods received', 'yoohw-stock-desk' ),
	];
}

function yoohwsm_csv_row( array $row ) {
	return array_map( static function ( $value ) {
		$value = (string) $value;
		return preg_match( '/^[\s\x00-\x20]*[=+\-@]/u', $value ) ? "'" . $value : $value;
	}, $row );
}

function yoohwsm_owner( $id ) {
	$product = wc_get_product( absint( $id ) );
	return $product ? wc_get_product( $product->get_stock_managed_by_id() ) : false;
}

function yoohwsm_can_edit( $product ) {
	return current_user_can( 'manage_woocommerce' ) && current_user_can( 'edit_post', $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
}
