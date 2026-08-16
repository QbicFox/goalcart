<?php
/**
 * FaraCart Phase 33.2 tests (Revenue Attribution).
 *
 * Boots WordPress, then exercises the Phase 33.2 revenue attribution
 * engine and reward-cost / profit estimator:
 *
 *  - service wiring: AttributionEngine + RewardCostEstimator resolve from
 *    the container and the order-association hooks are registered
 *  - reward cost: deterministic cost models for every reward type
 *    (percent / fixed / coupon / free shipping / free gift) with honest
 *    "unavailable" degradation when the store lacks the data (gift cost,
 *    shipping cost)
 *  - product margin: cost data is read from the store's product fields
 *    (or the goalcart_product_cost filter) — never invented; order margin
 *    requires every line to have cost data (graceful)
 *  - profit impact: incremental × margin − reward cost − shipping; without
 *    margin data the profit is unavailable (revenue-only analytics)
 *  - order association: a paid order is attributed to the goals that
 *    influenced its session — progressed/completed goals are DIRECT (the
 *    incremental value is split across them), exposed-only goals are
 *    ASSISTED (order total recorded, zero incremental); refunded/non
 *    revenue orders are skipped; re-processing is idempotent
 *  - metrics: funnel counts + completion/conversion rates, incremental
 *    cart value (peak − baseline per session), goal-driven (direct
 *    incremental) revenue, goal-assisted revenue (orders with only
 *    assisted rows), goal-influenced revenue (distinct orders), AOV
 *    analysis (exposed vs store-wide, labeled observed impact), shipping
 *    stats and per-goal performance metrics
 *
 * All writes happen inside a single database transaction that is rolled
 * back; the absence of residue is asserted afterwards. Store-wide AOV /
 * shipping assertions are computed against a baseline captured before the
 * fixture orders, so leftover dev-DB orders cannot skew them.
 *
 * Run: php tests/attribution-test.php   (from the plugin directory)
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

use GoalCart\Analytics\AttributionEngine;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\Session;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\Goal;
use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

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

function close( $a, $b, $eps = 0.01 ) {
	return abs( (float) $a - (float) $b ) < $eps;
}

// The Phase 33 tables are created by Installer::maybe_upgrade(), which runs
// on plugins_loaded / admin_init — neither fires in CLI after wp-load.
// Ensure the schema exists (dbDelta is idempotent) so the suite tests the
// real tables, exactly as the plugin would create them.
Installer::maybe_create_tables();

$container = \GoalCart\Plugin::instance()->container();

$engine   = $container->get( AttributionEngine::class );
$tracker  = $container->get( RevenueTracker::class );
$costs    = $container->get( RewardCostEstimator::class );
$settings = $container->get( Settings::class );
$wpdb     = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Service wiring
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'AttributionEngine resolves from the container', $engine instanceof AttributionEngine );
check( 'RewardCostEstimator resolves from the container', $costs instanceof RewardCostEstimator );
check( 'attribution models are direct/assisted', AttributionEngine::MODEL_DIRECT === 'direct' && AttributionEngine::MODEL_ASSISTED === 'assisted' );
check( 'revenue statuses exclude refunded/cancelled', ! in_array( 'refunded', AttributionEngine::REVENUE_STATUSES, true ) );

$hooks = new HookManager();
$engine->register( $hooks );
$hooks->run();
check( 'payment_complete hook registered by the engine', has_action( 'woocommerce_payment_complete' ) );
check( 'order_status_completed hook registered by the engine', has_action( 'woocommerce_order_status_completed' ) );

// ---------------------------------------------------------------------------
// 2. Reward cost estimation (pure — no database)
// ---------------------------------------------------------------------------
echo "\n== 2. Reward cost estimation ==\n";

$goal_percent      = new Goal( array( 'id' => 1, 'reward_type' => 'percent_discount', 'reward_value' => 10 ) );
$goal_percent_max  = new Goal( array( 'id' => 2, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 80 ) );
$goal_fixed        = new Goal( array( 'id' => 3, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$goal_coupon_pct   = new Goal( array( 'id' => 4, 'reward_type' => 'coupon', 'reward_value' => 10, 'reward_max_value' => 100, 'reward_meta' => array( 'coupon_discount_type' => 'percent' ) ) );
$goal_coupon_fixed = new Goal( array( 'id' => 5, 'reward_type' => 'coupon', 'reward_value' => 25, 'reward_meta' => array( 'coupon_discount_type' => 'fixed_cart' ) ) );
$goal_shipping     = new Goal( array( 'id' => 6, 'reward_type' => 'free_shipping' ) );
$goal_gift         = new Goal( array( 'id' => 7, 'reward_type' => 'free_gift', 'reward_meta' => array( 'gift_product_id' => 4242 ) ) );
$goal_none         = new Goal( array( 'id' => 8, 'reward_type' => null ) );

$pct   = $costs->estimate_reward_cost( $goal_percent, 1000 );
$pctm  = $costs->estimate_reward_cost( $goal_percent_max, 1000 );
$fixed = $costs->estimate_reward_cost( $goal_fixed, 1000 );
$cpct  = $costs->estimate_reward_cost( $goal_coupon_pct, 1000 );
$cfix  = $costs->estimate_reward_cost( $goal_coupon_fixed, 1000 );

check( 'percent discount costs value% of order total', close( 100, $pct['estimated_cost'] ) && $pct['available'] );
check( 'percent discount capped at reward max', close( 80, $pctm['estimated_cost'] ) );
check( 'fixed discount costs its amount', close( 50, $fixed['estimated_cost'] ) );
check( 'percent coupon capped at reward max', close( 100, $cpct['estimated_cost'] ) );
check( 'fixed cart coupon costs its amount', close( 25, $cfix['estimated_cost'] ) );
check( 'no-reward goal costs zero', close( 0, $costs->estimate_reward_cost( $goal_none, 1000 )['estimated_cost'] ) );

$ship_known = $costs->estimate_reward_cost( $goal_shipping, 1000, array( 'shipping_total' => 85 ) );
$ship_unknown = $costs->estimate_reward_cost( $goal_shipping, 1000 );
check( 'free shipping costs the order shipping total', close( 85, $ship_known['estimated_cost'] ) && $ship_known['available'] );
check( 'free shipping without shipping data is unavailable', ! $ship_unknown['available'] && 0.0 === $ship_unknown['estimated_cost'] );

$gift_unknown = $costs->estimate_reward_cost( $goal_gift, 1000 );
check( 'free gift without product cost is unavailable', ! $gift_unknown['available'] );

// ---------------------------------------------------------------------------
// 3. Product margin + profit impact (transaction — fixtures rolled back)
// ---------------------------------------------------------------------------
echo "\n== 3. Margin, profit impact, attribution (rolled back) ==\n";

$revenue_table = Schema::table( 'revenue_events' );
$attrib_table  = Schema::table( 'goal_attribution' );
$goals_table   = Schema::table( 'goals' );

$wpdb->query( 'START TRANSACTION' );

try {
	// --- Fixture product with cost data (store-provided margin). ---
	$gift_id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => 'P33 gift product',
		'post_status' => 'publish',
	) );

	$gift_product = wc_get_product( $gift_id );
	$gift_product->set_regular_price( '1000' );
	$gift_product->update_meta_data( '_cost', '400' );
	$gift_product->save();

	check( 'product cost read from the store field', close( 400, $costs->product_cost( $gift_id ) ) );

	$margin = $costs->product_margin( $gift_id );
	check( 'product margin computed from cost data', null !== $margin && close( 600, $margin['margin'] ) && close( 0.6, $margin['margin_pct'] ) );

	$gift_known = $costs->estimate_reward_cost(
		new Goal( array( 'id' => 9, 'reward_type' => 'free_gift', 'reward_meta' => array( 'gift_product_id' => $gift_id ) ) ),
		1000
	);
	check( 'free gift costs the gift product cost', close( 400, $gift_known['estimated_cost'] ) && $gift_known['available'] );

	// --- Profit impact: pure math, both degradation paths. ---
	$profit = $costs->profit_impact( array(
		'incremental_revenue' => 1000,
		'margin_pct'          => 0.4,
		'reward_cost'         => 50,
		'shipping_cost'       => 85,
	) );
	check( 'profit impact = incremental × margin − reward − shipping', $profit['available'] && close( 265, $profit['estimated_profit'] ) );

	$profit_none = $costs->profit_impact( array(
		'incremental_revenue' => 1000,
		'margin_pct'          => null,
		'reward_cost'         => 50,
	) );
	check( 'profit without margin data is unavailable', ! $profit_none['available'] && null === $profit_none['estimated_profit'] && '' !== (string) $profit_none['reason'] );

	// --- Fixture goals (must exist for the event FKs). ---
	foreach ( array(
		array( 'id' => 101, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 50 ),
		array( 'id' => 202 ),
		array( 'id' => 303 ),
	) as $goal_row ) {
		$wpdb->insert( $goals_table, array(
			'id'              => $goal_row['id'],
			'name'            => 'P33 attribution goal ' . $goal_row['id'],
			'status'          => 'active',
			'type'            => 'amount',
			'target'          => 1000000,
			'reward_type'     => isset( $goal_row['reward_type'] ) ? $goal_row['reward_type'] : null,
			'reward_value'    => isset( $goal_row['reward_value'] ) ? $goal_row['reward_value'] : null,
			'reward_max_value'=> isset( $goal_row['reward_max_value'] ) ? $goal_row['reward_max_value'] : null,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		) );
	}

	$session_a = str_repeat( 'ab', 16 );
	$session_b = str_repeat( 'cd', 16 );

	// --- Session funnel events (the Phase 33.1 tracker, deduped). ---
	$tracker->record( 'goal_view', array( 'goal_id' => 101, 'cart_value' => 700000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_progress', array( 'goal_id' => 101, 'cart_value' => 900000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_completed', array( 'goal_id' => 101, 'cart_value' => 1050000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_view', array( 'goal_id' => 202, 'cart_value' => 800000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_view', array( 'goal_id' => 303, 'cart_value' => 750000, 'goal_target' => 1000000, 'session_id' => $session_b ) );

	// --- Store-wide baseline BEFORE the fixture orders (leftover dev-DB
	// orders must not skew the AOV / shipping assertions). ---
	$baseline_orders = wc_get_orders( array(
		'status' => AttributionEngine::REVENUE_STATUSES,
		'limit'  => 1000,
		'return' => 'objects',
	) );

	$baseline_total     = 0.0;
	$baseline_shipping  = 0.0;

	foreach ( $baseline_orders as $baseline_order ) {
		$baseline_total    += (float) $baseline_order->get_total();
		$baseline_shipping += (float) $baseline_order->get_shipping_total();
	}

	$baseline_count = count( $baseline_orders );

	// Today-windowed baseline, matching the engine's date_created range
	// format (exercises the from...to path of the store-wide scan).
	$today_baseline_orders = wc_get_orders( array(
		'status'       => AttributionEngine::REVENUE_STATUSES,
		'limit'        => 1000,
		'return'       => 'objects',
		'date_created' => date( 'Y-m-d 00:00:00' ) . '...' . date( 'Y-m-d 23:59:59' ),
	) );

	$today_baseline_total = 0.0;

	foreach ( $today_baseline_orders as $today_order ) {
		$today_baseline_total += (float) $today_order->get_total();
	}

	$today_baseline_count = count( $today_baseline_orders );

	// --- Fixture orders (tracking off so creation never self-attributes). ---
	$settings->set( 'analytics_enabled', false );

	$make_order = function ( $total, $shipping ) {
		$order = wc_create_order();
		$order->set_total( $total );
		$order->set_shipping_total( $shipping );
		$order->set_status( 'completed' );
		$order->save();

		return (int) $order->get_id();
	};

	$order_direct   = $make_order( 1050000, 85 );   // attributed direct (101) + assisted (202).
	$order_assisted = $make_order( 800000, 50 );    // attributed assisted (303).
	$order_plain    = $make_order( 600000, 30 );    // NOT attributed (non-exposed comparison).

	$settings->set( 'analytics_enabled', true );

	// Read the fixture orders' persisted totals back (the dev environment
	// may tweak them) so the store-wide expectations stay exact.
	$fixture_total    = 0.0;
	$fixture_shipping = 0.0;

	foreach ( array( $order_direct, $order_assisted, $order_plain ) as $fixture_id ) {
		$fixture_order = wc_get_order( $fixture_id );
		$fixture_total    += (float) $fixture_order->get_total();
		$fixture_shipping += (float) $fixture_order->get_shipping_total();
	}

	// --- Order association. ---
	$written_1 = $engine->attribute_order( $order_direct, array(
		'total'      => 1050000,
		'status'     => 'completed',
		'shipping_total' => 85,
		'session_id' => $session_a,
	) );

	check( 'direct order writes attribution rows', 2 === $written_1 );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT model, order_total, incremental_value, goal_completed, session_id FROM {$attrib_table} WHERE order_id = %d AND goal_id = %d",
			$order_direct,
			101
		),
		ARRAY_A
	);
	check( 'progressed/completed goal attributed as direct', null !== $row && AttributionEngine::MODEL_DIRECT === $row['model'] );
	check( 'direct row carries the full order total', null !== $row && close( 1050000, $row['order_total'] ) );
	check( 'direct row carries the incremental value', null !== $row && close( 350000, $row['incremental_value'] ) );
	check( 'direct row marks the goal completed', null !== $row && 1 === (int) $row['goal_completed'] );
	check( 'direct row carries the session', null !== $row && $session_a === $row['session_id'] );

	$assisted_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT model, incremental_value, goal_completed FROM {$attrib_table} WHERE order_id = %d AND goal_id = %d",
			$order_direct,
			202
		),
		ARRAY_A
	);
	check( 'viewed-only goal attributed as assisted', null !== $assisted_row && AttributionEngine::MODEL_ASSISTED === $assisted_row['model'] );
	check( 'assisted row carries zero incremental value', null !== $assisted_row && close( 0, $assisted_row['incremental_value'] ) );
	check( 'assisted row not marked completed', null !== $assisted_row && 0 === (int) $assisted_row['goal_completed'] );

	$event = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT session_id, cart_value FROM {$revenue_table} WHERE event_type = %s AND order_id = %d",
			RevenueTracker::EVENT_ORDER_PAID,
			$order_direct
		),
		ARRAY_A
	);
	check( 'order_paid event recorded for the order', null !== $event && $session_a === $event['session_id'] && close( 1050000, $event['cart_value'] ) );

	// Assisted-only order.
	$written_2 = $engine->attribute_order( $order_assisted, array(
		'total'      => 800000,
		'status'     => 'completed',
		'session_id' => $session_b,
	) );
	check( 'assisted-only order writes one row', 1 === $written_2 );

	// Non-revenue order statuses are never attributed (array path — no WC
	// order fixture needed, so the dev environment's auto-refund side
	// effects cannot pollute the store-wide scans).
	$written_refunded = $engine->attribute_order( 999900, array(
		'total'      => 500000,
		'status'     => 'refunded',
		'session_id' => $session_b,
	) );
	$refunded_rows = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE order_id = %d", 999900 )
	);
	check( 'refunded order skipped', 0 === $written_refunded && 0 === $refunded_rows );

	// Failed/cancelled statuses are skipped the same way.
	$failed_written = $engine->attribute_order( 999901, array(
		'total'      => 100000,
		'status'     => 'failed',
		'session_id' => $session_b,
	) );
	check( 'failed order skipped', 0 === $failed_written );

	// Idempotency: re-attribution writes nothing new.
	$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );
	$again = $engine->attribute_order( $order_direct, array(
		'total'      => 1050000,
		'status'     => 'completed',
		'session_id' => $session_a,
	) );
	$rows_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );
	check( 're-attribution is idempotent (0 written)', 0 === $again );
	check( 're-attribution adds no rows', $rows_before === $rows_after );

	// --- Funnel metrics. ---
	$funnel = $engine->funnel();
	check( 'funnel counts three views', 3 === $funnel['views'] );
	check( 'funnel counts one progression', 1 === $funnel['progressed'] );
	check( 'funnel counts one completion', 1 === $funnel['completed'] );
	check( 'funnel counts two converted orders', 2 === $funnel['converted'] );
	check( 'completion rate is completed/views', close( 0.3333, $funnel['completion_rate'] ) );
	check( 'conversion rate is orders/completions', close( 2.0, $funnel['conversion_rate'] ) );

	$funnel_101 = $engine->funnel( array( 'goal_id' => 101 ) );
	check( 'per-goal funnel views', 1 === $funnel_101['views'] );
	check( 'per-goal funnel completion rate', close( 1.0, $funnel_101['completion_rate'] ) );
	check( 'per-goal funnel conversion rate', close( 1.0, $funnel_101['conversion_rate'] ) );

	// --- Incremental cart value. ---
	$incremental = $engine->incremental_cart_value( array( 'goal_id' => 101 ) );
	check( 'incremental cart value = peak − baseline', close( 350000, $incremental['average'] ) );
	check( 'incremental cart value baseline captured', close( 700000, $incremental['average_baseline'] ) );
	check( 'single-session sample flagged low sufficiency', 'low' === $incremental['data_sufficiency'] );

	// --- Attribution summary. ---
	$summary = $engine->attribution_summary();
	check( 'goal-driven revenue = direct incremental', close( 350000, $summary['goal_driven_revenue'] ) );
	check( 'goal-assisted revenue = pure-assisted orders', close( 800000, $summary['goal_assisted_revenue'] ) );
	check( 'goal-influenced revenue = distinct order totals', close( 1850000, $summary['goal_influenced_revenue'] ) );
	check( 'summary counts two attributed orders', 2 === $summary['orders'] );
	check( 'reward cost estimated from completed goals', close( 50, $summary['reward_cost'] ) && $summary['reward_cost_available'] );
	check( 'profit unavailable without margin data', ! $summary['profit_available'] && null === $summary['profit_impact'] && '' !== (string) $summary['profit_reason'] );

	// --- Per-goal performance metrics. ---
	$metrics = $engine->goal_metrics( 101 );
	check( 'goal metrics expose the funnel', null !== $metrics && 1 === $metrics['views'] && 1 === $metrics['completed'] && 1 === $metrics['converted'] );
	check( 'goal metrics expose attributed revenue', close( 350000, $metrics['attributed_revenue'] ) );
	check( 'goal metrics expose average cart value', close( 700000, $metrics['average_cart_value'] ) );
	check( 'goal metrics expose incremental cart value', close( 350000, $metrics['incremental_cart_value'] ) );
	check( 'goal metrics expose reward cost', close( 50, $metrics['reward_cost'] ) );
	check( 'goal metrics unknown goal is null', null === $engine->goal_metrics( 999999 ) );

	// --- AOV analysis (baseline-aware). ---
	$aov = $engine->aov_analysis();

	$expected_total     = $baseline_total + $fixture_total;
	$expected_count     = $baseline_count + 3;
	$expected_overall   = $expected_count > 0 ? $expected_total / $expected_count : 0.0;
	$expected_nonexposed = ( $baseline_total + ( $fixture_total - 1850000 ) ) / ( $baseline_count + 1 );

	check( 'aov exposed orders = attributed orders', 2 === $aov['exposed_orders'] );
	check( 'aov exposed average is the attributed average', close( 925000, $aov['exposed_aov'] ) );
	check( 'aov overall includes the store baseline', close( $expected_overall, $aov['overall_aov'] ) );
	check( 'aov non-exposed excludes attributed orders', close( $expected_nonexposed, $aov['non_exposed_aov'] ) );
	check( 'aov absolute change is exposed − overall', close( $aov['exposed_aov'] - $aov['overall_aov'], $aov['absolute_change'] ) );
	check( 'aov labeled as observed impact', 'observed_impact' === $aov['label'] );

	// Windowed store scan (date_created from...to range path).
	$today = date( 'Y-m-d' );
	$aov_today = $engine->aov_analysis( array( 'from' => $today, 'to' => $today ) );
	$today_expected_total = $today_baseline_total + $fixture_total;
	$today_expected_count = $today_baseline_count + 3;
	check(
		'aov windowed scan includes only in-window orders',
		$aov_today['total_orders'] === $today_expected_count
		&& close( $today_expected_total / $today_expected_count, $aov_today['overall_aov'] )
	);

	// --- Shipping stats (baseline-aware). ---
	$shipping = $engine->shipping_stats();
	$expected_shipping = ( $baseline_shipping + $fixture_shipping ) / $expected_count;

	check( 'shipping stats available', $shipping['available'] );
	check( 'shipping stats orders include fixtures', $shipping['orders'] === $expected_count );
	check( 'shipping average includes the store baseline', close( $expected_shipping, $shipping['average_shipping'] ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 4. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

$revenue_after = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN (%s, %s)",
		str_repeat( 'ab', 16 ),
		str_repeat( 'cd', 16 )
	)
);
$attrib_after = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE order_id IN (%d, %d, %d)", $order_direct, $order_assisted, $order_plain )
);

check( 'no test events remain by session', 0 === $revenue_after );
check( 'no test attribution rows remain by order', 0 === $attrib_after );

// The tables may legitimately hold rows from live store traffic (a real
// order_paid event, a real attribution row) — the suite asserts only that
// ITS OWN fixtures are gone, never that the tables are globally empty.
$live_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$live_attrib = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );
check( 'live store traffic is untouched', $live_events >= $revenue_after && $live_attrib >= $attrib_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "ATTRIBUTION TEST FAILED\n" : "ATTRIBUTION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
