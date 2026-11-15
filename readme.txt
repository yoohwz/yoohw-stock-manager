=== YoOhw Stock Manager for WooCommerce ===
Contributors: yoohwz
Tags: woocommerce, inventory, stock, stock manager
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WooCommerce stock, single and bulk adjustments, movement history, low stock views and basic CSV exports.

== Description ==

YoOhw Stock Manager for WooCommerce is a free, standalone inventory workspace.

* Inventory: product, SKU, variation, WooCommerce quantity, status and low stock threshold.
* Search by name, SKU or ID; filter by category and stock status; sort and paginate.
* Set, increase or decrease stock with a reason and optional note.
* Bulk adjustments on each 25-product page with success, failure and skipped counts. No commercial usage limits.
* Basic history with date, before/after, change, source, actor, reason and note.
* Filtered inventory and movement CSV exports, streamed in chunks.
* WooCommerce inventory settings and emails remain authoritative; no duplicate email sender.

Shared parent-managed stock appears once on its parent. Search a variation SKU/ID to find its stock owner. Variation-managed stock appears separately. Increasing stock records goods already received; there are no incoming shipments or purchase orders.

History starts when tracking is active. Standard WooCommerce product saves, stock API changes, order reductions/restores and restocked refunds are observed. Existing historical changes and direct database writes are not reconstructed. Simultaneous external updates can limit observed before/after accuracy.

Free requires no account, license key or paid service. It sends no telemetry. Only local WooCommerce data is processed. Stock history stores product name/SKU, quantities, timestamps, user ID, order reference, reason and optional notes. Do not enter personal information in notes. Administrators and shop managers with manage_woocommerce can view/export history; adjustments also require product edit permission.

== Installation ==

1. Install and activate WooCommerce 8.0 or newer.
2. Install this plugin in wp-content/plugins/yoohw-stock-manager and activate it.
3. Open Stock Manager > Inventory. Enable WooCommerce stock management on products you want to manage.

== Frequently Asked Questions ==

= Can I manage decimal quantities and backorders? =

Existing WooCommerce quantity filters and backorder settings are respected. WooCommerce uses integer stock by default. This plugin does not enable fractional stock globally.

= What happens when Premium is active? =

Free adjustment and tracking pause while Premium is active. Use Premium for stock operations. Existing Free history remains readable and exportable; neither activation nor switching changes quantities. Free does not import or merge Premium history and does not turn Premium into an add-on.

= Are data deleted when the plugin is removed? =

No. Deactivation and uninstall retain the Free movement table and settings. This preserves records for later reactivation or a switch to Premium. Back up and manage retained data according to your store policy.

= Can I import a CSV? =

Use WooCommerce's product importer. This plugin only provides basic exports.

== Changelog ==

= 0.1.0 =
* Initial development candidate with inventory, adjustments, history and scoped CSV.
