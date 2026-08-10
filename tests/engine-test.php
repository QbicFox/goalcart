<?php
/**
 * Goal Cart engine edge-case tests (P04-T05).
 *
 * Boots WordPress, then runs the GoalEngine against synthetic CartContext
 * snapshots — the engine is UI- and WooCommerce-independent, so every edge
 * case from the phase spec is exercised with plain data:
 *
 *   empty cart | zero target | negative/invalid target | sale prices |
 *   coupons | taxes | shipping costs | virtual products | downloadable
 *   products | variable products | variations | excluded products |
 *   decimal quantities | guest users | logged-in users
 *
 * Run: php tests/engine-test.php   (from the plugin directory)
 *
 * The script only reads state; it does not activate the plugin or write
 * to the database.
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
use GoalCart\Goals\ProgressCalculator;

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

/**
 * Build a cart context quickly.
 *
 * @param array<string, mixed> $data  Context data.
 * @param array[]              $items CartItem payloads.
 * @return CartContext
 */
function ctx( array $data, array $items = array() ) {
    $data['items'] = $items;
    return new CartContext( $data );
}

function goal( array $data ) {
    return new Goal( $data );
}

$engine = new GoalEngine();

// ---------------------------------------------------------------------------
// 1. ProgressCalculator unit math
// ---------------------------------------------------------------------------
echo "\n== 1. ProgressCalculator ==\n";

check( 'remaining clamps at zero', near( ProgressCalculator::remaining( 120, 100 ), 0 ) );
check( 'remaining normal', near( ProgressCalculator::remaining( 40, 100 ), 60 ) );
check( 'percentage normal', near( ProgressCalculator::percentage( 50, 200 ), 25 ) );
check( 'percentage caps at 100', near( ProgressCalculator::percentage( 300, 100 ), 100 ) );
check( 'percentage zero target = 100', near( ProgressCalculator::percentage( 0, 0 ), 100 ) );
check( 'completed when current >= target', ProgressCalculator::completed( 100, 100 ) );
check( 'completed zero target', ProgressCalculator::completed( 0, 0 ) );
check( 'not completed below target', ! ProgressCalculator::completed( 99.99, 100 ) );

// ---------------------------------------------------------------------------
// 2. Empty cart
// ---------------------------------------------------------------------------
echo "\n== 2. Empty cart ==\n";

$empty     = ctx( array( 'subtotal' => 0, 'total' => 0 ) );
$goal_100  = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ) );
$r         = $engine->evaluate( $goal_100, $empty );

check( 'amount goal eligible on empty cart', $r->eligible() );
check( 'empty cart current 0', near( $r->current(), 0 ) );
check( 'empty cart remaining = target', near( $r->remaining(), 100 ) );
check( 'empty cart percentage 0', near( $r->percentage(), 0 ) );
check( 'empty cart not completed', ! $r->completed() );
check( 'empty cart reward locked', GoalResult::REWARD_LOCKED === $r->reward_state() );

// ---------------------------------------------------------------------------
// 3. Amount goals: subtotal / total / discounted_subtotal, sale prices
// ---------------------------------------------------------------------------
echo "\n== 3. Amount goals ==\n";

