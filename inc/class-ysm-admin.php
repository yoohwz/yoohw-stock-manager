<?php
/** Free workspace using selected Premium page/filter/bulk patterns. @package YoOhwStockManager */
defined( 'ABSPATH' ) || exit;

class YSM_Admin {
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_filter( 'custom_menu_order', [ $this, 'enable_custom_menu_order' ], 999 );
		add_filter( 'menu_order', [ $this, 'place_after_products' ], PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ], 20 );
		add_action( 'admin_post_ysm_adjust', [ $this, 'adjust' ] );
		add_action( 'admin_post_ysm_export', [ $this, 'export' ] );
	}
	public static function sources() {
		return [ 'free' => __( 'Stock Manager', 'yoohw-stock-manager' ), 'woocommerce' => __( 'WooCommerce', 'yoohw-stock-manager' ), 'order' => __( 'Order', 'yoohw-stock-manager' ), 'refund' => __( 'Refund restock (order)', 'yoohw-stock-manager' ) ];
	}
	public static function external_reasons() {
		return [ 'stock_adjustment' => __( 'WooCommerce adjustment', 'yoohw-stock-manager' ), 'order_sale' => __( 'Order sale', 'yoohw-stock-manager' ), 'order_restore' => __( 'Order restore', 'yoohw-stock-manager' ), 'refund_return' => __( 'Refund return', 'yoohw-stock-manager' ) ];
	}
	public function menu() {
		add_menu_page( __( 'Stock Manager', 'yoohw-stock-manager' ), __( 'Stock Manager', 'yoohw-stock-manager' ), 'manage_woocommerce', 'yoohw-stock-manager', [ $this, 'render' ], 'none', 56 );
		foreach ( [ 'yoohw-stock-manager' => __( 'Inventory', 'yoohw-stock-manager' ), 'ysm-history' => __( 'History', 'yoohw-stock-manager' ), 'ysm-help' => __( 'Settings & help', 'yoohw-stock-manager' ) ] as $slug => $label ) {
			add_submenu_page( 'yoohw-stock-manager', $label, $label, 'manage_woocommerce', $slug, [ $this, 'render' ] );
		}
	}
	public function enable_custom_menu_order( $custom_menu_order ) {
		return current_user_can( 'manage_woocommerce' ) ? true : $custom_menu_order;
	}
	public function place_after_products( $menu_order ) {
		if ( ! is_array( $menu_order ) || ! in_array( 'yoohw-stock-manager', $menu_order, true ) ) {
			return $menu_order;
		}
		$menu_order = array_values( array_diff( $menu_order, [ 'yoohw-stock-manager' ] ) );
		// WooCommerce can supply duplicate Products entries; WordPress keeps the last.
		$products_index = array_search( 'edit.php?post_type=product', array_reverse( $menu_order, true ), true );
		if ( false === $products_index ) {
			$menu_order[] = 'yoohw-stock-manager';
			return $menu_order;
		}
		array_splice( $menu_order, $products_index + 1, 0, [ 'yoohw-stock-manager' ] );
		return $menu_order;
	}
	public function assets( $hook ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			// Use the same glyph as Premium, from WooCommerce's existing menu font.
			wp_add_inline_style( 'woocommerce_admin_menu_styles', '#adminmenu #toplevel_page_yoohw-stock-manager > a .wp-menu-image::before { content: "\e900"; font-family: "WooCommerce" !important; font-size: 20px; font-weight: 400; line-height: 1; }' );
		}
		if ( false !== strpos( $hook, 'yoohw-stock-manager' ) || false !== strpos( $hook, 'ysm-' ) ) {
			wp_enqueue_style( 'ysm-admin', plugins_url( 'css/admin.css', YSM_FILE ), [], YSM_VERSION );
			wp_enqueue_script( 'ysm-admin', plugins_url( 'js/admin.js', YSM_FILE ), [], YSM_VERSION, true );
		}
	}
	private function permission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage stock.', 'yoohw-stock-manager' ), '', [ 'response' => 403 ] );
		}
	}
	private function filters( array $input ) {
		$out = [];
		foreach ( [ 's', 'status', 'source', 'reason', 'date_from', 'date_to', 'orderby', 'order' ] as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
		}
		foreach ( [ 'category', 'product_id' ] as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
		}
		return $out;
	}
	private function text( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Handler checks nonce before reading.
	}
	public function adjust() {
		$this->permission();
		check_admin_referer( 'ysm_adjust', 'ysm_nonce' );
		$ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_values( array_unique( array_map( 'absint', $_POST['product_ids'] ) ) ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Integer IDs only.
		if ( ! $ids || count( $ids ) > 25 ) {
			wp_die( esc_html__( 'Select between 1 and 25 products on this page.', 'yoohw-stock-manager' ) );
		}
		$counts = [ 'success' => 0, 'failed' => 0, 'skipped' => 0 ];
		$owners = [];
		$errors = [];
		foreach ( $ids as $id ) {
			$original = wc_get_product( $id );
			$product = ysm_owner( $id );
			if ( ! $original || ! $product || ! ysm_can_edit( $original ) ) {
				++$counts['failed'];
				continue;
			}
			if ( isset( $owners[ $product->get_id() ] ) ) {
				++$counts['skipped'];
				continue;
			}
			$owners[ $product->get_id() ] = true;
			$result = YSM_Store::adjust( $id, $this->text( 'operation' ), $this->text( 'quantity' ), $this->text( 'reason' ), $this->text( 'note' ), $this->text( 'operation_uuid' ) );
			++$counts[ is_wp_error( $result ) ? 'failed' : $result ];
			if ( is_wp_error( $result ) ) {
				$errors[] = '#' . $id . ': ' . $result->get_error_message();
			}
		}
		set_transient( 'ysm_notice_' . get_current_user_id(), [ 'counts' => $counts, 'errors' => array_unique( $errors ) ], 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=yoohw-stock-manager' ) );
		exit;
	}
	public function export() {
		$this->permission();
		check_admin_referer( 'ysm_export' );
		$filters = $this->filters( $_GET );
		$history = isset( $_GET['view'] ) && 'history' === $_GET['view'];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="yoohw-' . ( $history ? 'history' : 'inventory' ) . '.csv"' );
		$stream = fopen( 'php://output', 'w' );
		self::write_csv( $stream, $history, $filters );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV response stream, not a filesystem path.
		exit;
	}
	public static function write_csv( $stream, $history, array $filters ) {
		global $wpdb;
		$columns = $history ? [ 'Date (UTC)', 'Product ID', 'Product', 'SKU', 'Before', 'After', 'Change', 'Source', 'Order ID', 'User ID', 'Reason', 'Note' ] : [ 'Product ID', 'Product', 'SKU', 'WooCommerce stock', 'Stock status', 'Low stock threshold' ];
		fputcsv( $stream, $columns, ',', '"', '' );
		$table = $history ? YSM_Store::table() : $wpdb->posts;
		$column = $history ? 'id' : 'ID';
		$ceiling = (int) $wpdb->get_var( "SELECT MAX($column) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only internal allowlisted table and column identifiers.
		$cursor = 0;
		if ( ! $ceiling ) {
			return;
		}
		do {
			$rows = $history ? YSM_Store::history( $filters, 250, 0, $cursor, false, $ceiling ) : YSM_Store::inventory( $filters, 250, 0, false, $cursor, $ceiling );
			foreach ( $rows as $row ) {
				if ( $history ) {
					$cursor = (int) $row['id'];
					$values = [ $row['created_at_gmt'], $row['product_id'], $row['product_name'], $row['sku'], $row['stock_before'], $row['stock_after'], $row['quantity_delta'], $row['source'], $row['source_id'], $row['user_id'], $row['reason'], $row['note'] ];
				} else {
					$cursor = (int) $row;
					$product = wc_get_product( $row );
					if ( ! $product ) {
						continue;
					}
					$values = [ $product->get_id(), $product->get_name(), $product->get_sku(), $product->get_stock_quantity(), $product->get_stock_status(), wc_get_low_stock_amount( $product ) ];
				}
				fputcsv( $stream, ysm_csv_row( $values ), ',', '"', '' );
			}
			// Bound request-local object cache growth while streaming large exports.
			if ( wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $rows ) === 250 );
	}
	private function select( $name, $label, array $options, $value, $required = false ) {
		echo '<label class="screen-reader-text" for="ysm-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="ysm-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . '>';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select>';
	}
	private function adjustment_fields() {
		wp_nonce_field( 'ysm_adjust', 'ysm_nonce' );
		echo '<input type="hidden" name="action" value="ysm_adjust"><input type="hidden" name="operation_uuid" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		$this->select( 'operation', __( 'Operation', 'yoohw-stock-manager' ), [ 'increase' => __( 'Increase', 'yoohw-stock-manager' ), 'decrease' => __( 'Decrease', 'yoohw-stock-manager' ), 'set' => __( 'Set quantity', 'yoohw-stock-manager' ) ], 'increase', true );
		echo '<label class="screen-reader-text" for="ysm-quantity">' . esc_html__( 'Quantity', 'yoohw-stock-manager' ) . '</label><input id="ysm-quantity" name="quantity" type="number" step="any" required placeholder="' . esc_attr__( 'Quantity', 'yoohw-stock-manager' ) . '">';
		$this->select( 'reason', __( 'Reason', 'yoohw-stock-manager' ), ysm_reasons(), 'manual_adjustment', true );
		echo '<label class="screen-reader-text" for="ysm-note">' . esc_html__( 'Note', 'yoohw-stock-manager' ) . '</label><input id="ysm-note" name="note" type="text" placeholder="' . esc_attr__( 'Note (optional)', 'yoohw-stock-manager' ) . '">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply adjustment', 'yoohw-stock-manager' ) . '</button>';
	}
	public function render() {
		$this->permission();
		require_once __DIR__ . '/class-ysm-list-table.php';
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'yoohw-stock-manager'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$history = 'ysm-history' === $page;
		$filters = $this->filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		echo '<div class="wrap ysm-stock-manager"><h1>' . esc_html__( 'Stock Manager', 'yoohw-stock-manager' ) . '</h1><nav class="ysm-stock-manager-tabs" aria-label="' . esc_attr__( 'Stock Manager navigation', 'yoohw-stock-manager' ) . '">';
		foreach ( [ 'yoohw-stock-manager' => __( 'Inventory', 'yoohw-stock-manager' ), 'ysm-history' => __( 'History', 'yoohw-stock-manager' ), 'ysm-help' => __( 'Settings & help', 'yoohw-stock-manager' ) ] as $slug => $label ) {
			echo '<a class="ysm-stock-manager-tab ' . ( $page === $slug ? 'is-active' : '' ) . '" ' . ( $page === $slug ? 'aria-current="page"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( ysm_premium_active() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Premium is active. Use Premium to adjust stock. Free tracking is paused; existing Free history remains available to read and export.', 'yoohw-stock-manager' ) . '</p></div>';
		}
		if ( get_option( 'ysm_history_error' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'A WooCommerce stock change could not be recorded. History may be incomplete. Check database health before relying on exports.', 'yoohw-stock-manager' ) . '</p></div>';
		}
		$notice = get_transient( 'ysm_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'ysm_notice_' . get_current_user_id() );
			echo '<div role="status" class="notice inline ' . ( $notice['counts']['failed'] ? 'notice-warning' : 'notice-success' ) . '"><p>';
			/* translators: 1: successful count, 2: failed count, 3: skipped count. */
			echo esc_html( sprintf( __( '%1$d successful, %2$d failed, %3$d skipped (unchanged or already applied).', 'yoohw-stock-manager' ), $notice['counts']['success'], $notice['counts']['failed'], $notice['counts']['skipped'] ) );
			foreach ( $notice['errors'] as $error ) {
				echo '<br>' . esc_html( $error );
			}
			echo '</p></div>';
		}
		if ( 'ysm-help' === $page ) {
			echo '<div class="ysm-help"><h2>' . esc_html__( 'Stock settings', 'yoohw-stock-manager' ) . '</h2><p>' . esc_html__( 'WooCommerce controls stock quantities, backorders, low stock thresholds and inventory emails. Stock Manager does not send duplicate alerts.', 'yoohw-stock-manager' ) . '</p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=products&section=inventory' ) ) . '">' . esc_html__( 'Open WooCommerce inventory settings', 'yoohw-stock-manager' ) . '</a><h2>' . esc_html__( 'Quick start', 'yoohw-stock-manager' ) . '</h2><p>' . esc_html__( 'Enable stock management on products. Use Inventory to search and select products, adjust received goods or counts, then check History and export CSV. Shared parent stock is shown once on the parent; search a variation SKU or ID to find its owner.', 'yoohw-stock-manager' ) . '</p><p>' . esc_html__( 'Tracking starts when this plugin is active. Standard WooCommerce stock updates, order reductions/restores and restocked refunds are observed. Direct database writes and historical changes are not reconstructed. Concurrent external writers can limit observed before/after accuracy. Deactivation or uninstall keeps Free history; Premium activation pauses Free tracking and adjustments.', 'yoohw-stock-manager' ) . '</p></div></div>';
			return;
		}
		if ( ! $history ) {
			echo '<div class="ysm-stock-metrics">';
			foreach ( [ '' => __( 'Stock-managed products', 'yoohw-stock-manager' ), 'low' => __( 'Low stock', 'yoohw-stock-manager' ), 'outofstock' => __( 'Out of stock', 'yoohw-stock-manager' ) ] as $status => $label ) {
				echo '<a class="ysm-stock-metric" href="' . esc_url( add_query_arg( [ 'page' => 'yoohw-stock-manager', 'status' => $status ], admin_url( 'admin.php' ) ) ) . '"><span>' . esc_html( $label ) . '</span><strong>' . absint( YSM_Store::inventory( [ 'status' => $status ], 25, 0, true ) ) . '</strong></a>';
			}
			echo '</div><p class="ysm-stock-formula">' . esc_html__( 'WooCommerce stock is the current tracked quantity. Shared stock is counted once. Increase stock only for goods already received.', 'yoohw-stock-manager' ) . '</p>';
		}
		echo '<form method="get" class="ysm-stock-filters"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
		if ( $history ) {
			echo '<label class="screen-reader-text" for="ysm-product-filter">' . esc_html__( 'Product ID', 'yoohw-stock-manager' ) . '</label><input id="ysm-product-filter" type="number" min="1" name="product_id" value="' . ( $filters['product_id'] ? absint( $filters['product_id'] ) : '' ) . '" placeholder="' . esc_attr__( 'Product ID', 'yoohw-stock-manager' ) . '">';
			$this->select( 'source', __( 'Source', 'yoohw-stock-manager' ), [ '' => __( 'All sources', 'yoohw-stock-manager' ) ] + self::sources(), $filters['source'] );
			$this->select( 'reason', __( 'Reason', 'yoohw-stock-manager' ), [ '' => __( 'All reasons', 'yoohw-stock-manager' ) ] + ysm_reasons() + self::external_reasons(), $filters['reason'] );
			foreach ( [ 'date_from' => __( 'From date', 'yoohw-stock-manager' ), 'date_to' => __( 'To date', 'yoohw-stock-manager' ) ] as $key => $label ) {
				echo '<label class="screen-reader-text" for="ysm-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input id="ysm-' . esc_attr( $key ) . '" type="date" name="' . esc_attr( $key ) . '" value="' . esc_attr( $filters[ $key ] ) . '">';
			}
		} else {
			echo '<label class="screen-reader-text" for="ysm-search">' . esc_html__( 'Product, SKU, or ID', 'yoohw-stock-manager' ) . '</label><input id="ysm-search" type="search" name="s" value="' . esc_attr( $filters['s'] ) . '" placeholder="' . esc_attr__( 'Product, SKU, or ID…', 'yoohw-stock-manager' ) . '">';
			$this->select( 'status', __( 'Stock status', 'yoohw-stock-manager' ), [ '' => __( 'All stock statuses', 'yoohw-stock-manager' ), 'low' => __( 'Low stock', 'yoohw-stock-manager' ) ] + wc_get_product_stock_status_options(), $filters['status'] );
			wp_dropdown_categories( [ 'taxonomy' => 'product_cat', 'name' => 'category', 'id' => 'ysm-category', 'aria_label' => __( 'Category', 'yoohw-stock-manager' ), 'selected' => $filters['category'], 'show_option_all' => __( 'All categories', 'yoohw-stock-manager' ), 'hide_empty' => false ] );
		}
		echo '<button class="button" type="submit">' . esc_html__( 'Filter', 'yoohw-stock-manager' ) . '</button><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html__( 'Reset', 'yoohw-stock-manager' ) . '</a></form>';
		$export_url = wp_nonce_url( add_query_arg( array_merge( $filters, [ 'action' => 'ysm_export', 'view' => $history ? 'history' : 'inventory' ] ), admin_url( 'admin-post.php' ) ), 'ysm_export' );
		echo '<p class="ysm-export"><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'yoohw-stock-manager' ) . '</a></p>';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows = $history ? YSM_Store::history( $filters, 25, ( $paged - 1 ) * 25 ) : YSM_Store::inventory( $filters, 25, ( $paged - 1 ) * 25 );
		$total = $history ? YSM_Store::history( $filters, 25, 0, 0, true ) : YSM_Store::inventory( $filters, 25, 0, true );
		$adjust = isset( $_GET['adjust'] ) ? absint( $_GET['adjust'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$single = $adjust ? ysm_owner( $adjust ) : false;
		if ( ! $history && ! ysm_premium_active() && $single && ysm_can_edit( $single ) ) {
			echo '<section id="ysm-adjust" class="ysm-help"><h2>' . esc_html__( 'Adjust stock', 'yoohw-stock-manager' ) . ': ' . esc_html( $single->get_name() ) . '</h2><form class="ysm-stock-bulk" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input name="product_ids[]" type="hidden" value="' . absint( $single->get_id() ) . '">';
			$this->adjustment_fields();
			echo '</form></section>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		if ( ! $history && ! ysm_premium_active() && ! $single ) {
			echo '<h2>' . esc_html__( 'Bulk adjustment', 'yoohw-stock-manager' ) . '</h2><p>' . esc_html__( 'Select products below. Apply one operation to up to 25 products per page; repeat on other pages as needed. There is no usage limit.', 'yoohw-stock-manager' ) . '</p><div class="ysm-stock-bulk">';
			$this->adjustment_fields();
			echo '</div>';
		}
		echo '<div class="ysm-stock-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr( $history ? __( 'Stock history table', 'yoohw-stock-manager' ) : __( 'Inventory table', 'yoohw-stock-manager' ) ) . '">';
		$table = new YSM_List_Table( $history, $rows, $total );
		$table->display();
		echo '</div></form></div>';
	}
}
