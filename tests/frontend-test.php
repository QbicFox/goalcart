<?php
/**
 * Goal Cart frontend progress UI tests (P11-T01 / P11-T02 / P11-T03).
 *
 * Boots WordPress and exercises the Phase 11 storefront widget layer:
 *
 *  - the ProgressUI service resolves from the container
 *  - hook registration for every display location (cart, mini-cart,
 *    checkout, shop, product, sticky bar) and the asset/config prints
 *  - shortcode registration, unique container ids, markup shape
 *  - the duplicate-render guard (a location renders exactly once)
 *  - the frontend config payload (endpoint, currency, reward labels)
 *  - the master enabled gate (settings toggle + goalcart_frontend_enabled)
 *  - page gating: the shortcode in a post content enables assets
 *
 * Read-only like the other suites: no DB writes, no product/cart
 * creation. Settings are flipped in memory only and restored.
 *
 * Run: php tests/frontend-test.php   (from the plugin directory)
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
require dirname( __DIR__ ) . '/goalcart.php';

use GoalCart\Frontend\ProgressUI;

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

/**
 * The registered priority of a callback on a hook.
 *
 * `has_action( $tag, $callback, $priority )` cannot match array callbacks
 * (the priority form indexes callbacks by unique id, and the callback is
 * an array), so resolve the unique id the way WP_Hook::add_filter does.
 *
 * @param string   $tag      Hook name.
 * @param callable $callback Registered callback.
 * @return int|false
 */
function hook_priority( $tag, $callback ) {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $tag ] ) ) {
		return false;
	}

	$id = _wp_filter_build_unique_id( $tag, $callback, 10 );

	foreach ( $wp_filter[ $tag ]->callbacks as $priority => $bucket ) {
		if ( isset( $bucket[ $id ] ) ) {
			return $priority;
		}
	}

	return false;
}

$container = \GoalCart\Plugin::instance()->container();
$ui        = $container->get( ProgressUI::class );
$settings  = $container->get( \GoalCart\Settings\Settings::class );

// ---------------------------------------------------------------------------
// 1. Service wiring (P11-T01)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'ProgressUI resolves from container', $ui instanceof ProgressUI );
check( 'is_enabled defaults to the settings toggle', true === $ui->is_enabled() );
check( 'default locations include cart/mini-cart/checkout/shop/product/sticky', array() === array_diff(
	array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ),
	$ui->locations()
) );

// ---------------------------------------------------------------------------
// 2. Hook registration (P11-T02 — display locations + assets/config)
// ---------------------------------------------------------------------------
echo "\n== 2. Hook registration ==\n";

check( 'wp_enqueue_scripts hooked', false !== has_action( 'wp_enqueue_scripts', array( $ui, 'enqueue_assets' ) ) );
check( 'wp_footer config print hooked at 5', 5 === hook_priority( 'wp_footer', array( $ui, 'print_config' ) ) );
check( 'wp_footer sticky bar hooked at 20', 20 === hook_priority( 'wp_footer', array( $ui, 'render_sticky_bar' ) ) );
check( 'init shortcode registration hooked', false !== has_action( 'init', array( $ui, 'register_shortcode' ) ) );
check( 'cart location hooked', false !== has_action( 'woocommerce_before_cart', array( $ui, 'render_cart_widget' ) ) );
check( 'mini-cart location hooked', false !== has_action( 'woocommerce_after_mini_cart', array( $ui, 'render_mini_cart_widget' ) ) );
check( 'checkout location hooked', false !== has_action( 'woocommerce_before_checkout_form', array( $ui, 'render_checkout_widget' ) ) );
check( 'shop location hooked', false !== has_action( 'woocommerce_archive_description', array( $ui, 'render_shop_widget' ) ) );
check( 'product location hooked at 45', 45 === hook_priority( 'woocommerce_single_product_summary', array( $ui, 'render_product_widget' ) ) );

// ---------------------------------------------------------------------------
// 3. Shortcode + container markup (P11-T02 — configurable widget)
// ---------------------------------------------------------------------------
echo "\n== 3. Shortcode & markup ==\n";

// 'init' never fires in CLI, so register the shortcode directly.
$ui->register_shortcode();

check( 'shortcode registered', shortcode_exists( ProgressUI::SHORTCODE ) );

$out = do_shortcode( '[goalcart_progress]' );
check( 'shortcode renders a container', false !== strpos( $out, 'data-goalcart-widget' ) );
check( 'shortcode container has unique id', false !== strpos( $out, 'id="goalcart-shortcode-1"' ) );
check( 'shortcode defaults to full variant', false !== strpos( $out, 'data-goalcart-variant="full"' ) );

$out = do_shortcode( '[goalcart_progress variant="compact"]' );
check( 'shortcode accepts compact variant', false !== strpos( $out, 'data-goalcart-variant="compact"' ) );