$cart = ctx(
    array(
        'subtotal'       => 120.00,   // pre-discount line sum
        'total'          => 137.60,   // 120 - 5 discount + 10 tax + 12.6 shipping
        'discount_total' => 5.00,
        'taxes_total'    => 10.00,
        'shipping_total' => 12.60,
        'currency'       => 'USD',
    ),
    array(
        array( 'product_id' => 1, 'name' => 'A', 'quantity' => 2, 'line_subtotal' => 60, 'line_total' => 57.5, 'price' => 30, 'categories' => array( 10 ), 'virtual' => true ),
        array( 'product_id' => 2, 'name' => 'B', 'quantity' => 1, 'line_subtotal' => 60, 'line_total' => 57.5, 'price' => 60, 'categories' => array( 11 ) ),
    )
);

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ) ), $cart );
check( 'subtotal mode uses pre-discount subtotal', near( $r->current(), 120 ) );
check( 'subtotal mode completed', $r->completed() );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 150, 'calculation_mode' => Goal::MODE_SUBTOTAL ) ),
    $cart
);
check( 'subtotal mode not completed below target', ! $r->completed() );
check( 'subtotal mode percentage 80', near( $r->percentage(), 80 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'calculation_mode' => Goal::MODE_TOTAL ) ),
    $cart
);
check( 'total mode includes tax + shipping', near( $r->current(), 137.60 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 120, 'calculation_mode' => Goal::MODE_DISCOUNTED_SUBTOTAL ) ),
    $cart
);
check( 'discounted subtotal reflects coupon discount', near( $r->current(), 115 ) );
check( 'discounted subtotal excludes tax/shipping', ! near( $r->current(), 137.60 ) );

// Sale prices: line_total already reflects the sale price (a $60 item on
// sale at $40 -> line_total 40).
$sale = ctx(
    array( 'subtotal' => 40, 'total' => 40 ),
    array( array( 'product_id' => 3, 'name' => 'Sale', 'quantity' => 1, 'line_subtotal' => 40, 'line_total' => 40, 'price' => 40 ) )
);
$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50 ) ), $sale );
check( 'sale price reflected in current (40, not 60)', near( $r->current(), 40 ) );
check( 'sale price goal not completed', ! $r->completed() );

// ---------------------------------------------------------------------------
// 4. Quantity goal (decimal quantities)
// ---------------------------------------------------------------------------
echo "\n== 4. Quantity ==\n";

$qty_cart = ctx(
    array( 'subtotal' => 90, 'total' => 90 ),
    array(
        array( 'product_id' => 1, 'quantity' => 1 ),
        array( 'product_id' => 2, 'quantity' => 2 ),
        array( 'product_id' => 3, 'quantity' => 0.5 ), // sold by weight
    )
);
$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 3 ) ), $qty_cart );
check( 'decimal quantities sum to 3.5', near( $r->current(), 3.5 ) );
check( 'quantity goal completed', $r->completed() );

// ---------------------------------------------------------------------------
// 5. Distinct quantity goal
// ---------------------------------------------------------------------------
echo "\n== 5. Distinct quantity ==\n";

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_DISTINCT_QUANTITY, 'target' => 3 ) ), $qty_cart );
check( 'distinct products = 3 (0.5 qty still counts once)', near( $r->current(), 3 ) );
check( 'distinct goal completed', $r->completed() );

$dup = ctx(
    array( 'subtotal' => 40, 'total' => 40 ),
    array(
        array( 'product_id' => 5, 'variation_id' => 51, 'quantity' => 2 ),
        array( 'product_id' => 5, 'variation_id' => 51, 'quantity' => 1 ),
    )
);
$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_DISTINCT_QUANTITY, 'target' => 2 ) ), $dup );
check( 'duplicate lines of same variation count once', near( $r->current(), 1 ) );

// ---------------------------------------------------------------------------
// 6. Category goal (amount + quantity), no matching items
// ---------------------------------------------------------------------------
echo "\n== 6. Category ==\n";

$cat_cart = ctx(
    array( 'subtotal' => 90, 'total' => 90 ),
    array(
        array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 30, 'line_total' => 30, 'categories' => array( 10 ) ),
        array( 'product_id' => 2, 'quantity' => 2, 'line_subtotal' => 60, 'line_total' => 60, 'categories' => array( 11, 10 ) ),
        array( 'product_id' => 3, 'quantity' => 1, 'line_subtotal' => 10, 'line_total' => 10, 'categories' => array( 12 ) ),
    )
);

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_CATEGORY, 'target' => 50, 'categories' => array( 10 ) ) ),
    $cat_cart
);
check( 'category amount = sum of matching items (90)', near( $r->current(), 90 ) );
check( 'category amount completed', $r->completed() );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_CATEGORY, 'target' => 2, 'categories' => array( 11 ), 'calculation_mode' => Goal::MODE_QUANTITY ) ),
    $cat_cart
);
check( 'category quantity mode = 2', near( $r->current(), 2 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_CATEGORY, 'target' => 10, 'categories' => array( 99 ) ) ),
    $cat_cart
);
check( 'category with no matching items -> 0, still eligible', $r->eligible() && near( $r->current(), 0 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_CATEGORY, 'target' => 10, 'categories' => array() ) ),
    $cat_cart
);
check( 'category goal without categories -> ineligible', ! $r->eligible() && GoalResult::REASON_NO_MATCHING_ITEMS === $r->reason() );

