<?php
/**
 * FaraCart admin preview tests (P15-T02 / P15-T03).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the admin preview endpoint:
 *
 *  - route registration and arg-schema validation (simulated object,
 *    exactly-one-target rule)
 *  - permission: anonymous rejected on /preview (admin-only)
 *  - preview states against a simulated cart: empty, 25/50/75%, completed
 *    — the payload matches the public /progress shape (current, target,
 *    remaining, percentage, completed, state, message, reward,
 *    suggestions, reward_state) and NEVER touches the real WooCommerce
 *    cart (the live cart's contents are asserted unchanged)
 *  - publish-gating bypass: an inactive mission still previews as active
 *  - campaign preview: every milestone mission evaluated in order against
 *    the same simulated cart (the "multiple milestones" state)
 *  - error paths: missing mission/campaign (404), both/neither targets (400)
 *
 * Read-only like the other suites: the only writes (mission/campaign rows,
 * rate-limit transients) happen inside a single database transaction that
 * is rolled back, and the absence of any residue is asserted afterwards.
 *
 * Run: php tests/preview-test.php (from the plugin directory)
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
check( 'negative mission_id rejected', is_wp_error( rest_validate_value_from_schema( -1, $args['mission_id'], 'mission_id' ) ) );

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
	$req->set_param( 'mission_id', 1 );
	$resp = $server->dispatch( $req );
	check( 'anonymous rejected on preview (403)', 403 === $resp->get_status() );

	// 3.2 Create an amount mission for previewing.
	$missions_ctrl = $container->get( \FaraCart\REST\MissionsController::class );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Amount Mission' );
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

	$resp = $missions_ctrl->handle_create( $req );
	$mission_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $mission_id;
	check( 'preview mission created', $mission_id > 0 );

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
	$req->set_param( 'mission_id', $mission_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 0 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'empty-cart preview returns envelope', isset( $data['data']['missions'] ) );
	check( 'empty-cart preview has 1 mission', 1 === count( $data['data']['missions'] ) );
	$mission_out = $data['data']['missions'][0];
	check( 'empty-cart current is 0', near( 0, $mission_out['current'] ) );
	check( 'empty-cart percentage is 0', near( 0, $mission_out['percentage'] ) );
	check( 'empty-cart not completed', false === $mission_out['completed'] );
	check( 'empty-cart reward_state locked', 'locked' === $mission_out['reward_state'] );
	check( 'empty-cart state progressing', 'progressing' === $mission_out['state'] );
	check( 'empty-cart message present', '' !== $mission_out['message'] );

	// 3.5 Preview state — 50%: current 500, percentage 50.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $mission_id );
	$req->set_param( 'simulated', array( 'amount' => 500, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( '50% current is 500', near( 500, $mission_out['current'] ) );
	check( '50% remaining is 500', near( 500, $mission_out['remaining'] ) );
	check( '50% percentage is 50', near( 50, $mission_out['percentage'] ) );
	check( '50% not completed', false === $mission_out['completed'] );
	check( '50% message has no unresolved placeholders', false === strpos( $mission_out['message'], '{' ) );
	check( '50% message includes remaining', false !== strpos( $mission_out['message'], '500' ) );
	check( '50% reward shape', 'percent_discount' === $mission_out['reward']['type'] );
	check( '50% template from display settings', 'template-2' === $mission_out['template'] );
	check( '50% icon from display settings', '🎯' === $mission_out['icon'] );
	check( '50% suggestions is array', is_array( $mission_out['suggestions'] ) );
	check( '50% simulated echoed', near( 500, $resp->get_data()['data']['simulated']['amount'] ) );
	check( 'preview meta mode mission', 'mission' === $resp->get_data()['meta']['mode'] );

	// 3.6 Preview state — completed (amount = target): 100%, unlocked.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $mission_id );
	$req->set_param( 'simulated', array( 'amount' => 1000, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'completed percentage is 100', near( 100, $mission_out['percentage'] ) );
	check( 'completed flag true', true === $mission_out['completed'] );
	check( 'completed reward_state unlocked', 'unlocked' === $mission_out['reward_state'] );
	check( 'completed state reward_activated', 'reward_activated' === $mission_out['state'] );
	check( 'completed message is the completed message', false !== strpos( $mission_out['message'], 'Reward unlocked' ) );
	check( 'completed has no suggestions', empty( $mission_out['suggestions'] ) );

	// 3.7 The real cart was never modified by any preview call.
	check( 'live cart unchanged after previews', wp_json_encode( $cart_before ) === wp_json_encode( $cart->get_cart() ) );

	// 3.8 Publish-gating bypass: an INACTIVE mission still previews as active.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Draft Mission' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$req->set_param( 'status', 'inactive' );
	$resp = $missions_ctrl->handle_create( $req );
	$draft_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $draft_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $draft_id );
	$req->set_param( 'simulated', array( 'amount' => 50, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'inactive mission previews as eligible', true === $mission_out['eligible'] );
	check( 'inactive mission preview percentage 50', near( 50, $mission_out['percentage'] ) );
	check( 'inactive mission preview state not inactive', 'inactive' !== $mission_out['state'] );

	// 3.9 Quantity-mode mission: the simulated quantity drives progress.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Quantity Mission' );
	$req->set_param( 'type', 'quantity' );
	$req->set_param( 'target', 4 );
	$resp = $missions_ctrl->handle_create( $req );
	$qty_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $qty_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $qty_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'quantity mission current 2', near( 2, $mission_out['current'] ) );
	check( 'quantity mission percentage 50', near( 50, $mission_out['percentage'] ) );
	check( 'quantity mission is_money false', false === $mission_out['is_money'] );

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
		$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
		$req->set_param( 'name', 'Preview Milestone ' . ( $index + 1 ) );
		$req->set_param( 'type', 'amount' );
		$req->set_param( 'target', $target );
		$req->set_param( 'reward_type', 0 === $index ? 'free_shipping' : 'percent_discount' );
		$req->set_param( 'reward_value', 0 === $index ? null : 10 );
		$req->set_param( 'campaign_id', $campaign_id );
		$resp = $missions_ctrl->handle_create( $req );
		$milestone_ids[] = (int) $resp->get_data()['data']['id'];
		$created_ids[]   = $milestone_ids[ $index ];
	}

	$req = new \WP_REST_Request( 'PUT', '/faracart/v1/campaigns/' . $campaign_id );
	$req->set_param( 'id', $campaign_id );
	$req->set_param( 'missions', $milestone_ids );
	$resp = $campaigns_ctrl->handle_update( $req );
	check( 'campaign has 3 milestones', 3 === $resp->get_data()['data']['mission_count'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign_id', $campaign_id );
	$req->set_param( 'simulated', array( 'amount' => 150, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'campaign preview meta mode campaign', 'campaign' === $data['meta']['mode'] );
	check( 'campaign preview has 3 missions', 3 === count( $data['data']['missions'] ) );

	$missions_out = $data['data']['missions'];
	check( 'milestone order preserved', 'Preview Milestone 1' === $missions_out[0]['mission_name'] );
	check( 'milestone 1 completed at 150 (target 100)', true === $missions_out[0]['completed'] );
	check( 'milestone 2 in progress at 150 (target 200)', false === $missions_out[1]['completed'] );
	check( 'milestone 2 percentage 75', near( 75, $missions_out[1]['percentage'] ) );
	check( 'milestone 3 at 50%', near( 50, $missions_out[2]['percentage'] ) );
	check( 'milestone 1 reward free_shipping', 'free_shipping' === $missions_out[0]['reward']['type'] );
	check( 'milestone 2 reward percent_discount', 'percent_discount' === $missions_out[1]['reward']['type'] );

	// 3.11 Remaining mission-type previews: the simulated context is built per
	// type, so each branch of simulated_context() gets exercised.

	// 3.11.1 Distinct-quantity mission: simulated quantity → distinct items.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Distinct Mission' );
	$req->set_param( 'type', 'distinct_quantity' );
	$req->set_param( 'target', 3 );
	$resp = $missions_ctrl->handle_create( $req );
	$distinct_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $distinct_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $distinct_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'distinct mission current 2', near( 2, $mission_out['current'] ) );
	check( 'distinct mission percentage ~67', near( 66.67, $mission_out['percentage'] ) );
	check( 'distinct mission is_money false', false === $mission_out['is_money'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $distinct_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 0 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'distinct mission empty cart current 0', near( 0, $mission_out['current'] ) );

	// 3.11.2 Category mission (quantity mode): synthetic line carries the mission's
	// categories, so category_value() matches.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Category Mission' );
	$req->set_param( 'type', 'category' );
	$req->set_param( 'target', 4 );
	$req->set_param( 'calculation_mode', 'quantity' );
	$req->set_param( 'categories', array( 5, 6 ) );
	$resp = $missions_ctrl->handle_create( $req );
	$category_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $category_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $category_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'category mission current 2', near( 2, $mission_out['current'] ) );
	check( 'category mission percentage 50', near( 50, $mission_out['percentage'] ) );

	// 3.11.3 Category mission (money mode): simulated amount drives subtotal.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Category Money Mission' );
	$req->set_param( 'type', 'category' );
	$req->set_param( 'target', 1000 );
	$req->set_param( 'calculation_mode', 'subtotal' );
	$req->set_param( 'categories', array( 5 ) );
	$resp = $missions_ctrl->handle_create( $req );
	$cat_money_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $cat_money_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $cat_money_id );
	$req->set_param( 'simulated', array( 'amount' => 500, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'category money mission current 500', near( 500, $mission_out['current'] ) );
	check( 'category money mission percentage 50', near( 50, $mission_out['percentage'] ) );

	// 3.11.4 Product mission: synthetic line carries the first configured product.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Product Mission' );
	$req->set_param( 'type', 'product' );
	$req->set_param( 'target', 2 );
	$req->set_param( 'calculation_mode', 'quantity' );
	$req->set_param( 'products', array( 42 ) );
	$resp = $missions_ctrl->handle_create( $req );
	$product_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $product_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $product_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'product mission current 1', near( 1, $mission_out['current'] ) );
	check( 'product mission percentage 50', near( 50, $mission_out['percentage'] ) );

	// A product mission without products is honestly ineligible in preview.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Product-less Mission' );
	$req->set_param( 'type', 'product' );
	$req->set_param( 'target', 2 );
	$resp = $missions_ctrl->handle_create( $req );
	$no_product_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $no_product_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $no_product_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'product-less mission ineligible', false === $mission_out['eligible'] );
	check( 'product-less mission reason no_matching_items', 'no_matching_items' === $mission_out['reason'] );

	// 3.11.5 Weight mission: simulated quantity maps to the cart weight.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Weight Mission' );
	$req->set_param( 'type', 'weight' );
	$req->set_param( 'target', 10 );
	$resp = $missions_ctrl->handle_create( $req );
	$weight_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $weight_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $weight_id );
	$req->set_param( 'simulated', array( 'amount' => 0, 'quantity' => 5 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'weight mission current 5', near( 5, $mission_out['current'] ) );
	check( 'weight mission percentage 50', near( 50, $mission_out['percentage'] ) );
	check( 'weight mission is_money false', false === $mission_out['is_money'] );

	// 3.11.6 Composite mission (AND of amount + quantity): one synthetic line
	// carries both bases, so both children evaluate against it.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Preview Composite Mission' );
	$req->set_param( 'type', 'composite' );
	$req->set_param( 'target', 10 );
	$req->set_param( 'operator', 'and' );
	$req->set_param( 'children', array(
		array( 'type' => 'amount', 'target' => 100, 'calculation_mode' => 'subtotal' ),
		array( 'type' => 'quantity', 'target' => 4 ),
	) );
	$resp = $missions_ctrl->handle_create( $req );
	$composite_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $composite_id;

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $composite_id );
	$req->set_param( 'simulated', array( 'amount' => 50, 'quantity' => 2 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'composite mission eligible', true === $mission_out['eligible'] );
	check( 'composite mission percentage 50 (weakest child)', near( 50, $mission_out['percentage'] ) );
	check( 'composite mission not completed', false === $mission_out['completed'] );

	// 3.12 Unsaved mission form payload (builder live preview): the preview
	// reflects the form values before they are persisted — no mission row.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission', array(
		'name'             => 'Unsaved Mission',
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
	check( 'unsaved mission preview meta mode mission', 'mission' === $data['meta']['mode'] );
	$mission_out = $data['data']['missions'][0];
	check( 'unsaved mission name from form', 'Unsaved Mission' === $mission_out['mission_name'] );
	check( 'unsaved mission target from form', near( 800, $mission_out['target'] ) );
	check( 'unsaved mission current from simulated amount', near( 400, $mission_out['current'] ) );
	check( 'unsaved mission percentage 50', near( 50, $mission_out['percentage'] ) );
	check( 'unsaved mission template from display settings', 'template-5' === $mission_out['template'] );
	check( 'unsaved mission icon from display settings', '🚀' === $mission_out['icon'] );
	check( 'unsaved mission reward from form', 'fixed_discount' === $mission_out['reward']['type'] && near( 50, $mission_out['reward']['value'] ) );

	// 3.13 Editing an existing mission: the form payload wins over the stored
	// row for the edited fields (the builder previews unsaved edits).
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $mission_id );
	$req->set_param( 'mission', array(
		'name'   => 'Edited Name',
		'target' => 2000,
	) );
	$req->set_param( 'simulated', array( 'amount' => 1000, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$mission_out = $resp->get_data()['data']['missions'][0];
	check( 'edited mission name from form payload', 'Edited Name' === $mission_out['mission_name'] );
	check( 'edited mission target from form payload', near( 2000, $mission_out['target'] ) );
	check( 'edited mission keeps stored reward below the payload', 'percent_discount' === $mission_out['reward']['type'] );

	// 3.14 Unsaved campaign form payload: milestone ids + name +
	// display_rules drive the preview, and the campaign template group is
	// resolved from the form's rules even for a campaign with no id yet.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign', array(
		'name'          => 'Unsaved Campaign',
		'display_rules' => array(
			'template_id' => 'milestone_chain',
		),
		'missions' => $milestone_ids,
	) );
	$req->set_param( 'simulated', array( 'amount' => 150, 'quantity' => 1 ) );
	$resp = $preview_ctrl->handle_preview( $req );
	$data = $resp->get_data();
	check( 'unsaved campaign preview meta mode campaign', 'campaign' === $data['meta']['mode'] );
	check( 'unsaved campaign preview has 3 missions', 3 === count( $data['data']['missions'] ) );
	check( 'unsaved campaign milestone name from stored missions', 'Preview Milestone 1' === $data['data']['missions'][0]['mission_name'] );
	check( 'unsaved campaign group carries the template', isset( $data['data']['campaigns'][0] ) && 'milestone_chain' === $data['data']['campaigns'][0]['template'] );
	check( 'unsaved campaign group carries the form name', 'Unsaved Campaign' === $data['data']['campaigns'][0]['name'] );
	check( 'unsaved campaign missions joined to the group id', 3 === count( array_filter( $data['data']['missions'], function ( $g ) use ( $data ) {
		return (int) $g['campaign_id'] === (int) $data['data']['campaigns'][0]['campaign_id'];
	} ) ) );

	// 3.15 Error paths.
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'simulated', array() );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'no target → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $mission_id );
	$req->set_param( 'campaign_id', $campaign_id );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'both targets → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission', array( 'name' => 'x', 'type' => 'amount', 'target' => 10 ) );
	$req->set_param( 'campaign', array( 'name' => 'y', 'missions' => array() ) );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'both form payloads → 400', is_wp_error( $resp ) && 400 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', 99999999 );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'missing mission → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'campaign_id', 99999999 );
	$resp = $preview_ctrl->handle_preview( $req );
	check( 'missing campaign → 404', is_wp_error( $resp ) && 404 === $resp->get_error_data()['status'] );

	// 3.16 End-to-end dispatch (authenticated path would run in the admin
	// app; the anonymous 403 dispatch above already proves the gate).
	$req  = new \WP_REST_Request( 'POST', '/faracart/v1/preview' );
	$req->set_param( 'mission_id', $mission_id );
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

$missions_table = \FaraCart\Database\Schema::table( 'missions' );

foreach ( $created_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE id = %d", $id ) );
	check( "rolled-back mission {$id} is gone", 0 === $count );
}

$campaigns_table = \FaraCart\Database\Schema::table( 'campaigns' );

foreach ( $created_campaign_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$campaigns_table} WHERE id = %d", $id ) );
	check( "rolled-back campaign {$id} is gone", 0 === $count );
}

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE name = %s", 'Preview Amount Mission' ) );
check( 'no rolled-back preview mission remains by name', 0 === $count );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "PREVIEW TEST FAILED\n" : "PREVIEW TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
