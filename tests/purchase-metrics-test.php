<?php
/**
 * FaraCart Phase 2 tests (Backend/Data Layer — purchase & profit metrics).
 *
 * Exercises the purchase-analysis data layer added for the Revenue &
 * Analytics UX simplification (Improvement.md Phase 2):
 *
 *  - funnel purchase metrics: views → progressed → completed → purchased,
 *    completion rate (completed/views) and purchase rate
 *    (purchased/completed — null when there is no completion denominator)
 *  - purchase states: no purchases / one / multiple purchases; multiple
 *    goals; direct; assisted; mixed direct + assisted (distinct order
 *    counting — never double counted); direct+direct incremental split;
 *    duplicate order events (idempotent re-attribution)
 *  - profit states: complete cost data (available), no cost data
 *    (unavailable + missing_product_cost), partial cost data
 *    (incomplete_product_cost — profit still computed over the orders
 *    that have cost data), zero profit (0 is not "unavailable"), negative
 *    profit (never hidden), reward cost included in the profit model
 *    (regression), margin + shipping math
 *  - cost coverage: attributed orders vs orders with cost data + coverage
 *    percent (§11)
 *  - profit metadata: stable reason codes (§39), profit_details building
 *    blocks (§12), human reason strings
 *  - date filtering: every metric respects the same from/to window
 *  - goal filtering: goal_id, goal_ids, campaign_id and reward_type
 *    resolution on the /analytics purchase summary; product_id is
 *    unsupported in attribution → null (never a fabricated number)
 *  - API: the legacy /analytics summary is extended with the purchase /
 *    profit fields while every pre-existing field stays intact (§37)
 *
 * All writes happen inside a single database transaction that is rolled
 * back; absence of residue is asserted afterwards. Fixtures use goal ids
 * 501–512, sessions s01–s20 and products with the P2. prefix, so they
 * never collide with live store traffic or other suites' residue.
 *
 * Run: php tests/purchase-metrics-test.php   (from the plugin directory)
 */

// Locate wp-load.php by walking up from this file.
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

use FaraCart\Analytics\AttributionEngine;
use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\RewardCostEstimator;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Goals\GoalRepository;
use FaraCart\REST\AnalyticsController;
use FaraCart\Settings\Settings;

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
Installer::maybe_create_tables();

$container = \FaraCart\Plugin::instance()->container();

$engine    = $container->get( AttributionEngine::class );
$tracker   = $container->get( RevenueTracker::class );
$costs     = $container->get( RewardCostEstimator::class );
$settings  = $container->get( Settings::class );
$goals_repo = $container->get( GoalRepository::class );
$repo      = $container->get( RevenueRepository::class );
$analytics_ctrl = $container->get( AnalyticsController::class );
$wpdb      = $GLOBALS['wpdb'];

$revenue_table = Schema::table( 'revenue_events' );
$attrib_table  = Schema::table( 'goal_attribution' );
$goals_table   = Schema::table( 'goals' );
$campaigns_table = Schema::table( 'campaigns' );

// ---------------------------------------------------------------------------
// 1. Service wiring + reason-code unit checks
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring + profit reason codes ==\n";

check( 'AttributionEngine resolves', $engine instanceof AttributionEngine );
check( 'RevenueRepository resolves', $repo instanceof RevenueRepository );
check( 'AnalyticsController resolves', $analytics_ctrl instanceof AnalyticsController );

// Stable machine-readable reason codes (§39) on the pure profit model.
$profit_ok = $costs->profit_impact( array(
	'incremental_revenue' => 1000,
	'margin_pct'          => 0.4,
	'reward_cost'         => 50,
	'shipping_cost'       => 85,
) );
check( 'profit with margin carries reason_code available', 'available' === $profit_ok['reason_code'] );

$profit_none = $costs->profit_impact( array(
	'incremental_revenue' => 1000,
	'margin_pct'          => null,
	'reward_cost'         => 50,
) );
check( 'profit without margin carries reason_code missing_product_cost', 'missing_product_cost' === $profit_none['reason_code'] );

