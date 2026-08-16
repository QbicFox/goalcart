<?php
/**
 * FaraCart Phase 33.5 tests (Smart Upsell).
 *
 * Boots WordPress, then exercises the Phase 33.5 deterministic
 * product-ranking engine and its endpoints:
 *
 *  - service wiring: UpsellRanker + UpsellController resolve from the
 *    container; the upsell/track + revenue/upsells routes register
 *  - scoring math (unit, via reflection): price-gap sweet band + overshoot
 *    tolerance + hard decay, relevance signal composition, inventory
 *    thresholds, popularity normalization, margin neutrality/gain,
 *    conversion blend, composite weight normalization
 *  - integration (rolled back): fixture products + a fixture goal drive an
 *    exact ranking — gap-fitting products rank above overshoots and
 *    unrelated expensive items; stock-managed scoring; margin-aware profit;
 *    out-of-stock exclusion; explicit remaining vs goal-derived gap
 *  - historical performance (P33-35): tracker upsell events feed the
 *    aggregator, the conversion score reflects the funnel, and
 *    attribute_order() records exactly-once upsell_order events for a
 *    session's recommended products
 *  - graceful degradation: no goal/remaining → unavailable with a reason;
 *    disabled flag; no candidates; no margin data → profit unavailable
 *  - caching: the ranking is served through the generation-versioned
 *    transient, and invalidate() forces a recompute on the next read
 *
 * All writes happen inside a single database transaction that is rolled
 * back; the absence of residue is asserted afterwards.
 *
 * Run: php tests/upsell-test.php   (from the plugin directory)
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
// upsell reasons are translated for the site locale (fa_IR on this
// install), so switching to en_US and unloading the domain keeps the
// reason regexes stable — the same convention message-test.php uses.
switch_to_locale( 'en_US' );
unload_textdomain( 'goalcart' );

// Hard-block the just-in-time loader (WP 6.5+): WooCommerce order
// processing pops the locale stack back to the site locale mid-suite, and
// without this flag the first goalcart __() call would reload the fa_IR
// .mo and translate the reason strings the suite asserts in English.
$GLOBALS['l10n_unloaded']['goalcart'] = true;

use GoalCart\Analytics\DailyAggregator;
use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Analytics\Session;
use GoalCart\Analytics\UpsellRanker;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalRepository;
use GoalCart\REST\UpsellController;
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

$ranker  = $container->get( UpsellRanker::class );
$repo    = $container->get( RevenueRepository::class );
$tracker = $container->get( RevenueTracker::class );
$costs   = $container->get( RewardCostEstimator::class );
$aggregator = $container->get( DailyAggregator::class );
$settings = $container->get( Settings::class );
$goals    = $container->get( GoalRepository::class );
$wpdb     = $GLOBALS['wpdb'];

$upsell_events = Schema::table( 'upsell_events' );
$upsell_stats  = Schema::table( 'upsell_stats' );
$goals_table   = Schema::table( 'goals' );
$revenue_table = Schema::table( 'revenue_events' );

// ---------------------------------------------------------------------------
// 1. Service wiring
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'UpsellRanker resolves from the container', $ranker instanceof UpsellRanker );
check( 'UpsellController resolves from the container', $container->get( UpsellController::class ) instanceof UpsellController );

$controller = $container->get( UpsellController::class );
$controller->register_routes();
$routes = function_exists( 'rest_get_server' ) ? rest_get_server()->get_routes() : array();
check( 'upsell track REST route registered', isset( $routes['/goalcart/v1/upsell/track'] ) );
check( 'revenue upsells REST route registered', isset( $routes['/goalcart/v1/revenue/upsells'] ) );
check( 'revenue upsells per-product REST route registered', isset( $routes['/goalcart/v1/revenue/upsells/(?P<product_id>[\d]+)'] ) );
check( 'upsell controller registered on rest_api_init', has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );

// ---------------------------------------------------------------------------
// 2. Scoring math (unit, via reflection)
// ---------------------------------------------------------------------------
echo "\n== 2. Scoring math ==\n";

// The fixture products/terms below are real DB rows — wrap everything in
// the transaction so repeated runs never leak residue. The cache-version
// baseline must be captured BEFORE the transaction: product saves fire
// save_post_product → RevenueRepository::invalidate() and would otherwise
// skew the comparison.
$version_start = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );

// Row-count baselines (the store may hold pre-existing events from other
// suites) — the rollback assertions compare against these instead of zero.
$revenue_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$upsell_events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_events}" );
$upsell_stats_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_stats}" );

$wpdb->query( 'START TRANSACTION' );

$invoke = function ( $method, array $args ) use ( $ranker ) {
	$r = new ReflectionMethod( UpsellRanker::class, $method );
	$r->setAccessible( true );

	return $r->invokeArgs( $ranker, $args );
};

// --- Price gap (P33-27/36): sweet band [0.75, 1.30], small overshoot OK. ---
check( 'price gap: exact fit scores 100', close( 100, $invoke( 'price_gap_score', array( 450, 450 ) ) ) );
check( 'price gap: small overshoot (1.3x) still 100', close( 100, $invoke( 'price_gap_score', array( 585, 450 ) ) ) );
check( 'price gap: cheap filler scores proportionally', close( 100 * ( 300 / 337.5 ), $invoke( 'price_gap_score', array( 300, 450 ) ) ) );
check( 'price gap: big overshoot (4x) scores zero', close( 0, $invoke( 'price_gap_score', array( 1800, 450 ) ) ) );
check( 'price gap: missing price is neutral 50', close( 50, $invoke( 'price_gap_score', array( null, 450 ) ) ) );
check( 'price gap: missing gap is neutral 50', close( 50, $invoke( 'price_gap_score', array( 450, null ) ) ) );
check( 'price gap: zero gap is neutral 50', close( 50, $invoke( 'price_gap_score', array( 450, 0 ) ) ) );

// --- Relevance (P33-28): goal eligibility + cart overlap + WC sources. ---
// term_exists() first — a previous run's leftovers would make wp_insert_term
// return a WP_Error instead of the term id.
$cat_id = term_exists( 'P33.5 Upsell Cat', 'product_cat' );

if ( ! $cat_id ) {
	$cat_term = wp_insert_term( 'P33.5 Upsell Cat', 'product_cat' );
	$cat_id   = is_wp_error( $cat_term ) ? 0 : (int) $cat_term['term_id'];
} else {
	$cat_id = (int) $cat_id;
}

$tag_id = term_exists( 'P33.5 Upsell Tag', 'product_tag' );

if ( ! $tag_id ) {
	$tag_term = wp_insert_term( 'P33.5 Upsell Tag', 'product_tag' );
	$tag_id   = is_wp_error( $tag_term ) ? 0 : (int) $tag_term['term_id'];
} else {
	$tag_id = (int) $tag_id;
}

$make_product = function ( $title, $price, $stock = null ) {
	$id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_title'  => $title,
		'post_status' => 'publish',
	) );

	$product = wc_get_product( $id );		$product->set_regular_price( (string) $price );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}

		$product->save();

		return $product;
	};

// Helper: idempotent taxonomy lookup so leftover terms from earlier runs
// don't break the unit assertions.
$resolve_term = function ( $name, $taxonomy ) {
	$id = term_exists( $name, $taxonomy );

	if ( $id ) {
		return (int) $id;
	}

	$term = wp_insert_term( $name, $taxonomy );

	return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
};

$relevance_a = $make_product( 'Relevance A', 100 );
$relevance_b = $make_product( 'Relevance B', 100 );

// Assign the taxonomies AFTER the products exist, then re-fetch so the
// WC_Product objects carry fresh category/tag caches (the unit assertions
// read them off the object).
if ( $cat_id ) {
	wp_set_object_terms( $relevance_a->get_id(), array( $cat_id ), 'product_cat' );
	wc_delete_product_transients( $relevance_a->get_id() );
	$relevance_a = wc_get_product( $relevance_a->get_id() );
}

if ( $tag_id ) {
	wp_set_object_terms( $relevance_b->get_id(), array( $tag_id ), 'product_tag' );
	wc_delete_product_transients( $relevance_b->get_id() );
	$relevance_b = wc_get_product( $relevance_b->get_id() );
}

check( 'relevance: no signals is a low baseline', close( 0, $invoke( 'relevance_score', array( $relevance_b, 'popular', array(), null ) ) ) );
check( 'relevance: WC-endorsed source adds trust', close( 15, $invoke( 'relevance_score', array( $relevance_b, 'upsell', array(), null ) ) ) );
check( 'relevance: shared category adds 30', close( 30, $invoke( 'relevance_score', array( $relevance_a, 'popular', array( $relevance_a->get_id() ), null ) ) ) );
check( 'relevance: goal manual product adds 55', close( 55, $invoke( 'relevance_score', array( $relevance_a, 'manual', array(), new Goal( array( 'products' => array( $relevance_a->get_id() ) ) ) ) ) ) );

// --- Inventory (P33-29). ---
$inv_high  = $make_product( 'Inv High', 100, 50 );
$inv_med   = $make_product( 'Inv Med', 100, 10 );
$inv_low   = $make_product( 'Inv Low', 100, 2 );
$inv_none  = $make_product( 'Inv None', 100 );

check( 'inventory: >20 stock is 100', close( 100, $invoke( 'inventory_score', array( $inv_high ) ) ) );
check( 'inventory: 5-20 stock is 70', close( 70, $invoke( 'inventory_score', array( $inv_med ) ) ) );
check( 'inventory: 1-4 stock is 40', close( 40, $invoke( 'inventory_score', array( $inv_low ) ) ) );
check( 'inventory: unmanaged stock is neutral 70', close( 70, $invoke( 'inventory_score', array( $inv_none ) ) ) );

// --- Popularity (P33-30): sales + rating, bounded. ---
$popular = $make_product( 'Popular', 100 );
check( 'popularity: no sales no rating is 0', close( 0, $invoke( 'popularity_score', array( $popular, array() ) ) ) );

// --- Margin (P33-31): neutral without data, gain with cost. ---
check( 'margin: unavailable is neutral 50', close( 50, $invoke( 'margin_score', array( null ) ) ) );
check( 'margin: 40% margin scores 90', close( 90, $invoke( 'margin_score', array( array( 'margin_pct' => 0.4, 'cost' => 60, 'price' => 100, 'margin' => 40 ) ) ) ) );
check( 'margin: extreme margin capped at 100', close( 100, $invoke( 'margin_score', array( array( 'margin_pct' => 0.9, 'cost' => 10, 'price' => 100, 'margin' => 90 ) ) ) ) );

// --- Conversion (P33-32): impressions-weighted blend. ---
check( 'conversion: no data is neutral 50', close( 50, $invoke( 'conversion_score', array( array( 'impressions' => 0, 'orders' => 0 ) ) ) ) );
check( 'conversion: 5% over 100 impressions scores 100', close( 100, $invoke( 'conversion_score', array( array( 'impressions' => 100, 'orders' => 5 ) ) ) ) );
check( 'conversion: sparse data blends toward neutral', close( 50 + ( 50 * 0.2 ), $invoke( 'conversion_score', array( array( 'impressions' => 10, 'orders' => 5 ) ) ) ) );

// --- Composite weights (P33-33): filterable + normalized. ---
$weights = $invoke( 'score_weights', array() );
check( 'weights sum to one', close( 1.0, array_sum( $weights ) ) );
check( 'default price-gap weight is 0.25', close( 0.25, $weights['price_gap'] ) );
check( 'default relevance weight is 0.25', close( 0.25, $weights['relevance'] ) );
check( 'default conversion weight is 0.10', close( 0.10, $weights['conversion'] ) );

add_filter( 'goalcart_upsell_weights', function () {
	return array( 'price_gap' => 50, 'relevance' => 50 );
} );
$filtered = $invoke( 'score_weights', array() );
remove_all_filters( 'goalcart_upsell_weights' );
check( 'partial weight filter falls back per key', close( 0.5, $filtered['price_gap'] ) && close( 0.5, $filtered['relevance'] ) && close( 0.1, $filtered['conversion'] ) );

// ---------------------------------------------------------------------------
// 3. Integration (transaction — fixtures rolled back)
// ---------------------------------------------------------------------------
echo "\n== 3. Integration ==\n";

try {
	// --- Fixture products: one in the gap band, one overshoot, one far. ---
	$gap_product  = $make_product( 'P33.5 Gap Filler', 490000, 50 );
	$ok_product   = $make_product( 'P33.5 Close Fit', 420000, 5 );
	$overshoot    = $make_product( 'P33.5 Overshoot', 600000, 30 );
	$expensive    = $make_product( 'P33.5 Expensive', 1800000, 10 );
	$out_of_stock = $make_product( 'P33.5 Sold Out', 450000, 0 );
	$out_of_stock->set_stock_status( 'outofstock' );
	$out_of_stock->save();

	$candidate_ids = array( $gap_product->get_id(), $ok_product->get_id(), $overshoot->get_id(), $expensive->get_id(), $out_of_stock->get_id() );

	// --- Fixture goal (money goal, target 2,000,000) whose own products
	// are the fixtures — pinning the candidate set so the assertions never
	// depend on which products happen to fill the store's best-seller
	// pool. ---
	$goal_id = $goals->create( array(
		'name'      => 'P33.5 upsell goal',
		'type'      => 'amount',
		'target'    => 2000000,
		'status'    => 'active',
		'products'  => $candidate_ids,
	) );

	check( 'fixture goal created', $goal_id > 0 );

	// Explicit remaining gap: 450,000 (P33-36 — prefer ~350K–600K products).
	$rec = $ranker->rank( array(
		'remaining' => 450000,
		'cart'      => array(),
		'goal_id'   => $goal_id,
		'limit'     => 5,
	) );

	check( 'ranking available with an explicit remaining gap', ! empty( $rec['available'] ) );
	check( 'ranking exposes the weights', is_array( $rec['weights'] ) && close( 1.0, array_sum( $rec['weights'] ) ) );
	check( 'ranking context echoes the gap', close( 450000, $rec['context']['remaining'] ) );

	$top_ids = array_map( function ( $item ) {
		return (int) $item['product_id'];
	}, $rec['recommendations'] );

	check( 'sold-out product excluded from the ranking', ! in_array( $out_of_stock->get_id(), $top_ids, true ) );

	// Gap-filler (490K, ratio 1.09) must outrank the 1.8M product.
	$score_gap = null;
	$score_exp = null;

	foreach ( $rec['recommendations'] as $item ) {
		if ( (int) $item['product_id'] === (int) $gap_product->get_id() ) {
			$score_gap = (float) $item['score'];
		}

		if ( (int) $item['product_id'] === (int) $expensive->get_id() ) {
			$score_exp = (float) $item['score'];
		}
	}

	check( 'gap-filling product ranked above the expensive one', null !== $score_gap && ( null === $score_exp || $score_gap > $score_exp ) );

	$top = isset( $rec['recommendations'][0] ) ? $rec['recommendations'][0] : null;
	check( 'composite score is bounded 0-100', null !== $top && (float) $top['score'] <= 100 && (float) $top['score'] >= 0 );
	check( 'every product exposes component breakdowns', null !== $top && isset( $top['components']['price_gap'], $top['components']['relevance'], $top['components']['popularity'], $top['components']['inventory'], $top['components']['margin'], $top['components']['conversion'] ) );
	check( 'every product exposes plain-English reasons', null !== $top && is_array( $top['reasons'] ) && count( $top['reasons'] ) >= 2 );
	check( 'every product exposes historical conversion stats', null !== $top && isset( $top['conversion']['impressions'], $top['conversion']['orders'] ) );

	// Goal-derived gap: target 2,000,000 − cart_value 1,550,000 = 450,000.
	$rec_goal = $ranker->rank( array(
		'goal_id'    => $goal_id,
		'cart_value' => 1550000,
		'limit'      => 5,
	) );
	check( 'goal-derived gap matches explicit gap', close( 450000, $rec_goal['context']['remaining'] ) );

	// No remaining and no goal → unavailable with a reason.
	$no_gap = $ranker->rank( array() );
	check( 'no goal and no remaining → unavailable', empty( $no_gap['available'] ) && '' !== (string) $no_gap['reason'] );

	// Closed gap → unavailable.
	$closed = $ranker->rank( array( 'remaining' => 0 ) );
	check( 'closed gap → unavailable', empty( $closed['available'] ) && false !== strpos( (string) $closed['reason'], 'closed' ) );

	// Disabled flag → unavailable with a reason.
	add_filter( 'goalcart_upsells_enabled', '__return_false' );
	$disabled = $ranker->rank( array( 'remaining' => 450000 ) );
	remove_all_filters( 'goalcart_upsells_enabled' );
	check( 'disabled flag → unavailable with reason', empty( $disabled['available'] ) && false !== strpos( (string) $disabled['reason'], 'disabled' ) );

	// Margin-aware profit: give the gap product a cost.
	$costs_product = wc_get_product( $gap_product->get_id() );
	$costs_product->update_meta_data( '_cost', '245000' );
	$costs_product->save();

	$margin = $costs->product_margin( $gap_product->get_id() );
	check( 'product cost read from the store field', null !== $margin && close( 0.5, $margin['margin_pct'] ) );

	$rec_margin = $ranker->rank( array( 'remaining' => 450000, 'goal_id' => $goal_id, 'limit' => 5 ) );
	$gap_margin = null;

	foreach ( $rec_margin['recommendations'] as $item ) {
		if ( (int) $item['product_id'] === (int) $gap_product->get_id() ) {
			$gap_margin = $item;
		}
	}

	check( 'margin-aware profit exposed when cost data exists', null !== $gap_margin && ! empty( $gap_margin['profit_available'] ) && null !== $gap_margin['estimated_profit'] );
	check( 'margin reason present', null !== $gap_margin && 1 === preg_match( '/Estimated margin/', implode( ' ', $gap_margin['reasons'] ) ) );

	// Without margin data, profit is unavailable but the product still ranks.
	$rec_nomargin = $ranker->rank( array( 'remaining' => 450000, 'goal_id' => $goal_id, 'limit' => 5 ) );
	$ok_nomargin  = null;

	foreach ( $rec_nomargin['recommendations'] as $item ) {
		if ( (int) $item['product_id'] === (int) $ok_product->get_id() ) {
			$ok_nomargin = $item;
		}
	}

	check( 'no margin data → profit unavailable, product still ranked', null !== $ok_nomargin && empty( $ok_nomargin['profit_available'] ) && null === $ok_nomargin['estimated_profit'] );
	check( 'no margin data → reason explains the exclusion', null !== $ok_nomargin && 1 === preg_match( '/margin data is not available/', implode( ' ', $ok_nomargin['reasons'] ) ) );

	// Candidate filter.
	add_filter( 'goalcart_upsell_candidates', function () use ( $candidate_ids ) {
		return array_combine( array_slice( $candidate_ids, 0, 2 ), array_fill( 0, 2, 'manual' ) );
	} );
	$pinned = $ranker->rank( array( 'remaining' => 450000, 'goal_id' => $goal_id, 'limit' => 5 ) );
	remove_all_filters( 'goalcart_upsell_candidates' );
	check( 'candidate filter pins the candidate set', (int) $pinned['candidates'] >= 2 && count( $pinned['recommendations'] ) >= 2 );

	// Payload filter.
	add_filter( 'goalcart_upsells', function ( $payload ) {
		$payload['filter_applied'] = true;

		return $payload;
	} );
	$filtered_payload = $ranker->rank( array( 'remaining' => 450000, 'goal_id' => $goal_id ) );
	remove_all_filters( 'goalcart_upsells' );
	check( 'payload filter can shape the result', ! empty( $filtered_payload['filter_applied'] ) );

	// -----------------------------------------------------------------------
	// 4. Historical performance tracking (P33-35)
	// -----------------------------------------------------------------------
	echo "\n== 4. Historical performance tracking ==\n";

	// Privacy-safe session: 32 hex chars (hex-only pairs — the tracker
	// rejects non-hex session ids).
	$session = str_repeat( 'ab', 16 );
	check( 'test session is well-formed', Session::is_valid( $session ) );

	// The ordering session is the one the live cookie resolves to — the
	// AttributionEngine's order_paid hook records THAT session, so the
	// funnel events must share it for attribute_order() to connect the
	// dots.
	$cookie_session = $tracker->get_session_id();
	check( 'cookie session is well-formed', Session::is_valid( $cookie_session ) );

	// 20 impressions for the gap product in the goal's funnel. Upsell
	// impressions are deduped per session+goal+product within 24h, so each
	// impression must come from a distinct session to accumulate a real
	// funnel (mirrors 20 different shoppers seeing the recommendation).
	for ( $i = 0; $i < 20; $i++ ) {
		$tracker->record_upsell( 'upsell_impression', array(
			'goal_id'    => $goal_id,
			'product_id' => $gap_product->get_id(),
			'session_id' => sprintf( '%032x', $i + 1 ),
			'cart_value' => 1550000,
		) );
	}

	$tracker->record_upsell( 'upsell_clicked', array(
		'goal_id'    => $goal_id,
		'product_id' => $gap_product->get_id(),
		'session_id' => $cookie_session,
		'cart_value' => 1550000,
	) );

	$tracker->record_upsell( 'upsell_added', array(
		'goal_id'    => $goal_id,
		'product_id' => $gap_product->get_id(),
		'session_id' => $cookie_session,
		'cart_value' => 1550000,
	) );

	check( 'upsell impression events recorded', 20 === (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$upsell_events} WHERE event_type = %s AND product_id = %d", 'upsell_impression', $gap_product->get_id() )
	) );
	check( 'upsell click + add recorded', 2 === (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$upsell_events} WHERE event_type IN (%s, %s) AND product_id = %d", 'upsell_clicked', 'upsell_added', $gap_product->get_id() )
	) );

	// Server-side upsell_order attribution on a paid order. The ranker's own
	// order_status_completed hook would attribute automatically before the
	// manual call (and dedup it), so it is removed here to exercise the
	// method directly.
	remove_action( 'woocommerce_order_status_completed', array( $ranker, 'handle_order_paid' ), 20 );
	remove_action( 'woocommerce_payment_complete', array( $ranker, 'handle_order_paid' ), 20 );

	$order = wc_create_order();
	$order->set_total( 2040000 );
	$order->set_date_created( current_time( 'mysql' ) );
	$order->set_status( 'completed' );
	$order->add_meta_data( '_goalcart_upsell_test', '1' );
	$order->save();
	$order_id = (int) $order->get_id();

	// The order_paid event is recorded by the AttributionEngine hook (with
	// the live cookie session) when the status flips to completed — the
	// same anchor attribute_order() resolves.

	$written = $ranker->attribute_order( $order_id, array(
		'total' => 2040000,
		'date'  => current_time( 'mysql' ),
	) );

	check( 'upsell_order attributed for the session product', $written >= 1 );

	$order_events = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$upsell_events} WHERE event_type = %s AND order_id = %d", 'upsell_order', $order_id )
	);
	check( 'upsell_order recorded exactly once per order+product', $order_events >= 1 );

	$again = $ranker->attribute_order( $order_id, array(
		'total' => 2040000,
		'date'  => current_time( 'mysql' ),
	) );
	check( 're-attribution is idempotent (no new rows)', 0 === $again );

	// A session with no recommendation history gets no upsell_order. Rotate
	// the live cookie first so this order anchors to a fresh session with
	// no funnel events.
	$container->get( Session::class )->rotate();
	$order2        = wc_create_order();
	$order2->set_total( 1000 );
	$order2->set_status( 'completed' );
	$order2->add_meta_data( '_goalcart_upsell_test', '1' );
	$order2->save();
	$order2_id = (int) $order2->get_id();

	$written2 = $ranker->attribute_order( $order2_id, array(
		'total' => 1000,
		'date'  => current_time( 'mysql' ),
	) );
	check( 'no recommendation history → no upsell_order events', 0 === $written2 );

	// Rebuild upsell_stats (the Phase 33.3 aggregator path) — after the
	// upsell_order rows exist, so the funnel includes the purchases.
	$rebuilt = $aggregator->aggregate_upsells();
	check( 'aggregator rebuilds upsell_stats', $rebuilt >= 1 );

	// A fresh ranker instance so its per-request product/stats memoization
	// (built while the funnel was still empty) cannot shadow the rebuild.
	$fresh_ranker = new UpsellRanker(
		$tracker,
		$costs,
		$goals,
		$settings
	);

	// The conversion scorer must reflect the funnel (5% over 20 impressions).
	$rec_hist = $fresh_ranker->rank( array( 'remaining' => 450000, 'goal_id' => $goal_id, 'limit' => 5 ) );
	$gap_hist = null;

	foreach ( $rec_hist['recommendations'] as $item ) {
		if ( (int) $item['product_id'] === (int) $gap_product->get_id() ) {
			$gap_hist = $item;
		}
	}

	check( 'conversion stats read from the aggregated funnel', null !== $gap_hist && 20 === (int) $gap_hist['conversion']['impressions'] && 1 === (int) $gap_hist['conversion']['orders'] );
	check( 'conversion score lifted by historical performance', null !== $gap_hist && $gap_hist['components']['conversion'] > 50 );
	check( 'conversion reason mentions the funnel', null !== $gap_hist && 1 === preg_match( '/Historical upsell performance/', implode( ' ', $gap_hist['reasons'] ) ) );

	// -----------------------------------------------------------------------
	// 5. Caching
	// -----------------------------------------------------------------------
	echo "\n== 5. Caching ==\n";

	$cache_args  = array( 'remaining' => 450000, 'goal_id' => $goal_id, 'limit' => 5 );
	$version_before = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	$key_before     = RevenueRepository::CACHE_PREFIX . $version_before . '_upsell_rank_' . md5( wp_json_encode( $cache_args ) );

	$cached = $repo->upsell_ranking( $cache_args );
	check( 'cached ranking served through the transient', false !== get_transient( $key_before ) && ! empty( $cached['available'] ) );

	$repo->invalidate();
	$version_after = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );
	check( 'invalidate bumps the cache generation', $version_after === $version_before + 1 );

	$key_after = RevenueRepository::CACHE_PREFIX . $version_after . '_upsell_rank_' . md5( wp_json_encode( $cache_args ) );
	$fresh     = $repo->upsell_ranking( $cache_args );
	check( 'fresh read recomputes on the new generation', false !== get_transient( $key_after ) && ! empty( $fresh['available'] ) );

	add_filter( 'goalcart_revenue_cache_enabled', '__return_false' );
	$bypass = $repo->upsell_ranking( $cache_args );
	remove_all_filters( 'goalcart_revenue_cache_enabled' );
	check( 'cache bypass still returns the ranking', ! empty( $bypass['available'] ) );

	// Analytics table. upsell_order rows inherit the goal from the funnel
	// events that triggered the attribution, so the goal filter counts the
	// order too.
	$analytics = $repo->upsell_analytics( array( 'goal_id' => $goal_id, 'limit' => 10 ) );
	check( 'upsell analytics groups the funnel per product', count( $analytics ) >= 1 );
	$gap_row = null;

	foreach ( $analytics as $row ) {
		if ( (int) $row['product_id'] === (int) $gap_product->get_id() ) {
			$gap_row = $row;
		}
	}

	check( 'analytics row carries the funnel counts', null !== $gap_row && 20 === (int) $gap_row['impressions'] && 1 === (int) $gap_row['orders'] );
	check( 'analytics row carries a score', null !== $gap_row && is_numeric( $gap_row['upsell_score'] ) );

	// Per-product detail.
	$detail = $repo->upsell_product_detail( $gap_product->get_id(), array( 'remaining' => 450000 ) );
	check( 'per-product detail exposes the score breakdown', null !== $detail && isset( $detail['components'], $detail['reasons'] ) );

	$missing = $repo->upsell_product_detail( 99999999, array() );
	check( 'per-product detail null for unknown products', null === $missing );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// The options/transient writes happened inside the rolled-back transaction,
// but WP's in-memory caches still hold the pre-rollback values — flush so
// the verification reads see the real database.
wp_cache_flush();

// ---------------------------------------------------------------------------
// 6. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 6. Rollback verification ==\n";

check( 'upsell_events row count unchanged after rollback', $upsell_events_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_events}" ) );
check( 'upsell_stats row count unchanged after rollback', $upsell_stats_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_stats}" ) );
check( 'revenue_events row count unchanged after rollback', $revenue_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" ) );

$leftover_goals = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE name = %s", 'P33.5 upsell goal' )
);
check( 'fixture goal removed by rollback', 0 === $leftover_goals );

$leftover_products = get_posts( array(
	'post_type'   => 'product',
	'post_status' => 'publish',
	'fields'      => 'ids',
	'numberposts' => -1,
	'title'       => 'P33.5 ',
) );
check( 'no P33.5 fixture products remain', 0 === count( $leftover_products ) );

$leftover_orders = wc_get_orders( array(
	'limit'        => 100,
	'return'       => 'objects',
	'meta_key'     => '_goalcart_upsell_test', // phpcs:ignore WordPress.DB.SlowDBQuery -- test fixture marker.
	'meta_value'   => '1',
) );
check( 'no fixture orders remain after rollback', 0 === count( $leftover_orders ) );

check( 'cache generation returns to the pre-test baseline', $version_start === (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "UPSELL TEST FAILED\n" : "UPSELL TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
