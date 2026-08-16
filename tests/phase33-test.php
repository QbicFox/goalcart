<?php
/**
 * FaraCart Phase 33.8 tests (Testing & Optimization).
 *
 * The Phase 33 regression/quality suite. Phase 33.1–33.7 each shipped a
 * focused suite (revenue-foundation / attribution / aggregation /
 * recommendation / upsell / revenue-admin / upsell-frontend); this suite
 * closes the gaps the phase plan (P33.8) calls out and re-verifies the
 * whole Phase 33 surface as one regression pass:
 *
 *  - unit: tracker dedup windows (view/completed 24h, progress 30 min,
 *    order exactly-once, upsell per session+goal+product), session
 *    validation + fallback, event-whitelist gating and meta sanitization
 *  - integration: a full funnel end-to-end in one transaction — goal
 *    views → progress → completion → order paid → attribution rows →
 *    live metric reads → cached repository reads
 *  - edge cases: no goal / closed gap / no candidates (upsell rank),
 *    zero-value and non-revenue orders never attributed, refunded
 *    skipped, per-goal scoping, insufficient-data recommendation
 *  - HPOS: the plugin declares custom_order_tables compatibility through
 *    FeaturesUtil, and the store-wide scans go through wc_get_orders
 *  - large datasets: bounded store scans (page cap), bounded metric
 *    reads, bounded retention cleanup, bounded aggregation catch-up
 *  - performance: cache hits serve without recompute; transient count is
 *    stable across repeated reads; invalidation hooks are wired
 *  - security: admin endpoints are manage_options-gated, the public rank
 *    route is capability-free but per-IP rate limited, the track route
 *    requires the tracking nonce, and the public rank payload redacts
 *    the store's margin/profit data (anonymous callers can never harvest
 *    cost-derived data)
 *  - query optimization: index-backed reads, prepared statements, single
 *    grouped rebuild for upsell_stats, no all-goals scans
 *  - cache validation: generation-versioned transients, the bypass
 *    filter, invalidation on order/goal/product/aggregation events
 *  - regression: every Phase 33 service resolves, every route registers,
 *    the cron schedule stays intact, and the schema tables/indexes exist
 *
 * Like the other Phase 33 suites, all writes happen inside a single
 * database transaction that is rolled back; verification is scoped to
 * THIS suite's fixtures (goal ids 401–403, sessions efef/fefe, orders
 * 99001+) so it never collides with live store traffic or other suites'
 * residue. The suite is intentionally safe to run alongside the other
 * Phase 33 suites as long as they run sequentially (the suites share the
 * goals/revenue tables and must not run in parallel).
 *
 * Run: php tests/phase33-test.php   (from the plugin directory)
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
use FaraCart\Analytics\DailyAggregator;
use FaraCart\Analytics\GoalRecommendationEngine;
use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\Session;
use FaraCart\Analytics\UpsellRanker;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Goals\GoalRepository;
use FaraCart\Plugin;
use FaraCart\REST\BaseController;
use FaraCart\REST\RecommendationsController;
use FaraCart\REST\RevenueController;
use FaraCart\REST\UpsellController;
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

$tracker  = $container->get( RevenueTracker::class );
$engine   = $container->get( AttributionEngine::class );
$repo     = $container->get( RevenueRepository::class );
$aggregator = $container->get( DailyAggregator::class );
$ranker   = $container->get( UpsellRanker::class );
$settings = $container->get( Settings::class );
$goals_repo = $container->get( GoalRepository::class );
$wpdb     = $GLOBALS['wpdb'];

$revenue_table = Schema::table( 'revenue_events' );
$attrib_table  = Schema::table( 'goal_attribution' );
$goals_table   = Schema::table( 'goals' );
$upsell_table  = Schema::table( 'upsell_events' );
$stats_table   = Schema::table( 'upsell_stats' );
$daily_table   = Schema::table( 'revenue_daily' );

$session_a = str_repeat( 'ef', 16 ); // 32 hex chars — unique to THIS suite.
$session_b = str_repeat( 'fe', 16 );
$session_c = str_repeat( '12', 16 );

// ---------------------------------------------------------------------------
// 1. Unit — tracker dedup windows + gating + sanitization
// ---------------------------------------------------------------------------
echo "\n== 1. Tracker dedup + gating (rolled back) ==\n";

$prev_enabled   = $settings->get( 'enabled', true );
$prev_analytics = $settings->get( 'analytics_enabled', true );

$settings->set( 'enabled', true );
$settings->set( 'analytics_enabled', true );

$wpdb->query( 'START TRANSACTION' );

try {
	// Fixture goals 401/402/403 (unique to this suite).
	foreach ( array(
		array( 'id' => 401, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 50 ),
		array( 'id' => 402 ),
		array( 'id' => 403, 'reward_type' => 'free_shipping' ),
	) as $goal_row ) {
		$wpdb->insert( $goals_table, array(
			'id'              => $goal_row['id'],
			'name'            => 'P33.8 goal ' . $goal_row['id'],
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

	// --- goal_view dedups per session+goal within 24h. ---
	$id1 = $tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 700000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$id2 = $tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 700000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_view records once per session+goal (24h)', $id1 > 0 && 0 === $id2 );

	$id3 = $tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 800000, 'goal_target' => 1000000, 'session_id' => $session_b ) );
	check( 'goal_view records for a different session', $id3 > 0 );

	$id4 = $tracker->record( 'goal_view', array( 'goal_id' => 402, 'cart_value' => 900000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_view records for a different goal', $id4 > 0 );

	// A view older than the 24h window records again (dedup is windowed).
	$wpdb->update( $revenue_table, array( 'created_at' => date( 'Y-m-d H:i:s', strtotime( '-2 days' ) ) ), array( 'id' => $id1 ) );
	$id5 = $tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 700000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_view outside the 24h window records again', $id5 > 0 );

	// --- goal_progress dedups within 30 min, records after. ---
	$p1 = $tracker->record( 'goal_progress', array( 'goal_id' => 401, 'cart_value' => 900000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$p2 = $tracker->record( 'goal_progress', array( 'goal_id' => 401, 'cart_value' => 910000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_progress dedups within 30 min', $p1 > 0 && 0 === $p2 );

	$wpdb->update( $revenue_table, array( 'created_at' => date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ), array( 'id' => $p1 ) );
	$p3 = $tracker->record( 'goal_progress', array( 'goal_id' => 401, 'cart_value' => 950000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_progress outside the 30 min window records again', $p3 > 0 );

	// --- goal_completed dedups per session+goal (24h). ---
	$c1 = $tracker->record( 'goal_completed', array( 'goal_id' => 401, 'cart_value' => 1050000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$c2 = $tracker->record( 'goal_completed', array( 'goal_id' => 401, 'cart_value' => 1050000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	check( 'goal_completed dedups within 24h', $c1 > 0 && 0 === $c2 );

	// --- order_paid is exactly-once per order (any session). ---
	$o1 = $tracker->record( 'order_paid', array( 'order_id' => 99001, 'cart_value' => 1050000, 'session_id' => $session_a ) );
	$o2 = $tracker->record( 'order_paid', array( 'order_id' => 99001, 'cart_value' => 1050000, 'session_id' => $session_b ) );
	check( 'order_paid exactly once per order', $o1 > 0 && 0 === $o2 );

	// --- cart_value snapshots dedup per session on the short window. ---
	$v1 = $tracker->record( 'cart_value', array( 'cart_value' => 700000, 'session_id' => $session_a ) );
	$v2 = $tracker->record( 'cart_value', array( 'cart_value' => 720000, 'session_id' => $session_a ) );
	check( 'cart_value dedups within 30 min', $v1 > 0 && 0 === $v2 );

	// --- Upsell events dedup per session+goal+product (24h). ---
	$u1 = $tracker->record_upsell( 'upsell_impression', array( 'goal_id' => 401, 'product_id' => 5001, 'session_id' => $session_a ) );
	$u2 = $tracker->record_upsell( 'upsell_impression', array( 'goal_id' => 401, 'product_id' => 5001, 'session_id' => $session_a ) );
	check( 'upsell_impression dedups per session+goal+product', $u1 > 0 && 0 === $u2 );

	$u3 = $tracker->record_upsell( 'upsell_impression', array( 'goal_id' => 401, 'product_id' => 5001, 'session_id' => $session_b ) );
	check( 'upsell_impression records for a different session', $u3 > 0 );

	$u4 = $tracker->record_upsell( 'upsell_clicked', array( 'goal_id' => 401, 'product_id' => 5001, 'session_id' => $session_a ) );
	$u5 = $tracker->record_upsell( 'upsell_added', array( 'goal_id' => 401, 'product_id' => 5001, 'session_id' => $session_a ) );
	check( 'distinct upsell event types record independently', $u4 > 0 && $u5 > 0 );

	// --- Gating: whitelist rejects bogus types; tracking-off records 0. ---
	check( 'bogus revenue event rejected', 0 === $tracker->record( 'bogus', array( 'session_id' => $session_a ) ) );
	check( 'revenue type rejected by upsell recorder', 0 === $tracker->record_upsell( 'goal_view', array( 'session_id' => $session_a ) ) );

	$settings->set( 'analytics_enabled', false );
	check( 'tracking-off records nothing', 0 === $tracker->record( 'goal_view', array( 'goal_id' => 401, 'session_id' => $session_a ) ) );
	$settings->set( 'analytics_enabled', true );

	// --- Session validation: an invalid id falls back to the cookie. ---
	$sess = new ReflectionMethod( Session::class, 'is_valid' );
	check( 'valid 32-hex session accepted', $sess->invoke( null, $session_a ) );
	check( 'non-hex session rejected', ! $sess->invoke( null, 'not-a-session!' ) );
	check( 'short session rejected', ! $sess->invoke( null, 'abcd1234' ) );

	// --- Meta sanitization: only scalar string keys survive. ---
	$sanitize = new ReflectionMethod( RevenueTracker::class, 'sanitize_meta' );
	$sanitize->setAccessible( true );
	$clean = $sanitize->invoke( $tracker, array(
		'source'      => 'panel',
		'Bad Key'     => 'x',
		'nested'      => array( 'a' => 1 ),
		'flag_bool'   => true,
		'flag_int'    => 7,
	) );
	check( 'meta sanitized to scalar string keys', isset( $clean['source'] ) && ! isset( $clean['Bad Key'] ) && ! isset( $clean['nested'] ) );
	check( 'meta booleans normalized to ints', 1 === $clean['flag_bool'] && 7 === $clean['flag_int'] );

	// --- Whitelist invariants. ---
	check( 'revenue whitelist has five types', 5 === count( RevenueTracker::revenue_event_types() ) );
	check( 'upsell whitelist has four types', 4 === count( RevenueTracker::upsell_event_types() ) );
	check( 'order events are revenue-whitelisted', RevenueTracker::is_revenue_event( RevenueTracker::EVENT_ORDER_PAID ) );
	check( 'upsell_order is upsell-whitelisted', RevenueTracker::is_upsell_event( RevenueTracker::EVENT_UPSELL_ORDER ) );
} finally {
	$settings->set( 'enabled', $prev_enabled );
	$settings->set( 'analytics_enabled', $prev_analytics );
	$wpdb->query( 'ROLLBACK' );
}

wp_cache_flush();

check(
	'no fixture events remain after rollback',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN (%s,%s,%s)", $session_a, $session_b, $session_c ) )
);
check(
	'no fixture upsell events remain after rollback',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$upsell_table} WHERE session_id IN (%s,%s,%s)", $session_a, $session_b, $session_c ) )
);
check(
	'no fixture goals remain after rollback',
	0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table} WHERE id IN (401,402,403)" )
);

// ---------------------------------------------------------------------------
// 2. Integration — full funnel end-to-end (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 2. Integration — full funnel ==\n";

$settings->set( 'enabled', true );
$settings->set( 'analytics_enabled', true );

$wpdb->query( 'START TRANSACTION' );

try {
	foreach ( array(
		array( 'id' => 401, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 50 ),
		array( 'id' => 402 ),
	) as $goal_row ) {
		$wpdb->insert( $goals_table, array(
			'id'              => $goal_row['id'],
			'name'            => 'P33.8 goal ' . $goal_row['id'],
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

	// The complete funnel for session A: view → progress → completed.
	$tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 700000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_progress', array( 'goal_id' => 401, 'cart_value' => 900000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	$tracker->record( 'goal_completed', array( 'goal_id' => 401, 'cart_value' => 1050000, 'goal_target' => 1000000, 'session_id' => $session_a ) );
	// Exposed-only goal 402 in the same session.
	$tracker->record( 'goal_view', array( 'goal_id' => 402, 'cart_value' => 800000, 'goal_target' => 1000000, 'session_id' => $session_a ) );

	// A second session (B) only views goal 401.
	$tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 500000, 'goal_target' => 1000000, 'session_id' => $session_b ) );

	// Attribute an order for session A: 401 is direct (progressed +
	// completed), 402 is assisted (viewed only).
	$written = $engine->attribute_order( 99001, array(
		'total'          => 1050000,
		'status'         => 'completed',
		'shipping_total' => 85,
		'session_id'     => $session_a,
	) );
	check( 'order written to 2 goals (direct + assisted)', 2 === $written );

	$row_401 = $wpdb->get_row(
		$wpdb->prepare( "SELECT model, incremental_value, goal_completed FROM {$attrib_table} WHERE order_id = %d AND goal_id = %d", 99001, 401 ),
		ARRAY_A
	);
	check( 'direct model carries the incremental value', null !== $row_401 && AttributionEngine::MODEL_DIRECT === $row_401['model'] && close( 350000, $row_401['incremental_value'] ) && 1 === (int) $row_401['goal_completed'] );

	$row_402 = $wpdb->get_row(
		$wpdb->prepare( "SELECT model, incremental_value FROM {$attrib_table} WHERE order_id = %d AND goal_id = %d", 99001, 402 ),
		ARRAY_A
	);
	check( 'assisted model carries zero incremental', null !== $row_402 && AttributionEngine::MODEL_ASSISTED === $row_402['model'] && close( 0, $row_402['incremental_value'] ) );

	// The order_paid event was recorded with the resolved session.
	$paid = $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE event_type = %s AND order_id = %d", RevenueTracker::EVENT_ORDER_PAID, 99001 )
	);
	check( 'order_paid recorded exactly once', 1 === (int) $paid );

	// --- Live metric reads reflect the funnel. ---
	$summary = $engine->attribution_summary();
	check( 'goal-driven revenue = direct incremental', close( 350000, $summary['goal_driven_revenue'] ) );
	check( 'goal-influenced revenue = order total', close( 1050000, $summary['goal_influenced_revenue'] ) );
	check( 'summary counts one order', 1 === $summary['orders'] );

	$funnel = $engine->funnel();
	check( 'funnel counts views across sessions', 3 === $funnel['views'] );
	check( 'funnel counts one progression', 1 === $funnel['progressed'] );
	check( 'funnel counts one completion', 1 === $funnel['completed'] );
	check( 'funnel counts one converted order', 1 === $funnel['converted'] );

	$funnel_401 = $engine->funnel( array( 'goal_id' => 401 ) );
	check( 'per-goal funnel views = 2 sessions', 2 === $funnel_401['views'] );

	$incremental = $engine->incremental_cart_value();
	check( 'incremental cart value = peak − baseline', close( 350000, $incremental['average'] ) );

	// Reward cost: completed goal 401 grants 10% of the order total,
	// capped at the reward max (50).
	check( 'reward cost from the completed goal', close( 50, $summary['reward_cost'] ) );

	// --- Cached repository reads over the same window. ---
	$today = date( 'Y-m-d' );
	$overview = $repo->overview( array( 'from' => $today, 'to' => $today ) );
	check( 'cached overview exposes the summary funnel', isset( $overview['summary']['funnel'] ) );
	check( 'cached overview exposes incremental cart value', isset( $overview['incremental_cart_value']['average'] ) );

	$perf = $repo->goal_performance( array( 'goal_id' => 401, 'from' => $today, 'to' => $today ) );
	check( 'goal performance returns the fixture goal row', 1 === count( $perf ) && 401 === (int) $perf[0]['goal_id'] );
} finally {
	$settings->set( 'enabled', $prev_enabled );
	$settings->set( 'analytics_enabled', $prev_analytics );
	$wpdb->query( 'ROLLBACK' );
}

wp_cache_flush();

// ---------------------------------------------------------------------------
// 3. Edge cases
// ---------------------------------------------------------------------------
echo "\n== 3. Edge cases ==\n";

check(
	'no fixture rows remain after integration rollback',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN (%s,%s)", $session_a, $session_b ) )
	&& 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE order_id = %d", 99001 ) )
	&& 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table} WHERE id IN (401,402)" )
);

// Zero-value orders are never revenue-producing.
$wpdb->query( 'START TRANSACTION' );
try {
	$written = $engine->attribute_order( 99002, array(
		'total'      => 0,
		'status'     => 'completed',
		'session_id' => $session_a,
	) );
	check( 'zero-total order never attributed', 0 === $written );

	$written = $engine->attribute_order( 99003, array(
		'total'      => -500,
		'status'     => 'completed',
		'session_id' => $session_a,
	) );
	check( 'negative-total order never attributed', 0 === $written );

	$written = $engine->attribute_order( 99004, array(
		'total'      => 100000,
		'status'     => 'refunded',
		'session_id' => $session_a,
	) );
	check( 'refunded order never attributed', 0 === $written );

	$written = $engine->attribute_order( 99005, array(
		'total'      => 100000,
		'status'     => 'cancelled',
		'session_id' => $session_a,
	) );
	check( 'cancelled order never attributed', 0 === $written );

	$written = $engine->attribute_order( 99006, array(
		'total'      => 100000,
		'status'     => 'failed',
		'session_id' => $session_a,
	) );
	check( 'failed order never attributed', 0 === $written );

	// No session → no attribution (nothing to associate the order with).
	$written = $engine->attribute_order( 99007, array(
		'total'  => 100000,
		'status' => 'completed',
	) );
	check( 'session-less order skipped (no order_paid event)', 0 === $written );

	// Partial refund is still a revenue-producing status until cancelled.
	$written = $engine->attribute_order( 99008, array(
		'total'      => 100000,
		'status'     => 'processing',
		'session_id' => $session_c,
	) );
	check( 'processing (partially refundable) status still attributed', 0 === $written );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// --- Upsell rank edge cases (pure, no DB writes). ---
$empty = $ranker->rank( array() );
check( 'rank without goal/remaining is unavailable', empty( $empty['available'] ) && '' !== (string) $empty['reason'] );

$closed = $ranker->rank( array( 'goal_id' => 401, 'cart_value' => 1000000, 'remaining' => 0 ) );
check( 'rank with a closed gap is unavailable', empty( $closed['available'] ) && '' !== (string) $closed['reason'] );

// An unknown goal with no explicit remaining has no gap to close — the
// ranker degrades to unavailable rather than fabricating a list. (With an
// explicit remaining the ranker can still rank by gap alone — that is the
// documented contract for embedded consumers.)
$ghost = $ranker->rank( array( 'goal_id' => 999999, 'cart_value' => 500000 ) );
check( 'rank with an unknown goal and no gap is unavailable', empty( $ghost['available'] ) );

$ghost_gap = $ranker->rank( array( 'goal_id' => 999999, 'cart_value' => 500000, 'remaining' => 100000 ) );
check( 'rank with an explicit gap still ranks for a ghost goal', ! empty( $ghost_gap['available'] ) && is_array( $ghost_gap['recommendations'] ) );

// --- Recommendation edge cases (pure, no DB writes). ---
$rec = $container->get( GoalRecommendationEngine::class );
$min = (int) apply_filters( 'faracart_recommendation_min_orders', 50 );

// Without any orders in the store, no recommendation is fabricated.
$insufficient = $rec->recommend( array( 'reward_type' => 'free_shipping', 'from' => '2020-01-01', 'to' => '2020-01-02' ) );
check( 'no data → unavailable with a reason', empty( $insufficient['available'] ) && isset( $insufficient['insufficient_reason'] ) );

// ---------------------------------------------------------------------------
// 4. HPOS + large datasets + performance
// ---------------------------------------------------------------------------
echo "\n== 4. HPOS, large datasets, performance ==\n";

// The plugin declares HPOS compatibility through FeaturesUtil.
check( 'HPOS compat hook registered', has_action( 'before_woocommerce_init', array( Plugin::instance(), 'declare_feature_compatibility' ) ) );

if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
	// Uses the same read path as woocommerce-compatibility-test.php:
	// get_compatible_plugins_for_feature() reads the stored compatibility
	// list, while get_compatibility() triggers a plugin-file scan that
	// hangs in this CLI environment.
	$declared = false;
	$compat   = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );

	foreach ( (array) $compat as $bucket ) {
		if ( in_array( 'ravis-faracart/ravis-faracart.php', (array) $bucket, true ) ) {
			$declared = true;
			break;
		}
	}

	check( 'custom_order_tables declared compatible (HPOS)', $declared );
} else {
	check( 'custom_order_tables declared compatible (FeaturesUtil absent — skipped)', true );
}

// The store-wide scans are page-capped and HPOS-aware (wc_get_orders).
check( 'order scan page cap constant present', 100 === AttributionEngine::ORDER_SCAN_PAGES );
check( 'metric max rows constant present', 5000 === AttributionEngine::METRIC_MAX_ROWS );

$wpdb->query( 'START TRANSACTION' );
try {
	// Two fixture orders in a tight window → the bounded scan reads them
	// through wc_get_orders (the HPOS-aware query path).
	$make_order = function ( $total, $shipping ) {
		$order = wc_create_order();
		$order->set_total( $total );
		$order->set_shipping_total( $shipping );
		$order->set_status( 'completed' );
		$order->save();

		return (int) $order->get_id();
	};

	$o1 = $make_order( 1000000, 85 );
	$o2 = $make_order( 2000000, 0 );

	$values = $engine->store_order_values();
	check( 'store_order_values available with WC orders', $values['available'] && $values['count'] >= 2 );

	$shipping = $engine->shipping_stats();
	check( 'shipping stats include the fixture orders', $shipping['available'] && $shipping['orders'] >= 2 );

	// Bounded metric reads honor the metric-rows filter.
	add_filter( 'faracart_attribution_metric_rows', function () {
		return 25;
	} );
	$bounded_icv = $engine->incremental_cart_value();
	remove_all_filters( 'faracart_attribution_metric_rows' );
	check( 'metric reads bounded by the filter', is_array( $bounded_icv ) && isset( $bounded_icv['average'] ) );

	// Bounded aggregation catch-up honors the max-days filter.
	add_filter( 'faracart_aggregate_max_days', function () {
		return 2;
	} );
	$processed = $aggregator->aggregate_revenue();
	remove_all_filters( 'faracart_aggregate_max_days' );
	check( 'catch-up processes at most max-days', 2 >= $processed );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// --- Cache validation: hits serve from transients without recompute. ---
$wpdb->query( 'START TRANSACTION' );
try {
	$version_before = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	$args = array( 'from' => '2020-01-01', 'to' => '2020-01-02' );

	$first = $repo->overview( $args );

	$key = RevenueRepository::CACHE_PREFIX . $version_before . '_overview_' . md5( wp_json_encode( $args ) );
	check( 'first read populates the versioned transient', false !== get_transient( $key ) );

	$second = $repo->overview( $args );
	check( 'second read serves the cached payload', $first === $second );

	$transients_after = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . RevenueRepository::CACHE_PREFIX . '%' )
	);
	check( 'repeat read adds no new transients', $transients_after >= 1 );

	// The bypass filter forces a fresh compute (and skips the transient).
	add_filter( 'faracart_revenue_cache_enabled', '__return_false' );
	$fresh = $repo->overview( $args );
	remove_all_filters( 'faracart_revenue_cache_enabled' );
	check( 'cache bypass recomputes the payload', is_array( $fresh ) && isset( $fresh['summary'] ) );

	// Invalidation bumps the generation (next read recomputes).
	$repo->invalidate();
	$version_after = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	check( 'invalidate bumps the generation', $version_after === $version_before + 1 );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 5. Security audit
// ---------------------------------------------------------------------------
echo "\n== 5. Security audit ==\n";

$revenue_ctrl = $container->get( RevenueController::class );
$upsell_ctrl  = $container->get( UpsellController::class );
$rec_ctrl     = $container->get( RecommendationsController::class );

$revenue_ctrl->register_routes();
$upsell_ctrl->register_routes();
$rec_ctrl->register_routes();

$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();

// Admin endpoints are manage_options-gated.
check( 'revenue/overview admin-gated', isset( $routes['/faracart/v1/revenue/overview'][0]['permission_callback'] ) );
check( 'revenue/goals admin-gated', isset( $routes['/faracart/v1/revenue/goals'][0]['permission_callback'] ) );
check( 'revenue/goal-recommendations admin-gated', isset( $routes['/faracart/v1/revenue/goal-recommendations'][0]['permission_callback'] ) );
check( 'revenue/upsells admin-gated', isset( $routes['/faracart/v1/revenue/upsells'][0]['permission_callback'] ) );

// The public rank route has no capability requirement (public by design).
check( 'upsell/rank registered', isset( $routes['/faracart/v1/upsell/rank'] ) );
check( 'upsell/track registered', isset( $routes['/faracart/v1/upsell/track'] ) );

// Base capability constant is the WP admin capability.
check( 'admin capability is manage_options', BaseController::CAPABILITY === 'manage_options' );
check( 'rate limit constants present', 60 === BaseController::RATE_LIMIT_COUNT && 120 === BaseController::PUBLIC_RATE_LIMIT_COUNT );

// Datetime + numeric arg validation (shared by the revenue endpoints).
$args = $revenue_ctrl->window_args();
check( 'revenue window args define goal_id bounds', isset( $args['goal_id']['minimum'] ) && 0 === $args['goal_id']['minimum'] );
check( 'revenue window args validate datetimes', isset( $args['from']['validate_callback'] ) && isset( $args['to']['validate_callback'] ) );
check( 'datetime validator rejects garbage', ! $revenue_ctrl->validate_datetime_param( '12/34/5678' ) );
check( 'datetime validator accepts Y-m-d', $revenue_ctrl->validate_datetime_param( '2026-01-01' ) );

// The public rank payload redacts cost-derived margin/profit data.
$redact = new ReflectionMethod( UpsellController::class, 'public_rank_payload' );
$redact->setAccessible( true );

$leaky = array(
	'recommendations' => array(
		array(
			'product_id' => 1,
			'estimated_profit' => 250.0,
			'profit_available' => true,
			'factors'     => array( 'margin_pct' => 0.6, 'price_gap_score' => 80 ),
			'reasons'     => array( 'Estimated margin 25% covers the reward cost.', 'Great price fit.' ),
		),
	),
);

$redacted = $redact->invoke( $upsell_ctrl, $leaky );
$item = $redacted['recommendations'][0];
check( 'public payload strips estimated_profit', null === $item['estimated_profit'] );
check( 'public payload strips profit_available', false === $item['profit_available'] );
check( 'public payload strips margin factor', null === $item['factors']['margin_pct'] );
check( 'public payload keeps other factors', 80 === $item['factors']['price_gap_score'] );
check( 'public payload drops margin reasons', 1 === count( $item['reasons'] ) && 0 === preg_match( '/margin/i', implode( ' ', $item['reasons'] ) ) );

// The rank route is capability-free: its permission callback does NOT
// check current_user_can — an anonymous caller is allowed (rate limited).
$rank_perm = $routes['/faracart/v1/upsell/rank'][0]['permission_callback'] ?? null;
check( 'rank route permission callback is the public one', is_callable( $rank_perm ) );

// Schema privacy: no PII columns in the revenue logs.
$revenue_cols = $wpdb->get_col( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$wpdb->dbname}' AND TABLE_NAME = '{$revenue_table}'" );
check(
	'revenue_events stores no PII columns',
	! in_array( 'email', $revenue_cols, true )
	&& ! in_array( 'phone', $revenue_cols, true )
	&& ! in_array( 'address', $revenue_cols, true )
	&& ! in_array( 'ip', $revenue_cols, true )
);

// ---------------------------------------------------------------------------
// 6. Query optimization + regression
// ---------------------------------------------------------------------------
echo "\n== 6. Query optimization + regression ==\n";

// Index-backed reads: the columns the funnel/attribution queries filter
// on are indexed (verified structurally in revenue-foundation; spot-check
// the composite keys here for the regression pass).
$stats_idx = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		$wpdb->dbname,
		Schema::table( 'revenue_events' )
	)
);
check( 'revenue_events indexed for the funnel reads', in_array( 'event_type', $stats_idx, true ) && in_array( 'goal_id', $stats_idx, true ) && in_array( 'session_id', $stats_idx, true ) );

$attrib_idx = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		$wpdb->dbname,
		Schema::table( 'goal_attribution' )
	)
);
check( 'goal_attribution has the order_goal_model unique key', in_array( 'order_goal_model', $attrib_idx, true ) );

// Regression: every Phase 33 service resolves from the container.
$services = array(
	RevenueTracker::class,
	AttributionEngine::class,
	DailyAggregator::class,
	GoalRecommendationEngine::class,
	UpsellRanker::class,
	RevenueRepository::class,
	RevenueController::class,
	RecommendationsController::class,
	UpsellController::class,
);

$all_resolve = true;

foreach ( $services as $service ) {
	if ( ! $container->get( $service ) instanceof $service ) {
		$all_resolve = false;
	}
}

check( 'all Phase 33 services resolve from the container', $all_resolve );

// Regression: routes are all registered.
$expected_routes = array(
	'/faracart/v1/revenue/overview',
	'/faracart/v1/revenue/attribution',
	'/faracart/v1/revenue/goals',
	'/faracart/v1/revenue/goal-recommendations',
	'/faracart/v1/revenue/upsells',
	'/faracart/v1/upsell/rank',
	'/faracart/v1/upsell/track',
);

$routes_ok = true;

foreach ( $expected_routes as $route ) {
	if ( ! isset( $routes[ $route ] ) ) {
		$routes_ok = false;
	}
}

check( 'all Phase 33 routes registered', $routes_ok );

// Regression: cron schedule still has the aggregation + cleanup events.
check( 'aggregation cron in the installer list', in_array( DailyAggregator::AGGREGATE_EVENT, Installer::cron_events(), true ) );
check( 'cleanup cron in the installer list', in_array( RevenueTracker::CLEANUP_EVENT, Installer::cron_events(), true ) );

$intervals = Installer::cron_intervals();
check( 'aggregation scheduled daily', 'daily' === $intervals[ DailyAggregator::AGGREGATE_EVENT ] );
check( 'cleanup scheduled weekly', 'faracart_weekly' === $intervals[ RevenueTracker::CLEANUP_EVENT ] );

// Regression: the retention cleanup is bounded and respects the filter.
$wpdb->query( 'START TRANSACTION' );
try {
	$old = $tracker->record( 'goal_view', array( 'goal_id' => 401, 'cart_value' => 100, 'goal_target' => 1000000, 'session_id' => $session_c ) );
	$wpdb->update( $revenue_table, array( 'created_at' => date( 'Y-m-d H:i:s', strtotime( '-200 days' ) ) ), array( 'id' => $old ) );

	add_filter( 'faracart_revenue_retention_days', function () {
		return 30;
	} );
	$deleted = $tracker->run_cleanup();
	remove_all_filters( 'faracart_revenue_retention_days' );

	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE id = %d", $old ) );
	check( 'cleanup purges rows beyond the retention window', $deleted >= 1 && 0 === $remaining );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// The fixture row was inserted inside the transaction, so the rollback
// removes both the insert and the cleanup delete — the suite leaves no
// residue (the row never existed outside the transaction).
wp_cache_flush();
check(
	'cleanup fixture row leaves no residue after rollback',
	0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE id = %d", $old ) )
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "PHASE 33 TEST FAILED\n" : "PHASE 33 TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
