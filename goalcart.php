<?php
/**
 * Plugin Name:       Goal Cart for WooCommerce
 * Plugin URI:        https://example.com/goalcart
 * Description:       Increase average order value by showing cart goals, progress bars, rewards, milestones, and smart product suggestions for your store.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Goal Cart Team
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       goalcart
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 * Requires Plugins:   woocommerce
 *
 * @package GoalCart
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'GOALCART_VERSION' ) ) {
	/**
	 * Plugin version.
	 */
	define( 'GOALCART_VERSION', '0.1.0' );
}

if ( ! defined( 'GOALCART_FILE' ) ) {
	/**
	 * Absolute path to the plugin main file.
	 */
	define( 'GOALCART_FILE', __FILE__ );
}

if ( ! defined( 'GOALCART_PATH' ) ) {
	/**
	 * Absolute plugin directory path with trailing slash.
	 */
	define( 'GOALCART_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GOALCART_URL' ) ) {
	/**
	 * Plugin directory URL with trailing slash.
	 */
	define( 'GOALCART_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GOALCART_BASENAME' ) ) {
	/**
	 * Plugin basename, e.g. "goalcart/goalcart.php".
	 */
	define( 'GOALCART_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'GOALCART_DB_VERSION' ) ) {
	/**
	 * Database schema version. Bump this to trigger Installer migrations.
	 */
	define( 'GOALCART_DB_VERSION', '0.1.0' );
}

// Load the Composer autoloader (PSR-4: GoalCart\ => includes/).
if ( file_exists( GOALCART_PATH . 'vendor/autoload.php' ) ) {
	require_once GOALCART_PATH . 'vendor/autoload.php';
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
// false or undefined simply because the Goal Cart file was required before
// WooCommerce's. Compatibility::gate() runs on plugins_loaded instead —
// after every plugin is loaded — and the admin menu is guarded by the
// same check (never a fatal error; see includes/Compatibility.php).
if ( class_exists( 'GoalCart\Plugin' ) ) {
	GoalCart\Plugin::instance()->boot();
}
