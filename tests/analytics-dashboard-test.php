<?php
/**
 * FaraCart analytics dashboard tests (P17-T01 Dashboard / P17-T02
 * Filters / P17-T03 Charts).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the Phase 17 analytics layer:
 *
 *  - service wiring: AnalyticsController resolves from the container and
 *    the GET /analytics route is registered
 *  - arg schema (P17-T02): date validation, the reward-type whitelist,
 *    mission_ids items, the limit clamp
 *  - permissions: anonymous dispatch is rejected (403), an authenticated
 *    administrator dispatch returns the full payload
 *  - summary KPIs (P17-T01): impressions, completions, completion rate,
 *    average cart value, revenue influenced, suggestion CTR and
 *    add-to-cart rate over seeded events
 *  - trend (P17-T03): a zero-filled daily series summing exactly to the
 *    seeded totals, with multi-day buckets
 *  - top lists: top missions / top campaigns / top suggested products with
 *    the expected ranking, names and derived rates
 *  - filters (P17-T02): campaign, mission, mission ids, reward type, product,
 *    future date range and limit — each slicing the payload correctly
 *  - full rollback verification (no residue)
 *
 * All writes (missions, campaigns, posts, users, events, rate-limit
 * transients) happen inside a single database transaction that is rolled
 * back; absence of residue is asserted afterwards.
 *
 * Run: php tests/analytics-dashboard-test.php   (from the plugin directory)
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

use FaraCart\Analytics\AnalyticsRepository;
use FaraCart\Analytics\Tracker;
use FaraCart\REST\AnalyticsController;
use FaraCart\REST\CampaignsController;
use FaraCart\REST\MissionsController;

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

function sum_of( array $rows, $key ) {
	$total = 0;

	foreach ( $rows as $row ) {
		$total += (float) $row[ $key ];
	}

	return $total;
}

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'faracart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'faracart_rest_test_ready' );
}

$container = \FaraCart\Plugin::instance()->container();

$repo           = $container->get( AnalyticsRepository::class );
$tracker        = $container->get( Tracker::class );
$analytics_ctrl = $container->get( AnalyticsController::class );
$missions_ctrl     = $container->get( MissionsController::class );
$campaigns_ctrl = $container->get( CampaignsController::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Service wiring (P17-T01: the dashboard data layer is wired)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'AnalyticsController resolves from container', $analytics_ctrl instanceof AnalyticsController );
check( '/analytics registered', isset( $routes['/faracart/v1/analytics'] ) );

$route = $routes['/faracart/v1/analytics'][0];
check( 'analytics route readable (GET)', isset( $route['methods']['GET'] ) );
check( 'analytics route is read-only', ! isset( $route['methods']['POST'] ) );
check( 'analytics route has permission callback', is_callable( $route['permission_callback'] ) );

// ---------------------------------------------------------------------------
// 2. Arg-schema validation (P17-T02)
// ---------------------------------------------------------------------------
echo "\n== 2. Input validation ==\n";

$args = $analytics_ctrl->analytics_args();

check( 'from validate_callback present', isset( $args['from']['validate_callback'] ) );
check( 'invalid from rejected', false === $args['from']['validate_callback']( '12/34/5678' ) );
check( 'valid from accepted', true === $args['from']['validate_callback']( '2026-08-01' ) );
check( 'empty from accepted', true === $args['from']['validate_callback']( '' ) );

check( 'reward enum in schema', isset( $args['reward']['enum'] ) );
check( 'bogus reward rejected by schema', is_wp_error( rest_validate_value_from_schema( 'bogus', $args['reward'], 'reward' ) ) );
check( 'coupon reward accepted by schema', true === rest_validate_value_from_schema( 'coupon', $args['reward'], 'reward' ) );
check( 'empty reward accepted by schema', true === rest_validate_value_from_schema( '', $args['reward'], 'reward' ) );

check( 'mission_ids items validated', is_wp_error( rest_validate_value_from_schema( array( 0, -1 ), $args['mission_ids'], 'mission_ids' ) ) );
check( 'positive mission_ids accepted', true === rest_validate_value_from_schema( array( 3, 7 ), $args['mission_ids'], 'mission_ids' ) );
check( 'limit above max rejected', is_wp_error( rest_validate_value_from_schema( 25, $args['limit'], 'limit' ) ) );

// ---------------------------------------------------------------------------
// 3. Dashboard payload over seeded events (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 3. Dashboard payload, filters, permissions (rolled back) ==\n";

$events_table = \FaraCart\Database\Schema::table( 'analytics_events' );
$events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	// 3.1 Seed: campaign X with mission A (free_shipping reward), standalone
	// mission B (coupon reward), and two suggested products.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/campaigns' );
	$req->set_param( 'name', 'Analytics Campaign X' );
	$req->set_param( 'status', 'active' );
	$resp = $campaigns_ctrl->handle_create( $req );
	$campaign_x = (int) $resp->get_data()['data']['id'];

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Analytics Mission A' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 500 );
	$req->set_param( 'reward_type', 'free_shipping' );
	$req->set_param( 'campaign_id', $campaign_x );
	$resp = $missions_ctrl->handle_create( $req );
	$mission_a = (int) $resp->get_data()['data']['id'];

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Analytics Mission B' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$req->set_param( 'reward_type', 'coupon' );
	$resp = $missions_ctrl->handle_create( $req );
	$mission_b = (int) $resp->get_data()['data']['id'];

	check( 'campaign seeded', $campaign_x > 0 );
	check( 'mission A seeded', $mission_a > 0 );
	check( 'mission B seeded', $mission_b > 0 );

	// Suggested products must exist as posts for the top-products join
	// (explicit IDs so the events' product_id references resolve).
	foreach ( array( 900007 => 'Suggested Widget', 900008 => 'Suggested Gadget' ) as $pid => $pname ) {
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->posts, array(
			'ID'                => $pid,
			'post_author'       => 0,
			'post_date'         => $now,
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'post_content'      => '',
			'post_title'        => $pname,
			'post_excerpt'      => '',
			'post_status'       => 'publish',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'post_name'         => 'suggested-' . $pid,
			'post_modified'     => $now,
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'post_type'         => 'product',
		) );
		check( "product {$pid} seeded", $pid === (int) $wpdb->insert_id );
	}

	// 3.2 Seed events (current user 0 — anonymous, like the storefront).
	$session = str_repeat( 'cd', 16 );

	$seed_a_impressions = array();
	foreach ( array( 100, 200, 300 ) as $value ) {
		$seed_a_impressions[] = $tracker->record( Tracker::EVENT_MISSION_IMPRESSION, array(
			'mission_id'     => $mission_a,
			'campaign_id' => $campaign_x,
			'cart_value'  => $value,
			'session_id'  => $session,
		) );
	}

	$tracker->record( Tracker::EVENT_MISSION_COMPLETED, array(
		'mission_id'     => $mission_a,
		'campaign_id' => $campaign_x,
		'cart_value'  => 300,
		'session_id'  => $session,
	) );
	$tracker->record( Tracker::EVENT_REWARD_ACTIVATED, array(
		'mission_id'     => $mission_a,
		'campaign_id' => $campaign_x,
		'cart_value'  => 400,
		'session_id'  => $session,
	) );

	foreach ( array( 50, 60 ) as $value ) {
		$tracker->record( Tracker::EVENT_MISSION_IMPRESSION, array(
			'mission_id'    => $mission_b,
			'cart_value' => $value,
			'session_id' => $session,
		) );
	}

	$mission_b_completion = $tracker->record( Tracker::EVENT_MISSION_COMPLETED, array(
		'mission_id'    => $mission_b,
		'cart_value' => 60,
		'session_id' => $session,
	) );

	for ( $i = 0; $i < 4; $i++ ) {
		$tracker->record( Tracker::EVENT_SUGGESTION_IMPRESSION, array(
			'mission_id'    => $mission_a,
			'product_id' => 900007,
			'session_id' => $session,
		) );
	}
	for ( $i = 0; $i < 2; $i++ ) {
		$tracker->record( Tracker::EVENT_SUGGESTION_IMPRESSION, array(
			'mission_id'    => $mission_b,
			'product_id' => 900008,
			'session_id' => $session,
		) );
	}
	for ( $i = 0; $i < 2; $i++ ) {
		$tracker->record( Tracker::EVENT_SUGGESTION_CLICKED, array(
			'mission_id'    => $mission_a,
			'product_id' => 900007,
			'session_id' => $session,
		) );
	}
	$tracker->record( Tracker::EVENT_SUGGESTED_PRODUCT_ADDED, array(
		'mission_id'    => $mission_a,
		'product_id' => 900007,
		'session_id' => $session,
	) );

	// Move one impression and one completion to yesterday so the trend has
	// two non-empty days.
	$yesterday = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
	$wpdb->update(
		$events_table,
		array( 'created_at' => $yesterday . ' 14:00:00' ),
		array( 'id' => $seed_a_impressions[0] )
	);
	$wpdb->update(
		$events_table,
		array( 'created_at' => $yesterday . ' 10:00:00' ),
		array( 'id' => $mission_b_completion )
	);

	check( 'seed events recorded', $seed_a_impressions[0] > 0 && $mission_b_completion > 0 );

	// 3.3 Summary KPIs (P17-T01) over the default 30-day window.
	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();

	check( 'payload has summary', isset( $data['data']['summary'] ) );
	check( 'meta echoes applied filters', isset( $data['meta']['applied']['from'] ) );

	$summary = $data['data']['summary'];
	check( 'impressions is 5', 5 === (int) $summary['impressions'] );
	check( 'completions is 3', 3 === (int) $summary['completions'] );
	check( 'completion rate is 0.6', near( 0.6, $summary['completion_rate'] ) );
	check( 'average cart value is 142', near( 142.0, $summary['average_cart_value'] ) );
	check( 'revenue influenced is 760', near( 760.0, $summary['revenue_influenced'] ) );
	check( 'suggestion CTR is 0.3333', near( 0.3333, $summary['suggestion_ctr'] ) );
	check( 'suggestion add-to-cart rate is 0.5', near( 0.5, $summary['suggestion_add_to_cart_rate'] ) );

	// 3.3b Phase 2 extension (Improvement.md §37/§38): the summary now also
	// carries the purchase/profit fields derived from the attribution
	// layer. The legacy Phase 17 fields above stay untouched.
	foreach ( array( 'progressed', 'purchased_orders', 'purchase_rate', 'attributed_sales', 'estimated_profit', 'profit_available', 'profit_reason', 'profit_reason_code' ) as $key ) {
		check( "summary extended with {$key}", array_key_exists( $key, $summary ) );
	}
	check( 'summary extended with cost_coverage', isset( $summary['cost_coverage'] ) && is_array( $summary['cost_coverage'] ) );
	check( 'summary extended with profit_details', isset( $summary['profit_details'] ) && is_array( $summary['profit_details'] ) );

	// Phase 6 (Analytics Redesign — Improvement.md §21–§30): the summary
	// also exposes the attribution funnel (views → progressed → completed
	// → purchased) and the assisted/influenced revenue splits, and the
	// payload carries the per-mission comparison rows (§27) — all additive.
	check( 'summary extended with funnel', isset( $summary['funnel'] ) && is_array( $summary['funnel'] ) );
	check(
		'summary funnel has the four stages',
		isset( $summary['funnel']['views'], $summary['funnel']['progressed'], $summary['funnel']['completed'], $summary['funnel']['converted'] )
	);
	check( 'summary extended with assisted_sales', array_key_exists( 'assisted_sales', $summary ) );
	check( 'summary extended with influenced_sales', array_key_exists( 'influenced_sales', $summary ) );
	check( 'payload has mission_comparison', isset( $data['data']['mission_comparison'] ) && is_array( $data['data']['mission_comparison'] ) );

	// 3.4 Trend (P17-T03): zero-filled daily series over the window.
	$today  = current_time( 'Y-m-d' );
	$from30 = date( 'Y-m-d', strtotime( $today ) - 29 * DAY_IN_SECONDS );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'from', $from30 );
	$req->set_param( 'to', $today );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();

	$trend = $data['data']['trend'];
	check( 'trend covers the full window', 30 === count( $trend ) );
	check( 'trend impressions sum to 5', near( 5, sum_of( $trend, 'impressions' ) ) );
	check( 'trend completions sum to 3', near( 3, sum_of( $trend, 'completions' ) ) );
	check( 'trend revenue sums to 760', near( 760, sum_of( $trend, 'revenue' ) ) );

	$has_yesterday = false;
	foreach ( $trend as $point ) {
		if ( $yesterday === $point['date'] ) {
			$has_yesterday = $point['impressions'] >= 1 && $point['completions'] >= 1;
		}
	}
	check( 'trend buckets both days', true === $has_yesterday );

	// 3.5 Top lists (P17-T01).
	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();

	$top_missions = $data['data']['top_missions'];
	check( 'top missions has 2 entries', 2 === count( $top_missions ) );
	check( 'top missions ranks mission A first', isset( $top_missions[0] ) && $mission_a === (int) $top_missions[0]['id'] );
	check( 'top missions names resolved', isset( $top_missions[0] ) && 'Analytics Mission A' === $top_missions[0]['name'] );
	check( 'top mission A completions', isset( $top_missions[0] ) && 2 === (int) $top_missions[0]['completions'] );
	check( 'top mission B follows', isset( $top_missions[1] ) && $mission_b === (int) $top_missions[1]['id'] );
	check( 'top mission completion rate', isset( $top_missions[1] ) && near( 0.5, $top_missions[1]['completion_rate'] ) );

	$top_campaigns = $data['data']['top_campaigns'];
	check( 'top campaigns has 1 entry', 1 === count( $top_campaigns ) );
	check( 'top campaign is campaign X', isset( $top_campaigns[0] ) && $campaign_x === (int) $top_campaigns[0]['id'] );
	check( 'top campaign name resolved', isset( $top_campaigns[0] ) && 'Analytics Campaign X' === $top_campaigns[0]['name'] );
	check( 'top campaign completions', isset( $top_campaigns[0] ) && 2 === (int) $top_campaigns[0]['completions'] );
	check( 'top campaign revenue', isset( $top_campaigns[0] ) && near( 700, $top_campaigns[0]['revenue'] ) );

	$top_products = $data['data']['top_suggested_products'];
	check( 'top products has 2 entries', 2 === count( $top_products ) );
	check( 'product 900007 ranks first', isset( $top_products[0] ) && 900007 === (int) $top_products[0]['product_id'] );
	check( 'product name resolved', isset( $top_products[0] ) && 'Suggested Widget' === $top_products[0]['name'] );
	check( 'product conversions counted', isset( $top_products[0] ) && 1 === (int) $top_products[0]['added'] );
	check( 'product CTR derived', isset( $top_products[0] ) && near( 0.5, $top_products[0]['ctr'] ) );
	check( 'product ATC rate derived', isset( $top_products[0] ) && near( 0.5, $top_products[0]['add_to_cart_rate'] ) );
	check( 'product 900008 follows', isset( $top_products[1] ) && 900008 === (int) $top_products[1]['product_id'] );
	check( 'unconverted product rates are 0', isset( $top_products[1] ) && 0.0 === (float) $top_products[1]['ctr'] );

	// 3.6 Filters (P17-T02).
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'campaign_id', $campaign_x );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'campaign filter impressions', 3 === (int) $summary['impressions'] );
	check( 'campaign filter completions', 2 === (int) $summary['completions'] );
	check( 'campaign filter revenue', near( 700, $summary['revenue_influenced'] ) );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'mission_id', $mission_b );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'mission filter impressions', 2 === (int) $summary['impressions'] );
	check( 'mission filter completions', 1 === (int) $summary['completions'] );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'mission_ids', array( $mission_a, $mission_b ) );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'mission_ids filter impressions', 5 === (int) $summary['impressions'] );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'reward', 'free_shipping' );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'reward filter (free_shipping) impressions', 3 === (int) $summary['impressions'] );
	check( 'reward filter (free_shipping) completions', 2 === (int) $summary['completions'] );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'reward', 'coupon' );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'reward filter (coupon) impressions', 2 === (int) $summary['impressions'] );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'product_id', 900007 );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'product filter CTR', near( 0.5, $summary['suggestion_ctr'] ) );

	// Phase 2: product_id cannot be expressed in attribution, so the
	// purchase fields degrade to null (never a fabricated number) while
	// the Phase 17 fields keep working.
	check( 'product filter purchase fields null', null === $summary['purchased_orders'] && null === $summary['attributed_sales'] && null === $summary['estimated_profit'] );
	check( 'product filter legacy impressions intact', array_key_exists( 'impressions', $summary ) );

	// Phase 6: the mission comparison rows respect the filters (§27) — a
	// mission filter narrows them to that mission, an unmatched filter yields
	// no rows, and a product filter cannot be expressed in attribution
	// (null, never a fabricated list).
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'mission_id', $mission_b );
	$resp = $analytics_ctrl->handle_get( $req );
	$comparison = $resp->get_data()['data']['mission_comparison'];
	check( 'mission filter comparison → exactly the mission', 1 === count( $comparison ) && $mission_b === (int) $comparison[0]['mission_id'] );
	// array_key_exists (not isset): conversion_rate is legitimately null
	// without completions — the key must still be present.
	check(
		'mission comparison rows carry purchase fields',
		array_key_exists( 'views', $comparison[0] )
			&& array_key_exists( 'converted', $comparison[0] )
			&& array_key_exists( 'attributed_revenue', $comparison[0] )
			&& array_key_exists( 'conversion_rate', $comparison[0] )
	);

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'mission_id', 999999 );
	$resp = $analytics_ctrl->handle_get( $req );
	check( 'unmatched mission → empty mission_comparison', array() === $resp->get_data()['data']['mission_comparison'] );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'product_id', 900007 );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();
	check( 'product filter → mission_comparison null', null === $data['data']['mission_comparison'] );
	check( 'product filter → funnel null', null === $data['data']['summary']['funnel'] );

	// A mission filter that resolves to no missions yields an honest empty
	// purchase summary — never store-wide data for the wrong filter.
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'mission_id', 999999 );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'unmatched mission → purchased_orders 0', 0 === (int) $summary['purchased_orders'] );
	check( 'unmatched mission → profit_reason_code insufficient_data', 'insufficient_data' === $summary['profit_reason_code'] );
	check( 'unmatched mission → profit unavailable', false === $summary['profit_available'] && null === $summary['estimated_profit'] );

	// Today-inclusive date bound (regression): a date-only `to` must cover
	// the whole `to` day. MySQL casts a bare 'YYYY-MM-DD' to midnight, so
	// events recorded on the `to` day were silently dropped and the
	// dashboard showed "No analytics yet" whenever the range ended on a
	// day with events (e.g. the default last-30-days window ending today).
	// Bounds are >= the seeded counts: the analytics table may also hold
	// live storefront events recorded on the same day (the suite otherwise
	// assumes a clean table), and every one of them is excluded by the bug.
	$today = current_time( 'Y-m-d' );
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'from', $today );
	$req->set_param( 'to', $today );
	$resp = $analytics_ctrl->handle_get( $req );
	$summary = $resp->get_data()['data']['summary'];
	check( 'today range includes today impressions (regression: was 0)', (int) $summary['impressions'] >= 4 );
	check( 'today range includes today completions', (int) $summary['completions'] >= 2 );
	check( 'today range trend covers one day', 1 === count( $resp->get_data()['data']['trend'] ) );

	$tomorrow = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) + DAY_IN_SECONDS );
	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'from', $tomorrow );
	$req->set_param( 'to', $tomorrow );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();
	check( 'future window impressions are 0', 0 === (int) $data['data']['summary']['impressions'] );
	check( 'future window trend is all zeros', 0.0 === sum_of( $data['data']['trend'], 'impressions' ) );

	$req = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$req->set_param( 'limit', 1 );
	$resp = $analytics_ctrl->handle_get( $req );
	$data = $resp->get_data();
	check( 'limit clamps top missions', 1 === count( $data['data']['top_missions'] ) );
	check( 'limit clamps top campaigns', 1 === count( $data['data']['top_campaigns'] ) );
	check( 'limit clamps top products', 1 === count( $data['data']['top_suggested_products'] ) );

	// 3.7 Permissions: anonymous 403, authenticated administrator 200.
	wp_set_current_user( 0 );
	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$resp = $server->dispatch( $req );
	check( 'anonymous rejected on analytics (403)', 403 === $resp->get_status() );

	$admin_id = wp_insert_user( array(
		'user_login' => 'faracart_admin_test_' . wp_rand( 1000, 9999 ),
		'user_pass'  => 'test-pass',
		'user_email' => 'faracart-admin-' . wp_rand( 1000, 9999 ) . '@example.test',
		'role'       => 'administrator',
	) );
	check( 'admin user created', ! is_wp_error( $admin_id ) && $admin_id > 0 );

	wp_set_current_user( (int) $admin_id );
	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/analytics' );
	$resp = $server->dispatch( $req );
	check( 'authenticated analytics dispatch → 200', 200 === $resp->get_status() );
	$dispatch_data = $resp->get_data();
	check( 'dispatch payload has summary', isset( $dispatch_data['data']['summary'] ) );
	check( 'dispatch summary matches seed', 5 === (int) $dispatch_data['data']['summary']['impressions'] );
	check( 'dispatch payload has trend', isset( $dispatch_data['data']['trend'] ) && is_array( $dispatch_data['data']['trend'] ) );
	check( 'dispatch payload has top lists', isset( $dispatch_data['data']['top_missions'], $dispatch_data['data']['top_campaigns'], $dispatch_data['data']['top_suggested_products'] ) );
	wp_set_current_user( 0 );
} finally {
	wp_set_current_user( 0 );
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 4. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

$events_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
check( 'no events persisted after rollback', $events_before === $events_after );

$posts_table = $wpdb->posts;
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE post_name = %s", 'suggested-900007' ) );
check( 'seeded products rolled back', 0 === $count );

$missions_table = \FaraCart\Database\Schema::table( 'missions' );
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE name = %s", 'Analytics Mission A' ) );
check( 'seeded mission rolled back', 0 === $count );

$campaigns_table = \FaraCart\Database\Schema::table( 'campaigns' );
$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE name = %s", 'Analytics Campaign X' ) );
check( 'seeded campaign rolled back', 0 === $count );

if ( ! is_wp_error( $admin_id ?? 0 ) && ( $admin_id ?? 0 ) > 0 ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", (int) $admin_id ) );
	check( 'admin user rolled back', 0 === $count );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "ANALYTICS DASHBOARD TEST FAILED\n" : "ANALYTICS DASHBOARD TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
