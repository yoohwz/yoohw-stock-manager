<?php
/**
 * Plugin Name: YoOhw Stock Desk for WooCommerce
 * Description: Manage WooCommerce stock, adjustments, movement history and basic CSV exports.
 * Version: 0.1.2
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * Author: Yo Ohw Studio
 * Author URI: https://yoohw.com
 * Text Domain: yoohw-stock-desk
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package YoOhwStockDesk
 */
defined( 'ABSPATH' ) || exit;
define( 'YOOHWSM_VERSION', '0.1.2' );
define( 'YOOHWSM_FILE', __FILE__ );
require_once __DIR__ . '/inc/functions.php';
register_activation_hook( __FILE__, 'yoohwsm_install' );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YOOHWSM_FILE, true );
	}
} );
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'YoOhw Stock Desk requires WooCommerce to be active.', 'yoohw-stock-desk' ) . '</p></div>';
			}
		} );
		return;
	}
	define( 'YOOHWSM_STORAGE_READY', yoohwsm_install() );
	if ( ! YOOHWSM_STORAGE_READY ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Stock Desk could not safely open or upgrade its history table. Free adjustments and tracking are paused. Existing data has not been replaced. Check database permissions and restore any missing history table before retrying.', 'yoohw-stock-desk' ) . '</p></div>';
			}
		} );
		return;
	}
	require_once __DIR__ . '/inc/class-yoohwsm-store.php';
	require_once __DIR__ . '/inc/class-yoohwsm-tracker.php';
	if ( ! yoohwsm_premium_active() ) {
		$GLOBALS['yoohwsm_tracker'] = new YOOHWSM_Tracker();
	}
	if ( is_admin() ) {
		require_once __DIR__ . '/inc/class-yoohwsm-admin.php';
		new YOOHWSM_Admin();
	}
}, 30 );
