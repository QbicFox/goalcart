<?php
/**
 * Goal Cart UPSELL_REFACTOR tests (Goal Optimization & Upsell Performance).
 *
 * Verifies the full refactor task list (`UPSELL_REFACTOR.md`):
 *
 *  - Product Cost foundation: the `_goalcart_product_cost` field's
 *    position in the source chain (filter → `_goalcart_product_cost` →
 *    `_cost` → `_wc_cog_cost` → variation fallback), `ProductCostField`
 *    wiring, and `RewardCostEstimator::COST_SOURCES` exposing the new key
 *  - Order cost snapshots: `OrderCostSnapshot` stamps each order line
 *    item with its unit cost at checkout (`_goalcart_unit_cost`), and
 *    `order_item_unit_cost()` prefers the snapshot over the live product
 *    cost — historical profit is stable when costs change later
 *  - Catalog cost coverage: `cost_coverage()` + the admin-only
 *    `GET /revenue/cost-coverage` endpoint (shape, permission, payload)
 *  - The feedback loop: `POST /revenue/goal-recommendations/apply`
 *    (route, anonymous 403, apply behavior — only the target changes,
 *    the `recommendation_applied` event is recorded with previous +
 *    applied threshold and deduped daily, unknown goal / invalid
 *    threshold errors, cache invalidation)
 *  - Upsell-assisted completions: `AttributionEngine::goal_metrics()`
 *    carries `upsell_assisted` / `upsell_assisted_rate` / `upsell_funnel`
 *    (completions whose session also engaged the smart-upsell panel)
 *  - Terminology sweep: the admin labels/navigation/redirects use Goal
 *    Optimization / Upsell Performance / Influenced sales
 *
 * All writes happen inside a single database transaction that is rolled
 * back; absence of residue is asserted afterwards. Fixtures use goal ids
 * 701+, sessions "t70"-style and products with the "R7 " prefix, so they
 * never collide with live store traffic or other suites' residue.
 *
 * Run: php tests/refactor-test.php   (from the plugin directory)
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

use GoalCart\Admin\ProductCostField;
use GoalCart\Analytics\AttributionEngine;
use GoalCart\Analytics\OrderCostSnapshot;
use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\GoalRepository;
use GoalCart\REST\RecommendationsController;
use GoalCart\REST\RevenueController;
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

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'goalcart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'goalcart_rest_test_ready' );
}

$container = \GoalCart\Plugin::instance()->container();

$engine   = $container->get( AttributionEngine::class );
$tracker  = $container->get( RevenueTracker::class );
$costs    = $container->get( RewardCostEstimator::class );
$repo     = $container->get( RevenueRepository::class );
$goals    = $container->get( GoalRepository::class );
$settings = $container->get( Settings::class );
$snapshot = $container->get( OrderCostSnapshot::class );
$wpdb     = $GLOBALS['wpdb'];

$server = rest_get_server();
$routes = $server->get_routes();

$goals_table   = Schema::table( 'goals' );
$revenue_table = Schema::table( 'revenue_events' );
$upsell_table  = Schema::table( 'upsell_events' );
$attrib_table  = Schema::table( 'goal_attribution' );

function route_exists( $routes, $pattern ) {
	return isset( $routes[ $pattern ] );
}

// ---------------------------------------------------------------------------
// 1. Wiring: services, hooks, routes, cost-source contract
// ---------------------------------------------------------------------------
echo "\n== 1. Wiring + cost-source contract ==\n";

check( 'OrderCostSnapshot resolves', $snapshot instanceof OrderCostSnapshot );
check( 'ProductCostField resolves', $container->get( ProductCostField::class ) instanceof ProductCostField );

check( 'snapshot hook registered', has_action( 'woocommerce_checkout_create_order_line_item', array( $snapshot, 'snapshot_line_item' ) ) );
check( 'simple product-cost field hook registered', has_action( 'woocommerce_product_options_pricing', array( $container->get( ProductCostField::class ), 'render_simple_field' ) ) );
check( 'variation product-cost field hook registered', has_action( 'woocommerce_variation_options_pricing', array( $container->get( ProductCostField::class ), 'render_variation_field' ) ) );

$sources = RewardCostEstimator::COST_SOURCES;
check( 'COST_SOURCES lists _goalcart_product_cost', in_array( '_goalcart_product_cost', $sources, true ) );
check( 'COST_SOURCES lists _cost', in_array( '_cost', $sources, true ) );
check( 'COST_SOURCES lists _wc_cog_cost', in_array( '_wc_cog_cost', $sources, true ) );
check( 'COST_SOURCES lists goalcart_product_cost', in_array( 'goalcart_product_cost', $sources, true ) );
check( 'COST_SOURCES lists variation_fallback', in_array( 'variation_fallback', $sources, true ) );
check( '_goalcart_product_cost precedes _cost', array_search( '_goalcart_product_cost', $sources, true ) < array_search( '_cost', $sources, true ) );

check( 'apply route registered', route_exists( $routes, '/goalcart/v1/revenue/goal-recommendations/apply' ) );
check( 'cost-coverage route registered', route_exists( $routes, '/goalcart/v1/revenue/cost-coverage' ) );
check( 'recommendation route still registered', route_exists( $routes, '/goalcart/v1/revenue/goal-recommendations' ) );

check( 'recommendation_applied is a revenue event', RevenueTracker::is_revenue_event( RevenueTracker::EVENT_RECOMMENDATION_APPLIED ) );
check( 'upsell funnel events are upsell events', RevenueTracker::is_upsell_event( RevenueTracker::EVENT_UPSELL_IMPRESSION ) && RevenueTracker::is_upsell_event( RevenueTracker::EVENT_UPSELL_CLICKED ) && RevenueTracker::is_upsell_event( RevenueTracker::EVENT_UPSELL_ADDED ) && RevenueTracker::is_upsell_event( RevenueTracker::EVENT_UPSELL_ORDER ) );

// ---------------------------------------------------------------------------
// 2. Transactional fixtures (everything rolls back)
// ---------------------------------------------------------------------------
echo "\n== 2. Fixtures (rolled back) ==\n";

$sessions = array();
$orders   = array();
$product_ids = array();

$revenue_events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$upsell_events_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_table}" );
$goals_before          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
$attrib_before         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	// --- Cost-source precedence: `_goalcart_product_cost` wins. ---
	$post_id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => 'R7 source precedence',
		'post_status' => 'publish',
	) );
	$product = wc_get_product( $post_id );
	$product->set_regular_price( '1000' );
	$product->update_meta_data( '_cost', '400' );
	$product->update_meta_data( '_wc_cog_cost', '300' );
	$product->save();
	$product_ids[] = (int) $post_id;

	check( 'live _cost read', close( 400, $costs->product_cost( (int) $post_id ) ) );

	// Now save Goal Cart's own field on top — it must take precedence.
	update_post_meta( (int) $post_id, RewardCostEstimator::PRODUCT_COST_META, '250' );
	check( '_goalcart_product_cost beats _cost', close( 250, $costs->product_cost( (int) $post_id ) ) );

	// Zero/negative in Goal Cart's field is "no data" → falls through.
	update_post_meta( (int) $post_id, RewardCostEstimator::PRODUCT_COST_META, '0' );
	check( 'zero _goalcart_product_cost treated as missing (falls back to _cost)', close( 400, $costs->product_cost( (int) $post_id ) ) );

	// The filter still outranks the stored field.
	add_filter( 'goalcart_product_cost', function ( $cost, $product ) use ( $post_id ) {
		return (int) $product->get_id() === (int) $post_id ? 550.0 : $cost;
	}, 10, 2 );
	check( 'goalcart_product_cost filter beats _goalcart_product_cost', close( 550, $costs->product_cost( (int) $post_id ) ) );
	remove_all_filters( 'goalcart_product_cost' );

	// ProductCostField::save_cost_meta — invalid/empty deletes, valid saves
	// (exercised via reflection, matching the other suites' protected-method
	// pattern; the public save_simple/save_variation are capability-gated
	// and need a logged-in admin, which CLI does not provide).
	$field = $container->get( ProductCostField::class );
	$save_cost_meta = new \ReflectionMethod( $field, 'save_cost_meta' );
	$save_cost_meta->setAccessible( true );
	update_post_meta( (int) $post_id, RewardCostEstimator::PRODUCT_COST_META, '999' );
	$save_cost_meta->invoke( $field, (int) $post_id, 'not-a-number' );
	check( 'save_cost_meta deletes invalid values', ! metadata_exists( 'post', (int) $post_id, RewardCostEstimator::PRODUCT_COST_META ) );
	$save_cost_meta->invoke( $field, (int) $post_id, '320.5' );
	check( 'save_cost_meta stores valid values', close( 320.5, $costs->product_cost( (int) $post_id ) ) );

	// --- Order cost snapshot: unit cost stamped at checkout. ---
	$order = wc_create_order();
	$item  = new \WC_Order_Item_Product();
	$item->set_product( wc_get_product( (int) $post_id ) );
	$order->add_item( $item );
	$order->set_total( 1000 );
	$order->set_status( 'completed' );
	$order->save();
	$order_id = (int) $order->get_id();
	$orders[] = $order_id;

	$snapshot->snapshot_line_item( $item, '', array(), $order );
	check( 'snapshot stamps _goalcart_unit_cost', close( 320.5, (float) $item->get_meta( RewardCostEstimator::ORDER_COST_META ) ) );
	check( 'item_snapshot_cost reads the stamped value', close( 320.5, OrderCostSnapshot::item_snapshot_cost( $item ) ) );
	check( 'order_item_unit_cost prefers the snapshot', close( 320.5, $costs->order_item_unit_cost( $item, (int) $post_id ) ) );

	// A product without cost data gets no snapshot at all.
	$plain_id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => 'R7 plain product',
		'post_status' => 'publish',
	) );
	$product_ids[] = (int) $plain_id;
	$plain_item = new \WC_Order_Item_Product();
	$plain_item->set_product( wc_get_product( (int) $plain_id ) );
	$snapshot->snapshot_line_item( $plain_item, '', array(), $order );
	check( 'uncosted product gets no snapshot', '' === (string) $plain_item->get_meta( RewardCostEstimator::ORDER_COST_META ) );
	check( 'order_item_unit_cost falls back to live cost when no snapshot', null === $costs->order_item_unit_cost( $plain_item, (int) $plain_id ) );

	// --- Catalog cost coverage. ---
	$coverage = $costs->cost_coverage();
	check( 'cost_coverage shape', isset( $coverage['total_products'], $coverage['products_with_cost'], $coverage['coverage_pct'], $coverage['available'] ) );
	check( 'cost_coverage counts the costed fixture product', $coverage['products_with_cost'] >= 1 );
	check( 'cost_coverage total covers it', $coverage['total_products'] >= $coverage['products_with_cost'] );
	check( 'coverage_pct is a percentage when available', ! $coverage['available'] || ( $coverage['coverage_pct'] >= 0 && $coverage['coverage_pct'] <= 100 ) );

	// --- REST: cost-coverage endpoint shape + anonymous rejection. ---
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/cost-coverage' );
	$resp = $server->dispatch( $req );
	check( 'anonymous rejected on cost-coverage (403)', 403 === $resp->get_status() );

	$revenue_ctrl = $container->get( RevenueController::class );
	$coverage_payload = $revenue_ctrl->handle_cost_coverage( new \WP_REST_Request( 'GET', '/goalcart/v1/revenue/cost-coverage' ) )->get_data()['data'];
	check( 'cost-coverage payload has product_coverage', isset( $coverage_payload['product_coverage'] ) );
	check( 'cost-coverage payload has store_has_cost_data', isset( $coverage_payload['store_has_cost_data'] ) && is_bool( $coverage_payload['store_has_cost_data'] ) );
	check( 'cost-coverage payload has cost_sources', isset( $coverage_payload['cost_sources'] ) && $coverage_payload['cost_sources'] === RewardCostEstimator::COST_SOURCES );

	// --- Upsell-assisted completions (per-goal smart-upsell linkage). ---
	$goal_id = $goals->create( array(
		'name'             => 'R7 upsell-assisted goal',
		'type'             => 'amount',
		'target'           => 5000000,
		'status'           => 'active',
		'calculation_mode' => 'subtotal',
	) );
	check( 'fixture goal created', $goal_id > 0 );

	// Session A: views → progresses → completes AND sees a product
	// recommendation for the goal → assisted completion.
	$session_a = sprintf( '%032x', 701 );
	$session_b = sprintf( '%032x', 702 );
	$sessions[] = $session_a;
	$sessions[] = $session_b;

	foreach ( array( 'goal_view', 'goal_progress', 'goal_completed' ) as $type ) {
		$tracker->record( $type, array( 'goal_id' => $goal_id, 'cart_value' => 3000000, 'goal_target' => 5000000, 'session_id' => $session_a ) );
	}
	$tracker->record_upsell( RevenueTracker::EVENT_UPSELL_IMPRESSION, array( 'goal_id' => $goal_id, 'product_id' => (int) $post_id, 'session_id' => $session_a, 'cart_value' => 3000000 ) );
	$tracker->record_upsell( RevenueTracker::EVENT_UPSELL_CLICKED, array( 'goal_id' => $goal_id, 'product_id' => (int) $post_id, 'session_id' => $session_a, 'cart_value' => 3000000 ) );

	// Session B: completes the goal with no upsell exposure → not assisted.
	foreach ( array( 'goal_view', 'goal_progress', 'goal_completed' ) as $type ) {
		$tracker->record( $type, array( 'goal_id' => $goal_id, 'cart_value' => 3000000, 'goal_target' => 5000000, 'session_id' => $session_b ) );
	}

	$repo->invalidate();
	$metrics = $engine->goal_metrics( (int) $goal_id );
	check( 'goal_metrics carries upsell_assisted', isset( $metrics['upsell_assisted'] ) && 1 === (int) $metrics['upsell_assisted'] );
	check( 'goal_metrics carries upsell_assisted_rate', close( 0.5, (float) $metrics['upsell_assisted_rate'] ) );
	check( 'goal_metrics carries upsell_funnel impressions', isset( $metrics['upsell_funnel'] ) && 1 === (int) $metrics['upsell_funnel']['impressions'] );
	check( 'goal_metrics upsell_funnel clicks', 1 === (int) $metrics['upsell_funnel']['clicks'] );
	check( 'goal_metrics upsell_funnel adds + orders zero', 0 === (int) $metrics['upsell_funnel']['adds'] && 0 === (int) $metrics['upsell_funnel']['orders'] );

	// --- Apply endpoint: behavior + feedback loop. ---
	$rec_ctrl = $container->get( RecommendationsController::class );

	$anon = new \WP_REST_Request( 'POST', '/goalcart/v1/revenue/goal-recommendations/apply' );
	$anon->set_param( 'goal_id', (int) $goal_id );
	$anon->set_param( 'threshold', 4000000 );
	check( 'anonymous rejected on apply (403)', 403 === $server->dispatch( $anon )->get_status() );

	$apply = new \WP_REST_Request( 'POST', '/goalcart/v1/revenue/goal-recommendations/apply' );
	$apply->set_param( 'goal_id', (int) $goal_id );
	$apply->set_param( 'threshold', 4000000 );
	$applied = $rec_ctrl->handle_apply( $apply );
	check( 'apply succeeds', ! is_wp_error( $applied ) );
	$applied_data = $applied->get_data()['data'];
	check( 'apply response reports the new target', close( 4000000, (float) $applied_data['target'] ) );
	check( 'apply response reports the previous target', close( 5000000, (float) $applied_data['previous_target'] ) );

	$after = $goals->find( (int) $goal_id );
	check( 'goal target actually updated', null !== $after && close( 4000000, (float) $after->target() ) );
	check( 'apply never touched the goal name', null !== $after && 'R7 upsell-assisted goal' === $after->name() );

	// The recommendation_applied event was recorded (previous + applied).
	$applied_events = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$revenue_table} WHERE event_type = %s AND goal_id = %d",
			RevenueTracker::EVENT_RECOMMENDATION_APPLIED,
			(int) $goal_id
		)
	);
	check( 'recommendation_applied event recorded', 1 === $applied_events );

	$event_meta = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta FROM {$revenue_table} WHERE event_type = %s AND goal_id = %d",
			RevenueTracker::EVENT_RECOMMENDATION_APPLIED,
			(int) $goal_id
		)
	);
	$meta = $event_meta ? json_decode( (string) $event_meta, true ) : array();
	check( 'event meta carries the applied threshold', isset( $meta['threshold'] ) && close( 4000000, (float) $meta['threshold'] ) );
	check( 'event meta carries the previous target', isset( $meta['previous_target'] ) && close( 5000000, (float) $meta['previous_target'] ) );

	// Re-applying within the daily window dedups the event (still one row).
	$apply2 = new \WP_REST_Request( 'POST', '/goalcart/v1/revenue/goal-recommendations/apply' );
	$apply2->set_param( 'goal_id', (int) $goal_id );
	$apply2->set_param( 'threshold', 4200000 );
	$rec_ctrl->handle_apply( $apply2 );
	$applied_events2 = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$revenue_table} WHERE event_type = %s AND goal_id = %d",
			RevenueTracker::EVENT_RECOMMENDATION_APPLIED,
			(int) $goal_id
		)
	);
	check( 'daily dedup keeps one recommendation_applied row', 1 === $applied_events2 );

	// Errors: unknown goal → 404; non-positive threshold → 400.
	$missing = new \WP_REST_Request( 'POST', '/goalcart/v1/revenue/goal-recommendations/apply' );
	$missing->set_param( 'goal_id', 999999 );
	$missing->set_param( 'threshold', 100 );
	$missing_resp = $rec_ctrl->handle_apply( $missing );
	check( 'unknown goal → 404', is_wp_error( $missing_resp ) && 404 === (int) $missing_resp->get_error_data()['status'] );

	$bad = new \WP_REST_Request( 'POST', '/goalcart/v1/revenue/goal-recommendations/apply' );
	$bad->set_param( 'goal_id', (int) $goal_id );
	$bad->set_param( 'threshold', 0 );
	$bad_resp = $rec_ctrl->handle_apply( $bad );
	check( 'non-positive threshold → 400', is_wp_error( $bad_resp ) && 400 === (int) $bad_resp->get_error_data()['status'] );

	$wpdb->query( 'ROLLBACK' );
} catch ( \Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	check( 'no exception during fixture reads', false );
	echo 'Exception: ' . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 3. Terminology sweep (source scan — the refactor's UI contract)
// ---------------------------------------------------------------------------
echo "\n== 3. Terminology sweep ==\n";

$navigation   = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/components/layout/navigation.ts' );
$app_tsx      = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/App.tsx' );
$reco_tsx     = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/Recommendations.tsx' );
$upsell_tsx   = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/UpsellAnalytics.tsx' );
$goals_tsx    = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/GoalPerformance.tsx' );
$overview_tsx = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/RevenueOverview.tsx' );
$analytics_tsx = (string) file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/Analytics.tsx' );

check( 'navigation uses Goal Optimization', false !== strpos( $navigation, "label: __('Goal Optimization', 'goalcart')" ) );
check( 'navigation uses Upsell Performance', false !== strpos( $navigation, "label: __('Upsell Performance', 'goalcart')" ) );
check( 'navigation drops Smart Recommendations label', false === strpos( $navigation, 'Smart Recommendations' ) );
check( 'navigation drops Upsell Analytics label', false === strpos( $navigation, 'Upsell Analytics' ) );
check( 'App redirects old recommendations route', false !== strpos( $app_tsx, "path: '/revenue/recommendations'" ) && false !== strpos( $app_tsx, '/optimization/goals' ) );
check( 'App redirects old upsells route', false !== strpos( $app_tsx, "path: '/revenue/upsells'" ) && false !== strpos( $app_tsx, '/optimization/upsells' ) );
check( 'Recommendations page titled Goal Optimization', false !== strpos( $reco_tsx, "title={__('Goal Optimization', 'goalcart')}" ) );
check( 'Recommendations page drops Smart Recommendations title', false === strpos( $reco_tsx, "Smart Recommendations" ) );
check( 'Upsell Analytics page titled Upsell Performance', false !== strpos( $upsell_tsx, "title={__('Upsell Performance', 'goalcart')}" ) );
check( 'Upsell Analytics page drops old title', false === strpos( $upsell_tsx, "title={__('Upsell Analytics', 'goalcart')}" ) );
check( 'Goal Performance reads upsell_assisted', false !== strpos( $goals_tsx, 'upsell_assisted' ) );
check( 'Goal Performance has Goal Optimization drawer section', false !== strpos( $goals_tsx, "__('Goal Optimization', 'goalcart')" ) );
check( 'Revenue Overview labels Influenced sales', false !== strpos( $overview_tsx, "label={__('Influenced sales', 'goalcart')}" ) );
check( 'Revenue Overview drops Influenced revenue', false === strpos( $overview_tsx, "label={__('Influenced revenue', 'goalcart')}" ) );
check( 'Analytics labels Influenced sales', false !== strpos( $analytics_tsx, "label={__('Influenced sales', 'goalcart')}" ) );
check( 'Analytics drops Influenced revenue', false === strpos( $analytics_tsx, "label={__('Influenced revenue', 'goalcart')}" ) );

// ---------------------------------------------------------------------------
// 4. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

$revenue_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$upsell_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_table}" );
$goals_after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
$attrib_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$attrib_table}" );

check( 'revenue_events unchanged after rollback', $revenue_after === $revenue_events_before );
check( 'upsell_events unchanged after rollback', $upsell_after === $upsell_events_before );
check( 'goals unchanged after rollback', $goals_after === $goals_before );
check( 'goal_attribution unchanged after rollback', $attrib_after === $attrib_before );

// wc_get_order() resolves from WC's in-memory order cache after the SQL
// rollback, so assert against the actual stores (HPOS + classic posts).
$orders_resolved = 0;
foreach ( $orders as $order_id ) {
	$oid = (int) $order_id;
	$in_hpos = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $oid )
	);
	$in_posts = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'", $oid )
	);
	$orders_resolved += ( $in_hpos + $in_posts );
}
check( 'no fixture orders remain after rollback', 0 === $orders_resolved );

$fixture_products = get_posts( array(
	'post_type'      => 'product',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	's'              => 'R7 ',
) );
check( 'no fixture products remain', empty( $fixture_products ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
