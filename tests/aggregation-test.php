<?php
/**
 * FaraCart Phase 33.3 tests (Aggregation & Performance).
 *
 * Boots WordPress, then exercises the Phase 33.3 aggregation layer:
 *
 *  - service wiring: DailyAggregator + RevenueRepository resolve from the
 *    container; the daily aggregation cron event is registered with the
 *    'daily' interval and the handlers are wired
 *  - schema: revenue_daily (goal_date composite) + upsell_stats (unique
 *    product_id) exist as the Phase 33.1 schema declares
 *  - daily aggregation: aggregate_revenue_day() rolls a day's revenue_events
 *    + goal_attribution rows into revenue_daily with the same definitions as
 *    the live reads (views/progressions/completions/conversions, revenue,
 *    incremental_revenue, reward_cost, estimated_profit); re-running is
 *    idempotent (delete-then-insert)
 *  - bounded catch-up: aggregate_revenue() starts the day after the last
 *    aggregated date (or the lookback floor), processes at most
 *    goalcart_aggregate_max_days per run and advances the option
 *  - upsell stats: aggregate_upsells() rebuilds upsell_stats from the raw
 *    upsell_events log (per-product impressions/clicks/adds/orders/revenue)
 *  - cached repository: overview / goal_performance / daily_trend (aggregated
 *    + today's live merge) / product_stats, with generation-versioned
 *    transients, the goalcart_revenue_cache_enabled bypass and invalidate()
 *  - invalidation hooks: order status, goal CRUD (goalcart_goals_changed),
 *    product saves and the aggregation run all bump the cache version
 *  - GoalRepository fires goalcart_goals_changed on create/update/delete
 *
 * All writes happen inside a single database transaction that is rolled
 * back; the absence of residue is asserted afterwards.
 *
 * Run: php tests/aggregation-test.php   (from the plugin directory)
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
use GoalCart\Analytics\DailyAggregator;
use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Analytics\Session;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\GoalRepository;
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
Installer::maybe_create_tables();

$container = \GoalCart\Plugin::instance()->container();

$aggregator = $container->get( DailyAggregator::class );
$repo       = $container->get( RevenueRepository::class );
$engine     = $container->get( AttributionEngine::class );
$tracker    = $container->get( RevenueTracker::class );
$costs      = $container->get( RewardCostEstimator::class );
$settings   = $container->get( Settings::class );
$goals_repo = $container->get( GoalRepository::class );
$wpdb       = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Service wiring + cron registration
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'DailyAggregator resolves from the container', $aggregator instanceof DailyAggregator );
check( 'RevenueRepository resolves from the container', $repo instanceof RevenueRepository );
check( 'aggregation event name is a plugin constant', DailyAggregator::AGGREGATE_EVENT === 'goalcart_revenue_aggregate' );
check( 'aggregation cron is in the installer cron list', in_array( DailyAggregator::AGGREGATE_EVENT, Installer::cron_events(), true ) );
check( 'cleanup cron still in the installer cron list', in_array( RevenueTracker::CLEANUP_EVENT, Installer::cron_events(), true ) );

$intervals = Installer::cron_intervals();
check( 'aggregation scheduled on the daily interval', isset( $intervals[ DailyAggregator::AGGREGATE_EVENT ] ) && 'daily' === $intervals[ DailyAggregator::AGGREGATE_EVENT ] );
check( 'cleanup scheduled on the weekly interval', isset( $intervals[ RevenueTracker::CLEANUP_EVENT ] ) && 'goalcart_weekly' === $intervals[ RevenueTracker::CLEANUP_EVENT ] );

// Register handlers through a fresh HookManager (mirrors the plugin boot path).
$hooks = new HookManager();
$aggregator->register( $hooks );
$repo->register( $hooks );
$hooks->run();

check( 'aggregation cron callback registered', has_action( DailyAggregator::AGGREGATE_EVENT ) );
check( 'cache invalidated on payment_complete', has_action( 'woocommerce_payment_complete' ) );
check( 'cache invalidated on goal changes', has_action( 'goalcart_goals_changed' ) );
check( 'cache invalidated on product saves', has_action( 'save_post_product' ) );
check( 'cache invalidated after aggregation', has_action( 'goalcart_revenue_aggregated' ) );

// ---------------------------------------------------------------------------
// 2. Schema
// ---------------------------------------------------------------------------
echo "\n== 2. Schema ==\n";

$daily_idx = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		$wpdb->dbname,
		Schema::table( 'revenue_daily' )
	)
);
check( 'revenue_daily has the goal_date composite index', in_array( 'goal_date', $daily_idx, true ) );
check( 'revenue_daily has the report_date index', in_array( 'report_date', $daily_idx, true ) );

$stats_idx = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
		$wpdb->dbname,
		Schema::table( 'upsell_stats' )
	)
);
check( 'upsell_stats has the unique product_id key', in_array( 'product_id', $stats_idx, true ) );

// ---------------------------------------------------------------------------
// 3. Daily aggregation + repository reads + invalidation (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 3. Aggregation (rolled back) ==\n";

$goals_table    = Schema::table( 'goals' );
$revenue_table  = Schema::table( 'revenue_events' );
$attrib_table   = Schema::table( 'goal_attribution' );
$upsell_table   = Schema::table( 'upsell_events' );
$daily_table    = Schema::table( 'revenue_daily' );
$stats_table    = Schema::table( 'upsell_stats' );

$session_a = str_repeat( 'ab', 16 );
$session_b = str_repeat( 'cd', 16 );

// Ensure the consent chain is on (other suites may leave it off).
$prev_enabled       = $settings->get( 'enabled', true );
$prev_analytics     = $settings->get( 'analytics_enabled', true );
$prev_last_agg      = get_option( DailyAggregator::LAST_AGGREGATED_OPTION, '' );
$prev_cache_version = get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );

$settings->set( 'enabled', true );
$settings->set( 'analytics_enabled', true );

$wpdb->query( 'START TRANSACTION' );

try {
	// --- Fixture goals (must exist for the event/attribution FKs). ---
	foreach ( array(
		array( 'id' => 101, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 50 ),
		array( 'id' => 202, 'reward_type' => null ),
	) as $goal_row ) {
		$wpdb->insert( $goals_table, array(
			'id'              => $goal_row['id'],
			'name'            => 'P33 aggregation goal ' . $goal_row['id'],
			'status'          => 'active',
			'type'            => 'amount',
			'target'          => 1000000,
			'reward_type'     => $goal_row['reward_type'],
			'reward_value'    => isset( $goal_row['reward_value'] ) ? $goal_row['reward_value'] : null,
			'reward_max_value'=> isset( $goal_row['reward_max_value'] ) ? $goal_row['reward_max_value'] : null,
			'created_at'      => '2026-08-05 00:00:00',
			'updated_at'      => '2026-08-05 00:00:00',
		) );
	}

	// --- Revenue events on a fixed past date (direct inserts — the tracker
	// stamps current_time; the aggregator must read the stored date). ---
	$event = function ( $type, $goal_id, $cart, $created_at, $extra = array() ) use ( $wpdb, $revenue_table, $session_a ) {
		$wpdb->insert( $revenue_table, array_merge( array(
			'event_type'   => $type,
			'goal_id'      => $goal_id,
			'session_id'   => $session_a,
			'cart_value'   => $cart,
			'goal_target'  => 1000000,
			'created_at'   => $created_at,
		), $extra ) );
	};

	$event( 'goal_view', 101, 700000, '2026-08-05 10:00:00' );
	$event( 'goal_progress', 101, 900000, '2026-08-05 10:30:00' );
	$event( 'goal_completed', 101, 1050000, '2026-08-05 11:00:00' );
	$event( 'goal_view', 202, 800000, '2026-08-05 11:30:00', array( 'session_id' => $session_b ) );

	// --- Attribution rows on the same day. ---
	$wpdb->insert( $attrib_table, array(
		'order_id'          => 9001,
		'goal_id'           => 101,
		'session_id'        => $session_a,
		'model'             => 'direct',
		'order_total'       => 1050000,
		'incremental_value' => 350000,
		'goal_completed'    => 1,
		'created_at'        => '2026-08-05 12:00:00',
	) );

	$wpdb->insert( $attrib_table, array(
		'order_id'          => 9002,
		'goal_id'           => 202,
		'session_id'        => $session_b,
		'model'             => 'assisted',
		'order_total'       => 800000,
		'incremental_value' => 0,
		'goal_completed'    => 0,
		'created_at'        => '2026-08-05 12:30:00',
	) );

	// --- Daily aggregation. ---
	$goal_count = $aggregator->aggregate_revenue_day( '2026-08-05' );
	check( 'aggregates both goals for the day', 2 === $goal_count );

	$row_101 = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$daily_table} WHERE report_date = %s AND goal_id = %d",
			'2026-08-05',
			101
		),
		ARRAY_A
	);

	check( 'daily row for the direct goal exists', null !== $row_101 );
	check( 'daily views = goal_view count', null !== $row_101 && 1 === (int) $row_101['views'] );
	check( 'daily progressions = goal_progress count', null !== $row_101 && 1 === (int) $row_101['progressions'] );
	check( 'daily completions = goal_completed count', null !== $row_101 && 1 === (int) $row_101['completions'] );
	check( 'daily conversions = distinct attributed orders', null !== $row_101 && 1 === (int) $row_101['conversions'] );
	check( 'daily revenue = influenced order totals', null !== $row_101 && close( 1050000, $row_101['revenue'] ) );
	check( 'daily incremental revenue = direct incremental', null !== $row_101 && close( 350000, $row_101['incremental_revenue'] ) );
	check( 'daily reward cost from the completed goal', null !== $row_101 && close( 50, $row_101['reward_cost'] ) );
	check( 'daily profit stored as 0 without margin data', null !== $row_101 && close( 0, $row_101['estimated_profit'] ) );

	$row_202 = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$daily_table} WHERE report_date = %s AND goal_id = %d",
			'2026-08-05',
			202
		),
		ARRAY_A
	);

	check( 'daily row for the assisted goal exists', null !== $row_202 );
	check( 'assisted goal daily views counted', null !== $row_202 && 1 === (int) $row_202['views'] );
	check( 'assisted goal daily revenue = order total', null !== $row_202 && close( 800000, $row_202['revenue'] ) );
	check( 'assisted goal daily incremental is zero', null !== $row_202 && close( 0, $row_202['incremental_revenue'] ) );

	// --- Idempotency: re-aggregating the day replaces, never duplicates. ---
	$aggregator->aggregate_revenue_day( '2026-08-05' );
	$after_repeat = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$daily_table} WHERE report_date = %s", '2026-08-05' )
	);
	check( 're-aggregation keeps exactly one row per goal', 2 === $after_repeat );

	// --- Bounded catch-up. ---
	update_option( DailyAggregator::LAST_AGGREGATED_OPTION, '2026-08-01', false );
	add_filter( 'goalcart_aggregate_max_days', function () {
		return 3;
	} );

	$processed = $aggregator->aggregate_revenue();

	remove_all_filters( 'goalcart_aggregate_max_days' );

	$today = date( 'Y-m-d', current_time( 'timestamp' ) );
	$expected_last = date( 'Y-m-d', strtotime( '2026-08-01 +3 days' ) );

	check( 'catch-up processes at most the max-days bound', 3 === $processed );
	check( 'catch-up advances the last-aggregated option', $expected_last === get_option( DailyAggregator::LAST_AGGREGATED_OPTION ) );
	check( 'catch-up never aggregates the future', $today > (string) get_option( DailyAggregator::LAST_AGGREGATED_OPTION ) );

	// A second run continues from where the first stopped (backlog drain).
	add_filter( 'goalcart_aggregate_max_days', function () {
		return 2;
	} );
	$processed_2 = $aggregator->aggregate_revenue();
	remove_all_filters( 'goalcart_aggregate_max_days' );

	check( 'second run continues from the last aggregated date', 2 === $processed_2 );

	// --- Upsell stats rebuild. ---
	// Non-order events store order_id as NULL (the tracker normalizes 0 →
	// NULL so the order_dedup unique key never collapses them).
	$upsell = function ( $type, $product_id, $order_id, $cart, $created_at, $goal_id = 101 ) use ( $wpdb, $upsell_table, $session_a ) {
		$wpdb->insert( $upsell_table, array(
			'event_type' => $type,
			'goal_id'    => $goal_id,
			'product_id' => $product_id,
			'order_id'   => $order_id > 0 ? $order_id : null,
			'session_id' => $session_a,
			'cart_value' => $cart,
			'created_at' => $created_at,
		) );
	};

	$upsell( 'upsell_impression', 5001, 0, null, '2026-08-05 10:00:00' );
	$upsell( 'upsell_impression', 5001, 0, null, '2026-08-05 10:05:00' );
	$upsell( 'upsell_clicked', 5001, 0, null, '2026-08-05 10:10:00' );
	$upsell( 'upsell_added', 5001, 0, null, '2026-08-05 10:15:00' );
	$upsell( 'upsell_order', 5001, 9100, 1000000, '2026-08-05 12:00:00' );
	$upsell( 'upsell_impression', 5002, 0, null, '2026-08-05 11:00:00' );

	$rebuild = $aggregator->aggregate_upsells();
	check( 'upsell stats rebuilt for the fixture products', $rebuild >= 2 );

	$fixture_stats = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$stats_table} WHERE product_id IN (%d, %d)", 5001, 5002 )
	);
	check( 'both fixture products have a stats row', 2 === $fixture_stats );

	$stat_5001 = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$stats_table} WHERE product_id = %d", 5001 ),
		ARRAY_A
	);

	check( 'upsell stats count impressions', null !== $stat_5001 && 2 === (int) $stat_5001['impressions'] );
	check( 'upsell stats count clicks', null !== $stat_5001 && 1 === (int) $stat_5001['clicks'] );
	check( 'upsell stats count adds', null !== $stat_5001 && 1 === (int) $stat_5001['adds'] );
	check( 'upsell stats count orders', null !== $stat_5001 && 1 === (int) $stat_5001['orders'] );
	check( 'upsell stats sum order revenue', null !== $stat_5001 && close( 1000000, $stat_5001['revenue'] ) );

	$stat_5002 = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$stats_table} WHERE product_id = %d", 5002 ),
		ARRAY_A
	);

	check( 'impression-only product has one impression', null !== $stat_5002 && 1 === (int) $stat_5002['impressions'] && 0 === (int) $stat_5002['orders'] );

	// --- Repository reads. ---

	// daily_trend reads the AGGREGATED table (zero-filled) — store-wide sums.
	$trend = $repo->daily_trend( array( 'from' => '2026-08-05', 'to' => '2026-08-05' ) );
	check( 'daily trend returns the aggregated bucket', 1 === count( $trend ) );
	check( 'trend bucket carries the aggregated views', 1 === count( $trend ) && 2 === (int) $trend[0]['views'] );
	check( 'trend bucket sums revenue across goals', 1 === count( $trend ) && close( 1850000, $trend[0]['revenue'] ) );
	check( 'trend bucket sums incremental revenue', 1 === count( $trend ) && close( 350000, $trend[0]['incremental_revenue'] ) );

	// Goal-scoped trend.
	$goal_trend = $repo->daily_trend( array( 'goal_id' => 101, 'from' => '2026-08-05', 'to' => '2026-08-05' ) );
	check( 'goal-scoped trend filters by goal', 1 === count( $goal_trend ) && 1 === (int) $goal_trend[0]['views'] && close( 50, $goal_trend[0]['reward_cost'] ) );

	// Today's live merge (window reaching today pulls the live bucket).
	$today_trend = $repo->daily_trend( array( 'from' => $today, 'to' => $today ) );
	check( 'trend includes a today bucket from live data', 1 === count( $today_trend ) && $today === $today_trend[0]['date'] );
	check( 'live today bucket has zero views (no events today)', 1 === count( $today_trend ) && 0 === (int) $today_trend[0]['views'] );

	// product_stats reads the rebuilt upsell_stats table. The store may
	// hold live upsell stats rows (real storefront traffic) alongside the
	// fixture rows, so the assertions target the fixture products by id
	// rather than expecting the table to contain exactly two products.
	$products = $repo->product_stats( array( 'limit' => 100 ) );

	$by_id = array();

	foreach ( $products as $product_row ) {
		$by_id[ (int) $product_row['product_id'] ] = $product_row;
	}

	check( 'product stats list the fixture products', isset( $by_id[5001] ) && isset( $by_id[5002] ) );
	check( 'product stats carry the converting product stats', isset( $by_id[5001] ) && 1 === (int) $by_id[5001]['orders'] );
	check( 'product stats expose conversion rate', isset( $by_id[5001] ) && close( 0.5, $by_id[5001]['conversion_rate'] ) );

	// overview merges the live attribution summary + AOV + shipping.
	$overview = $repo->overview( array( 'from' => '2026-08-05', 'to' => '2026-08-05' ) );
	check( 'overview exposes the attribution summary', isset( $overview['summary'] ) && close( 350000, $overview['summary']['goal_driven_revenue'] ) );
	check( 'overview exposes incremental cart value', isset( $overview['incremental_cart_value'] ) && isset( $overview['incremental_cart_value']['average'] ) );
	check( 'overview exposes AOV analysis', isset( $overview['aov'] ) && isset( $overview['aov']['exposed_aov'] ) );
	check( 'overview exposes shipping stats', isset( $overview['shipping'] ) && isset( $overview['shipping']['available'] ) );

	// goal_performance rows.
	$performance = $repo->goal_performance( array( 'goal_id' => 101, 'from' => '2026-08-05', 'to' => '2026-08-05' ) );
	check( 'goal performance returns the goal row', 1 === count( $performance ) && 101 === (int) $performance[0]['goal_id'] );
	check( 'goal performance exposes the funnel', 1 === count( $performance ) && 1 === (int) $performance[0]['views'] && 1 === (int) $performance[0]['completed'] );

	// --- Caching. ---
	$transient_rows = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
			'_transient_' . RevenueRepository::CACHE_PREFIX . '%'
		)
	);
	check( 'cached reads write versioned transients', $transient_rows >= 1 );

	$version_before = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	$repo->invalidate();
	$version_after = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	check( 'invalidate bumps the cache generation', $version_after === $version_before + 1 );

	// The cache bypass filter forces fresh computation every call.
	add_filter( 'goalcart_revenue_cache_enabled', '__return_false' );
	$fresh = $repo->overview( array( 'from' => '2026-08-05', 'to' => '2026-08-05' ) );
	remove_all_filters( 'goalcart_revenue_cache_enabled' );
	check( 'cache bypass still computes the overview', isset( $fresh['summary'] ) && close( 350000, $fresh['summary']['goal_driven_revenue'] ) );

	$version_bypass = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	check( 'cache bypass writes no new version', $version_bypass === $version_after );

	// --- Invalidation hooks actually fire. ---
	$fired = array();
	add_action( 'goalcart_revenue_aggregated', function () use ( &$fired ) {
		$fired[] = 'aggregated';
	} );

	$v1 = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	$aggregator->run();
	$v2 = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );

	remove_all_actions( 'goalcart_revenue_aggregated' );

	check( 'aggregation run fires the aggregated action', in_array( 'aggregated', $fired, true ) );
	check( 'aggregation run invalidates the revenue cache', $v2 === $v1 + 1 );

	// --- GoalRepository fires goalcart_goals_changed on CRUD. ---
	$changed = array();
	add_action( 'goalcart_goals_changed', function ( $goal_id ) use ( &$changed ) {
		$changed[] = (int) $goal_id;
	} );

	$new_id = $goals_repo->create( array(
		'name'   => 'P33 cache-invalidation goal',
		'type'   => 'amount',
		'target' => 500000,
	) );

	$goals_repo->update( $new_id, array( 'name' => 'P33 renamed goal' ) );
	$goals_repo->delete( $new_id );

	remove_all_actions( 'goalcart_goals_changed' );

	check( 'goal create fires goalcart_goals_changed', in_array( $new_id, $changed, true ) );
	check( 'goal update fires goalcart_goals_changed', 3 === count( $changed ) );
	check( 'goal delete fires goalcart_goals_changed', $new_id === end( $changed ) );

	// Order-status invalidation is wired through the repository's registered
	// callback (firing the real hook with a fake order id crashes WC core
	// handlers in this dev DB — the same reason the 33.2 suite avoids real
	// status transitions). has_action with the callback proves the wiring.
	check(
		'order status change invalidates the revenue cache',
		has_action( 'woocommerce_order_status_changed', array( $repo, 'invalidate' ) )
	);
} finally {
	$settings->set( 'enabled', $prev_enabled );
	$settings->set( 'analytics_enabled', $prev_analytics );

	if ( '' !== $prev_last_agg ) {
		update_option( DailyAggregator::LAST_AGGREGATED_OPTION, $prev_last_agg, false );
	}
	if ( 1 !== (int) $prev_cache_version ) {
		update_option( RevenueRepository::CACHE_VERSION_OPTION, (int) $prev_cache_version, false );
	}

	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 4. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

// The option writes happened inside the rolled-back transaction, but
// WordPress' in-memory options cache still holds the transactional values —
// drop it so the verification reads the true post-rollback state.
wp_cache_flush();

$daily_after  = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$daily_table} WHERE report_date = %s", '2026-08-05' )
);
$stats_after  = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$stats_table} WHERE product_id IN (%d, %d)", 5001, 5002 )
);
$event_after  = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN (%s, %s)", $session_a, $session_b )
);
$attrib_after = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE order_id IN (%d, %d)", 9001, 9002 )
);

// The tables may legitimately hold rows from live store traffic (real
// orders, upsell impressions, aggregation ticks) — the suite asserts only
// that ITS OWN fixture rows are gone, never that the tables are globally
// empty.
check( 'fixture daily rows removed by rollback', 0 === $daily_after );
check( 'fixture upsell stats removed by rollback', 0 === $stats_after );
check( 'fixture events removed by rollback', 0 === $event_after );
check( 'fixture attribution rows removed by rollback', 0 === $attrib_after );

$last_option = get_option( DailyAggregator::LAST_AGGREGATED_OPTION, '' );
check( 'last-aggregated option restored after rollback', (string) $last_option === (string) $prev_last_agg );

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table} WHERE id IN (101, 202)" );
check( 'fixture goals removed by rollback', 0 === $goals_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "AGGREGATION TEST FAILED\n" : "AGGREGATION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
