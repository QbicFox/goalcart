<?php
/**
 * FaraCart analytics foundation tests (P16-T02 Events / P16-T03 Metrics
 * / P16-T04 Privacy).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the Phase 16 analytics layer:
 *
 *  - service wiring: Session / Tracker / AnalyticsRepository resolve
 *    from the container and register their hooks
 *  - sessions: 32-hex ids, cookie validation
 *  - the event-type whitelist (P16-T02): all seven event types accepted,
 *    anything else rejected
 *  - recording: record() writes a row with mission/campaign/product ids,
 *    cart value, session and scalar-only meta; user_id is NULL for guests
 *  - privacy (P16-T04): no PII columns are written (only aggregate
 *    numbers + ids + the anonymous session token)
 *  - tracking gates: disabled master toggle and the
 *    faracart_tracking_enabled filter both block recording
 *  - the public /track route: registered, arg schema rejects unknown
 *    event types, a bad nonce is rejected 403, a valid dispatch records
 *  - suggested_product_added attribution: a cart addition becomes a
 *    conversion only when the session saw a suggestion_impression for
 *    that product within the window
 *  - metrics (P16-T03): impressions, completions, completion rate,
 *    average cart value, revenue on completed missions, suggestion CTR and
 *    suggestion add-to-cart rate — computed over seeded events
 *  - date-range filtering on the metrics
 *  - full rollback verification (no residue)
 *
 * All writes (events, rate-limit transients) happen inside a single
 * database transaction that is rolled back; absence of residue is
 * asserted afterwards.
 *
 * Run: php tests/analytics-test.php   (from the plugin directory)
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
use FaraCart\Analytics\Session;
use FaraCart\Analytics\Tracker;
use FaraCart\REST\TrackController;

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

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'faracart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'faracart_rest_test_ready' );
}

$container = \FaraCart\Plugin::instance()->container();

$tracker = $container->get( Tracker::class );
$session = $container->get( Session::class );
$repo    = $container->get( AnalyticsRepository::class );
$track_ctrl = $container->get( TrackController::class );
$settings = $container->get( \FaraCart\Settings\Settings::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Service wiring (P16-T01 objective: the tracking surface is wired)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'Tracker resolves from container', $tracker instanceof Tracker );
check( 'Session resolves from container', $session instanceof Session );
check( 'AnalyticsRepository resolves from container', $repo instanceof AnalyticsRepository );
check( 'TrackController resolves from container', $track_ctrl instanceof TrackController );
check( 'session cookie hook registered', false !== has_action( 'wp', array( $tracker, 'maybe_ensure_session' ) ) );
check( 'frontend config print hooked at 4', 4 === hook_priority( 'wp_footer', array( $tracker, 'output_frontend_config' ) ) );
check( 'add-to-cart attribution hooked', false !== has_action( 'woocommerce_add_to_cart', array( $tracker, 'handle_add_to_cart' ) ) );

// ---------------------------------------------------------------------------
// 2. Sessions (P16-T04: anonymous, 32-hex, cookie-validated)
// ---------------------------------------------------------------------------
echo "\n== 2. Sessions ==\n";

$sid = $session->id();
check( 'session id is 32 lowercase hex', 1 === preg_match( Session::ID_PATTERN, $sid ) );
check( 'session id is stable within request', $sid === $session->id() );
check( 'is_valid accepts well-formed id', true === Session::is_valid( $sid ) );
check( 'is_valid rejects garbage', false === Session::is_valid( 'not-a-session' ) );
check( 'is_valid rejects empty', false === Session::is_valid( '' ) );

// ---------------------------------------------------------------------------
// 3. Event-type whitelist (P16-T02)
// ---------------------------------------------------------------------------
echo "\n== 3. Event whitelist ==\n";

$expected = array(
	'goal_impression',
	'goal_progress',
	'goal_completed',
	'reward_activated',
	'suggestion_impression',
	'suggestion_clicked',
	'suggested_product_added',
);

check( 'all seven event types whitelisted', array() === array_diff( $expected, Tracker::event_types() ) );
check( 'no extra event types', array() === array_diff( Tracker::event_types(), $expected ) );
check( 'bogus type rejected', false === Tracker::is_event_type( 'bogus_event' ) );

// ---------------------------------------------------------------------------
// 4. Recording + privacy + metrics (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 4. Recording, privacy, metrics (rolled back) ==\n";

$events_table = \FaraCart\Database\Schema::table( 'analytics_events' );
$seed_session = $sid;

$wpdb->query( 'START TRANSACTION' );

try {
	// analytics_events carries real foreign keys to missions/campaigns, so the
	// seeded events must reference rows that exist (mirrors production).
	$missions_ctrl     = $container->get( \FaraCart\REST\MissionsController::class );
	$campaigns_ctrl = $container->get( \FaraCart\REST\CampaignsController::class );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Analytics Test Mission' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 1000 );
	$resp = $missions_ctrl->handle_create( $req );
	$seed_mission_id = (int) $resp->get_data()['data']['id'];

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/campaigns' );
	$req->set_param( 'name', 'Analytics Test Campaign' );
	$req->set_param( 'status', 'active' );
	$resp = $campaigns_ctrl->handle_create( $req );
	$seed_campaign_id = (int) $resp->get_data()['data']['id'];

	check( 'seed mission created', $seed_mission_id > 0 );
	check( 'seed campaign created', $seed_campaign_id > 0 );
	// 4.1 Master gate: disabled master toggle blocks recording.
	$enabled_before = $settings->get( 'enabled', true );
	$settings->set( 'enabled', false );
	check( 'disabled master toggle blocks recording', 0 === $tracker->record( Tracker::EVENT_GOAL_IMPRESSION ) );
	check( 'disabled master toggle blocks add-to-cart attribution', ! $tracker->handle_add_to_cart( 'k', 5 ) );
	$settings->set( 'enabled', true );

	// 4.2 The faracart_tracking_enabled filter blocks recording. goal_progress
	// is used for the gate checks (not any metric) so the metrics seed below
	// stays exact.
	add_filter( 'faracart_tracking_enabled', '__return_false' );
	check( 'tracking filter blocks recording', 0 === $tracker->record( Tracker::EVENT_GOAL_PROGRESS ) );
	remove_filter( 'faracart_tracking_enabled', '__return_false' );
	check( 'filter removal restores recording', $tracker->record( Tracker::EVENT_GOAL_PROGRESS ) > 0 );

	// 4.3 Unknown event type never records.
	check( 'unknown event type not recorded', 0 === $tracker->record( 'not_an_event' ) );

	// 4.4 Full event recording with every context key (goal_progress does
	// not feed any metric, so the seed stays exact).
	$event_id = $tracker->record(
		Tracker::EVENT_GOAL_PROGRESS,
		array(
			'mission_id'     => $seed_mission_id,
			'campaign_id' => $seed_campaign_id,
			'product_id'  => 0,
			'cart_value'  => 500.25,
			'session_id'  => $seed_session,
			'meta'        => array( 'percentage' => 100, 'quantity' => 2 ),
		)
	);
	check( 'event recorded', $event_id > 0 );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", $event_id ), ARRAY_A );
	check( 'event row carries event_type', is_array( $row ) && Tracker::EVENT_GOAL_PROGRESS === $row['event_type'] );
	check( 'event row carries mission_id', is_array( $row ) && $seed_mission_id === (int) $row['mission_id'] );
	check( 'event row carries campaign_id', is_array( $row ) && $seed_campaign_id === (int) $row['campaign_id'] );
	check( 'event row carries cart_value', is_array( $row ) && near( 500.25, $row['cart_value'] ) );
	check( 'event row carries session_id', is_array( $row ) && $seed_session === $row['session_id'] );
	check( 'guest user_id is NULL', is_array( $row ) && null === $row['user_id'] );

	$meta = is_array( $row ) ? json_decode( (string) $row['meta'], true ) : null;
	check( 'meta JSON stored', is_array( $meta ) && isset( $meta['percentage'] ) );
	check( 'meta is scalar-only', is_array( $meta ) && 100 === (int) $meta['percentage'] && 2 === (int) $meta['quantity'] );

	// 4.5 Privacy (P16-T04): the events table has no PII columns at all —
	// only ids, aggregate numbers and the anonymous session token.
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$events_table}" );
	$no_pii  = ! array_intersect( array( 'ip', 'user_agent', 'email', 'name', 'phone', 'address' ), $columns );
	check( 'no PII columns in events table', true === $no_pii );

	// 4.6 Metrics (P16-T03) over seeded events.
	// Seed: 4 mission impressions (cart 100, 200, 300, 0), 2 completions (one
	// with reward), 3 suggestion impressions, 2 suggestion clicks, 1
	// suggested_product_added.
	foreach ( array( 100, 200, 300, 0 ) as $value ) {
		$tracker->record( Tracker::EVENT_GOAL_IMPRESSION, array(
			'mission_id'     => $seed_mission_id,
			'campaign_id' => $seed_campaign_id,
			'cart_value'  => $value,
			'session_id'  => $seed_session,
		) );
	}

	$tracker->record( Tracker::EVENT_GOAL_COMPLETED, array(
		'mission_id'     => $seed_mission_id,
		'campaign_id' => $seed_campaign_id,
		'cart_value'  => 300,
		'session_id'  => $seed_session,
	) );
	$tracker->record( Tracker::EVENT_REWARD_ACTIVATED, array(
		'mission_id'     => $seed_mission_id,
		'campaign_id' => $seed_campaign_id,
		'cart_value'  => 500,
		'session_id'  => $seed_session,
	) );

	for ( $i = 1; $i <= 3; $i++ ) {
		$tracker->record( Tracker::EVENT_SUGGESTION_IMPRESSION, array(
			'mission_id'    => $seed_mission_id,
			'product_id' => $i,
			'session_id' => $seed_session,
		) );
	}

	for ( $i = 1; $i <= 2; $i++ ) {
		$tracker->record( Tracker::EVENT_SUGGESTION_CLICKED, array(
			'mission_id'    => $seed_mission_id,
			'product_id' => $i,
			'session_id' => $seed_session,
		) );
	}

	$tracker->record( Tracker::EVENT_SUGGESTED_PRODUCT_ADDED, array(
		'mission_id'    => $seed_mission_id,
		'product_id' => 1,
		'session_id' => $seed_session,
	) );

	check( 'impressions count is 4', 4 === $repo->impressions() );
	check( 'completions count is 2', 2 === $repo->completions() );
	check( 'completion rate is 0.5', near( 0.5, $repo->completion_rate() ) );
	// Impressions seeded with carts 100/200/300/0 — the 0-value (empty) cart
	// is excluded from the engaged-cart average: (100+200+300)/3 = 200.
	check( 'average cart value is 200', near( 200.0, $repo->average_cart_value() ) );
	check( 'revenue on completed missions is 800', near( 800.0, $repo->revenue_associated_with_completed_missions() ) );
	check( 'suggestion CTR is 0.6667', near( 0.6667, $repo->suggestion_ctr() ) );
	check( 'suggestion add-to-cart rate is 0.5', near( 0.5, $repo->suggestion_add_to_cart_rate() ) );

	// 4.7 Metrics respect campaign / mission filters (Phase 17 slices).
	check( 'impressions filtered by campaign', 4 === $repo->impressions( array( 'campaign_id' => $seed_campaign_id ) ) );
	check( 'impressions filtered by other campaign', 0 === $repo->impressions( array( 'campaign_id' => 99 ) ) );
	check( 'impressions filtered by mission', 4 === $repo->impressions( array( 'mission_id' => $seed_mission_id ) ) );
	check( 'completions filtered by campaign', 2 === $repo->completions( array( 'campaign_id' => $seed_campaign_id ) ) );

	// 4.8 Date-range filtering (from/to).
	$tomorrow = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) + DAY_IN_SECONDS );
	$yesterday = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
	check( 'impressions in future window are 0', 0 === $repo->impressions( array( 'from' => $tomorrow ) ) );
	check( 'impressions since yesterday include all', 4 === $repo->impressions( array( 'from' => $yesterday ) ) );

	// 4.9 Rate metrics with empty denominators return 0 (no div-by-zero).
	check( 'completion rate with no impressions is 0', 0.0 === $repo->completion_rate( array( 'mission_id' => 999999 ) ) );
	check( 'CTR with no impressions is 0', 0.0 === $repo->suggestion_ctr( array( 'mission_id' => 999999 ) ) );
	check( 'ATC rate with no clicks is 0', 0.0 === $repo->suggestion_add_to_cart_rate( array( 'mission_id' => 999999 ) ) );
	check( 'avg cart value with no impressions is 0', 0.0 === $repo->average_cart_value( array( 'mission_id' => 999999 ) ) );
	check( 'revenue with no completions is 0', 0.0 === $repo->revenue_associated_with_completed_missions( array( 'mission_id' => 999999 ) ) );

	// 4.9b FK resilience: an event whose mission was deleted records without
	// the FK id instead of being dropped entirely (the FK's SET NULL
	// semantics for the deleted row).
	$ghost_id = $tracker->record( Tracker::EVENT_GOAL_PROGRESS, array(
		'mission_id'    => 99999999,
		'session_id' => $seed_session,
	) );
	check( 'deleted-mission event still records', $ghost_id > 0 );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", $ghost_id ), ARRAY_A );
	check( 'deleted-mission event drops mission_id', is_array( $row ) && null === $row['mission_id'] );

	// 4.10 Server-side attribution (suggested_product_added).
	// A different session that saw the product suggested → attributed.
	// The container Session memoizes its id, so the cookie swap needs the
	// memo reset (reflection — the class keeps its public surface clean).
	$other_session = str_repeat( 'ab', 16 );
	$tracker->record( Tracker::EVENT_SUGGESTION_IMPRESSION, array(
		'mission_id'    => $seed_mission_id,
		'product_id' => 42,
		'session_id' => $other_session,
	) );

	$_COOKIE[ Session::COOKIE ] = $other_session;

	$reset = new \ReflectionProperty( $session, 'id' );
	$reset->setAccessible( true );
	$reset->setValue( $session, null );

	$conversion = $tracker->handle_add_to_cart( 'k', 42 );
	check( 'suggested product add attributed', $conversion > 0 );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", $conversion ), ARRAY_A );
	check( 'conversion event type correct', is_array( $row ) && Tracker::EVENT_SUGGESTED_PRODUCT_ADDED === $row['event_type'] );
	check( 'conversion carries product_id', is_array( $row ) && 42 === (int) $row['product_id'] );
	check( 'conversion carries mission_id', is_array( $row ) && $seed_mission_id === (int) $row['mission_id'] );

	// A product the session never saw is NOT attributed.
	check( 'unseen product not attributed', 0 === $tracker->handle_add_to_cart( 'k2', 9999 ) );

	// A fresh session (no impressions) is not attributed.
	unset( $_COOKIE[ Session::COOKIE ] );
	$reset->setValue( $session, null );
	check( 'fresh session not attributed', 0 === $tracker->handle_add_to_cart( 'k3', 42 ) );

	// Capture the session id now (a fresh one was created by the k3 add)
	// for the config-print assertion below.
	$current_session = $session->id();

	// 4.11 Public /track route.
	check( '/track registered', isset( $routes['/faracart/v1/track'] ) );

	// Arg-schema validation via the route definition.
	$route   = $routes['/faracart/v1/track'][0];
	$schema  = isset( $route['args'] ) ? $route['args'] : array();
	check( 'track route has event_type arg', isset( $schema['event_type'] ) );
	check( 'track route has nonce arg', isset( $schema['nonce'] ) );

	$validate = $schema['event_type']['validate_callback'];
	check( 'whitelisted event type passes schema', true === $validate( 'goal_impression' ) );
	check( 'unknown event type fails schema', false === $validate( 'hack_event' ) );

	// Anonymous dispatch without a valid nonce → 403.
	$req  = new \WP_REST_Request( 'POST', '/faracart/v1/track' );
	$req->set_param( 'event_type', 'goal_impression' );
	$req->set_param( 'nonce', 'bogus' );
	$resp = $server->dispatch( $req );
	check( 'bad nonce rejected (403)', 403 === $resp->get_status() );

	// Valid nonce dispatch records an event end-to-end.
	$good_nonce = wp_create_nonce( Tracker::TRACK_NONCE_ACTION );
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/track' );
	$req->set_param( 'event_type', 'goal_progress' );
	$req->set_param( 'mission_id', $seed_mission_id );
	$req->set_param( 'cart_value', 250 );
	$req->set_param( 'percentage', 50 );
	$req->set_param( 'session_id', $seed_session );
	$req->set_param( 'nonce', $good_nonce );
	$resp = $server->dispatch( $req );
	$body = $resp->get_data();
	check( 'valid track dispatch succeeds', 200 === $resp->get_status() && isset( $body['data']['id'] ) );

	$dispatch_id = (int) $body['data']['id'];
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE id = %d", $dispatch_id ), ARRAY_A );
	check( 'dispatched event recorded', is_array( $row ) && Tracker::EVENT_GOAL_PROGRESS === $row['event_type'] );
	check( 'dispatched event carries percentage meta', is_array( $row ) && false !== strpos( (string) $row['meta'], '50' ) );

	// 4.12 The frontend config print (window.faracartTracking).
	ob_start();
	$tracker->output_frontend_config();
	$config_out = ob_get_clean();
	check( 'tracking config printed', false !== strpos( $config_out, 'window.faracartTracking' ) );
	// rest_url() may URL-encode the slashes on non-pretty-permalink installs
	// (rest_route=%2Ffaracart%2Fv1%2Ftrack), so assert on the unencoded
	// path and the route tail.
	check( 'tracking config carries endpoint', false !== strpos( $config_out, 'faracart' ) && false !== strpos( $config_out, 'track' ) );
	check( 'tracking config carries a nonce', false !== strpos( $config_out, 'nonce' ) );
	check( 'tracking config carries the session id', false !== strpos( $config_out, $current_session ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	unset( $_COOKIE[ Session::COOKIE ] );
	$settings->set( 'enabled', $enabled_before ?? true );
}

// ---------------------------------------------------------------------------
// 5. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 5. Rollback verification ==\n";

$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );
check( 'no events persisted after rollback', 0 === $count );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "ANALYTICS TEST FAILED\n" : "ANALYTICS TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );

/**
 * The registered priority of a callback on a hook.
 *
 * @param string   $tag      Hook name.
 * @param callable $callback Registered callback.
 * @return int|false
 */
function hook_priority( $tag, $callback ) {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $tag ] ) ) {
		return false;
	}

	$id = _wp_filter_build_unique_id( $tag, $callback, 10 );

	foreach ( $wp_filter[ $tag ]->callbacks as $priority => $bucket ) {
		if ( isset( $bucket[ $id ] ) ) {
			return $priority;
		}
	}

	return false;
}
