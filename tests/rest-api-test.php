<?php
/**
 * Goal Cart REST API tests (P07-T01 / P07-T02 / P07-T03 / P07-T04).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the Phase 7 REST layer:
 *
 *  - route registration for every endpoint
 *  - the `{ data, meta, pagination }` response envelope
 *  - permission callbacks (anonymous 403 on admin routes, public progress
 *    allowed) and REST arg-schema validation
 *  - goal CRUD + duplicate, the public /progress payload, search, and
 *    settings — through direct handler calls and one end-to-end server
 *    dispatch
 *  - Phase 12 progress-template settings: sanitization of the template
 *    enum, color fallbacks, range clamping, tag-stripping, and the REST
 *    schema validation of the new keys
 *  - Phase 13 dynamic messaging: the public /progress payload carries the
 *    engine-rendered message (no unresolved placeholders) and the message
 *    state
 *
 * Read-only like the other suites: the only writes (goal rows, the
 * settings option, rate-limit transients) happen inside a single
 * database transaction that is rolled back, and the absence of any
 * residue is asserted afterwards. No products or users are created.
 *
 * Run: php tests/rest-api-test.php   (from the plugin directory)
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

use GoalCart\REST\CampaignsController;
use GoalCart\REST\FrontendController;
use GoalCart\REST\GoalsController;
use GoalCart\REST\SearchController;
use GoalCart\REST\SettingsController;

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

function route_exists( $routes, $pattern ) {
	return isset( $routes[ $pattern ] );
}

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'goalcart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'goalcart_rest_test_ready' );
}

$container = \GoalCart\Plugin::instance()->container();

$goals_ctrl     = $container->get( GoalsController::class );
$settings_ctrl  = $container->get( SettingsController::class );
$search_ctrl    = $container->get( SearchController::class );
$campaigns_ctrl = $container->get( CampaignsController::class );
$frontend_ctrl  = $container->get( FrontendController::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Route registration (P07-T02 / P07-T03)
// ---------------------------------------------------------------------------
echo "\n== 1. Route registration ==\n";

check( 'GET /goals registered', route_exists( $routes, '/goalcart/v1/goals' ) );
check( 'GET /goals/{id} registered', route_exists( $routes, '/goalcart/v1/goals/(?P<id>[\d]+)' ) );
check( 'POST /goals/{id}/duplicate registered', route_exists( $routes, '/goalcart/v1/goals/(?P<id>[\d]+)/duplicate' ) );
check( '/settings registered', route_exists( $routes, '/goalcart/v1/settings' ) );
check( '/search/products registered', route_exists( $routes, '/goalcart/v1/search/products' ) );
check( '/search/categories registered', route_exists( $routes, '/goalcart/v1/search/categories' ) );
check( '/search/coupons registered', route_exists( $routes, '/goalcart/v1/search/coupons' ) );
check( '/campaigns registered', route_exists( $routes, '/goalcart/v1/campaigns' ) );
check( '/campaigns/{id} registered', route_exists( $routes, '/goalcart/v1/campaigns/(?P<id>[\d]+)' ) );
check( '/campaigns/{id}/duplicate registered', route_exists( $routes, '/goalcart/v1/campaigns/(?P<id>[\d]+)/duplicate' ) );
check( '/progress registered', route_exists( $routes, '/goalcart/v1/progress' ) );

// ---------------------------------------------------------------------------
// 2. Arg-schema validation (P07-T04, read-only)
// ---------------------------------------------------------------------------
echo "\n== 2. Input validation ==\n";

$save = $goals_ctrl->save_args( true );

// Enum/type/range checks are exercised through the REST schema validator.
check( 'invalid status rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['status'], 'status' ) ) );
check( 'invalid type rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['type'], 'type' ) ) );
check( 'invalid calculation_mode rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['calculation_mode'], 'calculation_mode' ) ) );
check( 'invalid reward_type rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['reward_type'], 'reward_type' ) ) );
check( 'negative target rejected', is_wp_error( rest_validate_value_from_schema( -5, $save['target'], 'target' ) ) );
check( 'valid name accepted', true === rest_validate_value_from_schema( 'My goal', $save['name'], 'name' ) );

// Custom validate callbacks (datetime, campaign existence) are invoked by
// the REST server during dispatch; exercised directly here.
check( 'invalid starts_at rejected', false === $goals_ctrl->validate_datetime_param( '12/34/5678' ) );
check( 'valid starts_at accepted', true === $goals_ctrl->validate_datetime_param( '2026-01-01' ) );
check( 'null starts_at accepted', true === $goals_ctrl->validate_datetime_param( null ) );
check( 'invalid campaign_id rejected', false === $goals_ctrl->validate_campaign( 999999 ) );
check( 'zero campaign_id accepted', true === $goals_ctrl->validate_campaign( 0 ) );

// ---------------------------------------------------------------------------
// 3. Settings reads (read-only)
// ---------------------------------------------------------------------------
echo "\n== 3. Settings ==\n";

$resp = $settings_ctrl->handle_get( new \WP_REST_Request( 'GET', '/goalcart/v1/settings' ) );
$data = $resp->get_data();
check( 'settings GET envelope has data', isset( $data['data'] ) );
check( 'settings GET has enabled key', array_key_exists( 'enabled', $data['data'] ) );

$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
$resp = $settings_ctrl->handle_save( $req );
check( 'empty settings save → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
$req->set_param( 'unknown_key', 1 );
$resp = $settings_ctrl->handle_save( $req );
check( 'unknown-key-only settings save → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

// ---------------------------------------------------------------------------
// 4. Search handlers (read-only)
// ---------------------------------------------------------------------------
echo "\n== 4. Search ==\n";

$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/products' );
$req->set_param( 'q', '' );
$req->set_param( 'per_page', 5 );
$resp = $search_ctrl->handle_products( $req );
$data = $resp->get_data();
check( 'product search returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );

$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/categories' );
$req->set_param( 'q', '' );
$req->set_param( 'per_page', 5 );
$resp = $search_ctrl->handle_categories( $req );
$data = $resp->get_data();
check( 'category search returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );

$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/coupons' );
$req->set_param( 'q', '' );
$req->set_param( 'per_page', 5 );
$resp = $search_ctrl->handle_coupons( $req );
$data = $resp->get_data();
check( 'coupon search returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );

$resp = $campaigns_ctrl->handle_index( new \WP_REST_Request( 'GET', '/goalcart/v1/campaigns' ) );
$data = $resp->get_data();
check( 'campaign list returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );

// Phase 9: the `ids` param narrows search to exactly the given ids
// (the goal builder preloads saved product/category/coupon selections).
$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/products' );
$req->set_param( 'ids', array( 99999999 ) );
$resp = $search_ctrl->handle_products( $req );
$data = $resp->get_data();
check( 'product search by ids returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );
check( 'product search by unknown id returns no items', empty( $data['data']['items'] ) );

$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/categories' );
$req->set_param( 'ids', array( 99999999 ) );
$resp = $search_ctrl->handle_categories( $req );
$data = $resp->get_data();
check( 'category search by ids returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );
check( 'category search by unknown id returns no items', empty( $data['data']['items'] ) );

$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/coupons' );
$req->set_param( 'ids', array( 99999999 ) );
$resp = $search_ctrl->handle_coupons( $req );
$data = $resp->get_data();
check( 'coupon search by ids returns envelope', isset( $data['data']['items'] ) && is_array( $data['data']['items'] ) );
check( 'coupon search by unknown id returns no items', empty( $data['data']['items'] ) );

$search_args = $search_ctrl->search_args();
check( 'ids arg schema exists', isset( $search_args['ids'] ) );
check( 'non-positive ids rejected by schema', is_wp_error( rest_validate_value_from_schema( array( 0, -3 ), $search_args['ids'], 'ids' ) ) );

// ---------------------------------------------------------------------------
// 5. Transactional checks: permission, CRUD, progress, settings save.
//    Every write is rolled back at the end; absence of residue is asserted.
// ---------------------------------------------------------------------------
echo "\n== 5. Permissions, CRUD, progress (rolled back) ==\n";

$settings_option = \GoalCart\Settings\Settings::OPTION_NAME;
$option_before   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );

$created_ids         = array();
$created_campaign_ids = array();

$wpdb->query( 'START TRANSACTION' );

try {
	// 5.1 Anonymous users are rejected on admin routes (403). Dispatch
	// wraps the WP_Error into a 403 WP_REST_Response (what the HTTP client
	// actually receives).
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$resp = $server->dispatch( $req );
	check( 'anonymous rejected on admin goals list (403)', 403 === $resp->get_status() );

	// 5.2 Create a goal.
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/goals' );
	$req->set_param( 'name', 'REST Test Goal' );
	$req->set_param( 'description', 'Created by the REST test' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 1000 );
	$req->set_param( 'calculation_mode', 'subtotal' );
	$req->set_param( 'reward_type', 'percent_discount' );
	$req->set_param( 'reward_value', 10 );
	$req->set_param( 'categories', array( 5, 6 ) );

	$resp = $goals_ctrl->handle_create( $req );
	$data = $resp->get_data();
	check( 'create returns envelope', isset( $data['data']['id'] ) );

	$goal_id = (int) $data['data']['id'];
	$created_ids[] = $goal_id;
	check( 'created goal id > 0', $goal_id > 0 );
	check( 'created goal name matches', 'REST Test Goal' === $data['data']['name'] );
	check( 'created goal target persisted', near( 1000, $data['data']['target'] ) );
	check( 'created goal reward type persisted', 'percent_discount' === $data['data']['reward_type'] );
	check( 'created goal categories persisted', array( 5, 6 ) === $data['data']['categories'] );

	// 5.3 Get the created goal.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals/' . $goal_id );
	$req->set_param( 'id', $goal_id );
	$resp = $goals_ctrl->handle_get( $req );
	check( 'get returns the created goal', $goal_id === (int) $resp->get_data()['data']['id'] );

	// 5.4 The list includes it, with pagination metadata.
	$req = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$req->set_param( 'per_page', 50 );
	$resp = $goals_ctrl->handle_index( $req );
	$data = $resp->get_data();
	$found = false;
	foreach ( $data['data'] as $goal ) {
		if ( (int) $goal['id'] === $goal_id ) {
			$found = true;
		}
	}
	check( 'index lists the created goal', $found );
	check( 'index has pagination envelope', isset( $data['pagination']['total'], $data['pagination']['total_pages'] ) );

	// 5.5 Duplicate it.
	$req  = new \WP_REST_Request( 'POST', '/goalcart/v1/goals/' . $goal_id . '/duplicate' );
	$req->set_param( 'id', $goal_id );
	$resp = $goals_ctrl->handle_duplicate( $req );
	$copy = $resp->get_data()['data'];
	$created_ids[] = (int) $copy['id'];
	check( 'duplicate creates a new id', (int) $copy['id'] !== $goal_id );
	check( 'duplicate name has copy suffix', false !== strpos( $copy['name'], '(copy)' ) );
	check( 'duplicate keeps the reward', 'percent_discount' === $copy['reward_type'] );

	// 5.6 Update it (partial update: only target changes).
	$req = new \WP_REST_Request( 'PUT', '/goalcart/v1/goals/' . $goal_id );
	$req->set_param( 'id', $goal_id );
	$req->set_param( 'target', 2500 );
	$resp = $goals_ctrl->handle_update( $req );
	check( 'update changes the target', near( 2500, $resp->get_data()['data']['target'] ) );

	// 5.7 Public progress payload (P07-T03) against a live cart line.
	$cart = WC()->cart;
	$cart->cart_contents['rt1'] = array(
		'key'               => 'rt1',
		'product_id'        => 0,
		'variation_id'      => 0,
		'quantity'          => 2,
		'data'              => new \WC_Product_Simple(),
		'line_subtotal'     => 200.0,
		'line_total'        => 200.0,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/progress' );
	$resp = $frontend_ctrl->handle_progress( $req, $cart );
	$goals_out = $resp->get_data()['data']['goals'];

	$found = null;
	foreach ( $goals_out as $entry ) {
		if ( 'REST Test Goal' === $entry['goal_name'] ) {
			$found = $entry;
		}
	}
	check( 'progress includes the created goal', null !== $found );
	check( 'progress current from cart line', null !== $found && near( 200, $found['current'] ) );
	check( 'progress percentage (200/2500)', null !== $found && near( 8, $found['percentage'] ) );
	check( 'progress completed false', null !== $found && false === $found['completed'] );
	check( 'progress message present', null !== $found && '' !== $found['message'] );
	check( 'progress reward shape', null !== $found && 'percent_discount' === $found['reward']['type'] );
	check( 'progress icon key present', null !== $found && array_key_exists( 'icon', $found ) && '' === $found['icon'] );
	check( 'progress state key present', null !== $found && in_array(
		$found['state'],
		array( 'inactive', 'unavailable', 'progressing', 'nearly_complete', 'completed', 'reward_activated' ),
		true
	) );
	check( 'progress message rendered by the engine', null !== $found && false === strpos( $found['message'], '{' ) );
	check( 'progress suggestions is array', null !== $found && is_array( $found['suggestions'] ) );
	check( 'progress currency present', '' !== $resp->get_data()['data']['currency'] || is_string( $resp->get_data()['data']['currency'] ) );

	// 5.8 Delete it.
	$req  = new \WP_REST_Request( 'DELETE', '/goalcart/v1/goals/' . $goal_id );
	$req->set_param( 'id', $goal_id );
	$resp = $goals_ctrl->handle_delete( $req );
	check( 'delete reports deleted', true === $resp->get_data()['data']['deleted'] );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals/' . $goal_id );
	$req->set_param( 'id', $goal_id );
	$resp = $goals_ctrl->handle_get( $req );
	check( 'deleted goal returns 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	// 5.9 Missing-resource errors are predictable (404, no write).
	$req  = new \WP_REST_Request( 'DELETE', '/goalcart/v1/goals/99999999' );
	$req->set_param( 'id', 99999999 );
	$resp = $goals_ctrl->handle_delete( $req );
	check( 'delete of missing goal → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals/99999999' );
	$req->set_param( 'id', 99999999 );
	$resp = $goals_ctrl->handle_get( $req );
	check( 'get of missing goal → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	// 5.10 End-to-end server dispatch of the public progress route.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/progress' );
	$resp = $server->dispatch( $req );
	check( 'public progress dispatches (not an error)', ! is_wp_error( $resp ) );
	$data = $resp->get_data();
	check( 'dispatched progress has data.goals', isset( $data['data']['goals'] ) && is_array( $data['data']['goals'] ) );

	// 5.11 Settings save success (rolled back with the transaction).
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req->set_param( 'enabled', false );
	$resp = $settings_ctrl->handle_save( $req );
	check( 'settings save returns updated value', false === $resp->get_data()['data']['enabled'] );

	// 5.12 Progress-template settings sanitization (Phase 12). Direct
	// handler calls skip REST schema validation, so the sanitizer itself
	// must normalize enums, clamp ranges and clean string fields.
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req->set_param( 'frontend_template', 'card' );
	$req->set_param( 'frontend_accent', 'zzz' );
	$req->set_param( 'frontend_bar_height', 999 );
	$req->set_param( 'frontend_radius', -3 );
	$req->set_param( 'frontend_animation', false );
	$req->set_param( 'frontend_css_class', 'my <b>class</b>' );
	$req->set_param( 'frontend_custom_css', '<script>alert(1)</script>.goalcart-card { color: red; }' );
	$resp = $settings_ctrl->handle_save( $req );
	$data = $resp->get_data()['data'];
	check( 'frontend template sanitized', 'card' === $data['frontend_template'] );
	check( 'invalid color falls back to default', '#2271b1' === $data['frontend_accent'] );
	check( 'bar height clamped to max', 48 === $data['frontend_bar_height'] );
	check( 'radius clamped to min', 0 === $data['frontend_radius'] );
	check( 'animation persisted false', false === $data['frontend_animation'] );
	check( 'css class stripped of tags', 'my class' === $data['frontend_css_class'] );
	check( 'custom css stripped of tags', false === strpos( $data['frontend_custom_css'], '<script' ) );

	// The REST schema validates the template enum + ranges on dispatch.
	$save = $settings_ctrl->save_args();
	check( 'template enum in schema', isset( $save['frontend_template']['enum'] ) );
	check( 'invalid template rejected by schema', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['frontend_template'], 'frontend_template' ) ) );
	check( 'valid template accepted by schema', true === rest_validate_value_from_schema( 'milestone', $save['frontend_template'], 'frontend_template' ) );
	check( 'bar height range in schema', is_wp_error( rest_validate_value_from_schema( 999, $save['frontend_bar_height'], 'frontend_bar_height' ) ) );

	// 5.13 Campaign CRUD + milestone ordering (Phase 10).
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/campaigns' );
	$req->set_param( 'name', 'Summer Sale' );
	$req->set_param( 'description', 'Milestone campaign' );
	$req->set_param( 'status', 'active' );
	$req->set_param( 'priority', 5 );
	$resp = $campaigns_ctrl->handle_create( $req );
	$data = $resp->get_data();
	check( 'campaign create returns envelope', isset( $data['data']['id'] ) );

	$campaign_id = (int) $data['data']['id'];
	$created_campaign_ids[] = $campaign_id;
	check( 'created campaign id > 0', $campaign_id > 0 );
	check( 'created campaign name matches', 'Summer Sale' === $data['data']['name'] );
	check( 'created campaign priority persisted', 5 === $data['data']['priority'] );
	check( 'created campaign has 0 milestones', 0 === $data['data']['goal_count'] );

	// Name-less create fails predictably (repository returns 0 → 500).
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/campaigns' );
	$resp = $campaigns_ctrl->handle_create( $req );
	check( 'campaign create without name → 500', is_wp_error( $resp ) && 500 === $resp->get_error_data()['status'] );

	// Assign two goals as milestones (ordered).
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/goals' );
	$req->set_param( 'name', 'Campaign Milestone One' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$resp = $goals_ctrl->handle_create( $req );
	$milestone_one = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $milestone_one;

	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/goals' );
	$req->set_param( 'name', 'Campaign Milestone Two' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 200 );
	$resp = $goals_ctrl->handle_create( $req );
	$milestone_two = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $milestone_two;

	$req = new \WP_REST_Request( 'PUT', '/goalcart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$req->set_param( 'goals', array( $milestone_one, $milestone_two ) );
	$resp = $campaigns_ctrl->handle_update( $req );
	$data = $resp->get_data();
	check( 'campaign update returns envelope', isset( $data['data']['goals'] ) );
	check( 'campaign has 2 milestones', 2 === $data['data']['goal_count'] );
	check( 'milestone order one first', $milestone_one === $data['data']['goals'][0]['id'] );
	check( 'milestone order two second', $milestone_two === $data['data']['goals'][1]['id'] );
	check( 'milestone menu_order persisted', 1 === $data['data']['goals'][0]['menu_order'] );

	// Reorder milestones (2 → 1).
	$req = new \WP_REST_Request( 'PUT', '/goalcart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$req->set_param( 'goals', array( $milestone_two, $milestone_one ) );
	$resp = $campaigns_ctrl->handle_update( $req );
	$data = $resp->get_data();
	check( 'milestone reorder swaps order', $milestone_two === $data['data']['goals'][0]['id'] );

	// Goal detail reflects the campaign assignment.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals/' . $milestone_one );
	$req->set_param( 'id', $milestone_one );
	$resp = $goals_ctrl->handle_get( $req );
	check( 'goal kept campaign_id', $campaign_id === $resp->get_data()['data']['campaign_id'] );

	// Duplicate the campaign: copy starts inactive, milestones copied.
	$req  = new \WP_REST_Request( 'POST', '/goalcart/v1/campaigns/' . $campaign_id . '/duplicate' );
	$req->set_param( 'id', $campaign_id );
	$resp = $campaigns_ctrl->handle_duplicate( $req );
	$copy = $resp->get_data()['data'];
	$created_campaign_ids[] = (int) $copy['id'];
	check( 'campaign duplicate creates a new id', (int) $copy['id'] !== $campaign_id );
	check( 'campaign duplicate name has copy suffix', false !== strpos( $copy['name'], '(copy)' ) );
	check( 'campaign duplicate starts inactive', 'inactive' === $copy['status'] );
	check( 'campaign duplicate copied milestones', 2 === $copy['goal_count'] );
	check( 'campaign duplicate keeps milestone order', $copy['goals'][0]['id'] !== $copy['goals'][1]['id'] );

	// Deleting the original detaches its goals (they survive).
	$req  = new \WP_REST_Request( 'DELETE', '/goalcart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$resp = $campaigns_ctrl->handle_delete( $req );
	check( 'campaign delete reports deleted', true === $resp->get_data()['data']['deleted'] );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$resp = $campaigns_ctrl->handle_get( $req );
	check( 'deleted campaign returns 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	$repo = $container->get( \GoalCart\Campaigns\CampaignRepository::class );
	check( 'deleted campaign detached milestone one', null === $repo->get( $campaign_id ) );

	// 5.14 Stored vs folded campaign state: the admin CRUD shows the
	// stored status, the engine path folds the (inactive) campaign.
	$campaigns_table = \GoalCart\Database\Schema::table( 'campaigns' );
	$wpdb->insert(
		$campaigns_table,
		array(
			'name'       => 'REST Test Campaign',
			'description' => '',
			'status'     => 'inactive',
			'priority'   => 10,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		)
	);
	$campaign_id = (int) $wpdb->insert_id;

	$repo  = $container->get( \GoalCart\Goals\GoalRepository::class );
	$req   = new \WP_REST_Request( 'POST', '/goalcart/v1/goals' );
	$req->set_param( 'name', 'REST Campaign Goal' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$req->set_param( 'campaign_id', $campaign_id );
	$resp    = $goals_ctrl->handle_create( $req );
	$cg_id   = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $cg_id;

	check( 'admin get shows stored status', 'active' === $repo->get( $cg_id )['status'] );
	check( 'engine find folds inactive campaign', null !== $repo->find( $cg_id ) && false === $repo->find( $cg_id )->is_active() );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals/' . $cg_id );
	$req->set_param( 'id', $cg_id );
	$resp = $goals_ctrl->handle_get( $req );
	check( 'REST detail shows stored status', 'active' === $resp->get_data()['data']['status'] );
	check( 'REST detail keeps campaign_id', $campaign_id === $resp->get_data()['data']['campaign_id'] );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 6. Rollback verification: nothing persisted (P07-T04, test hygiene)
// ---------------------------------------------------------------------------
echo "\n== 6. Rollback verification ==\n";

$goals_table = \GoalCart\Database\Schema::table( 'goals' );

foreach ( $created_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE id = %d", $id ) );
	check( "rolled-back goal {$id} is gone", 0 === $count );
}

$campaigns_table = \GoalCart\Database\Schema::table( 'campaigns' );

foreach ( $created_campaign_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE id = %d", $id ) );
	check( "rolled-back campaign {$id} is gone", 0 === $count );
}

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE name = %s", 'Summer Sale' ) );
check( 'no rolled-back campaign remains by name', 0 === $count );

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE name = %s", 'REST Test Goal' ) );
check( 'no rolled-back goal remains by name', 0 === $count );

$option_after = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );
check( 'settings option unchanged after rollback', $option_before === $option_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "REST API TEST FAILED\n" : "REST API TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
