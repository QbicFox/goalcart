<?php
/**
 * FaraCart Phase 3 tests (Profit Availability).
 *
 * Verifies the actual WooCommerce cost sources behind the estimated-profit
 * model (Improvement.md Phase 3) and the UI-ready availability metadata
 * added for the Revenue/Analytics redesign:
 *
 *  - cost sources: `_cost`, `_wc_cog_cost` (fallback when `_cost` is
 *    absent), `_cost` precedence over `_wc_cog_cost`, zero/negative cost
 *    treated as "no data" (never a 100%-margin assumption), variation
 *    fallback to the parent's cost (both the raw meta path and the
 *    `faracart_product_cost` filter path on the parent), and filter
 *    overrides on the product itself
 *  - reward cost: percent discount (capped at max), fixed discount, free
 *    shipping (context and real-order paths), free gift (costed vs
 *    uncosted gift → unavailable)
 *  - shipping cost: read from a real order and via context
 *  - estimated_profit formula regression: incremental × margin% −
 *    reward_cost − shipping_cost stays correct (never invented costs)
 *  - UI-ready availability metadata: `cost_sources` (the stable source
 *    keys), `store_has_cost_data` (one cheap store-wide signal) and the
 *    machine-readable `profit_reason_code` on the attribution summary —
 *    the inputs the help panel needs to explain how to enable profit (§10)
 *
 * All writes happen inside a single database transaction that is rolled
 * back; absence of residue is asserted afterwards. Fixtures use goal ids
 * 601+, sessions "t01"-style and products with the "P3 " prefix, so they
 * never collide with live store traffic or other suites' residue.
 *
 * Run: php tests/profit-availability-test.php   (from the plugin directory)
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
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\RewardCostEstimator;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Goals\Goal;
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
$wpdb      = $GLOBALS['wpdb'];

$goals_table   = Schema::table( 'goals' );
$revenue_table = Schema::table( 'revenue_events' );
$attrib_table  = Schema::table( 'goal_attribution' );

// ---------------------------------------------------------------------------
// 1. Service wiring + the stable cost-source contract
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring + cost sources contract ==\n";

check( 'RewardCostEstimator resolves', $costs instanceof RewardCostEstimator );
check( 'AttributionEngine resolves', $engine instanceof AttributionEngine );

$sources = RewardCostEstimator::COST_SOURCES;
check( 'COST_SOURCES lists _cost', in_array( '_cost', $sources, true ) );
check( 'COST_SOURCES lists _wc_cog_cost', in_array( '_wc_cog_cost', $sources, true ) );
check( 'COST_SOURCES lists faracart_product_cost', in_array( 'faracart_product_cost', $sources, true ) );
check( 'COST_SOURCES lists variation_fallback', in_array( 'variation_fallback', $sources, true ) );

// ---------------------------------------------------------------------------
// 2. Cost sources (transaction — fixtures rolled back)
// ---------------------------------------------------------------------------
echo "\n== 2. Cost sources ==\n";

$session = '';
$wpdb->query( 'START TRANSACTION' );

try {
	$make_product = function ( $title, $price, $meta = array() ) {
		$post_id = wp_insert_post( array(
			'post_type'   => 'product',
			'post_title'  => $title,
			'post_status' => 'publish',
		) );
		$product = wc_get_product( $post_id );
		$product->set_regular_price( (string) $price );

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, (string) $value );
		}

		$product->save();

		return (int) $post_id;
	};

	$make_variable_parent = function ( $title ) {
		$product = new \WC_Product_Variable();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		$product->save();

		return (int) $product->get_id();
	};

	$make_variation = function ( $parent_id, $suffix, $price ) {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'size' => $suffix ) );
		$variation->set_regular_price( (string) $price );
		$variation->save();

		return (int) $variation->get_id();
	};

	// --- `_cost` (the standard WooCommerce field). ---
	$p_cost = $make_product( 'P3 _cost', 1000, array( '_cost' => 400 ) );
	check( '_cost read as the product cost', close( 400, $costs->product_cost( $p_cost ) ) );
	check( '_cost margin = (price − cost)/price', null !== $costs->product_margin( $p_cost ) && close( 0.6, $costs->product_margin( $p_cost )['margin_pct'] ) );

	// --- `_wc_cog_cost` (cost-of-goods fallback when `_cost` is absent). ---
	$p_cog = $make_product( 'P3 _wc_cog_cost', 1000, array( '_wc_cog_cost' => 300 ) );
	check( '_wc_cog_cost read when _cost absent', close( 300, $costs->product_cost( $p_cog ) ) );

	// --- `_cost` wins over `_wc_cog_cost` when both are present. ---
	$p_both = $make_product( 'P3 both', 1000, array( '_cost' => 200, '_wc_cog_cost' => 150 ) );
	check( '_cost takes precedence over _wc_cog_cost', close( 200, $costs->product_cost( $p_both ) ) );

	// --- Zero/negative stored costs are "no data", never a 100% margin. ---
	$p_zero  = $make_product( 'P3 zero', 1000, array( '_cost' => '0' ) );
	$p_neg   = $make_product( 'P3 negative', 1000, array( '_cost' => '-5' ) );
	check( 'zero _cost treated as missing', null === $costs->product_cost( $p_zero ) );
	check( 'negative _cost treated as missing', null === $costs->product_cost( $p_neg ) );

	// --- Plain product with no cost at all. ---
	$p_plain = $make_product( 'P3 plain', 1000 );
	check( 'no cost data → null', null === $costs->product_cost( $p_plain ) );

	// --- Variation fallback: the parent's raw `_cost`. ---
	$parent_raw = $make_variable_parent( 'P3 parent raw' );
	$parent_product = wc_get_product( $parent_raw );
	$parent_product->update_meta_data( '_cost', '500' );
	$parent_product->save();
	$var_raw = $make_variation( $parent_raw, 'M', 1200 );
	check( 'variation falls back to parent _cost', close( 500, $costs->product_cost( $var_raw ) ) );

	// --- Variation fallback to the parent's `_wc_cog_cost`. ---
	$parent_cog = $make_variable_parent( 'P3 parent cog' );
	$parent_cog_product = wc_get_product( $parent_cog );
	$parent_cog_product->update_meta_data( '_wc_cog_cost', '350' );
	$parent_cog_product->save();
	$var_cog = $make_variation( $parent_cog, 'S', 1100 );
	check( 'variation falls back to parent _wc_cog_cost', close( 350, $costs->product_cost( $var_cog ) ) );

	// --- Variation fallback via the filter: the PARENT runs through the
	// faracart_product_cost filter too (regression — the parent fallback
	// used to read raw meta only). ---
	$parent_filter = $make_variable_parent( 'P3 parent filter' );
	$var_filter    = $make_variation( $parent_filter, 'L', 1200 );
	$filter_parent = function ( $cost, $product ) use ( $parent_filter ) {
		return (int) $product->get_id() === $parent_filter ? 700.0 : $cost;
	};
	add_filter( 'faracart_product_cost', $filter_parent, 10, 2 );
	check( 'variation falls back to parent cost via the filter', close( 700, $costs->product_cost( $var_filter ) ) );
	remove_filter( 'faracart_product_cost', $filter_parent );

	// --- The filter overrides the stored meta on the product itself. ---
	$override = function () {
		return 600.0;
	};
	add_filter( 'faracart_product_cost', $override, 10, 2 );
	check( 'faracart_product_cost filter overrides stored _cost', close( 600, $costs->product_cost( $p_cost ) ) );
	remove_filter( 'faracart_product_cost', $override );

	// --- A filter returning null falls through to the stored meta. ---
	$filter_null = function () {
		return null;
	};
	add_filter( 'faracart_product_cost', $filter_null, 10, 2 );
	check( 'filter returning null falls back to stored _cost', close( 400, $costs->product_cost( $p_cost ) ) );
	remove_filter( 'faracart_product_cost', $filter_null );

	// --- Store-wide availability signal (UI-ready, §10). ---
	check( 'store_has_cost_data is a bool', is_bool( $costs->store_has_cost_data() ) );
	check( 'store_has_cost_data true once costed fixtures exist', true === $costs->store_has_cost_data() );

	// -----------------------------------------------------------------------
	// 3. Reward cost + shipping cost models
	// -----------------------------------------------------------------------
	echo "\n== 3. Reward cost + shipping cost ==\n";

	$goal_pct  = new Goal( array( 'id' => 601, 'reward_type' => 'percent_discount', 'reward_value' => 10, 'reward_max_value' => 50 ) );
	$goal_fixed = new Goal( array( 'id' => 602, 'reward_type' => 'fixed_discount', 'reward_value' => 75 ) );
	$goal_ship  = new Goal( array( 'id' => 603, 'reward_type' => 'free_shipping' ) );
	$goal_gift  = new Goal( array( 'id' => 604, 'reward_type' => 'free_gift', 'reward_meta' => array( 'gift_product_id' => $p_cost ) ) );
	$goal_gift_none = new Goal( array( 'id' => 605, 'reward_type' => 'free_gift', 'reward_meta' => array( 'gift_product_id' => $p_plain ) ) );

	$pct = $costs->estimate_reward_cost( $goal_pct, 1000 );
	check( 'percent discount cost capped at reward max', close( 50, $pct['estimated_cost'] ) && $pct['available'] );

	$fixed = $costs->estimate_reward_cost( $goal_fixed, 1000 );
	check( 'fixed discount costs its amount', close( 75, $fixed['estimated_cost'] ) && $fixed['available'] );

	$ship_ctx = $costs->estimate_reward_cost( $goal_ship, 1000, array( 'shipping_total' => 85 ) );
	check( 'free shipping costs the order shipping total (context)', close( 85, $ship_ctx['estimated_cost'] ) && $ship_ctx['available'] );

	$ship_unknown = $costs->estimate_reward_cost( $goal_ship, 1000 );
	check( 'free shipping without shipping data is unavailable', ! $ship_unknown['available'] && 0.0 === $ship_unknown['estimated_cost'] );

	$order = wc_create_order();
	$order->add_product( wc_get_product( $p_cost ), 1 );
	$order->set_total( 1000 );
	$order->set_shipping_total( 85 );
	$order->set_status( 'completed' );
	$order->save();
	$order_id = (int) $order->get_id();

	check( 'order shipping total readable', close( 85, $costs->order_shipping_total( $order_id ) ) );
	$ship_order = $costs->estimate_reward_cost( $goal_ship, 1000, array( 'order_id' => $order_id ) );
	check( 'free shipping reads the real order shipping', close( 85, $ship_order['estimated_cost'] ) && $ship_order['available'] );

	$gift = $costs->estimate_reward_cost( $goal_gift, 1000 );
	check( 'free gift costs the gift product cost', close( 400, $gift['estimated_cost'] ) && $gift['available'] );

	$gift_none = $costs->estimate_reward_cost( $goal_gift_none, 1000 );
	check( 'free gift without cost data is unavailable', ! $gift_none['available'] && 0.0 === $gift_none['estimated_cost'] );

	// -----------------------------------------------------------------------
	// 4. estimated_profit formula + UI-ready availability metadata
	// -----------------------------------------------------------------------
	echo "\n== 4. Profit formula + availability metadata ==\n";

	// Pure math: incremental × margin − reward − shipping.
	$profit = $costs->profit_impact( array(
		'incremental_revenue' => 1000,
		'margin_pct'          => 0.4,
		'reward_cost'         => 50,
		'shipping_cost'       => 85,
	) );
	check( 'profit formula = incremental × margin − reward − shipping', $profit['available'] && close( 265, $profit['estimated_profit'] ) );
	check( 'profit reason_code available', 'available' === $profit['reason_code'] );

	$profit_none = $costs->profit_impact( array(
		'incremental_revenue' => 1000,
		'margin_pct'          => null,
		'reward_cost'         => 50,
	) );
	check( 'no margin → profit unavailable + reason code', ! $profit_none['available'] && null === $profit_none['estimated_profit'] && 'missing_product_cost' === $profit_none['reason_code'] );

	// Attribution summary path: a real order attributed to a completed goal,
	// so the summary's profit + availability metadata reflect real data.
	$wpdb->insert( $goals_table, array(
		'id'               => 606,
		'name'             => 'P3 profit goal',
		'status'           => 'active',
		'type'             => 'amount',
		'target'           => 1000000,
		'reward_type'      => 'percent_discount',
		'reward_value'     => 10,
		'reward_max_value' => 50,
		'created_at'       => current_time( 'mysql' ),
		'updated_at'       => current_time( 'mysql' ),
	) );

	$session = 't01' . str_repeat( 'ab', 14 );
	$tracker->record( 'goal_view', array( 'goal_id' => 606, 'cart_value' => 600, 'goal_target' => 1000000, 'session_id' => $session ) );
	$tracker->record( 'goal_progress', array( 'goal_id' => 606, 'cart_value' => 800, 'goal_target' => 1000000, 'session_id' => $session ) );
	$tracker->record( 'goal_completed', array( 'goal_id' => 606, 'cart_value' => 1000, 'goal_target' => 1000000, 'session_id' => $session ) );

	// Order on the costed product → order margin = 0.6, incremental = 400.
	$settings->set( 'analytics_enabled', false );
	$order2 = wc_create_order();
	$order2->add_product( wc_get_product( $p_cost ), 1 );
	$order2->set_total( 1000 );
	$order2->set_shipping_total( 100 );
	$order2->set_status( 'completed' );
	$order2->save();
	$order2_id = (int) $order2->get_id();
	$settings->set( 'analytics_enabled', true );

	$written = $engine->attribute_order( $order2_id, array(
		'total'          => 1000,
		'status'         => 'completed',
		'shipping_total' => 100,
		'session_id'     => $session,
	) );
	check( 'profit order attributed', 1 === $written );

	$summary = $engine->attribution_summary( array( 'goal_id' => 606 ) );
	check( 'summary profit available', true === $summary['profit_available'] );
	check( 'summary profit = 400×0.6 − 50 − 100', close( 90, $summary['profit_impact'] ) );
	check( 'summary reason_code available', 'available' === $summary['profit_reason_code'] );
	check( 'summary profit_details margin = 0.6', close( 0.6, $summary['profit_details']['margin_pct'] ) );
	check( 'summary profit_details reward cost = 50', close( 50, $summary['profit_details']['reward_cost'] ) );
	check( 'summary profit_details shipping = 100', close( 100, $summary['profit_details']['shipping_cost'] ) );
	check( 'summary cost coverage 1/1 = 100%', 1 === $summary['cost_coverage']['attributed_orders'] && 1 === $summary['cost_coverage']['orders_with_cost_data'] && close( 100, $summary['cost_coverage']['coverage_pct'] ) );
	check( 'summary cost_sources matches COST_SOURCES', $summary['cost_sources'] === RewardCostEstimator::COST_SOURCES );
	check( 'summary store_has_cost_data true', true === $summary['store_has_cost_data'] );

	$wpdb->query( 'ROLLBACK' );
} catch ( \Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	check( 'no exception during fixture reads', false );
	echo 'Exception: ' . $e->getMessage() . "\n";
}

// ---------------------------------------------------------------------------
// 5. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 5. Rollback verification ==\n";

$sessions = array( $session );
$placeholders = implode( ', ', array_fill( 0, count( $sessions ), '%s' ) );

$revenue_after = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN ({$placeholders})", $sessions )
);
check( 'no test events remain after rollback', 0 === $revenue_after );

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table} WHERE id BETWEEN 601 AND 606" );
check( 'no test goals remain after rollback', 0 === $goals_after );

$attrib_after = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE session_id IN ({$placeholders})", $sessions )
);
check( 'no test attribution rows remain after rollback', 0 === $attrib_after );

$fixture_products = get_posts( array(
	'post_type'      => 'product',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	's'              => 'P3 ',
) );
check( 'no fixture products remain', empty( $fixture_products ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
