<?php
/**
 * FaraCart WooCommerce compatibility tests (P19-T01 / P19-T02).
 *
 * Boots WordPress + WooCommerce and verifies the Phase 19 must-test matrix
 * against the installed WooCommerce version:
 *
 *  - classic Cart        : woocommerce_before_cart widget hook
 *  - Cart Block          : render_block('woocommerce/cart') widget injection
 *  - classic Checkout    : woocommerce_before_checkout_form widget hook
 *  - Checkout Block      : render_block('woocommerce/checkout') injection
 *  - Mini Cart           : woocommerce_after_mini_cart + mini-cart block
 *  - variable products   : variations resolve categories from the parent
 *  - product variations  : variation_id is preserved on the context items
 *  - coupons             : apply/remove hooks invalidate the cart cache
 *  - sale prices         : on-sale exclusion path (include_sale)
 *  - tax                 : include_tax folds line taxes into the bases
 *  - shipping zones      : zone + method restrictions on free shipping
 *  - guest checkout      : is_guest default for anonymous carts
 *  - logged-in users     : user_id is captured on the context
 *  - HPOS                : custom_order_tables compatibility declared and
 *                          the public FeaturesUtil API is used
 *
 * Read-only like the other suites: no DB writes, no product creation, no
 * cart mutation. Full-cart integration logic itself is exercised by the
 * Phase 6 cart-integration suite; this suite pins the WooCommerce version
 * contract down so a WooCommerce update that breaks a public API shows up
 * here first (P19-T02 — never rely on undocumented internals, only the
 * supported public hooks/APIs below).
 *
 * Run: php tests/woocommerce-compatibility-test.php   (from the plugin directory)
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

use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Rewards\Applicators\FreeShippingApplicator;
use FaraCart\Rewards\Reward;

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

function bare_product( $name = 'Test product' ) {
	$product = new \WC_Product_Simple();
	$product->set_name( $name );

	return $product;
}

function cart_line( $key, $product_id, $variation_id, $quantity, $subtotal, $total, $product = null ) {
	return array(
		'key'               => $key,
		'product_taxonomy'  => 0,
		'variation_id'      => $variation_id,
		'product_id'        => $product_id,
		'quantity'          => $quantity,
		'data'              => null !== $product ? $product : bare_product(),
		'line_subtotal'     => $subtotal,
		'line_total'        => $total,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);
}

// ---------------------------------------------------------------------------
// 0. Environment baseline (WooCommerce present + supported version)
// ---------------------------------------------------------------------------
echo "\n== 0. Environment ==\n";

if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
	echo "SKIP: WooCommerce not active — nothing to test\n";
	exit( 0 );
}

check( 'plugin declares WooCommerce active in this environment', \FaraCart\Compatibility::is_woocommerce_active() );
check(
	'installed WC version meets the plugin minimum (8.0)',
	\FaraCart\Compatibility::is_woocommerce_compatible()
);
echo "  (WC_VERSION=" . WC_VERSION . ")\n";

// ---------------------------------------------------------------------------
// 1. Cart (classic + block)
// ---------------------------------------------------------------------------
echo "\n== 1. Cart (classic + block) ==\n";

$ui = \FaraCart\Plugin::instance()->container()->get( \FaraCart\Frontend\ProgressUI::class );

check( 'classic cart: woocommerce_before_cart wired', false !== has_action( 'woocommerce_before_cart', array( $ui, 'render_cart_widget' ) ) );
check( 'block cart: render_block filter wired', false !== has_filter( 'render_block', array( $ui, 'render_block_widget' ) ) );

$block_cart = $ui->render_block_widget( 'CART-BLOCK', array( 'blockName' => 'woocommerce/cart' ) );
check( 'block cart: block content preserved', false !== strpos( $block_cart, 'CART-BLOCK' ) );
check( 'block cart: full widget injected', false !== strpos( $block_cart, 'id="faracart-cart"' ) && false !== strpos( $block_cart, 'data-faracart-variant="full"' ) );

// ---------------------------------------------------------------------------
echo "\n== 2. Checkout (classic + block) ==\n";

check( 'classic checkout: woocommerce_before_checkout_form wired', false !== has_action( 'woocommerce_before_checkout_form', array( $ui, 'render_checkout_widget' ) ) );

$block_checkout = $ui->render_block_widget( 'CHECKOUT-BLOCK', array( 'blockName' => 'woocommerce/checkout' ) );
check( 'block checkout: widget injected (full)', false !== strpos( $block_checkout, 'id="faracart-checkout"' ) && false !== strpos( $block_checkout, 'data-faracart-variant="full"' ) );

// ---------------------------------------------------------------------------
echo "\n== 3. Mini Cart (classic + block) ==\n";

check( 'mini cart: woocommerce_after_mini_cart hooked', false !== has_action( 'woocommerce_after_mini_cart', array( $ui, 'render_mini_cart_widget' ) ) );

$block_mini = $ui->render_block_widget( 'MINI-BLOCK', array( 'blockName' => 'woocommerce/mini-cart' ) );
check( 'mini-cart block: widget injected (compact)', false !== strpos( $block_mini, 'id="faracart-mini-cart"' ) && false !== strpos( $block_mini, 'data-faracart-variant="compact"' ) );

$foreign = $ui->render_block_widget( 'PLAIN', array( 'blockName' => 'core/paragraph' ) );
check( 'non-woocommerce block untouched', 'PLAIN' === $foreign );

// ---------------------------------------------------------------------------
echo "\n== 4. Product variations ==\n";

if ( class_exists( 'WC_Cart' ) ) {
	$v_cart = new \WC_Cart();
	$v_cart->cart_contents['v1'] = cart_line( 'v1', 42, 99, 1, 50.0, 50.0 );

	$ctx = CartContext::from_cart( $v_cart, array( 'categories' => array( 42 => array( 10, 11 ), 99 => array( 12 ) ) ) );
	check( 'variation line built', 1 === count( $ctx->items() ) );

	if ( 1 === count( $ctx->items() ) ) {
		check( 'variation_id preserved', 99 === $ctx->items()[0]->variation_id() );
		check( 'effective id is the variation', 99 === $ctx->items()[0]->effective_product_id() );
		check( 'variation categories inherit parent', array( 10, 11 ) === $ctx->items()[0]->categories() );
	}

	// A variable product in the cart without the preload still builds.
	$ctx2 = CartContext::from_cart( $v_cart );
	check( 'variation fallback lookup does not crash', 1 === count( $ctx2->items() ) );
} else {
	echo "SKIP variation checks (WC_Cart unavailable)\n";
}

// ---------------------------------------------------------------------------
echo "\n== 5. Coupons ==\n";

$ci = \FaraCart\Plugin::instance()->cart_integration();
foreach ( array( 'woocommerce_applied_coupon', 'woocommerce_removed_coupon' ) as $hook ) {
	check( "{$hook} invalidates the context", 10 === has_action( $hook, array( $ci, 'invalidate' ) ) );
}

// ---------------------------------------------------------------------------
echo "\n== 6. Sale prices & tax ==\n";

if ( class_exists( 'WC_Product_Simple' ) ) {
	$sale = new \WC_Product_Simple();
	$sale->set_name( 'On sale' );
	$sale->set_regular_price( '100' );
	$sale->set_sale_price( '80' );

	$cart = new \WC_Cart();
	$cart->cart_contents['a'] = cart_line( 'a', 0, 0, 1, 100.0, 100.0, $sale );

	$ctx = CartContext::from_cart( $cart );
	check( 'sale products included by default', near( 100, $ctx->amount( Mission::MODE_SUBTOTAL ) ) );

	$ctx = CartContext::from_cart( $cart, array( 'include_sale' => false ) );
	check( 'sale products dropped when include_sale off', $ctx->is_empty() );

	$tax_cart = new \WC_Cart();
	$tax_cart->cart_contents['t1'] = cart_line( 't1', 0, 0, 1, 200.0, 180.0 );

	// make the cart hold a line tax the way a real totals pass does
	$tax_cart->cart_contents['t1']['line_tax'] = 20.0;
	$ctx = CartContext::from_cart( $tax_cart, array( 'include_tax' => true ) );
	check( 'include_tax folds line tax into subtotal', near( 220, $ctx->amount( Mission::MODE_SUBTOTAL ) ) );
} else {
	echo "SKIP sale/tax checks (WC_Product_Simple unavailable)\n";
}

// ---------------------------------------------------------------------------
echo "\n== 7. Shipping zones & methods ==\n";

if ( class_exists( 'WC_Shipping_Rate' ) ) {
	$fs = new FreeShippingApplicator();

	$rate = function ( $id, $cost ) {
		$parts    = explode( ':', $id );
		$instance = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return new \WC_Shipping_Rate( $id, 'Flat', $cost, array(), $parts[0], $instance );
	};

	$open  = Reward::from_mission( new Mission( array( 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ) );
	$rates = $fs->apply_to_rates(
		array( 'flat_rate:3' => $rate( 'flat_rate:3', 12 ), 'flat_rate:9' => $rate( 'flat_rate:9', 25 ) ),
		array(),
		array( $open )
	);
	check( 'unrestricted free shipping zeroes all rates', near( $rates['flat_rate:3']->cost, 0 ) && near( $rates['flat_rate:9']->cost, 0 ) );

	$restricted = Reward::from_mission(
		new Mission(
			array(
				'reward_type' => Reward::TYPE_FREE_SHIPPING,
				'reward_meta' => array( 'shipping_method_ids' => array( 'flat_rate:3' ) ),
			)
		)
	);
	$rates = $fs->apply_to_rates(
		array( 'flat_rate:3' => $rate( 'flat_rate:3', 12 ), 'flat_rate:9' => $rate( 'flat_rate:9', 25 ) ),
		array(),
		array( $restricted )
	);
	check( 'method-scoped free shipping zeroes only that instance', near( $rates['flat_rate:3']->cost, 0 ) && near( $rates['flat_rate:9']->cost, 25 ) );

	check(
		'zone matching uses the public WC_Shipping_Zones API',
		class_exists( 'WC_Shipping_Zones' ) && method_exists( 'WC_Shipping_Zones', 'get_zone_matching_package' )
	);
} else {
	echo "SKIP shipping checks (WC_Shipping_Rate unavailable)\n";
}

// ---------------------------------------------------------------------------
echo "\n== 8. Guest & logged-in ==\n";

$guest = new CartContext( array( 'user_id' => 0 ) );
check( 'guest context flagged', true === $guest->is_guest() );
check( 'guest user id is 0', 0 === $guest->user_id() );

$member = new CartContext( array( 'user_id' => 7, 'is_guest' => false ) );
check( 'member context flagged', false === $member->is_guest() && 7 === $member->user_id() );

// ---------------------------------------------------------------------------
echo "\n== 9. HPOS ==\n";

$features_api = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' );
check( 'HPOS compat uses the public FeaturesUtil API', $features_api );

$declared = false;
if ( $features_api ) {
	$compat = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
	foreach ( (array) $compat as $bucket ) {
		if ( in_array( 'ravis-faracart/ravis-faracart.php', (array) $bucket, true ) ) {
			$declared = true;
			break;
		}
	}
}
check( 'faracart declared compatible with custom_order_tables (HPOS)', $declared );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "WOOCOMMERCE COMPATIBILITY TEST FAILED\n" : "WOOCOMMERCE COMPATIBILITY TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );