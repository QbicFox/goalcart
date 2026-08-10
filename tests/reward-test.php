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
            // A legacy goal that still stores the removed 'optional' add
            // mode (pre-Phase-33 data) must read as automatic so the mode
            // can never surface in the UI or the engine again.
            'reward_meta'  => json_encode( array( 'gift_product_id' => 42, 'gift_add_mode' => 'optional' ) ),
        )
    )
);
check( 'reward_meta accepts a JSON string', 42 === $json->gift_product_id() && $json->is_gift_automatic() );
check( 'legacy optional gift mode normalized to automatic', Reward::GIFT_AUTOMATIC === $json->gift_add_mode() );

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
                'reward_meta' => array( 'gift_product_id' => 42, 'gift_add_mode' => Reward::GIFT_AUTOMATIC ),
            )
        ),
        $full_cart
    )
);
check( 'configured gift available', RewardResult::STATE_AVAILABLE === $r->state() );
check( 'gift meta carries product + mode', 42 === $r->meta()['gift_product_id'] && Reward::GIFT_AUTOMATIC === $r->meta()['gift_add_mode'] );

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
// 11. Free gift cart protection (shoppers cannot remove an earned gift)
//
// The gift line is marked with goalcart_gift*, and the engine makes it
// shopper-proof: zero-priced, no remove link, quantity locked to 1, and a
// removed gift is restored while its goal still grants it. These checks
// exercise the guard paths and filters against a non-persisted WC_Cart
// (no add_to_cart, so no session/customer dependency — consistent with the
// suite's read-only contract).
// ---------------------------------------------------------------------------
echo "\n== 11. Free gift cart protection ==\n";

$re = \GoalCart\Plugin::instance()->reward_engine();

check( 'gift remove-link filter wired', false !== has_filter( 'woocommerce_cart_item_remove_link', array( $re, 'hide_gift_remove_link' ) ) );
check( 'gift quantity filter wired', false !== has_filter( 'woocommerce_cart_item_quantity', array( $re, 'lock_gift_quantity' ) ) );
check( 'gift removal-restore action wired', false !== has_action( 'woocommerce_cart_item_removed', array( $re, 'restore_removed_gift' ) ) );

if ( class_exists( 'WC_Cart' ) && class_exists( 'WC_Product_Simple' ) ) {
	$gift_product = new \WC_Product_Simple();
	$gift_product->set_name( 'Gift' );
	$gift_product->set_price( 50 );
	$gift_product->set_regular_price( 50 );

	$normal_product = new \WC_Product_Simple();
	$normal_product->set_name( 'Normal' );
	$normal_product->set_price( 25 );
	$normal_product->set_regular_price( 25 );

	$cart = new \WC_Cart();
	$cart->cart_contents['gift1'] = array(
		'key'                 => 'gift1',
		'product_id'          => 42,
		'variation_id'        => 0,
		'quantity'            => 1,
		'data'                => $gift_product,
		'goalcart_gift'       => true,
		'goalcart_gift_goal'  => 1,
		'goalcart_gift_product' => 42,
		'goalcart_gift_mode'  => Reward::GIFT_AUTOMATIC,
		'line_subtotal'       => 50.0,
		'line_total'          => 50.0,
	);
	$cart->cart_contents['norm1'] = array(
		'key'           => 'norm1',
		'product_id'    => 7,
		'variation_id'  => 0,
		'quantity'      => 1,
		'data'          => $normal_product,
		'line_subtotal' => 25.0,
		'line_total'    => 25.0,
	);
	// Choose-mode gift: removable by the shopper (removal permission is
	// per mode — mandatory gifts hide the remove control, selectable
	// (choose-mode) gifts keep it).
	$cart->cart_contents['gift2'] = array(
		'key'                 => 'gift2',
		'product_id'          => 42,
		'variation_id'        => 0,
		'quantity'            => 1,
		'data'                => $gift_product,
		'goalcart_gift'       => true,
		'goalcart_gift_goal'  => 2,
		'goalcart_gift_product' => 42,
		'goalcart_gift_mode'  => Reward::GIFT_CHOOSE,
		'line_subtotal'       => 50.0,
		'line_total'          => 50.0,
	);
	// Legacy gift line: no mode stamp and an unresolvable granting goal —
	// the conservative default keeps it mandatory until re-added.
	$cart->cart_contents['gift3'] = array(
		'key'                 => 'gift3',
		'product_id'          => 42,
		'variation_id'        => 0,
		'quantity'            => 1,
		'data'                => $gift_product,
		'goalcart_gift'       => true,
		'goalcart_gift_goal'  => 99999999,
		'goalcart_gift_product' => 42,
		'line_subtotal'       => 50.0,
		'line_total'          => 50.0,
	);

	// zero_gift_prices zeroes only gift lines.
	$re->zero_gift_prices( $cart );
	check( 'gift line price zeroed', near( 0, $cart->cart_contents['gift1']['data']->get_price() ) );
	check( 'normal line price untouched', near( 25, $cart->cart_contents['norm1']['data']->get_price() ) );

	// Quantity lock: gift lines are locked to 1, normal lines pass through.
	$locked = $re->lock_gift_quantity( '<input />', 'gift1', $cart->cart_contents['gift1'] );
	check( 'gift quantity locked to 1', false !== strpos( (string) $locked, 'value="1"' ) );
	check( 'gift quantity hidden qty posts', false !== strpos( (string) $locked, 'cart[gift1][qty]' ) );
	check( 'normal quantity passes through', '<input />' === $re->lock_gift_quantity( '<input />', 'norm1', $cart->cart_contents['norm1'] ) );

	// Remove-link hiding needs the global cart (the filter only receives
	// the item key). Restore the previous instance afterwards.
	$previous_cart = WC()->cart;
	WC()->cart = $cart;
	try {
		check( 'gift remove link hidden', '' === $re->hide_gift_remove_link( '<a>x</a>', 'gift1' ) );
		check( 'normal remove link kept', '<a>x</a>' === $re->hide_gift_remove_link( '<a>x</a>', 'norm1' ) );
		check( 'unknown key remove link kept', '<a>x</a>' === $re->hide_gift_remove_link( '<a>x</a>', 'nope' ) );
		// Removal permission per mode: selectable (choose-mode) gifts keep
		// their remove control; legacy unstamped gift lines stay
		// mandatory.
		check( 'choose gift remove link kept', '<a>x</a>' === $re->hide_gift_remove_link( '<a>x</a>', 'gift2' ) );
		check( 'legacy unstamped gift remove link hidden', '' === $re->hide_gift_remove_link( '<a>x</a>', 'gift3' ) );
	} finally {
		WC()->cart = $previous_cart;
	}

	// Server-authoritative quantity clamp (Bug A): a quantity change aimed
	// directly at a gift line (classic form bypass, crafted AJAX) is forced
	// back to 1 on the next totals pass; normal lines are untouched.
	$cart->cart_contents['gift1']['quantity'] = 3;
	$re->clamp_gift_quantities( $cart );
	check( 'clamp forces gift quantity back to 1', 1 === (int) $cart->cart_contents['gift1']['quantity'] );
	check( 'clamp leaves normal quantity alone', 1 === (int) $cart->cart_contents['norm1']['quantity'] );
	check( 'clamp wired before sync at priority 5', 5 === has_action( 'woocommerce_before_calculate_totals', array( $re, 'clamp_gift_quantities' ) ) );

	// Store API / Blocks quantity lock (Bug A): gift lines are marked
	// quantity-fixed so the Blocks cart renders a fixed “1” (no stepper)
	// and the Store API rejects update attempts on them.
	check( 'store-api editable filter wired', false !== has_filter( 'woocommerce_store_api_product_quantity_editable', array( $re, 'store_api_gift_quantity_editable' ) ) );
	check( 'store-api gift quantity not editable', false === $re->store_api_gift_quantity_editable( true, $gift_product, $cart->cart_contents['gift1'] ) );
	check( 'store-api normal quantity stays editable', true === $re->store_api_gift_quantity_editable( true, $normal_product, $cart->cart_contents['norm1'] ) );

	// restore_removed_gift: non-gift removals are never restored.
	$cart->removed_cart_contents['norm1'] = $cart->cart_contents['norm1'];
	unset( $cart->cart_contents['norm1'] );
	$re->restore_removed_gift( 'norm1', $cart );
	check( 'non-gift removal not restored', ! isset( $cart->cart_contents['norm1'] ) );
	unset( $cart->removed_cart_contents['norm1'] );

	// restore_removed_gift: a gift whose goal no longer exists is not
	// restored (repository lookup fails before any cart mutation).
	$cart->removed_cart_contents['gift1'] = $cart->cart_contents['gift1'];
	unset( $cart->cart_contents['gift1'] );
	$cart->removed_cart_contents['gift1']['goalcart_gift_goal'] = 99999999;
	$re->restore_removed_gift( 'gift1', $cart );
	check( 'orphaned goal gift not restored', ! isset( $cart->cart_contents['gift1'] ) );

	// Engine-initiated removal (stale reward) is not restored: the
	// removing_gift flag suppresses the restore handler. remove_cart_item is
	// session-free, so no WC session/customer is required.
	$cart->cart_contents['gift1'] = $cart->removed_cart_contents['gift1'];
	unset( $cart->removed_cart_contents['gift1'] );

	$remove = new \ReflectionMethod( $re, 'remove_gift_line' );
	$remove->invoke( $re, $cart, 99999999 );
	check( 'engine removal drops the gift line', ! isset( $cart->cart_contents['gift1'] ) );
	check( 'engine removal not restored', ! isset( $cart->cart_contents['gift1'] ) && isset( $cart->removed_cart_contents['gift1'] ) );
} else {
	echo "SKIP free gift cart protection (WC_Cart/WC_Product_Simple unavailable)\n";
}

