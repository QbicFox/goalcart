<?php
/**
 * FaraCart smart-suggestion tests (P14-T02 / P14-T03 / P14-T04).
 *
 * Boots WordPress and exercises the Phase 14 SuggestionEngine against
 * products created inside a single database transaction (rolled back
 * afterwards, caches flushed) so the suite stays read-only like the
 * others:
 *
 *  - service wiring from the DI container
 *  - gates: completed / ineligible goals return no suggestions
 *  - sources: manual (goal products), category, cart upsells /
 *    cross-sells / related, best sellers (fallback)
 *  - stock availability filter (out-of-stock excluded)
 *  - cart items never suggested back
 *  - ranking: goal eligibility + price proximity to the remaining amount
 *  - cap at MAX_SUGGESTIONS, dedupe across sources, ghost ids skipped
 *  - the faracart_suggestions filter
 *
 * Run: php tests/suggestion-test.php   (from the plugin directory)
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

use FaraCart\Goals\CartContext;
use FaraCart\Goals\Goal;
use FaraCart\Goals\GoalResult;
use FaraCart\Suggestions\SuggestionEngine;

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

function goal( array $data ) {
	return new Goal( $data );
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
 * @param string $name       Product name.
 * @param float  $price      Price.
 * @param int[]  $categories product_cat term ids.
 * @param string $stock      instock|outofstock.
 * @param int    $sales      total_sales.
 * @param int[]  $upsells    Upsell product ids.
 * @param int[]  $cross      Cross-sell product ids.
 * @return int New product id.
 */
function make_product( $name, $price, $categories = array(), $stock = 'instock', $sales = 0, $upsells = array(), $cross = array() ) {
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

	if ( ! empty( $cross ) ) {
		update_post_meta( $id, '_crosssell_ids', $cross );
	}

	return (int) $id;
}

$engine = \FaraCart\Plugin::instance()->container()->get( SuggestionEngine::class );

// ---------------------------------------------------------------------------
// 1. Service wiring + gates
// ---------------------------------------------------------------------------
echo "\n== 1. Wiring & gates ==\n";

check( 'SuggestionEngine resolves from container', $engine instanceof SuggestionEngine );

