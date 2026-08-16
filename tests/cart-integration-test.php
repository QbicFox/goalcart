<?php
/**
 * FaraCart cart-integration tests (P06-T01 / P06-T02 / P06-T03 / P06-T04).
 *
 * Boots WordPress, then exercises the CartIntegration service — the single
 * source of truth for the live-cart snapshot — and the Phase 6 additions to
 * CartContext::from_cart() (preloaded category map, variation categories
 * resolved from the parent). Read-only like the other suites: no products
 * are created, no database rows are written, and the plugin is not
 * activated.
 *
 * Run: php tests/cart-integration-test.php   (from the plugin directory)
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

define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

require $dir . '/wp-load.php';
require dirname( __DIR__ ) . '/ravis-faracart.php';

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\CartContext;

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

function near( $a, $b, $eps = 0.001 ) {
	return abs( (float) $a - (float) $b ) < $eps;
}

// Bare product object: from_cart reads money values from the cart item
// array, not from the product's price, so no real product is needed.
function bare_product( $name = 'Test product' ) {
	$product = new \WC_Product_Simple();
	$product->set_name( $name );

	return $product;
}

function cart_line( $key, $product_id, $variation_id, $quantity, $subtotal, $total, $product = null ) {
	return array(
		'key'               => $key,
		'product_id'        => $product_id,
		'variation_id'      => $variation_id,
		'quantity'          => $quantity,
		'data'              => null !== $product ? $product : bare_product(),
		'line_subtotal'     => $subtotal,
		'line_total'        => $total,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);
}

// ---------------------------------------------------------------------------
// 1. Lifecycle hook wiring (read-only)
// ---------------------------------------------------------------------------
echo "\n== 1. Cart lifecycle hook wiring ==\n";

$ci = \GoalCart\Plugin::instance()->cart_integration();

$lifecycle_hooks = array(
	'woocommerce_cart_loaded_from_session',
	'woocommerce_add_to_cart',
	'woocommerce_cart_item_removed',
	'woocommerce_cart_item_restored',
	'woocommerce_after_cart_item_quantity_update',
	'woocommerce_applied_coupon',
	'woocommerce_removed_coupon',
	'woocommerce_shipping_method_chosen',
	'woocommerce_checkout_update_order_review',
	'woocommerce_store_api_cart_select_shipping_rate',
);

foreach ( $lifecycle_hooks as $hook ) {
	check(
		"invalidate hooked on {$hook}",
		10 === has_action( $hook, array( $ci, 'invalidate' ) )
	);
}

// ---------------------------------------------------------------------------
// 2. Memoization (P06-T04)
// ---------------------------------------------------------------------------
echo "\n== 2. Memoization ==\n";

$cart = new \WC_Cart();
$cart->cart_contents['k1'] = cart_line( 'k1', 0, 0, 2, 200.0, 180.0 );

$fresh = new CartIntegration();

$a = $fresh->context( $cart, array( 'exclude_shipping' => true ) );
check( 'first build reads line-item subtotal', near( $a->subtotal(), 200 ) );
check( 'first build reads line-item total basis', near( $a->amount( \GoalCart\Goals\Goal::MODE_TOTAL ), 180 ) );

$b = $fresh->context( $cart, array( 'exclude_shipping' => true ) );
check( 'second build is memoized (same instance)', $a === $b );

$fresh->invalidate();
$c = $fresh->context( $cart, array( 'exclude_shipping' => true ) );
check( 'invalidate forces a rebuild', $c !== $a );

$d = $fresh->context( $cart );
check( 'different args get a separate cache entry', $d !== $c );

// A cart-content change must rebuild too.
$cart->cart_contents['k2'] = cart_line( 'k2', 0, 0, 1, 100.0, 100.0 );
$e = $fresh->context( $cart, array( 'exclude_shipping' => true ) );
check( 'changed cart contents rebuild the context', $e !== $c && near( $e->subtotal(), 300 ) );

// ---------------------------------------------------------------------------
// 3. Preloaded categories + variation parent resolution (P06-T03)
// ---------------------------------------------------------------------------
echo "\n== 3. Preloaded categories / variations ==\n";

if ( class_exists( 'WC_Cart' ) ) {
	// A variation item whose categories live on the parent (id 42). The
	// preloaded map keys by the canonical product id, so the variation
	// inherits the parent's categories — the WooCommerce convention.
	$v_cart = new \WC_Cart();
	$v_cart->cart_contents['v1'] = cart_line( 'v1', 42, 99, 1, 50.0, 50.0 );

	$ctx = CartContext::from_cart(
		$v_cart,
		array( 'categories' => array( 42 => array( 10, 11 ), 99 => array( 12 ) ) )
	);

	$items = $ctx->items();
	check( 'context built for the variation line', 1 === count( $items ) );
	check( 'variation categories come from the parent preload', array( 10, 11 ) === $items[0]->categories() );
	check( 'variation id recorded', 99 === $items[0]->variation_id() );

	// Without the preload, the fallback per-item lookup still yields the
	// parent keyed lookup (and an empty result for an unknown product).
	// Note: real variation -> parent inheritance through the
	// wp_get_post_terms fallback is additionally verified against a live
	// variable product in the throwaway empirical store check; this suite
	// exercises the preloaded-map path so it stays catalog-independent.
	$ctx2 = CartContext::from_cart( $v_cart );
	check( 'fallback category lookup does not crash', 1 === count( $ctx2->items() ) );

	// The integration service builds the map itself (batched) and never
	// crashes for unknown product ids.
	$ci2 = new CartIntegration();
	$ctx3 = $ci2->context( $v_cart );
	check( 'integration builds context for unknown product ids', false === $ctx3->is_empty() );
} else {
	echo "SKIP preloaded categories (WC_Cart unavailable)\n";
}

// ---------------------------------------------------------------------------
// 4. Null-cart guard (no WooCommerce cart available)
// ---------------------------------------------------------------------------
echo "\n== 4. Null-cart guard ==\n";

$empty = ( new CartIntegration() )->context();
check( 'no cart available yields an empty context', $empty->is_empty() );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "CART INTEGRATION TEST FAILED\n" : "CART INTEGRATION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
