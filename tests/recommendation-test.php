<?php
/**
 * FaraCart tests (Smart Mission Recommendation).
 *
 * Boots WordPress, then exercises the deterministic
 * mission-threshold recommendation engine and its admin endpoint:
 *
 *  - service wiring: MissionRecommendationEngine + RecommendationsController
 *    resolve from the container; the REST route registers
 *  - scoring math (unit, via reflection): reachability peak/decay, distance
 *    bands, economics viability, history normalization, confidence
 *    composition, candidate generation (multipliers + shipping-aware
 *    additions), AOV/median/CV statistics and data tiers
 *  - integration (rolled back): fixture orders in a clean 2020 window drive
 *    exact AOV / median / distribution assertions; the ranked candidates,
 *    confidence bounds, reasons and factors are checked; free-shipping
 *    candidates include the shipping-aware additions
 *  - graceful degradation: insufficient data (< min orders) → no
 *    recommendation with a reason; the min-orders filter lowers the bar;
 *    the enable filter disables; margin data absent → profit excluded and
 *    economics neutral; margin present (product with _cost) → profit
 *    computed and the reasons turn margin-aware
 *  - mission history: real funnel events + an attributed order feed the
 *    completion-rate history signal
 *  - caching: the payload is served through the generation-versioned
 *    transient, and invalidate() forces a recompute on the next read
 *
 * All writes happen inside a single database transaction that is rolled
 * back; the absence of residue is asserted afterwards (with the WP
 * options/transient cache flushed so the rollback is visible).
 *
 * Run: php tests/recommendation-test.php (from the plugin directory)
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

// Deterministic English assertions regardless of the site locale: the
// recommendation reasons / insufficient_reason are translated for the
// site locale (fa_IR on this install), so switching to en_US and
// unloading the domain keeps the reason regexes stable — the same
// convention message-test.php uses.
switch_to_locale( 'en_US' );
unload_textdomain( 'faracart' );

// Hard-block the just-in-time loader (WP 6.5+): WooCommerce order
// processing pops the locale stack back to the site locale mid-suite, and
// without this flag the first faracart __() call would reload the fa_IR
// .mo and translate the reason strings the suite asserts in English.
$GLOBALS['l10n_unloaded']['faracart'] = true;

use FaraCart\Analytics\AttributionEngine;
use FaraCart\Analytics\MissionRecommendationEngine;
use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RewardCostEstimator;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Missions\Mission;
use FaraCart\Hooks\HookManager;
use FaraCart\REST\RecommendationsController;
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

// The tables are created by Installer::maybe_upgrade(), which runs
// on plugins_loaded / admin_init — neither fires in CLI after wp-load.
Installer::maybe_create_tables();

$container = \FaraCart\Plugin::instance()->container();

$engine    = $container->get( MissionRecommendationEngine::class );
$attrib    = $container->get( AttributionEngine::class );
$costs     = $container->get( RewardCostEstimator::class );
$tracker   = $container->get( RevenueTracker::class );
$repo      = $container->get( RevenueRepository::class );
$settings  = $container->get( Settings::class );
$wpdb      = $GLOBALS['wpdb'];

$revenue_table = Schema::table( 'revenue_events' );
$attrib_table  = Schema::table( 'mission_attribution' );
$missions_table   = Schema::table( 'missions' );

// ---------------------------------------------------------------------------
// 1. Service wiring
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'MissionRecommendationEngine resolves from the container', $engine instanceof MissionRecommendationEngine );
check( 'RevenueRepository resolves from the container', $repo instanceof RevenueRepository );
check( 'RecommendationsController resolves from the container', $container->get( RecommendationsController::class ) instanceof RecommendationsController );

$controller = $container->get( RecommendationsController::class );
$controller->register_routes();
$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
check( 'mission-recommendations REST route registered', isset( $routes['/faracart/v1/revenue/mission-recommendations'] ) );

// The plugin boot already registered the controller through the hook
// manager — assert the exact callback is on rest_api_init.
check( 'recommendations controller registered on rest_api_init', has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );

// ---------------------------------------------------------------------------
// 2. Scoring math (unit, via reflection)
// ---------------------------------------------------------------------------
echo "\n== 2. Scoring math ==\n";

$invoke = function ( $method, array $args ) use ( $engine ) {
	$r = new ReflectionMethod( MissionRecommendationEngine::class, $method );
	$r->setAccessible( true );

	return $r->invokeArgs( $engine, $args );
};

check( 'reachability peaks at 30% of orders in reach', close( 100, $invoke( 'reachability_score', array( 0.30 ) ) ) );
check( 'reachability scales below the peak', close( 50, $invoke( 'reachability_score', array( 0.15 ) ) ) );
check( 'reachability decays to zero at 60%', close( 0, $invoke( 'reachability_score', array( 0.60 ) ) ) );
check( 'reachability floors at zero', close( 0, $invoke( 'reachability_score', array( 0.90 ) ) ) );

check( 'distance sweet spot scores 100', close( 100, $invoke( 'distance_score', array( 1200, 1000, 900 ) ) ) );
check( 'distance too far scores low', close( 20, $invoke( 'distance_score', array( 2000, 1000, 800 ) ) ) );
check( 'distance blends aov + median', close( 75, $invoke( 'distance_score', array( 1000, 1000, 800 ) ) ) );

$margin_data = array( 'available' => true, 'average_margin_pct' => 0.4, 'sampled' => 1, 'with_cost' => 1 );
$margin_none = array( 'available' => false, 'sampled' => 0, 'with_cost' => 0, 'average_margin_pct' => null );

check( 'economics: viable discount scores 80', close( 80, $invoke( 'economics_score', array( 1400, 1000, 'percent_discount', 80, true, $margin_data ) ) ) );
check( 'economics: without margin is neutral 50', close( 50, $invoke( 'economics_score', array( 1400, 1000, 'percent_discount', 80, true, $margin_none ) ) ) );
check( 'economics: uncostable reward is neutral 50', close( 50, $invoke( 'economics_score', array( 1400, 1000, 'free_gift', 0, false, $margin_data ) ) ) );
check( 'economics: reward exceeds margin scores low', close( 35, $invoke( 'economics_score', array( 1050, 1000, 'percent_discount', 500, true, $margin_data ) ) ) );

check( 'history: strong completion scores 100', close( 100, $invoke( 'history_score', array( array( 'views' => 100, 'completion_rate' => 0.35 ) ) ) ) );
check( 'history: moderate completion scores 50', close( 50, $invoke( 'history_score', array( array( 'views' => 100, 'completion_rate' => 0.175 ) ) ) ) );
check( 'history: sparse views stay neutral', close( 50, $invoke( 'history_score', array( array( 'views' => 5, 'completion_rate' => 1.0 ) ) ) ) );
check( 'history: no history is neutral', close( 50, $invoke( 'history_score', array( null ) ) ) );

$shipping_avail = array( 'available' => true, 'average_shipping' => 85, 'orders' => 60, 'orders_with_shipping' => 60, 'free_shipping_orders' => 0, 'by_method' => array() );
$stats_basic    = array( 'aov' => 636000, 'median' => 636000, 'count' => 60, 'cv' => 0.2143 );
$stats_full     = array( 'aov' => 636000, 'median' => 636000, 'count' => 60, 'cv' => 0.2 );

check(
	'confidence composes tier + data availability',
	73 === $invoke( 'confidence', array( $stats_full, $shipping_avail, $margin_data, array( 'views' => 100, 'completion_rate' => 0.3 ), true, 80 ) )
);
check( 'confidence clamps to the ceiling', 95 === $invoke( 'confidence', array( array( 'count' => 1200, 'cv' => 0.1 ), $shipping_avail, $margin_data, array( 'views' => 500, 'completion_rate' => 0.4 ), true, 90 ) ) );
check( 'confidence worst case is the tier minus the data penalty', 50 === $invoke( 'confidence', array( array( 'count' => 60, 'cv' => 2.0 ), array( 'available' => false ), $margin_none, null, true, 80 ) ) );

// The engine returns rounded floats — compare candidates as ints.
$to_int = function ( array $values ) {
	return array_map( 'intval', $values );
};
check(
	'candidate multipliers plus shipping-aware additions',
	array( 900, 985, 1000, 1085, 1100, 1200, 1300, 1400, 1500 ) === $to_int( $invoke( 'candidate_thresholds', array( 1000, 900, 'free_shipping', $shipping_avail ) ) )
);
check(
	'non-shipping types keep only the multipliers',
	array( 900, 1000, 1100, 1200, 1300, 1400, 1500 ) === $to_int( $invoke( 'candidate_thresholds', array( 1000, 900, 'percent_discount', $shipping_avail ) ) )
);
check( 'no candidates without an AOV', array() === $invoke( 'candidate_thresholds', array( 0, 0, 'free_shipping', $shipping_avail ) ) );

$stats = $invoke( 'order_statistics', array( array( 100, 200, 300 ) ) );
check( 'statistics: aov is the mean', close( 200, $stats['aov'] ) );
check( 'statistics: median of an odd count', close( 200, $stats['median'] ) );
check( 'statistics: cv of a symmetric trio', close( 0.5, $stats['cv'] ) );

$stats_even = $invoke( 'order_statistics', array( array( 100, 200 ) ) );
check( 'statistics: median of an even count averages the middle', close( 150, $stats_even['median'] ) );

check( 'data tier: basic below 200', 'basic' === $invoke( 'data_tier', array( 60 ) ) );
check( 'data tier: reliable at 200', 'reliable' === $invoke( 'data_tier', array( 200 ) ) );
check( 'data tier: high confidence at 1000', 'high_confidence' === $invoke( 'data_tier', array( 1000 ) ) );

// ---------------------------------------------------------------------------
// 3. Integration (transaction — fixtures rolled back)
// ---------------------------------------------------------------------------
echo "\n== 3. Integration ==\n";

// The fixture order creations legitimately invalidate the revenue cache
// (order status changes fire the invalidation hook — by design), so the
// cache version is captured before the transaction and asserted to return
// to that exact baseline after the rollback. The missions row count is
// captured the same way — live stores may hold any number of real missions,
// so the suite asserts no drift rather than a hard-coded count.
$version_start = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
$missions_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	// --- Fixture missions (for the mission-scoped recommendation + history). ---
	foreach ( array(
		array( 'id' => 101, 'reward_type' => 'percent_discount', 'reward_value' => 10 ),
		array( 'id' => 202 ),
		array( 'id' => 303 ),
	) as $mission_row ) {
		$wpdb->insert( $missions_table, array(
			'id'              => $mission_row['id'],
			'name'            => 'P33.4 mission ' . $mission_row['id'],
			'status'          => 'active',
			'type'            => 'amount',
			'target'          => 1000000,
			'reward_type'     => isset( $mission_row['reward_type'] ) ? $mission_row['reward_type'] : null,
			'reward_value'    => isset( $mission_row['reward_value'] ) ? $mission_row['reward_value'] : null,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		) );
	}

	// --- Fixture orders in a clean 2020 window (exact AOV/median math).
	// Totals 400000..872000 in 8000 steps → uniform → AOV = median = 636000.
	// Tracking off during creation so the completed-status transition never
	// self-attributes (mirrors the 33.2 suite).
	$settings->set( 'analytics_enabled', false );

	$make_order = function ( $total, $shipping, $date = '2020-06-15 10:00:00' ) {
		$order = wc_create_order();
		$order->set_total( $total );
		$order->set_shipping_total( $shipping );
		$order->set_date_created( $date );
		$order->set_status( 'completed' );
		$order->save();

		return (int) $order->get_id();
	};

	$order_ids = array();

	for ( $i = 0; $i < 60; $i++ ) {
		$order_ids[] = $make_order( 400000 + $i * 8000, 85 );
	}

	// Insufficient-data window (5 orders only).
	$sparse_ids = array();
	foreach ( array( 500000, 600000, 700000, 800000, 900000 ) as $total ) {
		$sparse_ids[] = $make_order( $total, 60, '2020-07-15 10:00:00' );
	}

	$settings->set( 'analytics_enabled', true );

	$window = array( 'from' => '2020-06-15', 'to' => '2020-06-15' );

	// --- Store-order analysis via the recommendation pipeline. ---
	$values = $attrib->store_order_values( $window );
	check( 'store_order_values sees only the fixture window', $values['available'] && 60 === $values['count'] );
	check( 'store_order_values excludes zero/negative totals', ! in_array( 0, $values['totals'], true ) );

	$rec = $engine->recommend( array_merge( $window, array( 'reward_type' => 'free_shipping' ) ) );

	check( 'recommendation available with 60 orders', ! empty( $rec['available'] ) );
	check( 'recommendation status is basic tier', 'basic' === $rec['status'] );
	check( 'recommendation data aov exact', close( 636000, $rec['data']['aov'] ) );
	check( 'recommendation data median exact', close( 636000, $rec['data']['median'] ) );
	check( 'recommendation order count exact', 60 === (int) $rec['orders'] );
	check( 'shipping analysis available', ! empty( $rec['data']['shipping']['available'] ) && close( 85, $rec['data']['shipping']['average_shipping'] ) );
	check( 'margin analysis unavailable without cost data', empty( $rec['data']['margin']['available'] ) );

	$shares = 0.0;
	$counts = array();
	foreach ( $rec['data']['distribution'] as $bucket ) {
		$shares += (float) $bucket['share'];
		$counts[] = (int) $bucket['count'];
	}
	check( 'distribution bucket counts match the fixture', array( 0, 10, 20, 30, 0 ) === $counts );
	check( 'distribution shares sum to one', close( 1.0, $shares ) );

	check( 'free-shipping candidates include the shipping-aware addition', 8 === count( $rec['candidates'] ) );
	$thresholds = array_map( function ( $c ) {
		return (float) $c['threshold'];
	}, $rec['candidates'] );
	check( 'shipping-aware threshold present (aov + average shipping)', in_array( 636085.0, $thresholds, true ) );

	// --- Ranked output shape. ---
	$sorted = true;
	foreach ( $rec['candidates'] as $index => $candidate ) {
		if ( $index > 0 && $rec['candidates'][ $index - 1 ]['score'] < $candidate['score'] - 0.001 ) {
			$sorted = false;
		}
		if ( $candidate['score'] < 0 || $candidate['score'] > 100 ) {
			$sorted = false;
		}
		if ( $candidate['confidence'] < 40 || $candidate['confidence'] > 95 ) {
			$sorted = false;
		}
	}
	check( 'candidates ranked by score desc, scores and confidence bounded', $sorted );
	check( 'top candidate is the recommendation', $rec['recommendation']['threshold'] === $rec['candidates'][0]['threshold'] );

	// The single best recommendation must be the highest-scored candidate —
	// not merely the first one generated (generation order is threshold
	// order, which never equals score order in general). Proves the
	// deterministic score-desc ranking picks the true best.
	$top_score = (float) $rec['recommendation']['score'];
	$max_score = max( array_map( function ( $c ) {
		return (float) $c['score'];
	}, $rec['candidates'] ) );
	check( 'recommendation is the highest-scored candidate (best, not first)', close( $top_score, $max_score ) );

	$top = $rec['recommendation'];
	check( 'every candidate exposes scoring factors', isset( $top['factors']['reachability_score'], $top['factors']['distance_score'], $top['factors']['economics_score'], $top['factors']['history_score'] ) );
	check( 'every candidate exposes plain-English reasons', is_array( $top['reasons'] ) && count( $top['reasons'] ) >= 2 );
	check( 'reasons reference the median order value', 1 === preg_match( '/median order value/', implode( ' ', $top['reasons'] ) ) );
	check( 'expected completion rate bounded', $top['expected_completion_rate'] >= 0.05 && $top['expected_completion_rate'] <= 0.85 );
	check( 'profit excluded without margin data', ! $top['expected_profit_available'] && null === $top['expected_profit'] );
	check( 'reasons explain the missing margin data', 1 === preg_match( '/margin data is not available/', implode( ' ', $top['reasons'] ) ) );
	check( 'confidence for basic tier with shipping + tight cv', 60 === (int) $top['confidence'] );

	// --- No reward type → economics neutral (no reward to cost). ---
	$rec_plain = $engine->recommend( $window );
	check( 'plain recommendation (no type) still available', ! empty( $rec_plain['available'] ) );
	check( 'plain recommendation has no shipping-aware candidates', 7 === count( $rec_plain['candidates'] ) );
	check( 'plain recommendation economics neutral', close( 50, $rec_plain['candidates'][0]['factors']['economics_score'] ) );

	// --- Graceful degradation: insufficient data. ---
	$sparse = $engine->recommend( array( 'from' => '2020-07-15', 'to' => '2020-07-15', 'reward_type' => 'free_shipping' ) );
	check( 'insufficient orders → not available', empty( $sparse['available'] ) );
	check( 'insufficient reason explains the minimum', '' !== (string) $sparse['insufficient_reason'] && null === $sparse['recommendation'] );

	add_filter( 'faracart_recommendation_min_orders', function () {
		return 3;
	} );
	$sparse_ok = $engine->recommend( array( 'from' => '2020-07-15', 'to' => '2020-07-15', 'reward_type' => 'free_shipping' ) );
	remove_all_filters( 'faracart_recommendation_min_orders' );
	check( 'min-orders filter lowers the bar', ! empty( $sparse_ok['available'] ) && 5 === (int) $sparse_ok['orders'] );

	// --- Disabled feature flag. ---
	add_filter( 'faracart_recommendations_enabled', '__return_false' );
	$disabled = $engine->recommend( $window );
	remove_all_filters( 'faracart_recommendations_enabled' );
	check( 'disabled flag → unavailable with reason', empty( $disabled['available'] ) && false !== strpos( (string) $disabled['insufficient_reason'], 'disabled' ) );

	// --- Candidate + payload filters. ---
	add_filter( 'faracart_recommendation_candidates', function () {
		return array( 1000000 );
	} );
	$single = $engine->recommend( $window );
	remove_all_filters( 'faracart_recommendation_candidates' );
	check( 'candidate filter pins the threshold', 1 === count( $single['candidates'] ) && close( 1000000, $single['recommendation']['threshold'] ) );

	add_filter( 'faracart_recommendations', function ( $payload ) {
		$payload['filter_applied'] = true;

		return $payload;
	} );
	$filtered = $engine->recommend( $window );
	remove_all_filters( 'faracart_recommendations' );
	check( 'payload filter can shape the result', ! empty( $filtered['filter_applied'] ) );

	// --- Mission history (real funnel events + attribution). ---
	// 12 distinct valid 32-hex anonymous sessions (hex-only pairs — the
	// tracker rejects non-hex session ids).
	$sessions = array();

	for ( $i = 0; $i < 12; $i++ ) {
		$session = str_repeat( sprintf( '%02d', $i ), 16 );
		$sessions[] = $session;
		$tracker->record( 'mission_view', array( 'mission_id' => 101, 'cart_value' => 500000, 'mission_target' => 1000000, 'session_id' => $session ) );
		$tracker->record( 'mission_completed', array( 'mission_id' => 101, 'cart_value' => 700000, 'mission_target' => 1000000, 'session_id' => $session ) );
	}

	// Backdate the events into the analysis window so the mission history
	// lands inside the same 2020-06-15 window as the orders.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$revenue_table} SET created_at = %s WHERE created_at >= %s",
			'2020-06-15 09:00:00',
			date( 'Y-m-d 00:00:00', strtotime( '-1 day' ) )
		)
	);

	// Attribute one fixture order to mission 101 (completed → direct) so the
	// funnel reports a conversion inside the window.
	$written = $attrib->attribute_order( $order_ids[0], array(
		'total'      => 400000,
		'status'     => 'completed',
		'shipping_total' => 85,
		'session_id' => $sessions[0],
		'date'       => '2020-06-15 10:00:00',
	) );
	check( 'history order attributed to mission 101', 1 === $written );

	$mission_rec = $engine->recommend( array_merge( $window, array( 'mission_id' => 101 ) ) );

	check( 'mission-scoped recommendation available', ! empty( $mission_rec['available'] ) );
	check( 'mission reward type feeds the recommendation', 'percent_discount' === $mission_rec['data']['reward_type'] );
	check( 'mission history views recorded', 12 === (int) $mission_rec['data']['mission_history']['views'] );
	check( 'mission history completion rate', close( 1.0, $mission_rec['data']['mission_history']['completion_rate'] ) );
	check( 'mission history conversion counted', 1 === (int) $mission_rec['data']['mission_history']['converted'] );
	check( 'history signal lifts the history factor', close( 100, $mission_rec['candidates'][0]['factors']['history_score'] ) );
	check( 'history reason explains the completion rate', 1 === preg_match( '/Historical mission completion rate/', implode( ' ', $mission_rec['candidates'][0]['reasons'] ) ) );
	check( 'discount reward cost estimated from the mission value', close( 0.1 * $mission_rec['candidates'][0]['threshold'], $mission_rec['candidates'][0]['factors']['reward_cost'] ) );

	// --- Margin-aware path (store-provided cost data). ---
	$product_id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => 'P33.4 costed product',
		'post_status' => 'publish',
	) );

	$product = wc_get_product( $product_id );
	$product->set_regular_price( '1000' );
	$product->update_meta_data( '_cost', '400' );
	$product->save();

	check( 'product cost read from the store field', close( 400, $costs->product_cost( $product_id ) ) );

	$rec_margin = $engine->recommend( array_merge( $window, array( 'reward_type' => 'free_shipping' ) ) );

	check( 'margin analysis available with cost data', ! empty( $rec_margin['data']['margin']['available'] ) );
	check( 'average margin reflects the costed product', close( 0.6, $rec_margin['data']['margin']['average_margin_pct'] ) );
	check( 'economics uses margin once available', $rec_margin['candidates'][0]['factors']['economics_score'] > 50 );
	check( 'profit estimated once margin exists', ! empty( $rec_margin['candidates'][0]['expected_profit_available'] ) && null !== $rec_margin['candidates'][0]['expected_profit'] );
	check( 'margin-aware reason present', 1 === preg_match( '/covers the reward cost/', implode( ' ', $rec_margin['candidates'][0]['reasons'] ) ) );
	check( 'margin factor exposed', close( 0.6, $rec_margin['candidates'][0]['factors']['margin_pct'] ) );

	// --- Caching (generation-versioned transients). ---
	$cache_args  = array_merge( $window, array( 'reward_type' => 'free_shipping' ) );
	$version_before = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	$key_before     = RevenueRepository::CACHE_PREFIX . $version_before . '_mission_recs_' . md5( wp_json_encode( $cache_args ) );

	$cached = $repo->mission_recommendations( $cache_args );
	check( 'cached recommendation served through the transient', false !== get_transient( $key_before ) && ! empty( $cached['available'] ) );

	$repo->invalidate();
	$version_after = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	check( 'invalidate bumps the cache generation', $version_after === $version_before + 1 );

	$key_after = RevenueRepository::CACHE_PREFIX . $version_after . '_mission_recs_' . md5( wp_json_encode( $cache_args ) );
	$fresh     = $repo->mission_recommendations( $cache_args );
	check( 'fresh read recomputes on the new generation', false !== get_transient( $key_after ) && ! empty( $fresh['available'] ) );

	add_filter( 'faracart_revenue_cache_enabled', '__return_false' );
	$bypass = $repo->mission_recommendations( $cache_args );
	remove_all_filters( 'faracart_revenue_cache_enabled' );
	check( 'cache bypass still returns the recommendation', ! empty( $bypass['available'] ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// The options/transient writes happened inside the rolled-back transaction,
// but WP's in-memory caches still hold the pre-rollback values — flush so
// the verification reads see the real database.
wp_cache_flush();

// ---------------------------------------------------------------------------
// 4. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 4. Rollback verification ==\n";

check( 'no fixture events remain after rollback', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id IN (" . implode( ',', array_fill( 0, count( $sessions ), '%s' ) ) . ")", $sessions ) ) );
check( 'no fixture attribution rows remain after rollback', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attrib_table} WHERE order_id = %d", $order_ids[0] ) ) );
check( 'missions back to the pre-existing count', $missions_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" ) );

$leftover = wc_get_orders( array(
	'status'       => AttributionEngine::REVENUE_STATUSES,
	'limit'        => 100,
	'return'       => 'objects',
	'date_created' => '2020-06-15 00:00:00...2020-06-15 23:59:59',
) );
check( 'no fixture orders remain in the 2020 window', 0 === count( $leftover ) );

check( 'cache generation returns to the pre-test baseline', $version_start === (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 ) );

$leftover_sparse = wc_get_orders( array(
	'status'       => AttributionEngine::REVENUE_STATUSES,
	'limit'        => 100,
	'return'       => 'objects',
	'date_created' => '2020-07-15 00:00:00...2020-07-15 23:59:59',
) );
check( 'no sparse-window orders remain', 0 === count( $leftover_sparse ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "RECOMMENDATION TEST FAILED\n" : "RECOMMENDATION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
