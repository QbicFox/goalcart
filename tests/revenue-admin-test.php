<?php
/**
 * Goal Cart Phase 33.6 (React Admin) revenue REST tests.
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the revenue optimization read endpoints that power the new
 * admin pages:
 *
 *  - route registration for /revenue/overview, /revenue/attribution and
 *    /revenue/goals
 *  - anonymous 403 on every revenue admin route (dispatch)
 *  - the window arg schema (datetime validation, goal_id bounds)
 *  - the payload shapes: overview (summary + incremental cart value +
 *    AOV + shipping + daily trend), attribution (same minus trend) and
 *    goal performance (per-goal rows)
 *  - the Phase 33.5 upsell analytics rows carry the Phase 33.6 profit /
 *    margin fields (estimated_profit, profit_available, margin_pct)
 *  - goal-scoped reads return the requested goal only
 *
 * Read-only like the other suites: the only writes (fixture products,
 * upsell events, a goal row, cache invalidation) happen inside a single
 * database transaction that is rolled back, and the absence of any
 * residue is asserted afterwards.
 *
 * Run: php tests/revenue-admin-test.php   (from the plugin directory)
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
require dirname( __DIR__ ) . '/goalcart.php';

use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\REST\RevenueController;

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

function route_exists( $routes, $pattern ) {
	return isset( $routes[ $pattern ] );
}

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'goalcart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'goalcart_rest_test_ready' );
}

$container = \GoalCart\Plugin::instance()->container();

$revenue_ctrl = $container->get( RevenueController::class );
$repository   = $container->get( RevenueRepository::class );
$tracker      = $container->get( RevenueTracker::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Route registration
// ---------------------------------------------------------------------------
echo "\n== 1. Route registration ==\n";

check( '/revenue/overview registered', route_exists( $routes, '/goalcart/v1/revenue/overview' ) );
check( '/revenue/attribution registered', route_exists( $routes, '/goalcart/v1/revenue/attribution' ) );
check( '/revenue/goals registered', route_exists( $routes, '/goalcart/v1/revenue/goals' ) );
check( '/revenue/goal-recommendations registered', route_exists( $routes, '/goalcart/v1/revenue/goal-recommendations' ) );
check( '/revenue/upsells registered', route_exists( $routes, '/goalcart/v1/revenue/upsells' ) );

// ---------------------------------------------------------------------------
// 2. Arg-schema validation
// ---------------------------------------------------------------------------
echo "\n== 2. Input validation ==\n";

$args = $revenue_ctrl->window_args();

check( 'invalid from rejected', false === $revenue_ctrl->validate_datetime_param( '12/34/5678' ) );
check( 'valid from accepted', true === $revenue_ctrl->validate_datetime_param( '2026-01-01' ) );
check( 'empty from accepted', true === $revenue_ctrl->validate_datetime_param( '' ) );
check( 'negative goal_id rejected', is_wp_error( rest_validate_value_from_schema( -1, $args['goal_id'], 'goal_id' ) ) );
check( 'zero goal_id accepted', true === rest_validate_value_from_schema( 0, $args['goal_id'], 'goal_id' ) );

// ---------------------------------------------------------------------------
// 3. Anonymous users are rejected on the revenue admin routes (403)
// ---------------------------------------------------------------------------
echo "\n== 3. Permissions ==\n";

foreach ( array( '/goalcart/v1/revenue/overview', '/goalcart/v1/revenue/attribution', '/goalcart/v1/revenue/goals' ) as $route ) {
	$req  = new \WP_REST_Request( 'GET', $route );
	$resp = $server->dispatch( $req );
	check( "anonymous rejected on {$route} (403)", 403 === $resp->get_status() );
}

// ---------------------------------------------------------------------------
// 4. Payload shapes (read-only handler calls)
// ---------------------------------------------------------------------------
echo "\n== 4. Payload shapes ==\n";

$overview_req = new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/overview' );
$overview_resp = $revenue_ctrl->handle_overview( $overview_req );
$overview      = $overview_resp->get_data()['data'];

check( 'overview has summary', isset( $overview['summary'] ) );
check( 'overview has incremental_cart_value', isset( $overview['incremental_cart_value'] ) );
check( 'overview has aov', isset( $overview['aov'] ) );
check( 'overview has shipping', isset( $overview['shipping'] ) );
check( 'overview has trend', isset( $overview['trend'] ) );
check( 'overview has generated_at', isset( $overview['generated_at'] ) );	check( 'overview summary has funnel', isset( $overview['summary']['funnel'] ) );
	check( 'overview funnel has converted', array_key_exists( 'converted', $overview['summary']['funnel'] ) );
	// Phase 2: profit availability metadata on the overview summary (§38/
	// §39/§11/§12) — machine-readable reason code, cost coverage and the
	// profit-model building blocks.
	check( 'overview summary has profit_reason_code', isset( $overview['summary']['profit_reason_code'] ) );
	check( 'overview summary has cost_coverage', isset( $overview['summary']['cost_coverage'] ) && is_array( $overview['summary']['cost_coverage'] ) );
	check( 'overview summary has profit_details', isset( $overview['summary']['profit_details'] ) && is_array( $overview['summary']['profit_details'] ) );
check( 'overview aov labelled observed', 'observed_impact' === $overview['aov']['label'] );
check( 'overview trend is an array', is_array( $overview['trend'] ) );
check( 'overview trend rows have date+revenue', empty( $overview['trend'] ) || ( isset( $overview['trend'][0]['date'] ) && isset( $overview['trend'][0]['revenue'] ) ) );

$attribution_resp = $revenue_ctrl->handle_attribution( new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/attribution' ) );
$attribution      = $attribution_resp->get_data()['data'];

check( 'attribution has summary', isset( $attribution['summary'] ) );
check( 'attribution has incremental_cart_value', isset( $attribution['incremental_cart_value'] ) );
check( 'attribution has no trend (overview minus trend)', ! array_key_exists( 'trend', $attribution ) );

$goals_resp = $revenue_ctrl->handle_goals( new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/goals' ) );
$goals      = $goals_resp->get_data()['data'];

check( 'goals payload has items', isset( $goals['items'] ) );
check( 'goals items is an array', is_array( $goals['items'] ) );
if ( ! empty( $goals['items'] ) ) {
	$first = $goals['items'][0];
	check( 'goal row has goal_id', isset( $first['goal_id'] ) );
	check( 'goal row has funnel counts', isset( $first['views'], $first['progressed'], $first['completed'], $first['converted'] ) );
	check(
		'goal row has revenue metrics',
		array_key_exists( 'attributed_revenue', $first )
			&& array_key_exists( 'assisted_revenue', $first )
			&& array_key_exists( 'reward_cost', $first )
			&& array_key_exists( 'profit_impact', $first )
	);
	check( 'goal row has profit_reason_code', array_key_exists( 'profit_reason_code', $first ) );
	check( 'goal row has cost_coverage', array_key_exists( 'cost_coverage', $first ) );
	check( 'goal row has profit_details', array_key_exists( 'profit_details', $first ) );
	// Phase 5 (Goal Performance Redesign): commercial-outcome + detail
	// drawer fields — total influenced revenue, the attribution window and
	// the data-sufficiency signal (Improvement.md §20/§45).
	check( 'goal row has influenced_revenue', array_key_exists( 'influenced_revenue', $first ) );
	check( 'goal row has attribution_window_days', array_key_exists( 'attribution_window_days', $first ) && $first['attribution_window_days'] > 0 );
	check( 'goal row has data_sufficiency', array_key_exists( 'data_sufficiency', $first ) && in_array( $first['data_sufficiency'], array( 'low', 'medium', 'high' ), true ) );
}

// ---------------------------------------------------------------------------
// 5. Transactional fixtures: goal-scoped reads + upsell analytics fields
// ---------------------------------------------------------------------------
echo "\n== 5. Fixture reads (rolled back) ==\n";

$goal_repo = $container->get( \GoalCart\Goals\GoalRepository::class );

$revenue_events_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'revenue_events' ) );
$upsell_events_before  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'upsell_events' ) );
$goals_before          = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'goals' ) );

$wpdb->query( 'START TRANSACTION' );

try {
	// Fixture goal (read by the goal-scoped endpoints).
	$goal_id = $goal_repo->create(
		array(
			'name'             => 'P33.6 Revenue Admin Test Goal',
			'type'             => 'amount',
			'target'           => 5000000,
			'status'           => 'active',
			'calculation_mode' => 'subtotal',
		)
	);
	check( 'fixture goal created', $goal_id > 0 );

	// Goal-scoped reads return only that goal.
	$scoped_req = new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/goals' );
	$scoped_req->set_param( 'goal_id', (int) $goal_id );
	$scoped = $revenue_ctrl->handle_goals( $scoped_req )->get_data()['data']['items'];
	check( 'goal-scoped goals returns exactly the fixture goal', 1 === count( $scoped ) && (int) $scoped[0]['goal_id'] === (int) $goal_id );
	check( 'goal-scoped row has the fixture name', $scoped[0]['name'] === 'P33.6 Revenue Admin Test Goal' );

	// Fixture products + upsell funnel events for the analytics row shape.
	$product_ids = array();
	foreach ( array( 'P33.6 Upsell Alpha', 'P33.6 Upsell Beta' ) as $title ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		$product = wc_get_product( $post_id );
		$product->set_regular_price( '490000' );
		$product->save();
		$product_ids[] = $post_id;
	}
	check( 'fixture products created', 2 === count( $product_ids ) );

	// Record impressions/clicked/added per product in distinct sessions
	// (the tracker dedups per session+goal+product within 24h).
	foreach ( $product_ids as $index => $product_id ) {
		foreach ( array( RevenueTracker::EVENT_UPSELL_IMPRESSION, RevenueTracker::EVENT_UPSELL_CLICKED, RevenueTracker::EVENT_UPSELL_ADDED ) as $type ) {
			$tracker->record_upsell(
				$type,
				array(
					'goal_id'    => (int) $goal_id,
					'product_id' => $product_id,						'session_id' => sprintf( '%032x', $index + 1 ),
					'cart_value' => 3000000,
				)
			);
		}
	}

	// Fresh reads: bump the revenue cache generation so the fixtures are
	// visible (the option write rolls back with the transaction).
	$repository->invalidate();

	$analytics = $repository->upsell_analytics(
		array(
			'from' => date( 'Y-m-d', strtotime( '-1 day' ) ),
			'to'   => date( 'Y-m-d' ),
			'limit' => 50,
		)
	);

	check( 'upsell analytics returns the fixture products', count( $analytics ) >= 2 );

	$row = $analytics[0] ?? array();
	check( 'analytics row has upsell_score', array_key_exists( 'upsell_score', $row ) );
	check( 'analytics row has estimated_profit', array_key_exists( 'estimated_profit', $row ) );
	check( 'analytics row has profit_available', array_key_exists( 'profit_available', $row ) );
	check( 'analytics row has margin_pct', array_key_exists( 'margin_pct', $row ) );
	check( 'analytics row has conversion_rate', array_key_exists( 'conversion_rate', $row ) );

	// The store stores no product costs → profit degrades gracefully.
	check( 'profit degrades gracefully without cost data', false === $row['profit_available'] && null === $row['estimated_profit'] );

	$wpdb->query( 'ROLLBACK' );
} catch ( \Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	check( 'no exception during fixture reads', false );
	echo 'Exception: ' . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 6. Residue check (nothing escaped the rollback)
// ---------------------------------------------------------------------------
echo "\n== 6. Rollback residue ==\n";

$revenue_events_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'revenue_events' ) );
$upsell_events_after  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'upsell_events' ) );
$goals_after          = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \GoalCart\Database\Schema::table( 'goals' ) );

check( 'revenue_events unchanged after rollback', $revenue_events_after === $revenue_events_before );
check( 'upsell_events unchanged after rollback', $upsell_events_after === $upsell_events_before );
check( 'goals unchanged after rollback', $goals_after === $goals_before );

$fixture_products = get_posts(
	array(
		'post_type'      => 'product',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		's'              => 'P33.6 ',
	)
);
check( 'no fixture products remain', empty( $fixture_products ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