$out_a = do_shortcode( '[goalcart_progress]' );
$out_b = do_shortcode( '[goalcart_progress]' );

preg_match( '/id="(goalcart-shortcode-\d+)"/', $out_a, $m_a );
preg_match( '/id="(goalcart-shortcode-\d+)"/', $out_b, $m_b );
check( 'repeated shortcode ids stay unique', isset( $m_a[1], $m_b[1] ) && $m_a[1] !== $m_b[1] );

$markup = $ui->widget_container( 'goalcart-test', 'full' );
check( 'widget container carries aria-live', false !== strpos( $markup, 'aria-live="polite"' ) );
check( 'bogus variant normalizes to full', false !== strpos( $ui->widget_container( 'goalcart-x', 'banana' ), 'data-goalcart-variant="full"' ) );

// ---------------------------------------------------------------------------
// 4. Duplicate-render guard (P11 — no double injection)
// ---------------------------------------------------------------------------
echo "\n== 4. Duplicate-render guard ==\n";

ob_start();
$ui->render_cart_widget();
$ui->render_cart_widget(); // second call must be suppressed
$cart_out = ob_get_clean();

check( 'cart location renders once', 1 === substr_count( $cart_out, 'id="goalcart-cart"' ) );
check( 'cart widget markup is escaped/safe', false === strpos( $cart_out, '<script' ) );

// ---------------------------------------------------------------------------
// 5. Frontend config payload (P11-T02)
// ---------------------------------------------------------------------------
echo "\n== 5. Frontend config ==\n";

$config = $ui->frontend_config();
check( 'config has a progress endpoint', isset( $config['endpoint'] ) && '' !== $config['endpoint'] );
check( 'config endpoint points at /progress', false !== strpos( $config['endpoint'], '/goalcart/v1/progress' ) );
check( 'config has a currency key', array_key_exists( 'currency', $config ) );
check( 'config is RTL-aware', array_key_exists( 'isRtl', $config ) );
check( 'config labels cover reward types', isset( $config['labels']['free_shipping'], $config['labels']['percent_discount'], $config['labels']['fixed_discount'], $config['labels']['free_gift'], $config['labels']['coupon'] ) );

// ---------------------------------------------------------------------------
// 6. Enabled gate (P11-T03 — master toggle + filter)
// ---------------------------------------------------------------------------
echo "\n== 6. Enabled gate ==\n";

$enabled_before = $settings->get( 'enabled', true );

$settings->set( 'enabled', false );
check( 'disabled setting turns the UI off', false === $ui->is_enabled() );
check( 'disabled shortcode renders nothing', '' === do_shortcode( '[goalcart_progress]' ) );

ob_start();
$ui->render_cart_widget();
$off_out = ob_get_clean();
check( 'disabled widget locations render nothing', '' === $off_out );

$settings->set( 'enabled', true );

add_filter( 'goalcart_frontend_enabled', '__return_false' );
check( 'goalcart_frontend_enabled filter overrides', false === $ui->is_enabled() );
remove_filter( 'goalcart_frontend_enabled', '__return_false' );
check( 'filter removal restores enabled', true === $ui->is_enabled() );

$settings->set( 'enabled', $enabled_before );

// ---------------------------------------------------------------------------
// 7. Page gating (P11-T03 — assets only on widget-capable pages)
// ---------------------------------------------------------------------------
echo "\n== 7. Page gating ==\n";

// `page_needs_widget()` is protected; exercise it through reflection so
// the gating logic stays private while remaining testable.
$ref = new \ReflectionMethod( $ui, 'page_needs_widget' );
$ref->setAccessible( true );

// CLI: no cart/checkout query and no post → no assets.
check( 'no widget page → no assets', false === $ref->invoke( $ui ) );

// A real post whose content carries the shortcode must enable assets.
// Inserted inside a transaction (get_post() re-fetches from the DB in
// this WP version, so a fake object would not survive) and rolled back;
// the post cache is cleaned afterwards so the rollback stays observable.
$wpdb = $GLOBALS['wpdb'];
$wpdb->query( 'START TRANSACTION' );

try {
	$post_id = wp_insert_post( array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Goal Cart frontend test',
		'post_content' => '[goalcart_progress]',
	), true );

	check( 'shortcode post inserted', ! is_wp_error( $post_id ) && $post_id > 0 );

	$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post'] = get_post( $post_id );

	check( 'shortcode post enables page widget detection', true === $ref->invoke( $ui ) );

	$GLOBALS['post'] = $previous_post;
} finally {
	$wpdb->query( 'ROLLBACK' );
}

if ( isset( $post_id ) && ! is_wp_error( $post_id ) ) {
	clean_post_cache( $post_id );
	check( 'shortcode post rolled back', null === get_post( $post_id ) );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "FRONTEND TEST FAILED\n" : "FRONTEND TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