$wpdb = $GLOBALS['wpdb'];
$created = array();
$wpdb->query( 'START TRANSACTION' );	try {
	$cat10 = make_cat( 'Category Ten', 'cat-ten' );
	$cat11 = make_cat( 'Category Eleven', 'cat-eleven' );

	// The cart item product — upsells/cross-sells are wired AFTER every
	// target product exists (and a ghost upsell id must not break the run).
	$p_a = make_product( 'Alpha', 40, array( $cat10 ) );
	$created[] = $p_a;

	$p_b = make_product( 'Bravo', 100, array( $cat10 ), 'outofstock', 0 ); // out of stock
	$p_c = make_product( 'Charlie', 200, array(), 'instock', 0 );          // far above the gap
	$p_d = make_product( 'Delta', 150, array( $cat11 ), 'instock', 0 );    // cross-sell, above band
	$p_e = make_product( 'Echo', 55, array( $cat10 ), 'instock', 30 );     // upsell, in band
	$p_f = make_product( 'Foxtrot', 90, array(), 'instock', 100 );         // best seller
	$created = array_merge( $created, array( $p_b, $p_c, $p_d, $p_e, $p_f ) );

	update_post_meta( $p_a, '_upsell_ids', array( $p_e, 999999 ) ); // ghost id must not break
	update_post_meta( $p_a, '_crosssell_ids', array( $p_d ) );

	$cart = new CartContext( array(
		'subtotal' => 40,
		'total'    => 40,
		'items'    => array(
			array( 'product_id' => $p_a, 'name' => 'Alpha', 'quantity' => 1, 'line_subtotal' => 40, 'line_total' => 40 ),
		),
	) );

	$goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'name' => 'Free shipping' ) );

	// Gates first.
	$done = new GoalResult( $goal, 120, 100 );
	check( 'completed goal → no suggestions', array() === $engine->suggest( $goal, $done, $cart ) );

	$gone = GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
	check( 'ineligible goal → no suggestions', array() === $engine->suggest( $goal, $gone, $cart ) );

	// -----------------------------------------------------------------------
	// 2. Sources + ranking on a progressing goal (remaining 60).
	// -----------------------------------------------------------------------
	echo "\n== 2. Sources & ranking ==\n";

	$result = new GoalResult( $goal, 40, 100 ); // remaining 60
	$items  = $engine->suggest( $goal, $result, $cart );

	check( 'progressing goal returns suggestions', ! empty( $items ) );
	check( 'suggestions capped at MAX_SUGGESTIONS', count( $items ) <= SuggestionEngine::MAX_SUGGESTIONS );

	$ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $items );

	check( 'upsell product suggested (Echo)', in_array( $p_e, $ids, true ) );
	check( 'cross-sell product suggested (Delta)', in_array( $p_d, $ids, true ) );
	check( 'out-of-stock product excluded (Bravo)', ! in_array( $p_b, $ids, true ) );
	check( 'cart item never suggested back (Alpha)', ! in_array( $p_a, $ids, true ) );
	check( 'ghost upsell id did not break the engine', is_array( $items ) );

	// In-band price (Echo 55 vs remaining 60) outranks the far-away product.
	$rank_e = array_search( $p_e, $ids, true );
	$rank_c = array_search( $p_c, $ids, true );
	check( 'price-proximity ranks in-band product above far product', false !== $rank_e && ( false === $rank_c || $rank_e < $rank_c ) );

	// Dedupe: Echo must appear exactly once (upsell + best-seller sources).
	$echo_count = count( array_filter( $ids, function ( $id ) use ( $p_e ) {
		return $id === $p_e;
	} ) );
	check( 'product deduped across sources', 1 === $echo_count );

	// Item shape.
	$first = $items[0];
	check( 'item carries id/name/permalink', isset( $first['id'], $first['name'], $first['permalink'] ) );
	check( 'item carries price + price_html', isset( $first['price'], $first['price_html'] ) && '' !== $first['price_html'] );

	// The price label is plain text with the currency symbol's HTML
	// entities decoded (WooCommerce ships the IRT "تومان" symbol as an
	// entity) — a raw "&#x062A;…" would show literally to shoppers.
	check( 'price label has no raw entity text', false === strpos( (string) $first['price_html'], '&#' ) );

	check( 'item carries source + stock_status', isset( $first['source'], $first['stock_status'] ) );
	check( 'upsell item tagged with source', $p_e === (int) $items[0]['id'] && SuggestionEngine::SOURCE_UPSELL === $items[0]['source'] );

	// -----------------------------------------------------------------------
	// 3. Category goal (empty cart): category products are the pool.
	// -----------------------------------------------------------------------
	echo "\n== 3. Category goal ==\n";

	$empty = new CartContext( array( 'subtotal' => 0, 'total' => 0 ) );
	$cat_goal = goal( array( 'type' => Goal::TYPE_CATEGORY, 'target' => 100, 'categories' => array( $cat10 ) ) );
	$cat_items = $engine->suggest( $cat_goal, new GoalResult( $cat_goal, 40, 100 ), $empty );
	$cat_ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $cat_items );

	check( 'category goal suggests in-category products', in_array( $p_a, $cat_ids, true ) && in_array( $p_e, $cat_ids, true ) );
	check( 'out-of-stock in-category product still excluded', ! in_array( $p_b, $cat_ids, true ) );

	// -----------------------------------------------------------------------
	// 4. Manual products + exclusions.
	// -----------------------------------------------------------------------
	echo "\n== 4. Manual & exclusions ==\n";

	$manual_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'products' => array( $p_d ) ) );
	$manual_items = $engine->suggest( $manual_goal, new GoalResult( $manual_goal, 40, 100 ), $cart );
	$manual_ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $manual_items );

	check( 'explicitly selected product suggested first', isset( $manual_ids[0] ) && $p_d === $manual_ids[0] );

	$excl_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 100, 'excluded_products' => array( $p_e ) ) );
	$excl_items = $engine->suggest( $excl_goal, new GoalResult( $excl_goal, 40, 100 ), $cart );
	$excl_ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $excl_items );
	check( 'excluded product never suggested', ! in_array( $p_e, $excl_ids, true ) );

	// -----------------------------------------------------------------------
	// 5. Quantity goal: no price banding, no throw.
	// -----------------------------------------------------------------------
	echo "\n== 5. Quantity goal ==\n";

	$qty_goal = goal( array( 'type' => Goal::TYPE_QUANTITY, 'target' => 10, 'calculation_mode' => Goal::MODE_QUANTITY ) );
	$qty_items = $engine->suggest( $qty_goal, new GoalResult( $qty_goal, 4, 10 ), $cart );
	check( 'quantity goal suggests without price banding', is_array( $qty_items ) && ! empty( $qty_items ) );

	// -----------------------------------------------------------------------
	// 6. faracart_suggestions filter.
	// -----------------------------------------------------------------------
	echo "\n== 6. Filter ==\n";

	add_filter( 'faracart_suggestions', function ( $items, $filter_goal, $filter_result, $filter_context ) {
		return array_slice( $items, 0, 1 ); // keep only the top suggestion
	}, 10, 4 );

	$filtered = $engine->suggest( $goal, $result, $cart );
	check( 'faracart_suggestions filter applied', 1 === count( $filtered ) );
	remove_all_filters( 'faracart_suggestions' );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

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
echo $failures > 0 ? "SUGGESTION TEST FAILED\n" : "SUGGESTION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
