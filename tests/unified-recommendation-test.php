<?php
/**
 * FaraCart unified product recommendation tests (Suggestions + Upsells
 * consolidation).
 *
 * Boots WordPress and exercises the ProductRecommendationEngine against
 * products created inside a single database transaction (rolled back
 * afterwards, caches flushed) so the suite stays read-only like the
 * others:
 *
 *  - service wiring from the DI container
 *  - gates: completed / ineligible missions return no recommendations
 *  - BOTH strategies preserved: mission products + cart upsells land in
 *    both pools and merge into ONE item with source 'both' (deduped —
 *    never twice in the same block)
 *  - suggestion-only pool (quantity mission): the upsell half is skipped by
 *    type, every item stays source 'suggestion' and is scored on the
 *    unified 0–100 scale through the ranker's normalized scorer
 *  - upsell-only pool: a candidate injected via the existing
 *    faracart_upsell_candidates filter (outside every suggestion source)
 *    surfaces with source 'upsell' — the merged list never fabricates a
 *    suggestion label for it
 *  - merged score for a 'both' product equals the ranker's composite
 *    score (the stronger signal wins, no incompatible scales compared)
 *  - deterministic ranking, cart-item exclusion, out-of-stock exclusion,
 *    cap at the configured limit, no padding to fill slots
 *  - item shape (id === product_id, name/permalink/price_html/source/score)
 *  - the faracart_suggestions developer filter still applies
 *
 * The ranking sections pin `faracart_upsell_weights` to relevance-only
 * (the ranker's own developer filter) so fixture mission products always
 * outrank the LIVE catalog's best sellers — the same live-DB robustness
 * the other suites rely on.
 *
 * Run: php tests/unified-recommendation-test.php (from the plugin directory)
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

use FaraCart\Analytics\UpsellRanker;
use FaraCart\Database\Installer;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionResult;
use FaraCart\Recommendations\ProductRecommendationEngine;
use FaraCart\Settings\Settings;
use FaraCart\Suggestions\SuggestionEngine;

// The tables back the ranker's per-product stats (created by
// Installer::maybe_upgrade(), which never fires in CLI after wp-load).
Installer::maybe_create_tables();

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

function mission( array $data ) {
	return new Mission( $data );
}

/**
 * Create a product category inside the active transaction.
 *
 * @param string $name Category name.
 * @param string $slug Unique slug.
 * @return int Term id.
 */
function make_cat( $name, $slug ) {
	$term = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );

	if ( is_wp_error( $term ) ) {
		$existing = get_term_by( 'slug', $slug, 'product_cat' );

		return $existing ? (int) $existing->term_id : 0;
	}

	return (int) $term['term_id'];
}

/**
 * Create a product inside the active transaction.
 *
 * @param string $name Product name.
 * @param float  $price Price.
 * @param int[]  $categories product_cat term ids.
 * @param string $stock instock|outofstock.
 * @param int    $sales total_sales.
 * @param int[]  $upsells Upsell product ids.
 * @return int New product id.
 */
function make_product( $name, $price, $categories = array(), $stock = 'instock', $sales = 0, $upsells = array() ) {
	$id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_status' => 'publish',
		'post_title'  => $name,
	) );

	update_post_meta( $id, '_regular_price', (string) $price );
	update_post_meta( $id, '_price', (string) $price );
	update_post_meta( $id, '_stock_status', $stock );
	update_post_meta( $id, '_stock', 'outofstock' === $stock ? 0 : 5 );
	update_post_meta( $id, '_total_sales', (string) $sales );

	if ( ! empty( $categories ) ) {
		wp_set_object_terms( $id, $categories, 'product_cat' );
	}

	if ( ! empty( $upsells ) ) {
		update_post_meta( $id, '_upsell_ids', $upsells );
	}

	return (int) $id;
}

/**
 * Product ids in a shaped recommendation list.
 *
 * @param array $items Unified items.
 * @return int[]
 */
function item_ids( array $items ) {
	return array_map( function ( $item ) {
		return (int) $item['id'];
	}, $items );
}

/**
 * Pin the ranker to relevance-only scoring (the ranker's own developer
 * filter) so fixture mission products deterministically outrank the live
 * catalog's best sellers — live-DB-robust ranking for the merge tests.
 *
 * @return void
 */