// ---------------------------------------------------------------------------
// 7. Product goal (variations + parent)
// ---------------------------------------------------------------------------
echo "\n== 7. Product ==\n";

$prod_cart = ctx(
    array( 'subtotal' => 80, 'total' => 80 ),
    array(
        array( 'product_id' => 100, 'variation_id' => 101, 'quantity' => 1, 'line_subtotal' => 30, 'line_total' => 30 ),
        array( 'product_id' => 200, 'variation_id' => 0, 'quantity' => 2, 'line_subtotal' => 50, 'line_total' => 50 ),
    )
);

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_PRODUCT, 'target' => 1, 'products' => array( 101 ) ) ),
    $prod_cart
);
check( 'product goal matches variation id', near( $r->current(), 1 ) && $r->completed() );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_PRODUCT, 'target' => 2, 'products' => array( 100, 200 ) ) ),
    $prod_cart
);
check( 'product goal matches parent + simple product (qty 3)', near( $r->current(), 3 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_PRODUCT, 'target' => 1, 'products' => array( 999 ) ) ),
    $prod_cart
);
check( 'product goal with no matching items -> 0', $r->eligible() && near( $r->current(), 0 ) );

// ---------------------------------------------------------------------------
// 8. Weight goal
// ---------------------------------------------------------------------------
echo "\n== 8. Weight ==\n";

$weight_cart = ctx(
    array( 'subtotal' => 30, 'total' => 30 ),
    array(
        array( 'product_id' => 1, 'quantity' => 2, 'weight' => 1.5 ),
        array( 'product_id' => 2, 'quantity' => 0.5, 'weight' => 3 ),
        array( 'product_id' => 3, 'quantity' => 1, 'weight' => 0 ),
    )
);
$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_WEIGHT, 'target' => 4 ) ), $weight_cart );
check( 'weight = 2*1.5 + 0.5*3 + 0 = 4.5', near( $r->current(), 4.5 ) );
check( 'weight goal completed', $r->completed() );

// ---------------------------------------------------------------------------
// 9. Composite goals (AND / OR)
// ---------------------------------------------------------------------------
echo "\n== 9. Composite ==\n";

$comp_cart = ctx(
    array( 'subtotal' => 120, 'total' => 120 ),
    array(
        array( 'product_id' => 1, 'quantity' => 3, 'line_subtotal' => 60, 'line_total' => 60, 'categories' => array( 10 ) ),
        array( 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 60, 'line_total' => 60, 'categories' => array( 11 ) ),
    )
);

$and_goal = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_AND,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ),
            array( 'type' => Goal::TYPE_QUANTITY, 'target' => 3 ),
        ),
    )
);
$r = $engine->evaluate( $and_goal, $comp_cart );
check( 'AND completed when all children complete', $r->completed() );
check( 'AND percentage 100', near( $r->percentage(), 100 ) );
check( 'AND reward unlocked', GoalResult::REWARD_UNLOCKED === $r->reward_state() );

$and_partial = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_AND,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ),
            array( 'type' => Goal::TYPE_QUANTITY, 'target' => 5 ),
        ),
    )
);
$r = $engine->evaluate( $and_partial, $comp_cart );
check( 'AND incomplete when any child incomplete', ! $r->completed() );
check( 'AND percentage = weakest child (80)', near( $r->percentage(), 80 ) );
check( 'AND current = sum of children (124)', near( $r->current(), 124 ) );
check( 'AND target = sum of children (105)', near( $r->target(), 105 ) );
check( 'AND remaining stays target - current (0)', near( $r->remaining(), 0 ) );

