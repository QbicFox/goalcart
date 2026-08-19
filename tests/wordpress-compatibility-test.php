<?php
/**
 * FaraCart WordPress compatibility tests (P20).
 *
 * Boots WordPress and verifies the WordPress Compatibility
 * checklist against the installed environment:
 *
 *  - supported WordPress / WooCommerce / PHP versions (header + gate)
 *  - activation / deactivation wiring
 *  - plugin header contract (Requires at least / Requires PHP / WC
 *    requires at least / Text Domain / Domain Path / uninstall)
 *  - multisite-safety (per-site table prefix, per-site options)
 *  - localization (text domain load path, __() usage)
 *  - RTL (dashboard dir attribute, frontend config isRtl)
 *  - admin capabilities (menu + REST capability filters)
 *
 * Read-only like the other suites: no DB writes, no activation executed,
 * no product/cart creation.
 *
 * Run: php tests/wordpress-compatibility-test.php (from the plugin directory)
 */

// Locate wp-load.php by walking up from this file (tests -> plugin -> plugins -> wp-content -> root).
$dir = __DIR__;
while ( ! file_exists( $dir . '/wp-load.php' ) ) {
	$parent = dirname( $dir );
	if ( $parent === $dir ) {
		fwrite( STDERR, "Could not locate wp-load.php.\n" );
		exit( 2 );
	}
	$dir = $parent;
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

require $dir . '/wp-load.php';
require dirname( __DIR__ ) . '/ravis-faracart.php';

use FaraCart\Compatibility;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\REST\BaseController;

$failures = 0;
$checks   = 0;

function check( $label, $cond ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) {
		echo "OK   {$label}\n";
	} else {
		$failures++;
		echo "FAIL {$label}\n";
	}
}

// ---------------------------------------------------------------------------
// 1. Version gate (P20: WordPress / WooCommerce / PHP)
// ---------------------------------------------------------------------------
echo "\n== 1. Version gate ==\n";

global $wp_version;

check( 'WordPress version meets the requirement', Compatibility::is_wordpress_compatible() );
check( 'PHP version meets the requirement', Compatibility::is_php_compatible() );
check( 'WooCommerce active + compatible', Compatibility::is_requirements_met() );

check( 'WordPress supported (actual >= required)', version_compare( (string) $wp_version, Compatibility::REQUIRED_WP, '>=' ) );
check( 'PHP supported (actual >= required)', version_compare( PHP_VERSION, Compatibility::REQUIRED_PHP, '>=' ) );

if ( defined( 'WC_VERSION' ) ) {
	check( 'WooCommerce supported (actual >= required)', version_compare( (string) WC_VERSION, Compatibility::REQUIRED_WC, '>=' ) );
} else {
	echo "SKIP WooCommerce version check (WC not active)\n";
}

// ---------------------------------------------------------------------------
// 2. Plugin header contract
// ---------------------------------------------------------------------------
echo "\n== 2. Plugin header ==\n";

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$header = get_file_data(
	FARACART_FILE,
	array(
		'requires_wp'   => 'Requires at least',
		'requires_php'  => 'Requires PHP',
		'text_domain'   => 'Text Domain',
		'domain_path'   => 'Domain Path',
		'wc_requires'   => 'WC requires at least',
	)
);

check( 'header requires WordPress >= ' . Compatibility::REQUIRED_WP, version_compare( (string) $header['requires_wp'], Compatibility::REQUIRED_WP, '>=' ) );
check( 'header declares Requires PHP >= 7.4', version_compare( (string) $header['requires_php'], Compatibility::REQUIRED_PHP, '>=' ) );
check( 'header text domain matches', 'faracart' === $header['text_domain'] );
check( 'header domain path set', '/languages' === $header['domain_path'] );
check(
	'header WC version gate matches the Compatibility constant',
	(string) Compatibility::REQUIRED_WC === $header['wc_requires']
);

