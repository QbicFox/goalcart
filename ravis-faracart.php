<?php
/**
 * Plugin Name:       FaraCart
 * Plugin URI:        https://example.com/faracart
 * Description:       Increase average order value by showing cart goals, progress bars, rewards, milestones, and smart product suggestions for your store.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            FaraCart Team
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       faracart
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 * Requires Plugins:   woocommerce
 *
 * @package FaraCart
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FARACART_VERSION' ) ) {
	/**
	 * Plugin version.
	 */
	define( 'FARACART_VERSION', '0.1.0' );
}

if ( ! defined( 'FARACART_FILE' ) ) {
	/**
	 * Absolute path to the plugin main file.
	 */
	define( 'FARACART_FILE', __FILE__ );
}

if ( ! defined( 'FARACART_PATH' ) ) {
	/**
	 * Absolute plugin directory path with trailing slash.
	 */
	define( 'FARACART_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FARACART_URL' ) ) {
	/**
	 * Plugin directory URL with trailing slash.
	 */
	define( 'FARACART_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'FARACART_BASENAME' ) ) {
	/**
	 * Plugin basename, e.g. "ravis-faracart/ravis-faracart.php".
	 */
	define( 'FARACART_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'FARACART_DB_VERSION' ) ) {
	/**
	 * Database schema version. Bump this to trigger Installer migrations.
	 * 0.2.0 = Phase 3: goals, campaigns and analytics_events tables.
	 * 0.2.1 = Phase 22: composite analytics indexes (goal_event,
	 *         campaign_event) — dbDelta cannot add indexes to existing
	 *         tables, so Installer::maybe_add_indexes() applies them.
	 * 0.3.0 = Phase 26: goals.exclusive (mutually exclusive goals) —
	 *         dbDelta adds the missing column on upgrade.
	 * 0.4.0 = Pluggable template engine (display_settings.template_id +
	 *         template_settings storage shape).
	 * 0.5.0 = Phase 33 (Revenue Optimization): revenue_events,
	 *         revenue_daily, goal_attribution, upsell_events and
	 *         upsell_stats tables plus the daily aggregation cron.
	 * 0.6.0 = Phase 36 (Per-User Goal Completion Limit):
	 *         goals.max_completions_per_user (NULL = unlimited) plus the
	 *         goal_completions history table — dbDelta adds the column
	 *         and table on upgrade.
	 */
	define( 'FARACART_DB_VERSION', '0.6.0' );
}

// Load the Composer autoloader (PSR-4: FaraCart\ => includes/).
if ( file_exists( FARACART_PATH . 'vendor/autoload.php' ) ) {
	require_once FARACART_PATH . 'vendor/autoload.php';
}

// Bootstrap the plugin core (Phase 2: Plugin Foundation). The Plugin
// singleton registers activation/deactivation hooks, schema migrations,
// and every component hook through the HookManager. boot() runs at file
// scope (not deferred) so that register_activation_hook is always
// registered in time during the activation request.
//
// The WooCommerce dependency + version gate deliberately does NOT run at
// file scope: WordPress loads active plugins sequentially in
// active_plugins order, so class_exists('WooCommerce') / WC_VERSION can be
// false or undefined simply because the FaraCart file was required before
// WooCommerce's. Compatibility::gate() runs on plugins_loaded instead —
// after every plugin is loaded — and the admin menu is guarded by the
// same check (never a fatal error; see includes/Compatibility.php).
if ( class_exists( 'FaraCart\Plugin' ) ) {
	FaraCart\Plugin::instance()->boot();
}