$or_goal = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_OR,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 1000 ),
            array( 'type' => Goal::TYPE_QUANTITY, 'target' => 3 ),
        ),
    )
);
$r = $engine->evaluate( $or_goal, $comp_cart );
check( 'OR completed when any child completes', $r->completed() );

$or_open = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_OR,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 1000 ),
            array( 'type' => Goal::TYPE_QUANTITY, 'target' => 10 ),
        ),
    )
);
$r = $engine->evaluate( $or_open, $comp_cart );
check( 'OR incomplete when no child completes', ! $r->completed() );

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_COMPOSITE, 'children' => array() ) ), $comp_cart );
check( 'composite without children -> ineligible', ! $r->eligible() );

// AND with an ineligible child stays incomplete (per the documented semantics).
$and_ineligible = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_AND,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ),
            array( 'type' => Goal::TYPE_QUANTITY, 'target' => 3, 'status' => Goal::STATUS_INACTIVE ),
        ),
    )
);
$r = $engine->evaluate( $and_ineligible, $comp_cart );
check( 'AND with ineligible child stays incomplete', $r->eligible() && ! $r->completed() );

// Unknown-type children are ineligible, never a throw.
$unknown_child = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_AND,
        'children' => array(
            array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ),
            array( 'type' => 'mystery', 'target' => 1 ),
        ),
    )
);
$r = $engine->evaluate( $unknown_child, $comp_cart );
check( 'composite with unknown-type child does not throw and stays incomplete', $r->eligible() && ! $r->completed() );

$all_unknown = goal(
    array(
        'type'     => Goal::TYPE_COMPOSITE,
        'operator' => Goal::OP_OR,
        'children' => array(
            array( 'type' => 'mystery', 'target' => 1 ),
            array( 'type' => 'mystery2', 'target' => 1 ),
        ),
    )
);
$r = $engine->evaluate( $all_unknown, $comp_cart );
check( 'composite with only unknown-type children -> ineligible (unknown_type)', ! $r->eligible() && GoalResult::REASON_UNKNOWN_TYPE === $r->reason() );

// ---------------------------------------------------------------------------
// 10. Zero / negative / invalid targets
// ---------------------------------------------------------------------------
echo "\n== 10. Zero and invalid targets ==\n";

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 0 ) ), $empty );
check( 'zero target trivially completed', $r->completed() );
check( 'zero target percentage 100', near( $r->percentage(), 100 ) );
check( 'zero target reward unlocked', GoalResult::REWARD_UNLOCKED === $r->reward_state() );

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => -50 ) ), $empty );
check( 'negative target ineligible', ! $r->eligible() );
check( 'negative target reason', GoalResult::REASON_INVALID_TARGET === $r->reason() );
check( 'negative target reward not applicable', GoalResult::REWARD_NOT_APPLICABLE === $r->reward_state() );

// ---------------------------------------------------------------------------
// 11. Coupons / discounts
// ---------------------------------------------------------------------------
echo "\n== 11. Coupons ==\n";	$coupon = ctx(
    array(
        'subtotal'       => 200,
        'total'          => 190,
        'discount_total' => 10,
    ),
    array(
        array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 100, 'line_total' => 95 ),
        array( 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 100, 'line_total' => 95 ),
    )
);
$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 195, 'calculation_mode' => Goal::MODE_DISCOUNTED_SUBTOTAL ) ),
    $coupon
);
check( 'coupon discount lowers discounted current to 190', near( $r->current(), 190 ) );
check( 'coupon discount keeps subtotal at 200', near( $coupon->amount( Goal::MODE_SUBTOTAL ), 200 ) );

