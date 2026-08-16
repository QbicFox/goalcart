<?php
/**
 * Uninstall handler for FaraCart.
 *
 * Removes all plugin database tables and options when the plugin
 * is deleted from the WordPress admin.
 *
 * @package GoalCart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Load the Composer autoloader so the Installer class is available.
// __DIR__ is used (not a hardcoded folder name) so the uninstall works
// even if the plugin directory has been renamed.
$goalcart_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $goalcart_autoloader ) ) {
	require_once $goalcart_autoloader;
}

if ( class_exists( 'GoalCart\Database\Installer' ) ) {
	GoalCart\Database\Installer::uninstall();
}
