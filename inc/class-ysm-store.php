<?php
/** WooCommerce-backed inventory and retained movement storage. @package YoOhwStockManager */
defined( 'ABSPATH' ) || exit;

class YSM_Store {
	public static $adjusting = false;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ysm_movements';
	}

	public static function forget( $id ) {
		clean_post_cache( $id );
		wp_cache_delete( 'lookup_table', 'object_' . $id );
		wc_delete_product_transients( $id );
		$product = wc_get_product( $id );
		if ( $product && $product->get_parent_id() ) {
			clean_post_cache( $product->get_parent_id() );
			wp_cache_delete( 'lookup_table', 'object_' . $product->get_parent_id() );
			wc_delete_product_transients( $product->get_parent_id() );
		}
	}

	public static function record( $product, $before, $after, array $context ) {
		global $wpdb;
		return $wpdb->insert( self::table(), array_merge( [
			'operation_key' => wp_generate_uuid4(), 'payload_hash' => '',
			'product_id' => $product->get_id(), 'product_name' => $product->get_name(), 'sku' => $product->get_sku(),
			'stock_before' => $before, 'stock_after' => $after, 'quantity_delta' => $after - $before,
			'source' => 'woocommerce', 'source_id' => 0, 'reason' => 'stock_adjustment',
			'user_id' => get_current_user_id(), 'note' => '', 'created_at_gmt' => current_time( 'mysql', true ),
		], $context ) );
	}

	/** Receipt, Woo stock and history share the same transaction. */
	public static function adjust( $id, $operation, $raw_quantity, $reason, $note, $uuid ) {
		global $wpdb;
		$product = ysm_owner( $id );
		if ( ysm_premium_active() || ! $product || ! ysm_can_edit( $product ) || ! $product->managing_stock() ) {
			return new WP_Error( 'ysm_denied', __( 'Stock adjustment is unavailable for this product.', 'yoohw-stock-manager' ) );
		}
		$quantity = ysm_quantity( $raw_quantity );
		if ( null === $quantity || ! in_array( $operation, [ 'set', 'increase', 'decrease' ], true ) || ( 'set' !== $operation && $quantity <= 0 ) || ! isset( ysm_reasons()[ $reason ] ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $uuid ) ) {
			return new WP_Error( 'ysm_invalid', __( 'Check the operation, quantity and reason.', 'yoohw-stock-manager' ) );
		}
		$id = $product->get_id();
		$key = hash( 'sha256', $uuid . ':' . $id );
		$note = sanitize_textarea_field( $note );
		$hash = hash( 'sha256', wp_json_encode( [ get_current_user_id(), $operation, $quantity, $reason, $note ] ) );
		// Reject nested transactions; do not commit a caller's pending work.
		if ( ! $wpdb->dbh instanceof mysqli ) {
			return new WP_Error( 'ysm_transaction', __( 'Stock adjustment requires a supported database connection.', 'yoohw-stock-manager' ) );
		}
		// SAVEPOINT is a no-op outside a transaction; only an existing transaction
		// can roll back to it. This works on MySQL and MariaDB without committing
		// caller work or relying on MariaDB-only @@in_transaction.
		$quiet = $wpdb->suppress_errors( true );
		$probe = 'ysm_probe_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
		$savepoint = $wpdb->query( "SAVEPOINT $probe" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally generated hex-only savepoint identifier; no request data.
		$active = $wpdb->query( "ROLLBACK TO SAVEPOINT $probe" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally generated hex-only savepoint identifier.
		$missing = 1305 === mysqli_errno( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions -- wpdb exposes no error-number API; distinguish missing savepoint from connection failure.
		if ( false !== $active ) {
			$wpdb->query( "RELEASE SAVEPOINT $probe" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internally generated hex-only identifier.
		}
		$wpdb->suppress_errors( $quiet );
		if ( false === $savepoint || false !== $active || ! $missing ) {
			return new WP_Error( 'ysm_transaction', __( 'Stock adjustment requires an independent transaction.', 'yoohw-stock-manager' ) );
		}
		foreach ( [ $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_product_meta_lookup', self::table() ] as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return new WP_Error( 'ysm_engine', __( 'Stock adjustment requires InnoDB storage.', 'yoohw-stock-manager' ) );
			}
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'ysm_transaction', __( 'The stock transaction could not start.', 'yoohw-stock-manager' ) );
		}
		self::$adjusting = true;
		try {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' FOR UPDATE", $id ) );
			if ( 1 !== count( $rows ) || null === ysm_quantity( $rows[0] ) ) {
				throw new RuntimeException( 'invalid_stock' );
			}
			$before = (float) $rows[0];
			// Locking read sees receipts committed while this request waited for stock.
			$history_table = self::table();
			$receipt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $history_table WHERE operation_key = %s FOR UPDATE", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal prefixed table name.
			if ( $receipt ) {
				if ( ! hash_equals( $receipt->payload_hash, $hash ) ) {
					throw new RuntimeException( 'operation_conflict' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'commit_failed' );
				}
				return 'skipped';
			}
			self::forget( $id );
			$product = wc_get_product( $id );
			$amount = (float) wc_stock_amount( $quantity );
			$expected = 'set' === $operation ? $amount : $before + ( 'increase' === $operation ? $amount : -$amount );
			if ( ! is_finite( $expected ) || abs( $expected ) >= 1e12 ) {
				throw new RuntimeException( 'quantity_range' );
			}
			if ( $expected !== $before ) {
				wc_update_product_stock( $product, $quantity, $operation );
			}
			$after = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $id ) );
			if ( null === $after || abs( (float) $after - $expected ) > 0.0000005 ) {
				throw new RuntimeException( 'stock_write_failed' );
			}
			$lookup = $wpdb->get_row( $wpdb->prepare( "SELECT stock_quantity, stock_status FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $id ) );
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock_status'", $id ) );
			if ( ! $lookup || null === $lookup->stock_quantity || abs( (float) $lookup->stock_quantity - $expected ) > 0.0000005 || $lookup->stock_status !== $product->get_stock_status() || $status !== $product->get_stock_status() ) {
				throw new RuntimeException( 'stock_lookup_write_failed' );
			}
			if ( ! self::record( $product, $before, (float) $after, [ 'operation_key' => $key, 'payload_hash' => $hash, 'source' => 'free', 'reason' => $reason, 'note' => $note ] ) ) {
				throw new RuntimeException( 'history_write_failed' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit_failed' );
			}
			return $expected === $before ? 'skipped' : 'success';
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ysm_adjust_failed', __( 'The stock adjustment could not be completed. Refresh stock and retry.', 'yoohw-stock-manager' ) );
		} finally {
			self::$adjusting = false;
			self::forget( $id );
		}
	}

	public static function history( array $filters, $limit = 25, $offset = 0, $after_id = 0, $count = false, $ceiling = 0 ) {
		global $wpdb;
		$where = [ 'quantity_delta <> 0' ];
		foreach ( [ 'product_id' => '%d', 'source' => '%s', 'reason' => '%s' ] as $key => $format ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[] = $wpdb->prepare( "$key = $format", $filters[ $key ] );
			}
		}
		foreach ( [ 'date_from' => '>=', 'date_to' => '<=' ] as $key => $operator ) {
			if ( ! empty( $filters[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $filters[ $key ] ) ) {
				$date = get_gmt_from_date( $filters[ $key ] . ( 'date_from' === $key ? ' 00:00:00' : ' 23:59:59' ) );
				$where[] = $wpdb->prepare( "created_at_gmt $operator %s", $date );
			}
		}
		if ( $after_id ) {
			$where[] = $wpdb->prepare( 'id > %d', $after_id );
		}
		if ( $ceiling ) {
			$where[] = $wpdb->prepare( 'id <= %d', $ceiling );
		}
		$sql = 'SELECT ' . ( $count ? 'COUNT(*)' : '*' ) . ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		if ( $count ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions individually prepared.
		}
		$sql .= $after_id || $ceiling ? ' ORDER BY id ASC' : ' ORDER BY id DESC';
		return $wpdb->get_results( $sql . $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions individually prepared.
	}

	/** One row per stock owner; inherited variations are represented by the parent. */
	public static function inventory( array $filters, $limit = 25, $offset = 0, $count = false, $after_id = 0, $ceiling = 0 ) {
		global $wpdb;
		$where = [ "p.post_type IN ('product','product_variation')", "p.post_status IN ('publish','private','draft','pending','future')", "m.meta_key = '_manage_stock' AND m.meta_value = 'yes'" ];
		$search = $filters['s'] ?? '';
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(p.post_title LIKE %s OR l.sku LIKE %s OR p.ID = %d OR EXISTS (SELECT 1 FROM ' . $wpdb->posts . ' v LEFT JOIN ' . $wpdb->prefix . "wc_product_meta_lookup vl ON vl.product_id=v.ID WHERE v.post_parent=p.ID AND v.post_type='product_variation' AND (v.ID=%d OR v.post_title LIKE %s OR vl.sku LIKE %s)))", $like, $like, absint( ltrim( $search, '#' ) ), absint( ltrim( $search, '#' ) ), $like, $like );
		}
		if ( ! empty( $filters['category'] ) ) {
			$children = get_term_children( absint( $filters['category'] ), 'product_cat' );
			$terms = array_merge( [ absint( $filters['category'] ) ], is_wp_error( $children ) ? [] : $children );
			$where[] = 'EXISTS (SELECT 1 FROM ' . $wpdb->term_relationships . ' tr JOIN ' . $wpdb->term_taxonomy . " tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=IF(p.post_parent>0,p.post_parent,p.ID) AND tt.taxonomy='product_cat' AND tt.term_id IN (" . implode( ',', array_map( 'absint', $terms ) ) . '))';
		}
		$status = $filters['status'] ?? '';
		if ( '' !== $status && isset( wc_get_product_stock_status_options()[ $status ] ) ) {
			$where[] = $wpdb->prepare( 'l.stock_status = %s', $status );
		} elseif ( 'low' === $status ) {
			$where[] = $wpdb->prepare( "l.stock_quantity <= COALESCE(NULLIF(low.meta_value,''),NULLIF(plow.meta_value,''),%f) AND l.stock_status <> 'outofstock'", (float) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		}
		if ( $after_id ) {
			$where[] = $wpdb->prepare( 'p.ID > %d', $after_id );
		}
		if ( $ceiling ) {
			$where[] = $wpdb->prepare( 'p.ID <= %d', $ceiling );
		}
		$sql = ' FROM ' . $wpdb->posts . ' p JOIN ' . $wpdb->postmeta . ' m ON p.ID=m.post_id LEFT JOIN ' . $wpdb->prefix . "wc_product_meta_lookup l ON l.product_id=p.ID LEFT JOIN {$wpdb->postmeta} low ON low.post_id=p.ID AND low.meta_key='_low_stock_amount' LEFT JOIN {$wpdb->postmeta} plow ON plow.post_id=p.post_parent AND plow.meta_key='_low_stock_amount' WHERE " . implode( ' AND ', $where );
		if ( $count ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT p.ID)' . $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared conditions, internal identifiers.
		}
		$sorts = [ 'name' => 'p.post_title', 'stock' => 'l.stock_quantity', 'id' => 'p.ID' ];
		$sort = $sorts[ $filters['orderby'] ?? 'name' ] ?? 'p.post_title';
		$order = 'desc' === ( $filters['order'] ?? '' ) ? 'DESC' : 'ASC';
		$sort = $ceiling ? 'p.ID' : $sort;
		$order = $ceiling ? 'ASC' : $order;
		return $wpdb->get_col( 'SELECT DISTINCT p.ID, ' . $sort . $sql . " ORDER BY $sort $order, p.ID $order" . $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Allowlisted sort, prepared conditions.
	}
}