function pin_relevance_weights() {
	add_filter( 'faracart_upsell_weights', function () {
		return array(
			'price_gap'  => 0,
			'relevance'  => 1,
			'popularity' => 0,
			'inventory'  => 0,
			'margin'     => 0,
			'conversion' => 0,
		);
	} );
}

$container = \FaraCart\Plugin::instance()->container();

$engine   = $container->get( ProductRecommendationEngine::class );
$ranker   = $container->get( UpsellRanker::class );
$settings = $container->get( Settings::class );

// Pin the shared gates on (deterministic baseline — the stored options
// may hold non-default values) and force the upsell half on so both
// strategies always participate.
$settings->set( 'enabled', true );
$settings->set( 'analytics_enabled', true );
add_filter( 'faracart_upsells_enabled', '__return_true' );

// ---------------------------------------------------------------------------
// 1. Service wiring
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'ProductRecommendationEngine resolves from container', $engine instanceof ProductRecommendationEngine );
check( 'UpsellRanker resolves from container', $ranker instanceof UpsellRanker );
check( 'SuggestionEngine resolves from container', $container->get( SuggestionEngine::class ) instanceof SuggestionEngine );

$wpdb    = $GLOBALS['wpdb'];
$created = array();
$wpdb->query( 'START TRANSACTION' );
try {
	// -----------------------------------------------------------------------
	// Minimal catalog: no-padding + gates + item shape (fewer mission products
	// than the default limit — the block must never invent fillers).
	// -----------------------------------------------------------------------
	echo "\n== 2. Minimal catalog: no padding & gates ==\n";

	pin_relevance_weights();

	$m1 = make_product( 'Mike One', 25, array(), 'instock', 0 );
	$m2 = make_product( 'Mike Two', 35, array(), 'instock', 0 );
	$created = array_merge( $created, array( $m1, $m2 ) );

	$minimal_mission = mission( array( 'type' => Mission::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping', 'products' => array( $m1, $m2 ) ) );
	$empty_cart   = new CartContext( array( 'subtotal' => 0, 'total' => 0 ) );

	$minimal_items = $engine->recommend( $minimal_mission, new MissionResult( $minimal_mission, 0, 100 ), $empty_cart );
	$minimal_ids   = item_ids( $minimal_items );

	check( 'two mission products → exactly two items (no padding to the limit)', 2 === count( $minimal_items ) );
	check( 'no duplicate products in one block', count( $minimal_ids ) === count( array_unique( $minimal_ids ) ) );

	// Gates first.
	$done = new MissionResult( $minimal_mission, 120, 100 );
	check( 'completed mission → no recommendations', array() === $engine->recommend( $minimal_mission, $done, $empty_cart ) );

	$gone = MissionResult::ineligible( $minimal_mission, MissionResult::REASON_NO_MATCHING_ITEMS );
	check( 'ineligible mission → no recommendations', array() === $engine->recommend( $minimal_mission, $gone, $empty_cart ) );

	// Item shape.
	$first = $minimal_items[0];
	check( 'item carries id === product_id', isset( $first['id'], $first['product_id'] ) && (int) $first['id'] === (int) $first['product_id'] );
	check( 'item carries name/permalink/price_html', isset( $first['name'], $first['permalink'], $first['price_html'] ) && '' !== $first['name'] );
	check( 'item carries source + 0–100 score', isset( $first['source'], $first['score'] ) && is_numeric( $first['score'] ) && (float) $first['score'] >= 0 && (float) $first['score'] <= 100 );

	// -----------------------------------------------------------------------
	// Rich catalog: both pools merge, dedupe, source attribution, ranking.
	// -----------------------------------------------------------------------
	echo "\n== 3. Both pools merge ==\n";

	$cat10 = make_cat( 'Category Ten', 'cat-ten' );

	// The cart item — Echo rides as its upsell so Echo lands in BOTH the
	// suggestion pool (upsell source) and the ranker pool.
	$p_a = make_product( 'Alpha', 40, array( $cat10 ) );
	$created[] = $p_a;

	// Mission manual products (both pools via the manual source). Hotel is
	// out of stock and must never surface.
	$p_e = make_product( 'Echo', 55, array( $cat10 ), 'instock', 0, array() );
	$p_f = make_product( 'Foxtrot', 90, array(), 'instock', 0 );
	$p_h = make_product( 'Hotel', 20, array( $cat10 ), 'outofstock', 0 );
	$created = array_merge( $created, array( $p_e, $p_f, $p_h ) );

	update_post_meta( $p_a, '_upsell_ids', array( $p_e ) );

	// Best-seller fillers (higher sales, outside the mission) — with the
	// relevance-only weights they must never crowd the mission's products.
	$fillers = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$fillers[] = make_product( 'Filler ' . $i, 10 + $i, array(), 'instock', 5 );
	}
	$created = array_merge( $created, $fillers );

	// The upsell-only injection target: no category, no links, sales 0 —
	// outside the top-10 best sellers, so no suggestion source collects
	// it. The faracart_upsell_candidates filter (section 5) pushes it
	// through the ranker alone.
	$p_t = make_product( 'Target', 55, array(), 'instock', 0 );
	$created[] = $p_t;

	$cart = new CartContext( array(
		'subtotal' => 40,
		'total'    => 40,
		'items'    => array(
			array( 'product_id' => $p_a, 'name' => 'Alpha', 'quantity' => 1, 'line_subtotal' => 40, 'line_total' => 40 ),
		),
	) );

	$mission = mission( array(
		'type'     => Mission::TYPE_AMOUNT,
		'target'   => 100,
		'name'     => 'Free shipping',
		'products' => array( $p_e, $p_f, $m1, $m2, $p_h ),
	) );

	$result = new MissionResult( $mission, 40, 100 ); // remaining 60
	$items  = $engine->recommend( $mission, $result, $cart );
	$ids    = item_ids( $items );

	check( 'progressing mission returns recommendations', ! empty( $items ) );
	check( 'result capped at the default limit (3)', count( $items ) <= ProductRecommendationEngine::DEFAULT_LIMIT );
	check( 'mission products outrank live best sellers (top slots are the mission\'s)', 3 === count( $items ) );

	// Echo (mission product + cart upsell) merges from BOTH engines into one
	// item with source 'both' — the core consolidation guarantee.
	// (array_filter keeps original keys; reindex so [0] is always the hit.)
	$echo_items = array_values( array_filter( $items, function ( $item ) use ( $p_e ) {
		return (int) $item['id'] === $p_e;
	} ) );
	check( 'product in both pools appears exactly once', 1 === count( $echo_items ) );
	check( 'merged item carries source both', 1 === count( $echo_items ) && 'both' === $echo_items[0]['source'] );

	check( 'cart item never recommended back (Alpha)', ! in_array( $p_a, $ids, true ) );
	check( 'out-of-stock product excluded (Hotel)', ! in_array( $p_h, $ids, true ) );

	// The merged score is the ranker's composite (the stronger, unified
	// signal) — never a mismatched scale.
	$ranker_payload = $ranker->rank( array(
		// Same in-memory mission the unified engine hands over, so the
		// composite equals the merged score exactly.
		'mission'       => $mission,
		'mission_id'    => $mission->id(),
		'cart_value' => 40,
		'remaining'  => 60,
		'cart'       => array( $p_a ),
		'exclude'    => $mission->excluded_products(),
		'limit'      => ProductRecommendationEngine::DEFAULT_LIMIT,
	) );
	$ranker_echo = null;
	foreach ( (array) ( isset( $ranker_payload['recommendations'] ) ? $ranker_payload['recommendations'] : array() ) as $ranked ) {
		if ( (int) $ranked['product_id'] === $p_e ) {
			$ranker_echo = $ranked;
			break;
		}
	}
	check(
		'merged score equals the ranker composite for a both product',
		null !== $ranker_echo && abs( (float) $echo_items[0]['score'] - (float) $ranker_echo['score'] ) < 0.01
	);

	// Every item, whichever half it came from, is scored on the SAME
	// 0–100 scale.
	$scores_ok = true;
	foreach ( $items as $item ) {
		if ( ! is_numeric( $item['score'] ) || (float) $item['score'] < 0 || (float) $item['score'] > 100 ) {
			$scores_ok = false;
		}
	}
	check( 'every unified score stays on the 0–100 scale', $scores_ok );

	// Determinism: the same mission + cart + catalog always yields the same
	// ranked list.
	$again = $engine->recommend( $mission, $result, $cart );
	check( 'ranking is deterministic', item_ids( $items ) === item_ids( $again ) );

	// -----------------------------------------------------------------------
	// 4. Suggestion-only pool: quantity missions skip the upsell half by type.
	// -----------------------------------------------------------------------
	echo "\n== 4. Suggestion-only pool (quantity mission) ==\n";

	$qty_mission = mission( array( 'type' => Mission::TYPE_QUANTITY, 'target' => 10, 'calculation_mode' => Mission::MODE_QUANTITY, 'products' => array( $p_e, $p_f ) ) );
	$qty_items = $engine->recommend( $qty_mission, new MissionResult( $qty_mission, 4, 10 ), $cart );
	$qty_ids   = item_ids( $qty_items );

	check( 'quantity mission still recommends (suggestion half)', in_array( $p_e, $qty_ids, true ) && in_array( $p_f, $qty_ids, true ) );

	$all_suggestion = true;
	$all_scaled     = true;
	foreach ( $qty_items as $item ) {
		if ( 'suggestion' !== $item['source'] ) {
			$all_suggestion = false;
		}
		if ( ! is_numeric( $item['score'] ) || (float) $item['score'] < 0 || (float) $item['score'] > 100 ) {
			$all_scaled = false;
		}
	}
	check( 'every item keeps source suggestion when the upsell half is skipped', $all_suggestion );
	check( 'suggestion-only items are normalized to the 0–100 scale', $all_scaled );

	// -----------------------------------------------------------------------
	// 5. Upsell-only pool: a ranker-only candidate keeps source upsell.
	// -----------------------------------------------------------------------
	echo "\n== 5. Upsell-only pool (rank-endpoint candidate) ==\n";

	// The ranker pool is replaced by the injected target alone (default
	// weights — price-gap etc. — and a wide limit so the target is
	// guaranteed a slot), while the suggestion half contributes its own
	// items. The target must surface once, tagged 'upsell'.
	remove_all_filters( 'faracart_upsell_weights' );
	add_filter( 'faracart_upsell_candidates', function ( $candidates ) use ( $p_t ) {
		return array( $p_t => UpsellRanker::SOURCE_POPULAR );
	} );
	add_filter( 'faracart_frontend_upsell_limit', function () {
		return 10;
	} );

	$open_mission  = mission( array( 'type' => Mission::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping' ) );
	$open_items = $engine->recommend( $open_mission, new MissionResult( $open_mission, 40, 100 ), $cart );

	$target_items = array_values( array_filter( $open_items, function ( $item ) use ( $p_t ) {
		return (int) $item['id'] === $p_t;
	} ) );
	check( 'ranker-only candidate surfaces in the unified list', 1 === count( $target_items ) );
	check( 'ranker-only candidate keeps source upsell', 1 === count( $target_items ) && 'upsell' === $target_items[0]['source'] );
	check( 'ranker-only candidate is scored on the 0–100 scale', 1 === count( $target_items ) && is_numeric( $target_items[0]['score'] ) && (float) $target_items[0]['score'] >= 0 && (float) $target_items[0]['score'] <= 100 );

	remove_all_filters( 'faracart_upsell_candidates' );
	remove_all_filters( 'faracart_frontend_upsell_limit' );

	// -----------------------------------------------------------------------
	// 6. Configurable limit + developer filter.
	// -----------------------------------------------------------------------
	echo "\n== 6. Limit & filter ==\n";

	pin_relevance_weights();

	add_filter( 'faracart_frontend_upsell_limit', function () {
		return 5;
	} );
	$wide_items = $engine->recommend( $mission, $result, $cart );
	check( 'configured limit (5) respected without padding', 4 === count( $wide_items ) );
	remove_all_filters( 'faracart_frontend_upsell_limit' );

	add_filter( 'faracart_suggestions', function ( $items ) {
		return array_slice( $items, 0, 1 ); // keep only the top recommendation
	} );
	$filtered = $engine->recommend( $mission, $result, $cart );
	check( 'faracart_suggestions developer filter still applies to the merged list', 1 === count( $filtered ) );
	remove_all_filters( 'faracart_suggestions' );
	remove_all_filters( 'faracart_upsell_weights' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

remove_all_filters( 'faracart_upsells_enabled' );

// ---------------------------------------------------------------------------
// 7. Rollback hygiene: no residue, caches flushed.
// ---------------------------------------------------------------------------
echo "\n== 7. Rollback hygiene ==\n";

foreach ( $created as $id ) {
	clean_post_cache( $id );
	check( "rolled-back product {$id} is gone", null === get_post( $id ) );
}

wp_cache_flush();

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "UNIFIED RECOMMENDATION TEST FAILED\n" : "UNIFIED RECOMMENDATION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