// ---------------------------------------------------------------------------
// 12. Virtual / downloadable products are counted normally
// ---------------------------------------------------------------------------
echo "\n== 12. Virtual & downloadable ==\n";

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 2 ) ), $qty_cart );
check( 'virtual/downloadable counted in quantity', $r->completed() );

// ---------------------------------------------------------------------------
// 13. Excluded products
// ---------------------------------------------------------------------------
echo "\n== 13. Excluded products ==\n";

$excl = ctx(
    array( 'subtotal' => 100, 'total' => 100 ),
    array(
        array( 'product_id' => 1, 'quantity' => 1, 'line_subtotal' => 40, 'line_total' => 40 ),
        array( 'product_id' => 2, 'quantity' => 1, 'line_subtotal' => 60, 'line_total' => 60 ),
    )
);
$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50, 'excluded_products' => array( 2 ) ) ),
    $excl
);
check( 'excluded product dropped from amount (40)', near( $r->current(), 40 ) );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 1, 'excluded_products' => array( 1, 2 ) ) ),
    $excl
);
check( 'all items excluded -> 0', near( $r->current(), 0 ) );

// ---------------------------------------------------------------------------
// 14. Guest vs logged-in users
// ---------------------------------------------------------------------------
echo "\n== 14. Guests & logged-in users ==\n";

$guest = ctx( array( 'subtotal' => 50, 'total' => 50, 'user_id' => 0, 'is_guest' => true ) );
$user  = ctx( array( 'subtotal' => 50, 'total' => 50, 'user_id' => 7, 'is_guest' => false ) );

check( 'guest context flagged', $guest->is_guest() && 0 === $guest->user_id() );
check( 'logged-in context flagged', ! $user->is_guest() && 7 === $user->user_id() );

$r_guest = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50 ) ), $guest );
$r_user  = $engine->evaluate( goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50 ) ), $user );
check( 'both guests and logged-in users get evaluated', $r_guest->completed() && $r_user->completed() );

// ---------------------------------------------------------------------------
// 15. Inactive goal
// ---------------------------------------------------------------------------
echo "\n== 15. Inactive goal ==\n";

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50, 'status' => Goal::STATUS_INACTIVE ) ),
    $cart
);
check( 'inactive goal ineligible', ! $r->eligible() );
check( 'inactive goal reason', GoalResult::REASON_GOAL_INACTIVE === $r->reason() );

// ---------------------------------------------------------------------------
// 16. Scheduling
// ---------------------------------------------------------------------------
echo "\n== 16. Scheduling ==\n";

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50, 'ends_at' => '2020-01-01 00:00:00' ) ),
    $cart,
    '2024-06-01 12:00:00'
);
check( 'expired goal ineligible', ! $r->eligible() );
check( 'expired goal reason', GoalResult::REASON_OUT_OF_SCHEDULE === $r->reason() );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50, 'starts_at' => '2030-01-01 00:00:00' ) ),
    $cart,
    '2024-06-01 12:00:00'
);
check( 'not-yet-started goal ineligible', ! $r->eligible() );

$r = $engine->evaluate(
    goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 50, 'starts_at' => '2024-01-01', 'ends_at' => '2024-12-31' ) ),
    $cart,
    '2024-06-01 12:00:00'
);
check( 'goal inside window eligible', $r->eligible() );

// ---------------------------------------------------------------------------
// 17. Unknown goal type
// ---------------------------------------------------------------------------
echo "\n== 17. Unknown type ==\n";

$r = $engine->evaluate( goal( array( 'type' => 'mystery', 'target' => 50 ) ), $cart );
check( 'unknown type ineligible', ! $r->eligible() );
check( 'unknown type reason', GoalResult::REASON_UNKNOWN_TYPE === $r->reason() );

$types = $engine->registry()->types();
sort( $types );
check(
    'registry exposes all 10 types',
    array( 'amount', 'attribute', 'brand', 'category', 'composite', 'distinct_quantity', 'product', 'quantity', 'tag', 'weight' ) === $types
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "ENGINE TEST FAILED\n" : "ENGINE TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
