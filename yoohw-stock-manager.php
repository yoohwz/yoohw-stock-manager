<?php
/**
 * Plugin Name: YoOhw Stock Manager for WooCommerce
 * Description: Manage WooCommerce stock, adjustments, movement history and basic CSV exports.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 * Author: Yo Ohw Studio
 * Author URI: https://yoohw.com
 * Text Domain: yoohw-stock-manager
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package YoOhwStockManager
 */
defined( 'ABSPATH' ) || exit;
define( 'YSM_VERSION', '0.1.0' );
define( 'YSM_FILE', __FILE__ );
require_once __DIR__ . '/inc/functions.php';
register_activation_hook( __FILE__, 'ysm_install' );
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YSM_FILE, true );
	}
} );
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'YoOhw Stock Manager requires WooCommerce to be active.', 'yoohw-stock-manager' ) . '</p></div>';
			}
		} );
		return;
	}
	if ( get_option( 'ysm_schema_version' ) !== '1' ) {
		ysm_install();
	}
	require_once __DIR__ . '/inc/class-ysm-store.php';
	require_once __DIR__ . '/inc/class-ysm-tracker.php';
	if ( ! ysm_premium_active() ) {
		$GLOBALS['ysm_tracker'] = new YSM_Tracker();
	}
	if ( is_admin() ) {
		require_once __DIR__ . '/inc/class-ysm-admin.php';
		new YSM_Admin();
	}
}, 30 );