// ---------------------------------------------------------------------------
// 12. Free gift removal restore (positive path, transactional)
//
// A shopper removing an earned gift line triggers
// 'woocommerce_cart_item_removed', and restore_removed_gift re-adds it
// while the goal still grants an automatic free-gift reward. Runs against
// a real goal row + purchasable product inside a rolled-back transaction;
// the cart session is swapped for an in-memory mock so no session row can
// be written to the database.
// ---------------------------------------------------------------------------
echo "\n== 12. Free gift removal restore (positive path) ==\n";

if ( class_exists( 'WC_Cart' ) && class_exists( 'WC_Product_Simple' ) && class_exists( 'WC_Session' ) ) {
	$container  = \GoalCart\Plugin::instance()->container();
	$goal_repo  = $container->get( \GoalCart\Goals\GoalRepository::class );
	$wpdb       = $GLOBALS['wpdb'];
	$goals_table = \GoalCart\Database\Schema::table( 'goals' );

	$real_session = WC()->session;
	// In-memory session so WC_Cart_Session::set_session() writes to memory
	// instead of the real session handler (no DB residue).
	WC()->session = new class extends \WC_Session {};

	$wpdb->query( 'START TRANSACTION' );
	try {
		// A purchasable, in-stock simple product (direct insert like the
		// analytics suite — wp_insert_post fires hooks we do not need).
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->posts, array(
			'ID'                => 900020 + wp_rand( 1, 9999 ),
			'post_author'       => 0,
			'post_date'         => $now,
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'post_content'      => '',
			'post_title'        => 'Gift product (test)',
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'post_name'         => 'goalcart-gift-test-' . wp_rand( 1000, 9999 ),
			'post_modified'     => $now,
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'post_type'         => 'product',
		) );
		$product_id = (int) $wpdb->insert_id;
		update_post_meta( $product_id, '_price', '50' );
		update_post_meta( $product_id, '_regular_price', '50' );
		update_post_meta( $product_id, '_stock_status', 'instock' );
		update_post_meta( $product_id, '_manage_stock', 'no' );
		update_post_meta( $product_id, '_virtual', 'no' );
		update_post_meta( $product_id, '_downloadable', 'no' );

		$wpdb->insert( $goals_table, array(
			'name'             => 'Gift Goal (test)',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'reward_type'      => 'free_gift',
			'reward_meta'      => wp_json_encode( array(
				'gift_product_id' => $product_id,
				'gift_add_mode'   => 'automatic',
			) ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );
		$goal_id = (int) $wpdb->insert_id;

		check( 'gift restore goal seeded', $goal_id > 0 && $product_id > 0 );
		check( 'gift restore product available', RewardSafety::gift_product_available( $product_id ) );

		$cart = new \WC_Cart();
		// A qualifying line (goal target is 100; cart subtotal 200).
		$qual = new \WC_Product_Simple();
		$qual->set_name( 'Qualifier' );
		$qual->set_price( 200 );
		$qual->set_regular_price( 200 );
		$cart->cart_contents['q1'] = array(
			'key'           => 'q1',
			'product_id'    => 5,
			'variation_id'  => 0,
			'quantity'      => 1,
			'data'          => $qual,
			'line_subtotal' => 200.0,
			'line_total'    => 200.0,
		);
		// The earned gift line, marked by the engine.
		$cart->cart_contents['giftX'] = array(
			'key'                   => 'giftX',
			'product_id'            => $product_id,
			'variation_id'          => 0,
			'quantity'              => 1,
			'data'                  => wc_get_product( $product_id ),
			'goalcart_gift'         => true,
			'goalcart_gift_goal'    => $goal_id,
			'goalcart_gift_product' => $product_id,
			'line_subtotal'         => 50.0,
			'line_total'            => 50.0,
		);

		// The shopper removes the gift: remove_cart_item fires
		// 'woocommerce_cart_item_removed', and the engine's handler must
		// re-add it (the goal is active and still grants the reward). The
		// restored line lives under a fresh generated cart key, so it is
		// located by its goal marker.
		$cart->remove_cart_item( 'giftX' );
		$restored_key = null;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['goalcart_gift_goal'] ) && $goal_id === (int) $item['goalcart_gift_goal'] ) {
				$restored_key = $key;
				break;
			}
		}
		check( 'removed gift restored by the engine', null !== $restored_key );
		check( 'restored gift keeps the goal marker', null !== $restored_key && $goal_id === (int) $cart->cart_contents[ $restored_key ]['goalcart_gift_goal'] );
		check( 'restored gift is zero-priced', null !== $restored_key && isset( $cart->cart_contents[ $restored_key ]['data'] ) && $cart->cart_contents[ $restored_key ]['data'] instanceof \WC_Product && near( 0, $cart->cart_contents[ $restored_key ]['data']->get_price() ) );

		// The qualifying line survived untouched.
		check( 'qualifying line survives', isset( $cart->cart_contents['q1'] ) );

		// Deactivating the goal turns the next removal into a permanent one.
		// This check simulates the admin's separate request: sync_cart was
		// never called in this test, so the repository's per-request
		// active-goal cache is unpopulated and the priority-20
		// calculate_totals listener reads a fresh query. Do NOT add a
		// sync_cart call before this — the stale cache would re-add the
		// gift mid-request and break the assertion (that only happens with
		// same-request DB mutations, which production never does).
		$wpdb->update( $goals_table, array( 'status' => 'inactive' ), array( 'id' => $goal_id ) );
		$cart->remove_cart_item( $restored_key );
		check( 'removed gift not restored once the goal is inactive', null !== $restored_key && ! isset( $cart->cart_contents[ $restored_key ] ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		WC()->session = $real_session;
	}
} else {
	echo "SKIP free gift removal restore (WC classes unavailable)\n";
}

