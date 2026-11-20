<?php
/** Selected Premium WP_List_Table behavior, reduced to Free columns. @package YoOhwStockManager */
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
class YOOHWSM_List_Table extends WP_List_Table {
	private $history;
	private $bulk_enabled;
	public function __construct( $history, $rows, $total, $bulk_enabled = false ) {
		parent::__construct( [ 'singular' => 'yoohwsm_product', 'plural' => 'yoohwsm_products', 'ajax' => false ] );
		$this->history = $history;
		$this->bulk_enabled = ! $history && $bulk_enabled;
		$this->items = $rows;
		$this->_column_headers = [ $this->get_columns(), [], $history ? [] : [ 'product' => [ 'name', false ], 'stock' => [ 'stock', false ] ], 'product' ];
		$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => 25 ] );
	}
	public function get_columns() {
		$columns = $this->history ? [
			'date' => __( 'Date', 'yoohw-stock-manager' ), 'product' => __( 'Product', 'yoohw-stock-manager' ),
			'change' => __( 'Change', 'yoohw-stock-manager' ), 'balance' => __( 'Before → after', 'yoohw-stock-manager' ),
			'source' => __( 'Source', 'yoohw-stock-manager' ), 'reason' => __( 'Reason', 'yoohw-stock-manager' ), 'note' => __( 'User / note', 'yoohw-stock-manager' ),
		] : [
			'cb' => '<input type="checkbox" />', 'product' => __( 'Product', 'yoohw-stock-manager' ),
			'status' => __( 'Status', 'yoohw-stock-manager' ), 'stock' => __( 'WooCommerce stock', 'yoohw-stock-manager' ),
			'threshold' => __( 'Low stock threshold', 'yoohw-stock-manager' ),
		];
		if ( ! $this->bulk_enabled ) {
			unset( $columns['cb'] );
		}
		return $columns;
	}
	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), [ 'yoohwsm-stock-manager-table', $this->history ? 'yoohwsm-history-table' : 'yoohwsm-inventory-table' ] );
	}
	public function no_items() {
		echo '<div class="yoohwsm-empty-state"><strong>' . esc_html__( 'No items match this view.', 'yoohw-stock-manager' ) . '</strong><p>' . esc_html__( 'Reset the filters or enable stock management on your WooCommerce products. History starts when tracking is enabled.', 'yoohw-stock-manager' ) . '</p></div>';
	}
	public function column_cb( $item ) {
		$product = wc_get_product( $item );
		return $this->bulk_enabled && $product && ! yoohwsm_premium_active() && yoohwsm_can_edit( $product ) ? '<input aria-label="' . esc_attr( $product->get_name() ) . '" type="checkbox" form="yoohwsm-bulk-adjustment" name="product_ids[]" value="' . absint( $item ) . '" />' : '';
	}
	public function column_default( $item, $column ) {
		if ( $this->history ) {
			$product = wc_get_product( $item['product_id'] );
			switch ( $column ) {
				case 'date': return esc_html( get_date_from_gmt( $item['created_at_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
				case 'product': return '<strong>' . esc_html( $item['product_name'] ) . '</strong><div class="yoohwsm-campaign-muted">#' . absint( $item['product_id'] ) . ' · ' . esc_html( $item['sku'] ) . '</div>';
				case 'change': return '<strong class="yoohwsm-inventory-delta ' . ( $item['quantity_delta'] < 0 ? 'is-negative' : '' ) . '">' . esc_html( wc_format_localized_decimal( $item['quantity_delta'] + 0 ) ) . '</strong>';
				case 'balance': return esc_html( wc_format_localized_decimal( $item['stock_before'] + 0 ) . ' → ' . wc_format_localized_decimal( $item['stock_after'] + 0 ) );
				case 'source': return esc_html( ( YOOHWSM_Admin::sources()[ $item['source'] ] ?? $item['source'] ) . ( $item['source_id'] ? ' #' . $item['source_id'] : '' ) );
				case 'reason': return esc_html( ( yoohwsm_reasons() + YOOHWSM_Admin::external_reasons() )[ $item['reason'] ] ?? $item['reason'] );
				case 'note':
					$user = get_userdata( $item['user_id'] );
					return esc_html( $user ? $user->display_name : __( 'System', 'yoohw-stock-manager' ) ) . '<div class="yoohwsm-campaign-muted">' . esc_html( $item['note'] ) . '</div>';
			}
			return '';
		}
		$product = wc_get_product( $item );
		if ( ! $product ) {
			return '—';
		}
		$id = $product->get_id();
		switch ( $column ) {
			case 'product':
				$edit_id = $product->get_parent_id() ?: $id;
				$title = '<strong>' . esc_html( $product->get_name() ) . '</strong>';
				if ( current_user_can( 'edit_post', $edit_id ) ) {
					$title = '<a href="' . esc_url( get_edit_post_link( $edit_id ) ) . '">' . $title . '</a>';
				}
				return $title . '<div class="yoohwsm-campaign-muted">#' . $id . ' · ' . esc_html( $product->get_sku() ) . '</div>' . $this->row_actions( [ 'history' => '<a href="' . esc_url( add_query_arg( [ 'page' => 'yoohwsm-history', 'product_id' => $id ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'History', 'yoohw-stock-manager' ) . '</a>' ] );
			case 'status':
				$status = $product->get_stock_status();
				$low = 'outofstock' !== $status && $product->get_stock_quantity() <= wc_get_low_stock_amount( $product );
				return '<span class="yoohwsm-status-pill yoohwsm-status-pill--' . esc_attr( $status ) . '">' . esc_html( wc_get_product_stock_status_options()[ $status ] ?? $status ) . '</span>' . ( $low ? '<div class="yoohwsm-stock-alert-text">' . esc_html__( 'Low stock', 'yoohw-stock-manager' ) . '</div>' : '' );
			case 'stock': return '<strong>' . esc_html( wc_format_localized_decimal( $product->get_stock_quantity() ) ) . '</strong>';
			case 'threshold': return esc_html( wc_format_localized_decimal( wc_get_low_stock_amount( $product ) ) );

		}
		return '';
	}
}
