<?php
/**
 * Goal Cart reward engine tests (P05-T02 / P05-T03).
 *
 * Boots WordPress, then exercises the RewardEngine, the reward applicators
 * and the RewardSafety guards against synthetic Goal / GoalResult /
 * CartContext objects — the same pure-value-object approach as the Phase 4
 * engine tests. The WooCommerce-only application path (live cart mutations)
 * is guarded by design and not simulated here.
 *
 * Run: php tests/reward-test.php   (from the plugin directory)
 *
 * The script only reads state; it does not activate the plugin, create
 * products, or write to the database.
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
require dirname( __DIR__ ) . '/goalcart.php';

use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalResult;
use GoalCart\Rewards\Applicators\FixedDiscountApplicator;
use GoalCart\Rewards\Applicators\FreeGiftApplicator;
use GoalCart\Rewards\Applicators\FreeShippingApplicator;
use GoalCart\Rewards\Applicators\PercentageDiscountApplicator;
use GoalCart\Rewards\Reward;
use GoalCart\Rewards\RewardEngine;
use GoalCart\Rewards\RewardResult;
use GoalCart\Rewards\RewardSafety;

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

function ctx( array $data, array $items = array() ) {
    $data['items'] = $items;
    return new CartContext( $data );
}

function goal( array $data ) {
    return new Goal( $data );
}

$engine       = new GoalEngine();
$reward_engine = new RewardEngine();

// ---------------------------------------------------------------------------
// 1. Reward value object (config normalization)
// ---------------------------------------------------------------------------
echo "\n== 1. Reward value object ==\n";

$reward = Reward::from_goal(
    goal(
        array(
            'reward_type'     => Reward::TYPE_PERCENT_DISCOUNT,
            'reward_value'    => 10,
            'reward_max_value' => 50,
            'reward_meta'     => array(
                'eligible_products'   => array( 5, '6', 0, -2 ),
                'eligible_categories' => array( 11 ),
                'excluded_products'   => array( 9 ),
                'stacking'            => Reward::STACK_STACK,
            ),
        )
    )
);
check( 'from_goal reads reward type', Reward::TYPE_PERCENT_DISCOUNT === $reward->type() );
check( 'from_goal reads reward value', near( $reward->value(), 10 ) );
check( 'from_goal reads max value', near( $reward->max_value(), 50 ) );
check( 'eligible products normalized to positive ints', array( 5, 6 ) === $reward->eligible_products() );
check( 'eligible categories parsed', array( 11 ) === $reward->eligible_categories() );
check( 'excluded products parsed', array( 9 ) === $reward->excluded_products() );
check( 'stacking parsed', $reward->stacking_is_stack() );

$defaults = Reward::from_goal( goal( array( 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ) );
check( 'stacking defaults to none', ! $defaults->stacking_is_stack() );
check( 'gift mode defaults to automatic', $defaults->is_gift_automatic() );
check( 'coupon generate defaults false', ! $defaults->coupon_generate() );

$json = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_FREE_GIFT,
            'reward_meta'  => json_encode( array( 'gift_product_id' => 42, 'gift_add_mode' => Reward::GIFT_OPTIONAL ) ),
        )
    )
);
check( 'reward_meta accepts a JSON string', 42 === $json->gift_product_id() && ! $json->is_gift_automatic() );

$empty = Reward::from_goal( goal( array() ) );
check( 'no reward configured -> has_config false', ! $empty->has_config() );

// ---------------------------------------------------------------------------
// 2. RewardResult factories
// ---------------------------------------------------------------------------
echo "\n== 2. RewardResult ==\n";

$reward = Reward::from_goal( goal( array( 'reward_type' => Reward::TYPE_FIXED_DISCOUNT, 'reward_value' => 30 ) ) );

$r = RewardResult::locked( $reward, 3 );
check( 'locked state', RewardResult::STATE_LOCKED === $r->state() );
check( 'locked is not active', ! $r->is_active() );

$r = RewardResult::available( $reward, 3, 25.0, array( 'k' => 'v' ) );
check( 'available state', RewardResult::STATE_AVAILABLE === $r->state() && $r->is_active() );
check( 'available amount', near( $r->amount(), 25 ) );
check( 'available meta', 'v' === $r->meta()['k'] );
check( 'available type', Reward::TYPE_FIXED_DISCOUNT === $r->type() );

$r = RewardResult::blocked( $reward, 3, RewardResult::REASON_STACKING );
check( 'blocked state + reason', RewardResult::STATE_BLOCKED === $r->state() && RewardResult::REASON_STACKING === $r->reason() );

$r = RewardResult::not_applicable( $reward, 3, RewardResult::REASON_NO_REWARD );
check( 'not_applicable state', RewardResult::STATE_NOT_APPLICABLE === $r->state() );

$arr = RewardResult::available( $reward, 7, 10.0 )->to_array();
check( 'to_array shape', array( 'type', 'state', 'goal_id', 'reason', 'amount', 'meta', 'reward' ) === array_keys( $arr ) );

// ---------------------------------------------------------------------------
// 3. RewardApplicatorRegistry
// ---------------------------------------------------------------------------
echo "\n== 3. Applicator registry ==\n";

$types = $reward_engine->registry()->types();
sort( $types );
check(
    'registry exposes all 5 reward types',
    array( 'coupon', 'fixed_discount', 'free_gift', 'free_shipping', 'percent_discount' ) === $types
);

// ---------------------------------------------------------------------------
// 4. Discount amount math (pure)
// ---------------------------------------------------------------------------
echo "\n== 4. Discount math ==\n";

$cart = ctx(
    array( 'subtotal' => 1000, 'total' => 1000 ),
    array(
        array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 400, 'line_total' => 400, 'categories' => array( 10 ) ),
        array( 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 600, 'line_total' => 600, 'categories' => array( 11 ) ),
    )
);

$pct = new PercentageDiscountApplicator();
$fixed = new FixedDiscountApplicator();

$percent_reward = Reward::from_goal(
    goal( array( 'reward_type' => Reward::TYPE_PERCENT_DISCOUNT, 'reward_value' => 10 ) )
);
check( '10% of 1000 = 100', near( $pct->compute_amount( $percent_reward, $cart ), 100 ) );

$capped = Reward::from_goal(
    goal( array( 'reward_type' => Reward::TYPE_PERCENT_DISCOUNT, 'reward_value' => 10, 'reward_max_value' => 50 ) )
);
check( 'percent capped at max discount 50', near( $pct->compute_amount( $capped, $cart ), 50 ) );

$restricted = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_PERCENT_DISCOUNT,
            'reward_value' => 10,
            'reward_meta'  => array( 'eligible_products' => array( 1 ) ),
        )
    )
);
check( 'percent restricted to product 1 -> 40', near( $pct->compute_amount( $restricted, $cart ), 40 ) );

$by_cat = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_PERCENT_DISCOUNT,
            'reward_value' => 10,
            'reward_meta'  => array( 'eligible_categories' => array( 11 ) ),
        )
    )
);
check( 'percent restricted to category 11 -> 60', near( $pct->compute_amount( $by_cat, $cart ), 60 ) );

$excluded = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_PERCENT_DISCOUNT,
            'reward_value' => 10,
            'reward_meta'  => array( 'excluded_products' => array( 1 ) ),
        )
    )
);
check( 'percent excludes product 1 -> 60', near( $pct->compute_amount( $excluded, $cart ), 60 ) );

$fixed_reward = Reward::from_goal(
    goal( array( 'reward_type' => Reward::TYPE_FIXED_DISCOUNT, 'reward_value' => 150 ) )
);
check( 'fixed 150 clamped to eligible base 1000', near( $fixed->compute_amount( $fixed_reward, $cart ), 150 ) );

$big_fixed = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_FIXED_DISCOUNT,
            'reward_value' => 5000,
            'reward_meta'  => array( 'eligible_products' => array( 2 ) ),
        )
    )
);
check( 'fixed never exceeds eligible base (600)', near( $fixed->compute_amount( $big_fixed, $cart ), 600 ) );

$nothing = Reward::from_goal(
    goal(
        array(
            'reward_type'  => Reward::TYPE_PERCENT_DISCOUNT,
            'reward_value' => 10,
            'reward_meta'  => array( 'eligible_products' => array( 999 ) ),
        )
    )
);
check( 'no eligible items -> 0 discount', near( $pct->compute_amount( $nothing, $cart ), 0 ) );

// ---------------------------------------------------------------------------
// 5. RewardEngine::evaluate — states and safety
// ---------------------------------------------------------------------------
echo "\n== 5. RewardEngine evaluation ==\n";

$full_cart = ctx(
    array( 'subtotal' => 200, 'total' => 200 ),
    array( array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 200, 'line_total' => 200 ) )
);

// 5a. No reward configured
$r = $reward_engine->evaluate( $engine->evaluate( goal( array( 'target' => 100 ) ), $full_cart ) );
check( 'goal without reward -> not_applicable no_reward', RewardResult::STATE_NOT_APPLICABLE === $r->state() && RewardResult::REASON_NO_REWARD === $r->reason() );

// 5b. Ineligible goal (inactive)
$r = $reward_engine->evaluate(
    $engine->evaluate( goal( array( 'target' => 100, 'status' => Goal::STATUS_INACTIVE ) ), $full_cart )
);
check( 'ineligible goal -> not_applicable', RewardResult::STATE_NOT_APPLICABLE === $r->state() );

// 5c. Locked (goal not reached)
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 300, 'reward_type' => Reward::TYPE_PERCENT_DISCOUNT, 'reward_value' => 10 ) ),
        $full_cart
    )
);
check( 'unreached goal -> locked', RewardResult::STATE_LOCKED === $r->state() );

// 5d. Unlocked percent discount with amount
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_PERCENT_DISCOUNT, 'reward_value' => 10 ) ),
        $full_cart
    ),
    array( 'cart' => $full_cart )
);
check( 'completed percent goal -> available', RewardResult::STATE_AVAILABLE === $r->state() );
check( 'available carries computed amount (20)', near( $r->amount(), 20 ) );

// 5e. Unknown reward type -> not_applicable (never throws)
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => 'mystery_reward', 'reward_value' => 1 ) ),
        $full_cart
    )
);
check( 'unknown reward type -> not_applicable', RewardResult::STATE_NOT_APPLICABLE === $r->state() && RewardResult::REASON_UNKNOWN_TYPE === $r->reason() );

// 5f. Stacking: second non-stacking discount blocked
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_FIXED_DISCOUNT, 'reward_value' => 20 ) ),
        $full_cart
    ),
    array( 'already_applied' => array( Reward::TYPE_FIXED_DISCOUNT ) )
);
check( 'duplicate non-stacking type blocked (stacking)', RewardResult::STATE_BLOCKED === $r->state() && RewardResult::REASON_STACKING === $r->reason() );

// 5g. Stacking allowed: different type is fine
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_FIXED_DISCOUNT, 'reward_value' => 20 ) ),
        $full_cart
    ),
    array( 'already_applied' => array( Reward::TYPE_PERCENT_DISCOUNT ) )
);
check( 'different type stacks by default', RewardResult::STATE_AVAILABLE === $r->state() );

// 5h. Stacking='stack' allows same type
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal(
            array(
                'target'      => 100,
                'reward_type' => Reward::TYPE_FIXED_DISCOUNT,
                'reward_value' => 20,
                'reward_meta' => array( 'stacking' => Reward::STACK_STACK ),
            )
        ),
        $full_cart
    ),
    array( 'already_applied' => array( Reward::TYPE_FIXED_DISCOUNT ) )
);
check( 'stacking=stack allows same type', RewardResult::STATE_AVAILABLE === $r->state() );

// 5i. Free shipping unlocked
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ),
        $full_cart
    )
);
check( 'free shipping completed -> available', RewardResult::STATE_AVAILABLE === $r->state() );
check( 'free shipping exposes zone/method config', array( 'shipping_zone_ids', 'shipping_method_ids' ) === array_keys( $r->meta() ) );

// 5j. Coupon: no code and no generate -> blocked invalid_coupon
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_COUPON ) ),
        $full_cart
    )
);
check( 'coupon without config blocked', RewardResult::STATE_BLOCKED === $r->state() && RewardResult::REASON_INVALID_COUPON === $r->reason() );

// 5k. Coupon: nonexistent code -> blocked invalid_coupon
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal(
            array(
                'target'      => 100,
                'reward_type' => Reward::TYPE_COUPON,
                'reward_meta' => array( 'coupon_code' => 'GOALCART_DOES_NOT_EXIST_12345' ),
            )
        ),
        $full_cart
    )
);
check( 'nonexistent coupon blocked', RewardResult::STATE_BLOCKED === $r->state() && RewardResult::REASON_INVALID_COUPON === $r->reason() );

// 5l. Coupon: generate mode -> available
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal(
            array(
                'target'       => 100,
                'reward_type'  => Reward::TYPE_COUPON,
                'reward_value' => 10,
                'reward_meta'  => array( 'coupon_generate' => true ),
            )
        ),
        $full_cart
    )
);
check( 'coupon generate mode available', RewardResult::STATE_AVAILABLE === $r->state() );

// 5m. Gift: no gift product -> blocked gift_unavailable
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal( array( 'target' => 100, 'reward_type' => Reward::TYPE_FREE_GIFT ) ),
        $full_cart
    )
);
check( 'gift without product blocked', RewardResult::STATE_BLOCKED === $r->state() && RewardResult::REASON_GIFT_UNAVAILABLE === $r->reason() );

// 5n. Gift: configured product -> available with meta
$r = $reward_engine->evaluate(
    $engine->evaluate(
        goal(
            array(
                'target'      => 100,
                'reward_type' => Reward::TYPE_FREE_GIFT,
                'reward_meta' => array( 'gift_product_id' => 42, 'gift_add_mode' => Reward::GIFT_OPTIONAL ),
            )
        ),
        $full_cart
    )
);
check( 'configured gift available', RewardResult::STATE_AVAILABLE === $r->state() );
check( 'gift meta carries product + mode', 42 === $r->meta()['gift_product_id'] && Reward::GIFT_OPTIONAL === $r->meta()['gift_add_mode'] );

// 5o. Zero-target goal with reward -> unlocked -> available
$r = $reward_engine->evaluate(
    $engine->evaluate( goal( array( 'target' => 0, 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ), $full_cart )
);
check( 'zero target + reward -> available', RewardResult::STATE_AVAILABLE === $r->state() );

// ---------------------------------------------------------------------------
// 6. RewardSafety guards
// ---------------------------------------------------------------------------
echo "\n== 6. RewardSafety ==\n";

$none = Reward::from_goal( goal( array( 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ) );
$stack = Reward::from_goal(
    goal( array( 'reward_type' => Reward::TYPE_FREE_SHIPPING, 'reward_meta' => array( 'stacking' => Reward::STACK_STACK ) ) )
);

check( 'stacking none blocks same type', ! RewardSafety::stacking_allows( $none, array( Reward::TYPE_FREE_SHIPPING ) ) );
check( 'stacking none allows first grant', RewardSafety::stacking_allows( $none, array() ) );
check( 'stacking none allows different type', RewardSafety::stacking_allows( $none, array( Reward::TYPE_PERCENT_DISCOUNT ) ) );
check( 'stacking stack allows same type', RewardSafety::stacking_allows( $stack, array( Reward::TYPE_FREE_SHIPPING ) ) );

check( 'coupon_exists false for empty code', ! RewardSafety::coupon_exists( '' ) );
check( 'coupon_exists rejects unknown code', ! RewardSafety::coupon_exists( 'GOALCART_DOES_NOT_EXIST_12345' ) );

check( 'gift unavailable for zero id', ! RewardSafety::gift_product_available( 0 ) );
check( 'gift unavailable for nonexistent product', ! RewardSafety::gift_product_available( 99999999 ) );

check( 'generated coupon code deterministic', RewardSafety::generated_coupon_code( 5 ) === RewardSafety::generated_coupon_code( 5 ) );
check( 'generated coupon code differs per goal', RewardSafety::generated_coupon_code( 5 ) !== RewardSafety::generated_coupon_code( 6 ) );

// ---------------------------------------------------------------------------
// 7. CartContext own-fee exclusion (reward-loop safety)
// ---------------------------------------------------------------------------
echo "\n== 7. CartContext loop safety ==\n";

$r = Reward::from_goal( goal( array( 'reward_type' => Reward::TYPE_FIXED_DISCOUNT, 'reward_value' => 30 ) ) );
check( 'own fee prefix constant exposed on CartContext', CartContext::OWN_FEE_PREFIX === 'goalcart_reward_' );

// The pure-constructor total is used directly; own-fee exclusion is applied
// in from_cart() (requires a live WC_Cart, not simulated here). Sanity-check
// that the constructor still honors the fields as before.
$plain = new CartContext( array( 'subtotal' => 100, 'total' => 90, 'discount_total' => 10 ) );
check( 'plain context totals preserved', near( $plain->total(), 90 ) && near( $plain->subtotal(), 100 ) );

// ---------------------------------------------------------------------------
// 8. Free shipping rate filtering (method specs, no WC zone data needed)
// ---------------------------------------------------------------------------
echo "\n== 8. Free shipping rate filtering ==\n";

if ( class_exists( 'WC_Shipping_Rate' ) ) {
	$fs = new FreeShippingApplicator();

	// Helper: a fresh flat-rate with the given cost. apply_to_rates() zeroes
	// rates in place, so each check gets its own objects (no aliasing).
	// The instance id is parsed from the rate id ('flat_rate:3' => 3) like
	// real shipping methods produce, so method-instance specs match.
	$rate = function ( $id, $cost ) {
		$parts    = explode( ':', $id );
		$instance = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return new \WC_Shipping_Rate( $id, 'Flat', $cost, array(), 'flat_rate', $instance );
	};

	// No restrictions -> everything free.
	$open  = Reward::from_goal( goal( array( 'reward_type' => Reward::TYPE_FREE_SHIPPING ) ) );
	$rates = $fs->apply_to_rates(
		array( 'flat_rate:3' => $rate( 'flat_rate:3', 12 ), 'flat_rate:9' => $rate( 'flat_rate:9', 25 ) ),
		array(),
		array( $open )
	);
	check( 'unrestricted free shipping zeroes all rates', near( $rates['flat_rate:3']->cost, 0 ) && near( $rates['flat_rate:9']->cost, 0 ) );

	// Method-restricted -> only the configured instance goes free.
	$restricted = Reward::from_goal(
		goal(
			array(
				'reward_type'  => Reward::TYPE_FREE_SHIPPING,
				'reward_meta'  => array( 'shipping_method_ids' => array( 'flat_rate:3' ) ),
			)
		)
	);
	$rates = $fs->apply_to_rates(
		array( 'flat_rate:3' => $rate( 'flat_rate:3', 12 ), 'flat_rate:9' => $rate( 'flat_rate:9', 25 ) ),
		array(),
		array( $restricted )
	);
	check( 'method-restricted free shipping zeroes only that instance', near( $rates['flat_rate:3']->cost, 0 ) && near( $rates['flat_rate:9']->cost, 25 ) );

	// No active reward -> rates untouched.
	$rates = $fs->apply_to_rates( array( 'flat_rate:3' => $rate( 'flat_rate:3', 12 ) ), array(), array() );
	check( 'no active reward -> rates untouched', near( $rates['flat_rate:3']->cost, 12 ) );
} else {
	echo "SKIP free shipping rate filtering (WC_Shipping_Rate unavailable)\n";
}

// ---------------------------------------------------------------------------
// 9. WooCommerce integration wiring (read-only smoke)
// ---------------------------------------------------------------------------
echo "\n== 9. WooCommerce integration wiring ==\n";

// The plugin boots at file scope (goalcart.php), so the RewardEngine's
// WooCommerce hooks must be live with their declared priorities. Read-only:
// no cart, session, or database state is touched.
$re = \GoalCart\Plugin::instance()->reward_engine();

check( 'sync_cart hooked before calculate totals at 100', 100 === has_action( 'woocommerce_before_calculate_totals', array( $re, 'sync_cart' ) ) );
check( 'zero_gift_prices hooked before calculate totals at 10', 10 === has_action( 'woocommerce_before_calculate_totals', array( $re, 'zero_gift_prices' ) ) );
check( 'discount fees hooked to cart_calculate_fees at 20', 20 === has_action( 'woocommerce_cart_calculate_fees', array( $re, 'apply_discount_fees' ) ) );
check( 'free shipping hooked to package_rates at 100', 100 === has_filter( 'woocommerce_package_rates', array( $re, 'apply_free_shipping' ) ) );

// ---------------------------------------------------------------------------
// 10. CartContext::from_cart line-item bases
// ---------------------------------------------------------------------------
echo "\n== 10. CartContext from_cart line-item bases ==\n";

if ( class_exists( 'WC_Cart' ) && class_exists( 'WC_Product_Simple' ) ) {
	// A bare product object is enough: from_cart reads the money values from
	// the cart item array, not from the product's price. The fresh cart's
	// aggregate getters read 0 — the exact state WC is in when
	// 'woocommerce_before_calculate_totals' fires (reset_totals() runs
	// first) — so the bases must come from the line items.
	$product = new \WC_Product_Simple();
	$product->set_name( 'Test product' );

	$cart = new \WC_Cart();
	$cart->cart_contents['k1'] = array(
		'key'               => 'k1',
		'product_id'        => 0,
		'variation_id'      => 0,
		'quantity'          => 2,
		'data'              => $product,
		'line_subtotal'     => 200.0,
		'line_total'        => 180.0,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);

	$ctx = CartContext::from_cart( $cart, array( 'exclude_shipping' => true ) );

	check( 'subtotal derived from line subtotals', near( $ctx->subtotal(), 200 ) );
	check( 'discounted subtotal derived from line totals', near( $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ), 180 ) );
	check( 'total falls back to after-discount line value', near( $ctx->amount( Goal::MODE_TOTAL ), 180 ) );
	check( 'discount total derived from line delta', near( $ctx->discount_total(), 20 ) );
	check( 'quantity still counted', near( $ctx->total_quantity(), 2 ) );
} else {
	echo "SKIP from_cart line-item bases (WC_Cart/WC_Product_Simple unavailable)\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "REWARD TEST FAILED\n" : "REWARD TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