// ---------------------------------------------------------------------------
// 2. Fixtures + purchase/profit scenarios (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 2. Purchase & profit metrics (rolled back) ==\n";

$wpdb->query( 'START TRANSACTION' );

try {
	// --- Fixture goals (must exist for the event/attribution FKs). ---
	foreach ( array(
		501 => array( 'percent_discount', 10, 50 ),  // full-cost profit scenario
		502 => array( 'free_shipping', null, null ), // no-cost scenario
		503 => array( 'percent_discount', 10, 50 ),  // partial-cost scenario
		504 => array( 'percent_discount', 10, 50 ),  // zero-profit scenario
		505 => array( 'percent_discount', 10, 50 ),  // negative-profit scenario
		506 => array( 'percent_discount', 10, 50 ),  // completed but never purchased
		507 => array( 'percent_discount', 10, 50 ),  // direct goal of a mixed order
		508 => array( 'free_shipping', null, null ), // assisted goal of the same order
		509 => array( 'percent_discount', 10, 50 ),  // direct+direct split (campaign)
		510 => array( 'percent_discount', 10, 50 ),  // direct+direct split (campaign)
		511 => array( 'percent_discount', 10, 50 ),  // date-filtering scenario
		512 => array( 'percent_discount', 10, 50 ),  // no completions → null purchase rate
	) as $goal_id => $reward ) {
		$wpdb->insert( $goals_table, array(
			'id'               => $goal_id,
			'name'             => 'P2 purchase goal ' . $goal_id,
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 1000000,
			'reward_type'      => $reward[0],
			'reward_value'     => $reward[1],
			'reward_max_value' => $reward[2],
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		) );
	}

	// A campaign that owns goals 509 + 510 (for campaign-filter resolution).
	$wpdb->insert( $campaigns_table, array(
		'name'       => 'P2 campaign',
		'status'     => 'active',
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	) );
	$campaign_id = (int) $wpdb->insert_id;
	$wpdb->update( $goals_table, array( 'campaign_id' => $campaign_id ), array( 'id' => 509 ) );
	$wpdb->update( $goals_table, array( 'campaign_id' => $campaign_id ), array( 'id' => 510 ) );

	// --- Fixture products: costed + plain. ---
	$make_product = function ( $title, $price, $cost = null ) {
		$post_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_title'  => $title,
			'post_status' => 'publish',
		) );
		$product = wc_get_product( $post_id );
		$product->set_regular_price( (string) $price );
		if ( null !== $cost ) {
			$product->update_meta_data( '_cost', (string) $cost );
		}
		$product->save();

		return (int) $post_id;
	};

	$p1 = $make_product( 'P2 costed A', 1000, 400 );  // margin 0.6
	$p2 = $make_product( 'P2 costed B', 2000, 500 );  // margin 0.75
	$p3 = $make_product( 'P2 costed C', 1000, 900 );  // margin 0.1
	$p0 = $make_product( 'P2 plain', 1000 );          // no cost data

	check( 'costed product margin readable', null !== $costs->product_margin( $p1 ) && close( 0.6, $costs->product_margin( $p1 )['margin_pct'] ) );
	check( 'plain product has no margin data', null === $costs->product_margin( $p0 ) );

	// --- Helpers. ---
	$session = function ( $i ) {
		return sprintf( '%02d', $i ) . str_repeat( 'ab', 15 );
	};

	$record = function ( $type, $goal_id, $sess, $cart ) use ( $tracker ) {
		$tracker->record( $type, array(
			'goal_id'     => $goal_id,
			'cart_value'  => $cart,
			'goal_target' => 1000000,
			'session_id'  => $sess,
		) );
	};

	$make_order = function ( $product_id, $total, $shipping ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_total( $total );
		$order->set_shipping_total( $shipping );
		$order->set_status( 'completed' );
		$order->save();

		return (int) $order->get_id();
	};

	$attribute = function ( $order_id, $total, $shipping, $sess, $date = null ) use ( $engine ) {
		return $engine->attribute_order( $order_id, array(
			'total'          => $total,
			'status'         => 'completed',
			'shipping_total' => $shipping,
			'session_id'     => $sess,
			'date'           => null !== $date ? $date : current_time( 'mysql' ),
		) );
	};

	// Record the whole funnel FIRST, while tracking is enabled — the tracker
	// drops events while analytics_enabled is off. Order creation runs with
	// tracking off (so creating an order never self-attributes through the
	// payment/status hooks), exactly like the attribution-test fixtures.

	// --- Scenario A: full cost data (goal 501). ---
	$s1 = $session( 1 );
	$record( 'goal_view', 501, $s1, 600 );
	$record( 'goal_progress', 501, $s1, 800 );
	$record( 'goal_completed', 501, $s1, 1000 );
	$s2 = $session( 2 );
	$record( 'goal_view', 501, $s2, 1500 );
	$record( 'goal_completed', 501, $s2, 2000 );

	// --- Scenario B: no cost data (goal 502). ---
	$s3 = $session( 3 );
	$record( 'goal_view', 502, $s3, 500 );
	$record( 'goal_completed', 502, $s3, 1000 );

	// --- Scenario C: partial cost data (goal 503). ---
	$s4 = $session( 4 );
	$record( 'goal_view', 503, $s4, 600 );
	$record( 'goal_completed', 503, $s4, 1000 );
	$s5 = $session( 5 );
	$record( 'goal_view', 503, $s5, 600 );
	$record( 'goal_completed', 503, $s5, 1000 );

	// --- Scenario D: zero profit (goal 504). ---
	$s6 = $session( 6 );
	$record( 'goal_view', 504, $s6, 500 );
	$record( 'goal_completed', 504, $s6, 1000 );

	// --- Scenario E: negative profit (goal 505). ---
	$s7 = $session( 7 );
	$record( 'goal_view', 505, $s7, 500 );
	$record( 'goal_completed', 505, $s7, 1000 );

	// --- Scenario F: completed but never purchased (goal 506). ---
	$s8 = $session( 8 );
	$record( 'goal_view', 506, $s8, 400 );
	$s9 = $session( 9 );
	$record( 'goal_view', 506, $s9, 500 );
	$record( 'goal_completed', 506, $s9, 900 );

	// --- Scenario G: mixed direct + assisted (goals 507/508). ---
	$s11 = $session( 11 );
	$record( 'goal_view', 507, $s11, 700 );
	$record( 'goal_progress', 507, $s11, 900 );
	$record( 'goal_view', 508, $s11, 700 );

	// --- Scenario H: direct+direct incremental split (goals 509/510). ---
	$s12 = $session( 12 );
	$record( 'goal_view', 509, $s12, 600 );
	$record( 'goal_progress', 509, $s12, 800 );
	$record( 'goal_view', 510, $s12, 600 );
	$record( 'goal_progress', 510, $s12, 800 );

	// --- Scenario I: date filtering (goal 511, backdated). ---
	$s13 = $session( 13 );
	$record( 'goal_view', 511, $s13, 500 );
	$record( 'goal_completed', 511, $s13, 1000 );

	// --- Scenario J: no completions → null purchase rate (goal 512). ---
	$s10 = $session( 10 );
	$record( 'goal_view', 512, $s10, 300 );

	// Backdate the goal-511 events so the funnel respects the January window
	// (the tracker stamps current_time; the engine reads stored dates).
	$wpdb->update(
		$revenue_table,
		array( 'created_at' => '2026-01-14 10:00:00' ),
		array( 'session_id' => $s13 )
	);

	// Create every order with tracking off so creation never self-attributes.
	$settings->set( 'analytics_enabled', false );

	$order_a = $make_order( $p1, 1000, 100 );
	$order_b = $make_order( $p2, 2000, 200 );
	$order_c = $make_order( $p0, 1000, 50 );
	$order_d = $make_order( $p1, 1000, 100 ); // costed
	$order_e = $make_order( $p0, 1000, 100 ); // plain
	$order_f = $make_order( $p1, 1000, 250 );
	$order_g = $make_order( $p3, 1000, 200 );
	$order_h = $make_order( $p1, 1050, 0 );
	$order_i = $make_order( $p1, 1000, 0 );
	$order_j = $make_order( $p1, 1000, 0 );

	$settings->set( 'analytics_enabled', true );

	check( 'order A attributed direct', 1 === $attribute( $order_a, 1000, 100, $s1 ) );
	check( 'order B attributed direct', 1 === $attribute( $order_b, 2000, 200, $s2 ) );
	check( 'order C attributed direct', 1 === $attribute( $order_c, 1000, 50, $s3 ) );
	check( 'order D attributed', 1 === $attribute( $order_d, 1000, 100, $s4 ) );
	check( 'order E attributed', 1 === $attribute( $order_e, 1000, 100, $s5 ) );
	check( 'order F attributed', 1 === $attribute( $order_f, 1000, 250, $s6 ) );
	check( 'order G attributed', 1 === $attribute( $order_g, 1000, 200, $s7 ) );
	check( 'mixed order writes direct + assisted', 2 === $attribute( $order_h, 1050, 0, $s11 ) );
	check( 'split order writes two direct rows', 2 === $attribute( $order_i, 1000, 0, $s12 ) );
	check( 'backdated order attributed', 1 === $attribute( $order_j, 1000, 0, $s13, '2026-01-15 12:00:00' ) );

	// Fresh reads: bump the revenue cache generation so the fixtures are
	// visible to the cached purchase_summary / /analytics reads.
	$repo->invalidate();

	// -----------------------------------------------------------------------
	// 3. Funnel + purchase metrics
	// -----------------------------------------------------------------------
	echo "\n== 3. Funnel & purchase metrics ==\n";

	$funnel = $engine->funnel( array( 'goal_id' => 501 ) );
	check( 'funnel views = 2', 2 === $funnel['views'] );
	check( 'funnel progressed = 1', 1 === $funnel['progressed'] );
	check( 'funnel completed = 2', 2 === $funnel['completed'] );
	check( 'funnel purchased = 2', 2 === $funnel['converted'] );
	check( 'completion rate = completed/views', close( 1.0, $funnel['completion_rate'] ) );
	check( 'purchase rate = purchased/completed', close( 1.0, $funnel['conversion_rate'] ) );

	// Completed but never purchased → purchase rate 0% (a real denominator).
	$funnel_506 = $engine->funnel( array( 'goal_id' => 506 ) );
	check( '506 views = 2', 2 === $funnel_506['views'] );
	check( '506 completed = 1', 1 === $funnel_506['completed'] );
	check( '506 purchased = 0', 0 === $funnel_506['converted'] );
	check( '506 purchase rate = 0 (not null)', 0.0 === $funnel_506['conversion_rate'] );

	// No completions at all → purchase rate is null (no denominator, "—").
	$funnel_512 = $engine->funnel( array( 'goal_id' => 512 ) );
	check( '512 has no completions', 0 === $funnel_512['completed'] );
	check( '512 purchase rate is null (no denominator)', null === $funnel_512['conversion_rate'] );

	// Distinct order counting: one order with direct + assisted rows counts
	// once across both goals.
	$mixed = $engine->funnel( array( 'goal_ids' => array( 507, 508 ) ) );
	check( 'mixed funnel views = 2', 2 === $mixed['views'] );
	check( 'mixed funnel purchased = 1 distinct order', 1 === $mixed['converted'] );

	// Idempotency: re-attributing an order writes nothing and changes nothing.
	$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );
	$again = $attribute( $order_a, 1000, 100, $s1 );
	$rows_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );
	check( 're-attribution writes 0 rows', 0 === $again );
	check( 're-attribution adds no rows', $rows_before === $rows_after );

	// -----------------------------------------------------------------------
	// 4. Profit states
	// -----------------------------------------------------------------------
	echo "\n== 4. Profit states ==\n";

	// Full cost data → available, reward cost included (regression: the
	// pre-computed reward cost used to be zeroed inside the profit model).
	$summary_a = $engine->attribution_summary( array( 'goal_id' => 501 ) );
	check( 'A purchased orders = 2', 2 === $summary_a['orders'] );
	check( 'A attributed sales = direct incremental', close( 900, $summary_a['goal_driven_revenue'] ) );
	check( 'A reward cost from completed goals', close( 100, $summary_a['reward_cost'] ) );
	check( 'A profit available', true === $summary_a['profit_available'] );
	check( 'A profit = incremental×margin − reward − shipping', close( 207.5, $summary_a['profit_impact'] ) );
	check( 'A reason code = available', 'available' === $summary_a['profit_reason_code'] );
	check( 'A details margin = 0.675', null !== $summary_a['profit_details']['margin_pct'] && close( 0.675, $summary_a['profit_details']['margin_pct'] ) );
	check( 'A details reward cost = 100', close( 100, $summary_a['profit_details']['reward_cost'] ) );
	check( 'A details shipping = 300', close( 300, $summary_a['profit_details']['shipping_cost'] ) );
	check( 'A details incremental = 900', close( 900, $summary_a['profit_details']['incremental_revenue'] ) );
	check( 'A cost coverage 2/2 = 100%', 2 === $summary_a['cost_coverage']['attributed_orders'] && 2 === $summary_a['cost_coverage']['orders_with_cost_data'] && close( 100, $summary_a['cost_coverage']['coverage_pct'] ) );

	// No cost data → unavailable with the stable code + human reason.
	$summary_b = $engine->attribution_summary( array( 'goal_id' => 502 ) );
	check( 'B purchased orders = 1', 1 === $summary_b['orders'] );
	check( 'B profit unavailable', false === $summary_b['profit_available'] && null === $summary_b['profit_impact'] );
	check( 'B reason code = missing_product_cost', 'missing_product_cost' === $summary_b['profit_reason_code'] );
	check( 'B has a human reason', '' !== (string) $summary_b['profit_reason'] );
	check( 'B coverage 0/1 = 0%', 1 === $summary_b['cost_coverage']['attributed_orders'] && 0 === $summary_b['cost_coverage']['orders_with_cost_data'] && close( 0, $summary_b['cost_coverage']['coverage_pct'] ) );
	check( 'B details margin null', null === $summary_b['profit_details']['margin_pct'] );

	// Partial cost data → profit still computed, flagged incomplete.
	$summary_c = $engine->attribution_summary( array( 'goal_id' => 503 ) );
	check( 'C purchased orders = 2', 2 === $summary_c['orders'] );
	check( 'C reason code = incomplete_product_cost', 'incomplete_product_cost' === $summary_c['profit_reason_code'] );
	check( 'C profit still computed (1 of 2 orders costed)', true === $summary_c['profit_available'] && close( 180, $summary_c['profit_impact'] ) );
	check( 'C coverage 1/2 = 50%', 1 === $summary_c['cost_coverage']['orders_with_cost_data'] && close( 50, $summary_c['cost_coverage']['coverage_pct'] ) );

	// Zero profit is 0 — never treated as unavailable.
	$summary_d = $engine->attribution_summary( array( 'goal_id' => 504 ) );
	check( 'D profit = 0 (zero, not unavailable)', true === $summary_d['profit_available'] && null !== $summary_d['profit_impact'] && close( 0, $summary_d['profit_impact'] ) );
	check( 'D reason code = available', 'available' === $summary_d['profit_reason_code'] );

	// Negative profit is supported and never hidden.
	$summary_e = $engine->attribution_summary( array( 'goal_id' => 505 ) );
	check( 'E profit negative', true === $summary_e['profit_available'] && close( -200, $summary_e['profit_impact'] ) );

	// -----------------------------------------------------------------------
	// 5. Goal-level purchase metrics + mixed/split attribution
	// -----------------------------------------------------------------------
	echo "\n== 5. Goal-level metrics ==\n";

	$g507 = $engine->goal_metrics( 507 );
	check( 'goal 507 purchased = 1', 1 === $g507['converted'] );
	check( 'goal 507 attributed sales = 350', close( 350, $g507['attributed_revenue'] ) );
	// 507 was progressed but never completed → purchase rate has no
	// denominator and reads "—" (Improvement.md §18), never 0% or 100%.
	check( 'goal 507 purchase rate null (no completions)', null === $g507['conversion_rate'] );

	$g508 = $engine->goal_metrics( 508 );
	check( 'goal 508 purchased = 1 (order associated)', 1 === $g508['converted'] );
	check( 'goal 508 assisted revenue = 0 (direct precedence)', close( 0, $g508['assisted_revenue'] ) );
	check( 'goal 508 goal metrics carry reason code', isset( $g508['profit_reason_code'] ) && '' !== $g508['profit_reason_code'] );

	// Direct+direct incremental split across goals 509/510.
	$split = $engine->attribution_summary( array( 'goal_ids' => array( 509, 510 ) ) );
	check( 'split attributed sales = full increment', close( 400, $split['goal_driven_revenue'] ) );
	check( 'split purchased orders = 1', 1 === $split['orders'] );
	check( 'goal 509 share = 200', close( 200, $engine->attribution_summary( array( 'goal_id' => 509 ) )['goal_driven_revenue'] ) );
	check( 'goal 510 share = 200', close( 200, $engine->attribution_summary( array( 'goal_id' => 510 ) )['goal_driven_revenue'] ) );

	// -----------------------------------------------------------------------
	// 6. Date filtering
	// -----------------------------------------------------------------------
	echo "\n== 6. Date filtering ==\n";

	$jan = $engine->funnel( array( 'goal_id' => 511, 'from' => '2026-01-01', 'to' => '2026-01-31' ) );
	check( 'January window sees the backdated funnel', 1 === $jan['views'] && 1 === $jan['completed'] && 1 === $jan['converted'] );

	$today = date( 'Y-m-d' );
	$now_window = $engine->funnel( array( 'goal_id' => 511, 'from' => $today, 'to' => $today ) );
	check( 'today window excludes the January order', 0 === $now_window['views'] && 0 === $now_window['converted'] );

	$summary_a_window = $engine->attribution_summary( array( 'goal_id' => 501, 'from' => $today, 'to' => $today ) );
	check( 'summary respects the same date range', 2 === $summary_a_window['orders'] && close( 207.5, $summary_a_window['profit_impact'] ) );

	// -----------------------------------------------------------------------
	// 7. /analytics extension + purchase_summary filter mapping
	// -----------------------------------------------------------------------
	echo "\n== 7. /analytics extension ==\n";

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'goal_id', 501 );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();

	check( 'legacy impressions field intact', array_key_exists( 'impressions', $data['data']['summary'] ) );
	check( 'legacy completions field intact', array_key_exists( 'completions', $data['data']['summary'] ) );
	check( 'summary has progressed', array_key_exists( 'progressed', $data['data']['summary'] ) );
	check( 'summary has purchased_orders', array_key_exists( 'purchased_orders', $data['data']['summary'] ) );
	check( 'summary has purchase_rate', array_key_exists( 'purchase_rate', $data['data']['summary'] ) );
	check( 'summary has attributed_sales', array_key_exists( 'attributed_sales', $data['data']['summary'] ) );
	check( 'summary has estimated_profit', array_key_exists( 'estimated_profit', $data['data']['summary'] ) );
	check( 'summary has profit_available', array_key_exists( 'profit_available', $data['data']['summary'] ) );
	check( 'summary has profit_reason', array_key_exists( 'profit_reason', $data['data']['summary'] ) );
	check( 'summary has profit_reason_code', array_key_exists( 'profit_reason_code', $data['data']['summary'] ) );
	check( 'summary has cost_coverage', isset( $data['data']['summary']['cost_coverage'] ) && is_array( $data['data']['summary']['cost_coverage'] ) );
	check( 'summary has profit_details', isset( $data['data']['summary']['profit_details'] ) && is_array( $data['data']['summary']['profit_details'] ) );

	$sum = $data['data']['summary'];
	check( 'analytics purchased_orders matches the engine', 2 === (int) $sum['purchased_orders'] );
	check( 'analytics purchase_rate matches', close( 1.0, $sum['purchase_rate'] ) );
	check( 'analytics attributed_sales matches', close( 900, $sum['attributed_sales'] ) );
	check( 'analytics estimated_profit matches', close( 207.5, $sum['estimated_profit'] ) );
	check( 'analytics profit_reason_code = available', 'available' === $sum['profit_reason_code'] );
	check( 'analytics progressed = 1', 1 === (int) $sum['progressed'] );

	// product_id cannot be expressed in attribution → the purchase fields
	// are null (never a fabricated number), legacy fields keep working.
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'product_id', $p1 );
	$resp = $analytics_ctrl->handle_get( $req );
	$sum = $resp->get_data()['data']['summary'];
	check( 'product filter → purchased_orders null', null === $sum['purchased_orders'] );
	check( 'product filter → estimated_profit null', null === $sum['estimated_profit'] );
	check( 'product filter → profit_available false', false === $sum['profit_available'] );
	check( 'product filter → legacy impressions still present', array_key_exists( 'impressions', $sum ) );

	// Campaign filter resolves to the campaign's goal ids (509 + 510).
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'campaign_id', $campaign_id );
	$resp = $analytics_ctrl->handle_get( $req );
	$sum = $resp->get_data()['data']['summary'];
	check( 'campaign filter → attributed_sales 400', close( 400, $sum['attributed_sales'] ) );
	check( 'campaign filter → purchased_orders 1', 1 === (int) $sum['purchased_orders'] );

	// Reward filter resolves to the goals carrying that reward type.
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'reward', 'free_shipping' );
	$resp = $analytics_ctrl->handle_get( $req );
	$sum = $resp->get_data()['data']['summary'];
	check( 'reward filter → attributed_sales 500', close( 500, $sum['attributed_sales'] ) );
	check( 'reward filter → purchased_orders 2', 2 === (int) $sum['purchased_orders'] );

	// A reward filter that resolves to no goals → honest empty summary.
	$none = $repo->purchase_summary( array( 'reward_type' => 'free_gift' ) );
	check( 'reward filter resolving to no goals → 0 orders', 0 === (int) $none['orders'] );
	check( 'reward filter resolving to no goals → insufficient_data', 'insufficient_data' === $none['profit_reason_code'] );

	// goal_ids filter passes through to the attribution layer.
	$multi = $repo->purchase_summary( array( 'goal_ids' => array( 501, 502 ) ) );
	check( 'goal_ids summary → purchased 3', 3 === (int) $multi['orders'] );
	check( 'goal_ids summary → attributed sales 1400', close( 1400, $multi['goal_driven_revenue'] ) );

	// A filter that resolves to no goals → honest empty summary, never a
	// store-wide fallback for the wrong filter.
	$empty = $repo->purchase_summary( array( 'goal_id' => 999999 ) );
	check( 'unmatched goal filter → 0 orders', 0 === (int) $empty['orders'] );
	check( 'unmatched goal filter → reason code insufficient_data', 'insufficient_data' === $empty['profit_reason_code'] );
	check( 'unmatched goal filter → profit unavailable', false === $empty['profit_available'] && null === $empty['profit_impact'] );

	$wpdb->query( 'ROLLBACK' );
} catch ( \Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	check( 'no exception during fixture reads', false );
	echo 'Exception: ' . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 8. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 8. Rollback verification ==\n";

$sessions = array( $session( 1 ), $session( 2 ), $session( 3 ), $session( 4 ), $session( 5 ), $session( 6 ), $session( 7 ), $session( 8 ), $session( 9 ), $session( 10 ), $session( 11 ), $session( 12 ), $session( 13 ) );
$placeholders = implode( ', ', array_fill( 0, count( $sessions ), '%s' ) );

$revenue_after = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN ({$placeholders})", $sessions )
);
check( 'no test events remain after rollback', 0 === $revenue_after );

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table} WHERE id BETWEEN 501 AND 512" );
check( 'no test goals remain after rollback', 0 === $goals_after );

$campaigns_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE name = %s", 'P2 campaign' ) );
check( 'no test campaign remains after rollback', 0 === $campaigns_after );

$fixture_products = get_posts( array(
	'post_type'      => 'product',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	's'              => 'P2 ',
) );
check( 'no fixture products remain', empty( $fixture_products ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
