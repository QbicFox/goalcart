<?php
/**
 * Goal Cart dynamic-messaging tests (P13-T01 / P13-T02 / P13-T03 / P13-T04).
 *
 * Boots WordPress and exercises the Phase 13 MessageEngine against
 * synthetic Goal + GoalResult pairs (the engine is UI- and
 * database-independent, like the GoalEngine before it):
 *
 *  - the service resolves from the DI container and is stateless
 *  - state detection: inactive / unavailable / progressing /
 *    nearly_complete / completed / reward_activated
 *  - variables: {current} {target} {remaining} {percentage} {quantity}
 *    {remaining_quantity} {reward} {goal_name} {campaign_name}
 *  - locale-aware formatting (currency via wc_price, plain via
 *    number_format_i18n)
 *  - template selection: per-state defaults + display_settings
 *    message/completed_message overrides, unknown placeholders untouched
 *
 * Locale-independent: the suite forces en_US and unloads the goalcart
 * domain so label/template assertions see the English source strings
 * regardless of the site locale or any shipped goalcart-<locale>.mo.
 *
 * Read-only like the other suites: no DB writes, no products, no cart.
 *
 * Run: php tests/message-test.php   (from the plugin directory)
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

use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalResult;
use GoalCart\Goals\MessageEngine;

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

function goal( array $data ) {
	return new Goal( $data );
}

// Force English assertions (the storefront strings are translated for the
// site locale, which is fa_IR on this install). Switching to en_US and
// unloading the domain keeps the checks deterministic: since WP 6.5 the
// just-in-time loader short-circuits for unloaded domains, so __() falls
// back to the source (English) strings.
switch_to_locale( 'en_US' );
unload_textdomain( 'goalcart' );

$engine  = new MessageEngine();
$version = \GoalCart\Plugin::instance()->container()->get( MessageEngine::class );

// ---------------------------------------------------------------------------
// 1. Service wiring (P13-T01)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'MessageEngine resolves from container', $version instanceof MessageEngine );

// A fresh instance renders immediately (no internal state to initialize).
$smoke_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100 ) );
$smoke      = new GoalResult( $smoke_goal, 0, 100 );
check( 'fresh instance renders a message', false !== strpos(
	$engine->message( $smoke_goal, $smoke ),
	'left to reach'
) );

// ---------------------------------------------------------------------------
// 2. State detection (P13-T03)
// ---------------------------------------------------------------------------
echo "\n== 2. States ==\n";

$g = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping' ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_GOAL_INACTIVE );
check( 'inactive goal -> inactive state', MessageEngine::STATE_INACTIVE === $engine->state( $g, $r ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_NO_MATCHING_ITEMS );
check( 'no matching items -> unavailable state', MessageEngine::STATE_UNAVAILABLE === $engine->state( $g, $r ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_OUT_OF_SCHEDULE );
check( 'out of schedule -> unavailable state', MessageEngine::STATE_UNAVAILABLE === $engine->state( $g, $r ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_INVALID_TARGET );
check( 'invalid target -> unavailable state', MessageEngine::STATE_UNAVAILABLE === $engine->state( $g, $r ) );

$r = new GoalResult( $g, 40, 100 );
check( '40% -> progressing state', MessageEngine::STATE_PROGRESSING === $engine->state( $g, $r ) );

$r = new GoalResult( $g, 80, 100 );
check( '80% -> nearly complete state', MessageEngine::STATE_NEARLY_COMPLETE === $engine->state( $g, $r ) );

$r = new GoalResult( $g, 100, 100 );
check( '100% without reward -> completed state', MessageEngine::STATE_COMPLETED === $engine->state( $g, $r ) );

$g_reward = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'reward_type' => 'free_shipping' ) );
$r        = new GoalResult( $g_reward, 100, 100 );
check( '100% with reward -> reward activated state', MessageEngine::STATE_REWARD_ACTIVATED === $engine->state( $g_reward, $r ) );

// ---------------------------------------------------------------------------
// 3. Variables (P13-T02)
// ---------------------------------------------------------------------------
echo "\n== 3. Variables ==\n";

$g = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping', 'reward_type' => 'percent_discount', 'reward_value' => 10 ) );
$r = new GoalResult( $g, 40, 100 );

$vars = $engine->variables( $g, $r, array( 'quantity' => 2 ) );

// Money expectations are computed with the same wc_price formatter the
// engine uses, so the suite passes under any store currency/locale.
$money = function ( $value ) {
	return function_exists( 'wc_price' )
		? wp_strip_all_tags( wc_price( (float) $value ) )
		: (string) number_format_i18n( (float) $value, 2 );
};

check( 'current formatted as money', $money(40) === $vars['current'] );
check( 'target formatted as money', $money(100) === $vars['target'] );
check( 'remaining formatted as money', $money(60) === $vars['remaining'] );
check( 'percentage formatted plain', '40' === $vars['percentage'] );
check( 'quantity from extra', '2' === $vars['quantity'] );
check( 'remaining_quantity renders 0 for money goals', '0' === $vars['remaining_quantity'] );
check( 'reward label value-aware', '10% discount' === $vars['reward'] );
check( 'goal_name variable', 'Free shipping' === $vars['goal_name'] );
check( 'campaign_name empty when standalone', '' === $vars['campaign_name'] );

// Quantity-mode goal: quantity/remaining_quantity fall back to current/remaining.
$gq = goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 10, 'name' => 'Ten items', 'calculation_mode' => Goal::MODE_QUANTITY ) );
$rq = new GoalResult( $gq, 4, 10 );
$vars = $engine->variables( $gq, $rq );
check( 'quantity mode: quantity falls back to current', '4' === $vars['quantity'] );
check( 'quantity mode: remaining_quantity falls back to remaining', '6' === $vars['remaining_quantity'] );
check( 'quantity mode: current is plain number', '4' === $vars['current'] );

// Quantity-TYPE goals default to the subtotal mode, so the type itself
// must also mean "not money" (the Phase 13 fix).
$gq2 = goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 10, 'name' => 'Ten items' ) );
$vars = $engine->variables( $gq2, new GoalResult( $gq2, 4, 10 ) );
check( 'quantity-type goal is not money', '4' === $vars['current'] );

// Campaign name from the goal (repository folds it in) and via extra.
$gc = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping', 'campaign_name' => 'Summer Sale' ) );
$vars = $engine->variables( $gc, new GoalResult( $gc, 10, 100 ) );
check( 'campaign_name from goal', 'Summer Sale' === $vars['campaign_name'] );

$gx = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'X' ) );
$vars = $engine->variables( $gx, new GoalResult( $gx, 10, 100 ), array( 'campaign_name' => 'Fall Drop' ) );
check( 'campaign_name from extra override', 'Fall Drop' === $vars['campaign_name'] );

// ---------------------------------------------------------------------------
// 4. Reward labels (P13-T02 — {reward})
// ---------------------------------------------------------------------------
echo "\n== 4. Reward labels ==\n";

check( 'free shipping label', 'Free shipping' === $engine->reward_label( goal( array( 'reward_type' => 'free_shipping' ) ) ) );
check( 'percent label with value', '15% discount' === $engine->reward_label( goal( array( 'reward_type' => 'percent_discount', 'reward_value' => 15 ) ) ) );
check( 'percent label without value', 'Percentage discount' === $engine->reward_label( goal( array( 'reward_type' => 'percent_discount' ) ) ) );
check( 'fixed label with value', 'Fixed ' . $money(20) . ' off' === $engine->reward_label( goal( array( 'reward_type' => 'fixed_discount', 'reward_value' => 20 ) ) ) );
check( 'free gift label', 'Free gift' === $engine->reward_label( goal( array( 'reward_type' => 'free_gift' ) ) ) );
check( 'coupon label', 'Coupon' === $engine->reward_label( goal( array( 'reward_type' => 'coupon' ) ) ) );
check( 'no reward -> empty label', '' === $engine->reward_label( goal( array() ) ) );

// ---------------------------------------------------------------------------
// 5. Templates & defaults (P13-T03 / P13-T04)
// ---------------------------------------------------------------------------
echo "\n== 5. Templates ==\n";

$g = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping' ) );

$r = new GoalResult( $g, 40, 100 );
check( 'progressing default has no unresolved placeholders', false === strpos( $engine->message( $g, $r ), '{' ) );
check( 'progressing default mentions remaining', false !== strpos( $engine->message( $g, $r ), $money(60) ) );

$r = new GoalResult( $g, 90, 100 );
check( 'nearly complete default', 'Almost there! Only ' . $money(10) . ' left' === $engine->message( $g, $r ) );

$r = new GoalResult( $g, 100, 100 );
check( 'completed default', 'You reached your goal!' === $engine->message( $g, $r ) );

$g_reward = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'reward_type' => 'free_shipping' ) );
$r = new GoalResult( $g_reward, 100, 100 );
check( 'reward activated default names the reward', 'Reward unlocked: Free shipping' === $engine->message( $g_reward, $r ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_GOAL_INACTIVE );
check( 'inactive default', 'This offer is not active right now.' === $engine->message( $g, $r ) );

$r = GoalResult::ineligible( $g, GoalResult::REASON_NO_MATCHING_ITEMS );
check( 'unavailable default', 'This offer is not available for your cart.' === $engine->message( $g, $r ) );

// Display-settings overrides (the goal builder's message fields).
$g_override = goal(
	array(
		'type'             => Goal::TYPE_AMOUNT,
		'target'           => 100,
		'reward_type'      => 'free_shipping',
		'display_settings' => array(
			'message'          => 'Add {remaining} more for {reward}',
			'completed_message' => 'You unlocked {reward} — enjoy!',
		),
	)
);
$r = new GoalResult( $g_override, 40, 100 );
check( 'custom message used for progressing', 'Add ' . $money(60) . ' more for Free shipping' === $engine->message( $g_override, $r ) );

$r = new GoalResult( $g_override, 100, 100 );
check( 'custom completed message used', 'You unlocked Free shipping — enjoy!' === $engine->message( $g_override, $r ) );

// Unknown placeholders stay untouched; the render never throws.
$r = new GoalResult( $g, 40, 100 );
check( 'unknown placeholder untouched', 'x{unknown_var}x' === $engine->render( 'x{unknown_var}x', $g, $r ) );
check( 'empty template renders empty', '' === $engine->render( '', $g, $r ) );

// ---------------------------------------------------------------------------
// 6. Formatting (P13-T02 — locale-aware)
// ---------------------------------------------------------------------------
echo "\n== 6. Formatting ==\n";

$g = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 1000, 'name' => 'Big' ) );
$r = new GoalResult( $g, 250, 1000 );
$vars = $engine->variables( $g, $r );
check( 'money uses store currency (wc_price)', $money(250) === $vars['current'] );
check( 'money target', $money(1000) === $vars['target'] );
check( 'plain percentage', '25' === $vars['percentage'] );

$gw = goal( array( 'type' => Goal::TYPE_WEIGHT, 'target' => 5, 'name' => 'Weight' ) );
$rw = new GoalResult( $gw, 3, 5 );
check( 'weight goal current is plain number', '3' === $engine->variables( $gw, $rw )['current'] );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "MESSAGE TEST FAILED\n" : "MESSAGE TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