// ---------------------------------------------------------------------------
// 13. Gift reconciliation (stale removal + selectable re-selection)
//
// End-to-end coverage of the free-gift bug fixes against real goal rows
// and purchasable products (rolled-back transaction, in-memory session):
//   (a) a gift line whose granting goal stops qualifying is revoked by
//       scanning the live cart (Bug B), while a customer-added line of
//       the same product — which carries no goal marker — survives;
//   (b) selectable (choose) mode adds exactly one gift per goal, and
//       re-selecting a candidate replaces the previous selection instead
//       of stacking a second line (Bug C); the chosen gift stays while
//       the goal grants it and is revoked the moment it stops qualifying;
//   (c) the quantity clamp and the goal markers (goalcart_gift_mode)
//       hold on lines added through the real add_to_cart path.
// ---------------------------------------------------------------------------
echo "\n== 13. Gift reconciliation (stale removal + selectable re-selection) ==\n";

if ( class_exists( 'WC_Cart' ) && class_exists( 'WC_Product_Simple' ) && class_exists( 'WC_Session' ) ) {
	$wpdb        = $GLOBALS['wpdb'];
	$goals_table = \GoalCart\Database\Schema::table( 'goals' );

	$real_session = WC()->session;
	// In-memory session so WC_Cart_Session writes to memory, not the DB.
	WC()->session = new class extends \WC_Session {};

	// Force the plugin enabled + cumulative conflict mode for this block,
	// whatever the dev database holds, and restore afterwards.
	$previous_option = get_option( 'goalcart_settings', null );
	$forced_settings = is_array( $previous_option ) ? $previous_option : array();
	$forced_settings['enabled']             = true;
	$forced_settings['conflict_resolution'] = 'cumulative';
	update_option( 'goalcart_settings', $forced_settings );

	// This block drives sync_cart through a dedicated engine instance (so
	// the seeded goals are the active ones) while the WooCommerce hooks
	// stay registered on the plugin's engine. When the dedicated engine
	// removes a gift line, the plugin's hooked restore_removed_gift would
	// re-add it (its own removing_gift flag is untouched) — a two-instance
	// artifact that cannot happen in production, where the removing engine
	// is the hooked one. Detach the restore handler for this block only.
	$plugin_engine    = \GoalCart\Plugin::instance()->reward_engine();
	$restore_hooked   = has_action( 'woocommerce_cart_item_removed', array( $plugin_engine, 'restore_removed_gift' ) );

	if ( $restore_hooked ) {
		remove_action( 'woocommerce_cart_item_removed', array( $plugin_engine, 'restore_removed_gift' ), 10 );
	}

	$wpdb->query( 'START TRANSACTION' );
	try {
		// Two purchasable, in-stock simple products (direct insert like the
		// analytics suite).
		$now         = current_time( 'mysql' );
		$product_ids = array();

		foreach ( array( 'Gift A (test)', 'Gift B (test)' ) as $title ) {
			$wpdb->insert( $wpdb->posts, array(
				'ID'                => 900030 + wp_rand( 1, 9999 ),
				'post_author'       => 0,
				'post_date'         => $now,
				'post_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
				'post_content'      => '',
				'post_title'        => $title,
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'open',
				'ping_status'       => 'open',
				'post_name'         => 'goalcart-gift-test-' . wp_rand( 1000, 9999 ),
				'post_modified'     => $now,
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'post_type'         => 'product',
			) );
			$pid = (int) $wpdb->insert_id;
			update_post_meta( $pid, '_price', '50' );
			update_post_meta( $pid, '_regular_price', '50' );
			update_post_meta( $pid, '_stock_status', 'instock' );
			update_post_meta( $pid, '_manage_stock', 'no' );
			update_post_meta( $pid, '_virtual', 'no' );
			update_post_meta( $pid, '_downloadable', 'no' );
			$product_ids[] = $pid;
		}

		$gift_a = $product_ids[0];
		$gift_b = $product_ids[1];

		// Automatic-mode goal (mandatory gift: product A at 100 subtotal).
		$wpdb->insert( $goals_table, array(
			'name'             => 'Auto Gift Goal (test)',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'reward_type'      => 'free_gift',
			'reward_meta'      => wp_json_encode( array(
				'gift_product_id' => $gift_a,
				'gift_add_mode'   => 'automatic',
				'stacking'        => Reward::STACK_STACK,
			) ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );
		$auto_goal = (int) $wpdb->insert_id;

		// Selectable-mode goal (choose A or B at the same threshold).
		$wpdb->insert( $goals_table, array(
			'name'             => 'Choose Gift Goal (test)',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'reward_type'      => 'free_gift',
			'reward_meta'      => wp_json_encode( array(
				'gift_products'   => array( $gift_a, $gift_b ),
				'gift_add_mode'   => 'choose',
				'stacking'        => Reward::STACK_STACK,
			) ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );
		$choose_goal = (int) $wpdb->insert_id;

		check( 'reconciliation goals seeded', $auto_goal > 0 && $choose_goal > 0 );
		check( 'reconciliation products available', RewardSafety::gift_product_available( $gift_a ) && RewardSafety::gift_product_available( $gift_b ) );

		// A dedicated engine + repository (fresh caches) so the seeded
		// goals are the active ones and the forced settings are read.
		$repo   = new \GoalCart\Goals\GoalRepository();
		$engine = new RewardEngine( null, $repo, new \GoalCart\Settings\Settings(), null, null );

		$cart = new \WC_Cart();
		$qual = new \WC_Product_Simple();
		$qual->set_name( 'Qualifier' );
		$qual->set_price( 200 );
		$qual->set_regular_price( 200 );
		$cart->cart_contents['q1'] = array(
			'key'           => 'q1',
			'product_id'    => 5,
			'variation_id'  => 0,
			'quantity'      => 1,
			'data'          => $qual,
			'line_subtotal' => 200.0,
			'line_total'    => 200.0,
		);

		$gift_lines = function ( $goal_id ) use ( $cart ) {
			$lines = array();

			foreach ( $cart->get_cart() as $key => $item ) {
				if ( ! empty( $item['goalcart_gift_goal'] ) && (int) $item['goalcart_gift_goal'] === (int) $goal_id ) {
					$lines[ $key ] = $item;
				}
			}

			return $lines;
		};

		// First pass: the automatic goal grants its gift on a qualifying
		// cart.
		$engine->sync_cart( $cart );
		$auto_lines = $gift_lines( $auto_goal );
		check( 'auto gift granted on qualifying cart', 1 === count( $auto_lines ) );
		$auto_line = reset( $auto_lines );
		check( 'auto gift line carries the mode marker', is_array( $auto_line ) && isset( $auto_line['goalcart_gift_mode'] ) && Reward::GIFT_AUTOMATIC === $auto_line['goalcart_gift_mode'] );
		check( 'auto gift line added at quantity 1', is_array( $auto_line ) && 1 === (int) $auto_line['quantity'] );

		// Bug B: the qualifying product is removed — the auto gift must go
		// with it, on the same recalculation.
		unset( $cart->cart_contents['q1'] );
		$engine->sync_cart( $cart );
		check( 'stale auto gift removed when the goal stops qualifying', 0 === count( $gift_lines( $auto_goal ) ) );

		// A customer-added line of the SAME product (no goal marker) must
		// never be touched by gift reconciliation — and the goal re-adding
		// its own marked gift line does not remove or merge the shopper's.
		$cart->cart_contents['own_a'] = array(
			'key'           => 'own_a',
			'product_id'    => $gift_a,
			'variation_id'  => 0,
			'quantity'      => 1,
			'data'          => wc_get_product( $gift_a ),
			'line_subtotal' => 50.0,
			'line_total'    => 50.0,
		);
		$cart->cart_contents['q1'] = array(
			'key'           => 'q1',
			'product_id'    => 5,
			'variation_id'  => 0,
			'quantity'      => 1,
			'data'          => $qual,
			'line_subtotal' => 200.0,
			'line_total'    => 200.0,
		);
		$engine->sync_cart( $cart );
		check( 'customer line of the same product survives reconciliation', isset( $cart->cart_contents['own_a'] ) );
		check( 'customer line never marked as a gift', empty( $cart->cart_contents['own_a']['goalcart_gift'] ) );
		check( 'auto gift re-added for the still-qualifying goal', 1 === count( $gift_lines( $auto_goal ) ) );

		// Bug C: selectable mode — choosing a candidate adds exactly one
		// gift line for the goal.
		check( 'choose-mode gift A added', $engine->add_chosen_gift( $choose_goal, $gift_a, $cart ) );
		$choose_lines = $gift_lines( $choose_goal );
		check( 'exactly one choose-mode gift line after choosing A', 1 === count( $choose_lines ) );
		$choose_line = reset( $choose_lines );
		check( 'chosen product A is the one added', is_array( $choose_line ) && (int) $choose_line['goalcart_gift_product'] === $gift_a );
		check( 'choose-mode gift carries the mode marker', is_array( $choose_line ) && isset( $choose_line['goalcart_gift_mode'] ) && Reward::GIFT_CHOOSE === $choose_line['goalcart_gift_mode'] );

		// Re-selecting a different candidate replaces, never duplicates.
		check( 'choose-mode gift B selected', $engine->add_chosen_gift( $choose_goal, $gift_b, $cart ) );
		$choose_lines = $gift_lines( $choose_goal );
		check( 're-selection replaces the old gift', 1 === count( $choose_lines ) );
		$choose_line = reset( $choose_lines );
		check( 're-selected product B is the one added', is_array( $choose_line ) && (int) $choose_line['goalcart_gift_product'] === $gift_b );

		// Re-selecting the SAME candidate stays idempotent.
		$engine->add_chosen_gift( $choose_goal, $gift_b, $cart );
		check( 'same-candidate re-selection is idempotent', 1 === count( $gift_lines( $choose_goal ) ) );

		// While the goal still grants it, the chosen gift survives a
		// reconciliation pass (the picker must not re-add — no auto-add in
		// choose mode — and must not remove a valid choice).
		$engine->sync_cart( $cart );
		check( 'chosen gift kept while the goal still grants it', 1 === count( $gift_lines( $choose_goal ) ) );

		// Bug B for choose mode: losing eligibility revokes the chosen gift
		// live (and the auto goal's gift at the same time).
		unset( $cart->cart_contents['q1'], $cart->cart_contents['own_a'] );
		$engine->sync_cart( $cart );
		check( 'chosen gift revoked when the goal stops qualifying', 0 === count( $gift_lines( $choose_goal ) ) );
		check( 'auto gift revoked with the goal', 0 === count( $gift_lines( $auto_goal ) ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		WC()->session = $real_session;

		if ( $restore_hooked ) {
			add_action( 'woocommerce_cart_item_removed', array( $plugin_engine, 'restore_removed_gift' ), 10, 2 );
		}

		if ( null === $previous_option ) {
			delete_option( 'goalcart_settings' );
		} else {
			update_option( 'goalcart_settings', $previous_option );
		}
	}
} else {
	echo "SKIP gift reconciliation (WC classes unavailable)\n";
}

// ---------------------------------------------------------------------------
// 14. Gift REST controller wiring (REST cart initialization fix)
//
// The storefront gift endpoint acquires the shopper's cart through
// CartIntegration::live_cart() (which restores the session-backed cart on
// custom REST routes instead of seeing a null WC()->cart). This guards
// the container wiring — GiftController must receive CartIntegration — so
// a future constructor change cannot silently reintroduce the
// "goalcart_gift_empty_cart" 400 on every gift claim.
// ---------------------------------------------------------------------------
echo "\n== 14. Gift REST controller wiring ==\n";

$gift_controller = \GoalCart\Plugin::instance()->container()->get( \GoalCart\REST\GiftController::class );
check( 'gift controller resolves from the container', $gift_controller instanceof \GoalCart\REST\GiftController );
check( 'gift controller uses CartIntegration::live_cart (not a bare WC()->cart check)', false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/REST/GiftController.php' ), 'cart_integration->live_cart()' ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "REWARD TEST FAILED\n" : "REWARD TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
