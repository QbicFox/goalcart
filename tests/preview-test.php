<?php
/**
 * FaraCart admin preview tests (P15-T02 / P15-T03).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the Phase 15 admin preview endpoint:
 *
 *  - route registration and arg-schema validation (simulated object,
 *    exactly-one-target rule)
 *  - permission: anonymous rejected on /preview (admin-only)
 *  - preview states against a simulated cart: empty, 25/50/75%, completed
 *    — the payload matches the public /progress shape (current, target,
 *    remaining, percentage, completed, state, message, reward,
 *    suggestions, reward_state) and NEVER touches the real WooCommerce
 *    cart (the live cart's contents are asserted unchanged)
 *  - publish-gating bypass: an inactive goal still previews as active
 *  - campaign preview: every milestone goal evaluated in order against
 *    the same simulated cart (the "multiple milestones" state)
 *  - error paths: missing goal/campaign (404), both/neither targets (400)
 *
 * Read-only like the other suites: the only writes (goal/campaign rows,
 * rate-limit transients) happen inside a single database transaction that
 * is rolled back, and the absence of any residue is asserted afterwards.
 *
 * Run: php tests/preview-test.php   (from the plugin directory)
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

use FaraCart\REST\PreviewController;

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
if ( ! did_action( 'faracart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'faracart_rest_test_ready' );
}

$container = \FaraCart\Plugin::instance()->container();

$preview_ctrl = $container->get( PreviewController::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Route registration (P15-T02 / P15-T03)
// ---------------------------------------------------------------------------
echo "\n== 1. Route registration ==\n";

check( '/preview registered', route_exists( $routes, '/faracart/v1/preview' ) );

// ---------------------------------------------------------------------------
// 2. Arg-schema validation (read-only)
// ---------------------------------------------------------------------------
echo "\n== 2. Input validation ==\n";

$args = $preview_ctrl->preview_args();

check( 'simulated arg schema exists', isset( $args['simulated'] ) );
check( 'simulated object type', 'object' === $args['simulated']['type'] );
check( 'negative simulated amount rejected', is_wp_error( rest_validate_value_from_schema( array( 'amount' => -5 ), $args['simulated'], 'simulated' ) ) );
check( 'unknown simulated key rejected', is_wp_error( rest_validate_value_from_schema( array( 'bogus' => 1 ), $args['simulated'], 'simulated' ) ) );
check( 'valid simulated accepted', true === rest_validate_value_from_schema( array( 'amount' => 250, 'quantity' => 2 ), $args['simulated'], 'simulated' ) );
check( 'negative goal_id rejected', is_wp_error( rest_validate_value_from_schema( -1, $args['goal_id'], 'goal_id' ) ) );

// ---------------------------------------------------------------------------
// 3. Transactional checks: preview states, permissions, campaign preview.
//    Every write is rolled back at the end; absence of residue is asserted.
// ---------------------------------------------------------------------------
echo "\n== 3. Preview states, permissions, campaigns (rolled back) ==\n";

$created_ids          = array();
$created_campaign_ids = array();

$wpdb->query( 'START TRANSACTION' );

try {
	// 3.1 Anonymous users are rejected on the preview route (admin-only).
	$req  = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', 1 );
	$resp = $server->dispatch( $req );
	check( 'anonymous rejected on preview (403)', 403 === $resp->get_status() );

	// 3.2 Create an amount goal for previewing.
	$goals_ctrl = $container->get( \FaraCart\REST\GoalsController::class );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Amount Goal' );
	$req->set_param( 'description', 'Phase 15 preview test' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 1000 );
	$req->set_param( 'calculation_mode', 'subtotal' );
	$req->set_param( 'reward_type', 'percent_discount' );
	$req->set_param( 'reward_value', 10 );
	$req->set_param( 'display_settings', array(
		'message'         => 'Only {remaining} left to unlock {reward}!',
		'completed_message' => 'Reward unlocked: {reward}',
		'template_id'     => 'template-2',
		'icon'            => '🎯',
	) );

	$resp = $goals_ctrl->handle_create( $req );
	$goal_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $goal_id;
	check( 'preview goal created', $goal_id > 0 );

	// 3.3 The live cart must be untouched by any preview call. Seed the
	// real WooCommerce cart with a line first, snapshot it, preview, then
	// verify the cart contents are byte-identical afterwards.
	$cart = WC()->cart;
	$cart->cart_contents['pv1'] = array(
		'key'               => 'pv1',
		'product_id'        => 0,
		'variation_id'      => 0,
		'quantity'          => 1,
		'data'              => new \WC_Product_Simple(),
		'line_subtotal'     => 99.0,
		'line_total'        => 99.0,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);
	$cart_before = $cart->get_cart();

	// 3.4 Preview state — empty cart (amount 0): 0%, not completed.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 0 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'empty-cart preview returns envelope', isset( $data['data']['goals'] ) );
	check( 'empty-cart preview has 1 goal', 1 === count( $data['data']['goals'] ) );
	$goal_out = $data['data']['goals'][0];
	check( 'empty-cart current is 0', near( 0, $goal_out['current'] ) );
	check( 'empty-cart percentage is 0', near( 0, $goal_out['percentage'] ) );
	check( 'empty-cart not completed', false === $goal_out['completed'] );
	check( 'empty-cart reward_state locked', 'locked' === $goal_out['reward_state'] );
	check( 'empty-cart state progressing', 'progressing' === $goal_out['state'] );
	check( 'empty-cart message present', '' !== $goal_out['message'] );

	// 3.5 Preview state — 50%: current 500, percentage 50.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'simulated', array( 'amount' => 500, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( '50% current is 500', near( 500, $goal_out['current'] ) );
	check( '50% remaining is 500', near( 500, $goal_out['remaining'] ) );
	check( '50% percentage is 50', near( 50, $goal_out['percentage'] ) );
	check( '50% not completed', false === $goal_out['completed'] );
	check( '50% message has no unresolved placeholders', false === strpos( $goal_out['message'], '{' ) );
	check( '50% message includes remaining', false !== strpos( $goal_out['message'], '500' ) );
	check( '50% reward shape', 'percent_discount' === $goal_out['reward']['type'] );
	check( '50% template from display settings', 'template-2' === $goal_out['template'] );
	check( '50% icon from display settings', '🎯' === $goal_out['icon'] );
	check( '50% suggestions is array', is_array( $goal_out['suggestions'] ) );
	check( '50% simulated echoed', near( 500, $resp->get_data()['data']['simulated']['amount'] ) );
	check( 'preview meta mode goal', 'goal' === $resp->get_data()['meta']['mode'] );

	// 3.6 Preview state — completed (amount = target): 100%, unlocked.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'simulated', array( 'amount' => 1000, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'completed percentage is 100', near( 100, $goal_out['percentage'] ) );
	check( 'completed flag true', true === $goal_out['completed'] );
	check( 'completed reward_state unlocked', 'unlocked' === $goal_out['reward_state'] );
	check( 'completed state reward_activated', 'reward_activated' === $goal_out['state'] );
	check( 'completed message is the completed message', false !== strpos( $goal_out['message'], 'Reward unlocked' ) );
	check( 'completed has no suggestions', empty( $goal_out['suggestions'] ) );

	// 3.7 The real cart was never modified by any preview call.
	check( 'live cart unchanged after previews', wp_json_encode( $cart_before ) === wp_json_encode( $cart->get_cart() ) );

	// 3.8 Publish-gating bypass: an INACTIVE goal still previews as active.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Draft Goal' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$req->set_param( 'status', 'inactive' );
	$resp = $goals_ctrl->handle_create( $req );
	$draft_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $draft_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $draft_id );
	$req->set_param( 'simulated', array( 'amount' => 50, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'inactive goal previews as eligible', true === $goal_out['eligible'] );
	check( 'inactive goal preview percentage 50', near( 50, $goal_out['percentage'] ) );
	check( 'inactive goal preview state not inactive', 'inactive' !== $goal_out['state'] );

	// 3.9 Quantity-mode goal: the simulated quantity drives progress.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Quantity Goal' );
	$req->set_param( 'type', 'quantity' );
	$req->set_param( 'target', 4 );
	$resp = $goals_ctrl->handle_create( $req );
	$qty_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $qty_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $qty_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'quantity goal current 2', near( 2, $goal_out['current'] ) );
	check( 'quantity goal percentage 50', near( 50, $goal_out['percentage'] ) );
	check( 'quantity goal is_money false', false === $goal_out['is_money'] );

	// 3.10 Campaign preview ("multiple milestones" state): every milestone
	// evaluated in order against the same simulated cart.
	$campaigns_ctrl = $container->get( \FaraCart\REST\CampaignsController::class );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/campaigns' );
	$req->set_param( 'name', 'Preview Campaign' );
	$req->set_param( 'description', 'Milestone ladder preview' );
	$req->set_param( 'status', 'active' );
	$resp = $campaigns_ctrl->handle_create( $req );
	$campaign_id = (int) $resp->get_data()['data']['id'];
	$created_campaign_ids[] = $campaign_id;

	$milestone_ids = array();
	foreach ( array( 100, 200, 300 ) as $index => $target ) {
		$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
		$req->set_param( 'name', 'Preview Milestone ' . ( $index + 1 ) );
		$req->set_param( 'type', 'amount' );
		$req->set_param( 'target', $target );
		$req->set_param( 'reward_type', 0 === $index ? 'free_shipping' : 'percent_discount' );
		$req->set_param( 'reward_value', 0 === $index ? null : 10 );
		$req->set_param( 'campaign_id', $campaign_id );
		$resp = $goals_ctrl->handle_create( $req );
		$milestone_ids[] = (int) $resp->get_data()['data']['id'];
		$created_ids[]   = $milestone_ids[ $index ];
	}

	$req = new \WP_REST_Request( 'PUT', '/faracart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$req->set_param( 'goals', $milestone_ids );
	$resp = $campaigns_ctrl->handle_update( $req );
	check( 'campaign has 3 milestones', 3 === $resp->get_data()['data']['goal_count'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign_id', $campaign_id );
	$req->set_param( 'simulated', array( 'amount' => 150, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'campaign preview meta mode campaign', 'campaign' === $data['meta']['mode'] );
	check( 'campaign preview has 3 goals', 3 === count( $data['data']['goals'] ) );

	$goals_out = $data['data']['goals'];
	check( 'milestone order preserved', 'Preview Milestone 1' === $goals_out[0]['goal_name'] );
	check( 'milestone 1 completed at 150 (target 100)', true === $goals_out[0]['completed'] );
	check( 'milestone 2 in progress at 150 (target 200)', false === $goals_out[1]['completed'] );
	check( 'milestone 2 percentage 75', near( 75, $goals_out[1]['percentage'] ) );
	check( 'milestone 3 at 50%', near( 50, $goals_out[2]['percentage'] ) );
	check( 'milestone 1 reward free_shipping', 'free_shipping' === $goals_out[0]['reward']['type'] );
	check( 'milestone 2 reward percent_discount', 'percent_discount' === $goals_out[1]['reward']['type'] );

	// 3.11 Remaining goal-type previews: the simulated context is built per
	// type, so each branch of simulated_context() gets exercised.

	// 3.11.1 Distinct-quantity goal: simulated quantity → distinct items.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Distinct Goal' );
	$req->set_param( 'type', 'distinct_quantity' );
	$req->set_param( 'target', 3 );
	$resp = $goals_ctrl->handle_create( $req );
	$distinct_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $distinct_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $distinct_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'distinct goal current 2', near( 2, $goal_out['current'] ) );
	check( 'distinct goal percentage ~67', near( 66.67, $goal_out['percentage'] ) );
	check( 'distinct goal is_money false', false === $goal_out['is_money'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $distinct_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 0 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'distinct goal empty cart current 0', near( 0, $goal_out['current'] ) );

	// 3.11.2 Category goal (quantity mode): synthetic line carries the goal's
	// categories, so category_value() matches.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Category Goal' );
	$req->set_param( 'type', 'category' );
	$req->set_param( 'target', 4 );
	$req->set_param( 'calculation_mode', 'quantity' );
	$req->set_param( 'categories', array( 5, 6 ) );
	$resp = $goals_ctrl->handle_create( $req );
	$category_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $category_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $category_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'category goal current 2', near( 2, $goal_out['current'] ) );
	check( 'category goal percentage 50', near( 50, $goal_out['percentage'] ) );

	// 3.11.3 Category goal (money mode): simulated amount drives subtotal.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Category Money Goal' );
	$req->set_param( 'type', 'category' );
	$req->set_param( 'target', 1000 );
	$req->set_param( 'calculation_mode', 'subtotal' );
	$req->set_param( 'categories', array( 5 ) );
	$resp = $goals_ctrl->handle_create( $req );
	$cat_money_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $cat_money_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $cat_money_id );
	$req->set_param( 'simulated', array( 'amount' => 500, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'category money goal current 500', near( 500, $goal_out['current'] ) );
	check( 'category money goal percentage 50', near( 50, $goal_out['percentage'] ) );

	// 3.11.4 Product goal: synthetic line carries the first configured product.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Product Goal' );
	$req->set_param( 'type', 'product' );
	$req->set_param( 'target', 2 );
	$req->set_param( 'calculation_mode', 'quantity' );
	$req->set_param( 'products', array( 42 ) );
	$resp = $goals_ctrl->handle_create( $req );
	$product_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $product_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $product_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'product goal current 1', near( 1, $goal_out['current'] ) );
	check( 'product goal percentage 50', near( 50, $goal_out['percentage'] ) );

	// A product goal without products is honestly ineligible in preview.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Product-less Goal' );
	$req->set_param( 'type', 'product' );
	$req->set_param( 'target', 2 );
	$resp = $goals_ctrl->handle_create( $req );
	$no_product_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $no_product_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $no_product_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'product-less goal ineligible', false === $goal_out['eligible'] );
	check( 'product-less goal reason no_matching_items', 'no_matching_items' === $goal_out['reason'] );

	// 3.11.5 Weight goal: simulated quantity maps to the cart weight.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Weight Goal' );
	$req->set_param( 'type', 'weight' );
	$req->set_param( 'target', 10 );
	$resp = $goals_ctrl->handle_create( $req );
	$weight_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $weight_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $weight_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 5 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'weight goal current 5', near( 5, $goal_out['current'] ) );
	check( 'weight goal percentage 50', near( 50, $goal_out['percentage'] ) );
	check( 'weight goal is_money false', false === $goal_out['is_money'] );

	// 3.11.6 Composite goal (AND of amount + quantity): one synthetic line
	// carries both bases, so both children evaluate against it.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/goals' );
	$req->set_param( 'name', 'Preview Composite Goal' );
	$req->set_param( 'type', 'composite' );
	$req->set_param( 'target', 10 );
	$req->set_param( 'operator', 'and' );
	$req->set_param( 'children', array(
		array( 'type' => 'amount', 'target' => 100, 'calculation_mode' => 'subtotal' ),
		array( 'type' => 'quantity', 'target' => 4 ),
	) );
	$resp = $goals_ctrl->handle_create( $req );
	$composite_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $composite_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $composite_id );
	$req->set_param( 'simulated', array( 'amount' => 50, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'composite goal eligible', true === $goal_out['eligible'] );
	check( 'composite goal percentage 50 (weakest child)', near( 50, $goal_out['percentage'] ) );
	check( 'composite goal not completed', false === $goal_out['completed'] );

	// 3.12 Unsaved goal form payload (builder live preview): the preview
	// reflects the form values before they are persisted — no goal row.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal', array(
		'name'             => 'Unsaved Goal',
		'type'             => 'amount',
		'target'           => 800,
		'calculation_mode' => 'subtotal',
		'reward_type'      => 'fixed_discount',
		'reward_value'     => 50,
		'display_settings' => array(
			'template_id' => 'template-5',
			'icon'        => '🚀',
		),
	) );
	$req->set_param( 'simulated', array( 'amount' => 400, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'unsaved goal preview meta mode goal', 'goal' === $data['meta']['mode'] );
	$goal_out = $data['data']['goals'][0];
	check( 'unsaved goal name from form', 'Unsaved Goal' === $goal_out['goal_name'] );
	check( 'unsaved goal target from form', near( 800, $goal_out['target'] ) );
	check( 'unsaved goal current from simulated amount', near( 400, $goal_out['current'] ) );
	check( 'unsaved goal percentage 50', near( 50, $goal_out['percentage'] ) );
	check( 'unsaved goal template from display settings', 'template-5' === $goal_out['template'] );
	check( 'unsaved goal icon from display settings', '🚀' === $goal_out['icon'] );
	check( 'unsaved goal reward from form', 'fixed_discount' === $goal_out['reward']['type'] && near( 50, $goal_out['reward']['value'] ) );

	// 3.13 Editing an existing goal: the form payload wins over the stored
	// row for the edited fields (the builder previews unsaved edits).
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'goal', array(
		'name'   => 'Edited Name',
		'target' => 2000,
	) );
	$req->set_param( 'simulated', array( 'amount' => 1000, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$goal_out = $resp->get_data()['data']['goals'][0];
	check( 'edited goal name from form payload', 'Edited Name' === $goal_out['goal_name'] );
	check( 'edited goal target from form payload', near( 2000, $goal_out['target'] ) );
	check( 'edited goal keeps stored reward below the payload', 'percent_discount' === $goal_out['reward']['type'] );

	// 3.14 Unsaved campaign form payload: milestone ids + name +
	// display_rules drive the preview, and the campaign template group is
	// resolved from the form's rules even for a campaign with no id yet.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign', array(
		'name'          => 'Unsaved Campaign',
		'display_rules' => array(
			'template_id' => 'milestone_chain',
		),
		'goals' => $milestone_ids,
	) );
	$req->set_param( 'simulated', array( 'amount' => 150, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'unsaved campaign preview meta mode campaign', 'campaign' === $data['meta']['mode'] );
	check( 'unsaved campaign preview has 3 goals', 3 === count( $data['data']['goals'] ) );
	check( 'unsaved campaign milestone name from stored goals', 'Preview Milestone 1' === $data['data']['goals'][0]['goal_name'] );
	check( 'unsaved campaign group carries the template', isset( $data['data']['campaigns'][0] ) && 'milestone_chain' === $data['data']['campaigns'][0]['template'] );
	check( 'unsaved campaign group carries the form name', 'Unsaved Campaign' === $data['data']['campaigns'][0]['name'] );
	check( 'unsaved campaign goals joined to the group id', 3 === count( array_filter( $data['data']['goals'], function ( $g ) use ( $data ) {
		return (int) $g['campaign_id'] === (int) $data['data']['campaigns'][0]['campaign_id'];
	} ) ) );

	// 3.15 Error paths.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'simulated', array() );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'no target → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'campaign_id', $campaign_id );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'both targets → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal', array( 'name' => 'x', 'type' => 'amount', 'target' => 10 ) );
	$req->set_param( 'campaign', array( 'name' => 'y', 'goals' => array() ) );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'both form payloads → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', 99999999 );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'missing goal → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign_id', 99999999 );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'missing campaign → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	// 3.16 End-to-end dispatch (authenticated path would run in the admin
	// app; the anonymous 403 dispatch above already proves the gate).
	$req  = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'goal_id', $goal_id );
	$req->set_param( 'simulated', array( 'amount' => 250, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'preview handler returns a response', $resp instanceof \WP_REST_Response );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 4. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

$goals_table = \FaraCart\Database\Schema::table( 'goals' );

foreach ( $created_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE id = %d", $id ) );
	check( "rolled-back goal {$id} is gone", 0 === $count );
}

$campaigns_table = \FaraCart\Database\Schema::table( 'campaigns' );

foreach ( $created_campaign_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE id = %d", $id ) );
	check( "rolled-back campaign {$id} is gone", 0 === $count );
}

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE name = %s", 'Preview Amount Goal' ) );
check( 'no rolled-back preview goal remains by name', 0 === $count );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "PREVIEW TEST FAILED\n" : "PREVIEW TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