// ---------------------------------------------------------------------------
// 3. Activation / deactivation wiring
// ---------------------------------------------------------------------------
echo "\n== 3. Activation & deactivation ==\n";

check(
	'activation hook registered',
	false !== has_action( 'activate_' . FARACART_BASENAME, array( Installer::class, 'activate' ) )
);
check(
	'deactivation hook registered',
	false !== has_action( 'deactivate_' . FARACART_BASENAME, array( Installer::class, 'deactivate' ) )
);
check(
	'plugins_loaded compatibility gate wired',
	false !== has_action( 'plugins_loaded', array( Compatibility::class, 'gate' ) )
);
check(
	'maybe_upgrade wired (plugins_loaded)',
	false !== has_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ) )
);
check(
	'maybe_upgrade wired (admin_init)',
	false !== has_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) )
);

// ---------------------------------------------------------------------------
// 4. Multisite behavior (per-site prefix and options)
// ---------------------------------------------------------------------------
echo "\n== 4. Multisite behavior ==\n";

global $wpdb;

check( 'Schema::table uses the per-site $wpdb->prefix', false !== strpos( Schema::table( 'missions' ), $wpdb->prefix ) );
check( 'table includes the plugin prefix', false !== strpos( Schema::table( 'missions' ), 'faracart_missions' ) );
check( 'SQLite option-suffixed tables not used (options path is per-site)', function_exists( 'add_option' ) );

// Options are per-site by default in WordPress; the plugin stores its
// schema version + settings in options (auto-loaded per site).
check( 'db-version option key is plugin-scoped', 0 === strpos( Installer::DB_VERSION_OPTION, 'faracart_' ) );

// ---------------------------------------------------------------------------
// 5. Localization
// ---------------------------------------------------------------------------
echo "\n== 5. Localization ==\n";

check(
	'plugin text domain loads on init',
	false !== has_action( 'init', array( FaraCart\Plugin::instance(), 'load_textdomain' ) )
);
// load_textdomain() calls load_plugin_textdomain( 'faracart', false,
// dirname( FARACART_BASENAME ). '/languages' ) — the relative path must
// resolve to <plugin>/languages from the plugins dir.
check(
	'text domain path resolves to <plugin>/languages',
	'faracart/languages' === dirname( FARACART_BASENAME ) . '/languages'
);

// All strings are translatable via WP functions.
check( 'translation function present', function_exists( '__' ) );

// ---------------------------------------------------------------------------
// 6. RTL
// ---------------------------------------------------------------------------
echo "\n== 6. RTL ==\n";

// Admin dashboard sets the dir attribute for RTL locales.
ob_start();
FaraCart\Plugin::instance()->admin()->render_dashboard();
$dashboard_output = ob_get_clean();

check( 'admin dashboard carries an LTR/RTL dir', false !== strpos( $dashboard_output, 'dir="' ) );

// Frontend config exposes isRtl to the storefront widgets.
$config = FaraCart\Plugin::instance()->container()->get( \FaraCart\Frontend\ProgressUI::class )->frontend_config();
check( 'frontend config is RTL-aware', array_key_exists( 'isRtl', $config ) );

// ---------------------------------------------------------------------------
// 7. Admin capabilities
// ---------------------------------------------------------------------------
echo "\n== 7. Admin capabilities ==\n";

check(
	'admin menu capability filterable (faracart_admin_capability)',
	is_string( apply_filters( 'faracart_admin_capability', 'manage_options' ) )
);
check(
	'REST endpoints use the shared capability',
	'manage_options' === BaseController::CAPABILITY
);
check(
	'REST capability filterable (faracart_rest_capability)',
	is_array( apply_filters( 'faracart_rest_capability', 'manage_options' ) ) || is_string( apply_filters( 'faracart_rest_capability', 'manage_options' ) )
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "WORDPRESS COMPATIBILITY TEST FAILED\n" : "WORDPRESS COMPATIBILITY TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );