<?php
/**
 * Goal Cart settings tests (P18-T01 General / P18-T02 Frontend /
 * P18-T03 Goal Calculation / P18-T04 Performance / P18-T05 Advanced).
 *
 * Boots WordPress and exercises the Phase 18 settings surface:
 *
 *  - defaults: every new setting key ships with the documented default
 *    (each preserves the pre-Phase-18 behavior)
 *  - the REST schema covers the new keys (enums for currency display /
 *    goal behavior / calculation mode / mobile, the location enum,
 *    booleans) and the sanitizer normalizes invalid values
 *  - goal calculation (P18-T03): CartContext::from_cart honors
 *    include_tax / include_discount / include_shipping / include_sale /
 *    include_virtual, and CartIntegration applies the settings
 *  - the store-wide calculation mode filter (amount goals follow it,
 *    quantity-style goals stay untouched)
 *  - frontend (P18-T02): locations follow the frontend_locations setting,
 *    the sticky bar is gated on the 'sticky' location, and the frontend
 *    config carries currencyDisplay + mobile
 *  - general (P18-T01): default_goal_behavior (all | first | closest)
 *    narrows the progress payload, and the progress cache serves the
 *    stored payload (P18-T04 performance caching)
 *  - performance (P18-T04): the analytics toggle gates the tracker and
 *    the suggestions toggle empties the storefront suggestion list
 *  - advanced (P18-T05): the GET settings meta carries the developer-hooks
 *    reference, and the Logger respects logging_enabled + debug_mode
 *
 * Settings flips are in-memory and restored; the only real writes
 * (goals, the settings option, the progress-cache transient, the debug
 * log file) are rolled back / removed, and residue is asserted.
 *
 * Run: php tests/settings-test.php   (from the plugin directory)
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

use GoalCart\Analytics\Tracker;
use GoalCart\Cart\CartIntegration;
use GoalCart\Frontend\ProgressUI;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\REST\FrontendController;
use GoalCart\REST\SettingsController;
use GoalCart\Settings\Settings;
use GoalCart\Utils\Logger;

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

// Bare product object: from_cart reads money values from the cart item
// array, so no real product row is needed (same pattern as the
// cart-integration suite).
function bare_product( $name = 'Test product' ) {
	$product = new \WC_Product_Simple();
	$product->set_name( $name );

	return $product;
}

function cart_line( $key, $product_id, $variation_id, $quantity, $subtotal, $total, $product = null, $line_tax = 0.0 ) {
	return array(
		'key'               => $key,
		'product_id'        => $product_id,
		'variation_id'      => $variation_id,
		'quantity'          => $quantity,
		'data'              => null !== $product ? $product : bare_product(),
		'line_subtotal'     => $subtotal,
		'line_total'        => $total,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => $line_tax,
	);
}

// A published product row (suggestion candidates read through the DB).
function make_product( $name, $price ) {
	$id = wp_insert_post( array(
		'post_type'   => 'product',
		'post_status' => 'publish',
		'post_title'  => $name,
	) );

	update_post_meta( $id, '_regular_price', (string) $price );
	update_post_meta( $id, '_price', (string) $price );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_stock', 5 );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_virtual', 'no' );
	update_post_meta( $id, '_downloadable', 'no' );

	return (int) $id;
}

$container = \GoalCart\Plugin::instance()->container();

$settings    = $container->get( Settings::class );
$settings_ctrl = $container->get( SettingsController::class );
$ui          = $container->get( ProgressUI::class );
$ci          = $container->get( CartIntegration::class );
$frontend    = $container->get( FrontendController::class );
$tracker     = $container->get( Tracker::class );
$engine      = $container->get( GoalEngine::class );

// Snapshot the in-memory settings so every section can restore them.
$all_before = $settings->all();

// Snapshot the persisted option too: sections 2 and 8 write it, so the
// final cleanup restores whatever the suite found (delete when it was
// missing, restore the stored value otherwise) instead of assuming
// absence.
$option_before = get_option( Settings::OPTION_NAME, null );

// ---------------------------------------------------------------------------
// 1. Defaults & wiring (P18-T01 – T05)
// ---------------------------------------------------------------------------
echo "\n== 1. Defaults & wiring ==\n";

check( 'settings resolves from container', $settings instanceof Settings );
check( 'settings filter registered', false !== has_filter( 'goalcart_default_calculation_mode', array( $settings, 'apply_default_calculation_mode' ) ) );

$d = $settings->defaults();

check( 'currency_display defaults to symbol', 'symbol' === $d['currency_display'] );
check( 'default_goal_behavior defaults to all', 'all' === $d['default_goal_behavior'] );
check( 'conflict_resolution defaults to cumulative', 'cumulative' === $d['conflict_resolution'] );
check( 'calculation_mode defaults to subtotal', 'subtotal' === $d['calculation_mode'] );
check( 'frontend_locations defaults to six locations', 6 === count( $d['frontend_locations'] ) );
check( 'frontend_mobile defaults to show', 'show' === $d['frontend_mobile'] );
check( 'include_tax defaults false', false === $d['calculation_include_tax'] );
check( 'include_discount defaults true', true === $d['calculation_include_discount'] );
check( 'include_shipping defaults true', true === $d['calculation_include_shipping'] );
check( 'include_sale defaults true', true === $d['calculation_include_sale'] );
check( 'include_virtual defaults true', true === $d['calculation_include_virtual'] );
check( 'performance_caching defaults false', false === $d['performance_caching'] );
check( 'analytics_enabled defaults true', true === $d['analytics_enabled'] );
check( 'performance_suggestions defaults true', true === $d['performance_suggestions'] );
check( 'debug_mode defaults false', false === $d['debug_mode'] );
check( 'logging_enabled defaults false', false === $d['logging_enabled'] );
check( 'developer_hooks defaults true', true === $d['developer_hooks'] );

// Store-wide calculation mode: amount goals follow it, quantity-style
// goals keep their type default.
check( 'default mode subtotal for amount', 'subtotal' === Goal::default_calculation_mode( 'amount' ) );

$settings->set( 'calculation_mode', 'total' );
check( 'store mode applies to amount goals', 'total' === Goal::default_calculation_mode( 'amount' ) );
check( 'store mode applies to category goals', 'total' === Goal::default_calculation_mode( 'category' ) );
check( 'quantity goals keep their default', 'subtotal' === Goal::default_calculation_mode( 'quantity' ) );
check( 'product goals keep quantity', 'quantity' === Goal::default_calculation_mode( 'product' ) );
$settings->set( 'calculation_mode', 'subtotal' );

// ---------------------------------------------------------------------------
// 2. REST schema + sanitizer (P18-T01 – T05)
// ---------------------------------------------------------------------------
echo "\n== 2. Schema & sanitizer ==\n";

$save = $settings_ctrl->save_args();

check( 'currency_display schema enum', isset( $save['currency_display']['enum'] ) );
check( 'invalid currency_display rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['currency_display'], 'currency_display' ) ) );
check( 'valid currency_display accepted', true === rest_validate_value_from_schema( 'name', $save['currency_display'], 'currency_display' ) );

check( 'default_goal_behavior schema enum', isset( $save['default_goal_behavior']['enum'] ) );
check( 'invalid behavior rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['default_goal_behavior'], 'default_goal_behavior' ) ) );
check( 'valid behavior accepted', true === rest_validate_value_from_schema( 'closest', $save['default_goal_behavior'], 'default_goal_behavior' ) );

check( 'conflict_resolution schema enum', isset( $save['conflict_resolution']['enum'] ) );
check( 'invalid conflict mode rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['conflict_resolution'], 'conflict_resolution' ) ) );
check( 'valid conflict mode accepted', true === rest_validate_value_from_schema( 'best', $save['conflict_resolution'], 'conflict_resolution' ) );

check( 'calculation_mode schema enum', isset( $save['calculation_mode']['enum'] ) );
check( 'invalid calculation_mode rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['calculation_mode'], 'calculation_mode' ) ) );

check( 'frontend_mobile schema enum', isset( $save['frontend_mobile']['enum'] ) );
check( 'invalid mobile rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['frontend_mobile'], 'frontend_mobile' ) ) );

check( 'frontend_locations items enum', isset( $save['frontend_locations']['items']['enum'] ) );
check( 'unknown location item rejected', is_wp_error( rest_validate_value_from_schema( array( 'cart', 'bogus' ), $save['frontend_locations'], 'frontend_locations' ) ) );
check( 'valid locations accepted', true === rest_validate_value_from_schema( array( 'cart', 'sticky' ), $save['frontend_locations'], 'frontend_locations' ) );

check( 'calculation_include_tax boolean schema', 'boolean' === $save['calculation_include_tax']['type'] );
check( 'performance_caching boolean schema', 'boolean' === $save['performance_caching']['type'] );
check( 'analytics_enabled boolean schema', 'boolean' === $save['analytics_enabled']['type'] );
check( 'performance_suggestions boolean schema', 'boolean' === $save['performance_suggestions']['type'] );
check( 'debug_mode boolean schema', 'boolean' === $save['debug_mode']['type'] );
check( 'logging_enabled boolean schema', 'boolean' === $save['logging_enabled']['type'] );
check( 'developer_hooks boolean schema', 'boolean' === $save['developer_hooks']['type'] );

// Sanitizer normalization through direct handler calls (no schema pass).
$wpdb = $GLOBALS['wpdb'];
$wpdb->query( 'START TRANSACTION' );

try {
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req->set_param( 'currency_display', 'bogus' );
	$req->set_param( 'default_goal_behavior', 'bogus' );
	$req->set_param( 'conflict_resolution', 'bogus' );
	$req->set_param( 'calculation_mode', 'bogus' );
	$req->set_param( 'frontend_mobile', 'bogus' );
	$req->set_param( 'frontend_locations', array( 'cart', 'bogus', 'sticky', 'sticky' ) );
	$req->set_param( 'calculation_include_tax', true );
	$req->set_param( 'calculation_include_discount', false );
	$req->set_param( 'calculation_include_shipping', false );
	$req->set_param( 'calculation_include_sale', false );
	$req->set_param( 'calculation_include_virtual', false );
	$req->set_param( 'performance_caching', true );
	$req->set_param( 'analytics_enabled', false );
	$req->set_param( 'performance_suggestions', false );
	$req->set_param( 'debug_mode', true );
	$req->set_param( 'logging_enabled', false );
	$req->set_param( 'developer_hooks', false );

	$resp = $settings_ctrl->handle_save( $req );
	$data = $resp->get_data()['data'];

	check( 'invalid currency falls back to symbol', 'symbol' === $data['currency_display'] );
	check( 'invalid behavior falls back to all', 'all' === $data['default_goal_behavior'] );
	check( 'invalid conflict mode falls back to cumulative', 'cumulative' === $data['conflict_resolution'] );
	check( 'invalid mode falls back to subtotal', 'subtotal' === $data['calculation_mode'] );
	check( 'invalid mobile falls back to show', 'show' === $data['frontend_mobile'] );
	check( 'locations filtered + deduped', array( 'cart', 'sticky' ) === $data['frontend_locations'] );
	check( 'include_tax persisted true', true === $data['calculation_include_tax'] );
	check( 'include_discount persisted false', false === $data['calculation_include_discount'] );
	check( 'include_shipping persisted false', false === $data['calculation_include_shipping'] );
	check( 'include_sale persisted false', false === $data['calculation_include_sale'] );
	check( 'include_virtual persisted false', false === $data['calculation_include_virtual'] );
	check( 'caching persisted true', true === $data['performance_caching'] );
	check( 'analytics persisted false', false === $data['analytics_enabled'] );
	check( 'suggestions persisted false', false === $data['performance_suggestions'] );
	check( 'debug persisted true', true === $data['debug_mode'] );
	check( 'logging persisted false', false === $data['logging_enabled'] );
	check( 'developer hooks persisted false', false === $data['developer_hooks'] );
} finally {
	$wpdb->query( 'ROLLBACK' );

	// ROLLBACK reverts the option row, but WP's in-memory options cache
	// still holds the pre-rollback value (a missing row makes delete_option
	// early-return without clearing the cache). Drop the cache entries so
	// later sections read the reverted (missing) option.
	wp_cache_delete( Settings::OPTION_NAME, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}

// The option rolled back; re-sync the in-memory service (handle_save
// mutated it) so the remaining sections run against the defaults.
$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 3. Goal calculation toggles (P18-T03)
// ---------------------------------------------------------------------------
echo "\n== 3. Goal calculation ==\n";

// 3.1 include_tax: line taxes fold into the subtotal-style bases.
$tax_cart = new \WC_Cart();
$tax_cart->cart_contents['t1'] = cart_line( 't1', 0, 0, 1, 200.0, 180.0, null, 20.0 );

$ctx = CartContext::from_cart( $tax_cart );
check( 'tax excluded from subtotal by default', near( 200, $ctx->amount( Goal::MODE_SUBTOTAL ) ) );
check( 'tax excluded from discounted by default', near( 180, $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ) ) );

$ctx = CartContext::from_cart( $tax_cart, array( 'include_tax' => true ) );
check( 'include_tax adds to subtotal', near( 220, $ctx->amount( Goal::MODE_SUBTOTAL ) ) );
check( 'include_tax adds to discounted', near( 200, $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ) ) );
check( 'item carries line_tax', near( 20, $ctx->items()[0]->line_tax() ) );

// 3.2 include_discount: when off the discounted basis ignores discounts.
$ctx = CartContext::from_cart( $tax_cart, array( 'include_discount' => false ) );
check( 'discounts excluded from discounted basis', near( 200, $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ) ) );

$ctx = CartContext::from_cart( $tax_cart, array( 'include_tax' => true, 'include_discount' => false ) );
check( 'discount + tax both applied', near( 220, $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ) ) );

// 3.3 include_shipping: the total basis keeps or drops shipping.
$ship_cart = new \WC_Cart();
$ship_cart->cart_contents['s1'] = cart_line( 's1', 0, 0, 1, 200.0, 180.0 );

$ref = new \ReflectionProperty( $ship_cart, 'totals' );
$ref->setAccessible( true );
$totals = $ref->getValue( $ship_cart );
$totals['shipping_total'] = 10.0;
$totals['total']          = 190.0;
$ref->setValue( $ship_cart, $totals );

$ctx = CartContext::from_cart( $ship_cart );
check( 'shipping stays in total by default', near( 190, $ctx->amount( Goal::MODE_TOTAL ) ) );

$ctx = CartContext::from_cart( $ship_cart, array( 'include_shipping' => false ) );
check( 'include_shipping off drops shipping', near( 180, $ctx->amount( Goal::MODE_TOTAL ) ) );

$ctx = CartContext::from_cart( $ship_cart, array( 'exclude_shipping' => true ) );
check( 'legacy exclude_shipping still wins', near( 180, $ctx->amount( Goal::MODE_TOTAL ) ) );

// 3.4 include_sale: products on sale are dropped from the snapshot.
$sale_product = new \WC_Product_Simple();
$sale_product->set_name( 'On sale' );
$sale_product->set_regular_price( '100' );
$sale_product->set_sale_price( '80' );

$sale_cart = new \WC_Cart();
$sale_cart->cart_contents['n1'] = cart_line( 'n1', 0, 0, 1, 200.0, 200.0 );
$sale_cart->cart_contents['s2'] = cart_line( 's2', 0, 0, 1, 100.0, 80.0, $sale_product );

$ctx = CartContext::from_cart( $sale_cart );
check( 'sale items count by default', near( 300, $ctx->amount( Goal::MODE_SUBTOTAL ) ) && 2 === count( $ctx->items() ) );

$ctx = CartContext::from_cart( $sale_cart, array( 'include_sale' => false ) );
check( 'sale items dropped when excluded', near( 200, $ctx->amount( Goal::MODE_SUBTOTAL ) ) );
check( 'dropped sale item leaves one line', 1 === count( $ctx->items() ) );
check( 'total rebased onto remaining lines', near( 200, $ctx->amount( Goal::MODE_TOTAL ) ) );

// 3.5 include_virtual: virtual/downloadable products are dropped.
$virtual_product = new \WC_Product_Simple();
$virtual_product->set_name( 'Virtual download' );
$virtual_product->set_virtual( true );

$virtual_cart = new \WC_Cart();
$virtual_cart->cart_contents['n2'] = cart_line( 'n2', 0, 0, 1, 200.0, 200.0 );
$virtual_cart->cart_contents['v1'] = cart_line( 'v1', 0, 0, 1, 100.0, 100.0, $virtual_product );

$ctx = CartContext::from_cart( $virtual_cart );
check( 'virtual items count by default', near( 300, $ctx->amount( Goal::MODE_SUBTOTAL ) ) && 2 === count( $ctx->items() ) );

$ctx = CartContext::from_cart( $virtual_cart, array( 'include_virtual' => false ) );
check( 'virtual items dropped when excluded', near( 200, $ctx->amount( Goal::MODE_SUBTOTAL ) ) );
check( 'dropped virtual item leaves one line', 1 === count( $ctx->items() ) );

// 3.6 CartIntegration applies the settings to the live-cart snapshot.
$calc_cart = new \WC_Cart();
$calc_cart->cart_contents['c1'] = cart_line( 'c1', 0, 0, 1, 200.0, 180.0, null, 20.0 );

$settings->set( 'calculation_include_tax', true );
$settings->set( 'calculation_include_discount', false );
$ctx = $ci->context( $calc_cart );
check( 'integration applies include_tax', near( 220, $ctx->amount( Goal::MODE_SUBTOTAL ) ) );
check( 'integration applies include_discount', near( 220, $ctx->amount( Goal::MODE_DISCOUNTED_SUBTOTAL ) ) );
$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 4. Frontend settings (P18-T02)
// ---------------------------------------------------------------------------
echo "\n== 4. Frontend settings ==\n";

check( 'default locations are the six', array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ) === $ui->locations() );

$settings->set( 'frontend_locations', array( 'cart', 'checkout' ) );
check( 'locations follow the setting', array( 'cart', 'checkout' ) === $ui->locations() );
check( 'sticky gated off with location', false === in_array( 'sticky', $ui->locations(), true ) );

add_filter( 'goalcart_frontend_locations', function () {
	return array( 'shop' );
} );
check( 'locations filter overrides', array( 'shop' ) === $ui->locations() );
remove_all_filters( 'goalcart_frontend_locations' );

$settings->set( 'frontend_locations', array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ) );
check( 'sticky location back on', true === in_array( 'sticky', $ui->locations(), true ) );	// Deterministic baseline: the stored option may already hold non-default
	// values (e.g. a dev site saved currency 'name'), so pin the settings
	// before asserting the config passthrough.
	$settings->set( 'currency_display', 'symbol' );
	$settings->set( 'frontend_mobile', 'show' );
	$config = $ui->frontend_config();
	check( 'config carries currencyDisplay', isset( $config['currencyDisplay'] ) && 'symbol' === $config['currencyDisplay'] );
	check( 'config carries mobile', isset( $config['mobile'] ) && 'show' === $config['mobile'] );

	$settings->set( 'currency_display', 'code' );
	$settings->set( 'frontend_mobile', 'hide' );
$config = $ui->frontend_config();
check( 'currencyDisplay follows setting', 'code' === $config['currencyDisplay'] );
check( 'mobile follows setting', 'hide' === $config['mobile'] );
$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 5. Goal behavior + progress caching (P18-T01 / P18-T04)
// ---------------------------------------------------------------------------
echo "\n== 5. Goal behavior & caching (rolled back) ==\n";

$goals_table = \GoalCart\Database\Schema::table( 'goals' );
$goals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
$wpdb->query( 'START TRANSACTION' );

$seeded_ids = array();

try {
	// Drop pre-existing goals inside the transaction (this dev database
	// ships with a leftover active goal) so the behavior checks below see
	// exactly the two seeded goals; the rollback restores every deleted
	// row, which the count check after the transaction verifies.
	$wpdb->query( "DELETE FROM {$goals_table}" );
	// Two active amount goals, ordered by id (priority ties).
	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Settings Goal A',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 1000,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$goal_a = (int) $wpdb->insert_id;
	$seeded_ids[] = $goal_a;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Settings Goal B',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 200,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$goal_b = (int) $wpdb->insert_id;
	$seeded_ids[] = $goal_b;

	$cart = WC()->cart;
	$cart->cart_contents['st1'] = cart_line( 'st1', 0, 0, 2, 200.0, 200.0 );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/progress' );

	// 'all' (default): both goals in the payload.
	$settings->set( 'default_goal_behavior', 'all' );
	$resp = $frontend->handle_progress( $req, $cart );
	$data = $resp->get_data()['data'];
	check( 'all behavior returns both goals', 2 === count( $data['goals'] ) );

	// 'first': only the first (id-ordered) goal.
	$settings->set( 'default_goal_behavior', 'first' );
	$resp = $frontend->handle_progress( $req, $cart );
	$data = $resp->get_data()['data'];
	check( 'first behavior returns one goal', 1 === count( $data['goals'] ) );
	check( 'first behavior keeps the first goal', 'Settings Goal A' === $data['goals'][0]['goal_name'] );

	// 'closest': the eligible goal with the highest percentage (B: 200/200).
	$settings->set( 'default_goal_behavior', 'closest' );
	$resp = $frontend->handle_progress( $req, $cart );
	$data = $resp->get_data()['data'];
	check( 'closest behavior returns one goal', 1 === count( $data['goals'] ) );
	check( 'closest behavior picks goal B', 'Settings Goal B' === $data['goals'][0]['goal_name'] );
	check( 'closest goal is completed', true === $data['goals'][0]['completed'] );

	$settings->set( 'default_goal_behavior', 'all' );

	// Deterministic baseline for the tracking-nonce assertions below: the
	// stored option may hold non-default values, so pin the toggles the
	// nonce gate reads (restored after the caching checks).
	$analytics_before = $settings->get( 'analytics_enabled', true );
	$enabled_before   = $settings->get( 'enabled', true );
	$settings->set( 'analytics_enabled', true );
	$settings->set( 'enabled', true );

	// ---- Progress caching (P18-T04) ----
	// Build the context through the same integration (memoized per cart +
	// args, so the key in the test matches the controller's).
	$context = $ci->context( $cart );
	$cache_key = 'goalcart_progress_' . md5( wp_json_encode( array(
		'ctx'         => array(
			$context->subtotal(),
			$context->total(),
			$context->total_quantity(),
			$context->distinct_product_count(),
			$context->total_weight(),
		),
		'goals'       => array( $goal_a, $goal_b ),
		'behavior'    => 'all',
		'conflict'    => 'cumulative',
		'suggestions' => true,
	) ) );

	delete_transient( $cache_key );

	// Caching off: no transient is written.
	$settings->set( 'performance_caching', false );
	$frontend->handle_progress( $req, $cart );
	check( 'no cache written when caching off', false === get_transient( $cache_key ) );

	// Caching on: the payload lands in the transient.
	$settings->set( 'performance_caching', true );
	$resp = $frontend->handle_progress( $req, $cart );
	$cached = get_transient( $cache_key );
	check( 'cache written when caching on', is_array( $cached ) && 2 === count( $cached['data']['goals'] ) );
	// The self-healing tracking nonce is never stored in the cache (it is
	// regenerated fresh on every read), so a cached payload can never
	// serve a stale or another user's nonce.
	check( 'cache never stores the tracking nonce', is_array( $cached ) && ! isset( $cached['data']['tracking_nonce'] ) );

	// The read path serves the stored payload (sentinel overwrite), with a
	// fresh tracking nonce injected on top.
	$sentinel = array(
		'data' => array( 'goals' => array(), 'currency' => 'USD' ),
		'meta' => array( 'total_goals' => 0 ),
	);
	set_transient( $cache_key, $sentinel, 10 );
	$resp          = $frontend->handle_progress( $req, $cart );
	$served        = $resp->get_data();
	$sentinel_nonce = isset( $served['data']['tracking_nonce'] ) ? (string) $served['data']['tracking_nonce'] : '';
	check( 'cached payload served on read', $served['data']['goals'] === $sentinel['data']['goals'] && $served['data']['currency'] === $sentinel['data']['currency'] && $served['meta'] === $sentinel['meta'] );
	check( 'cached payload gets a fresh tracking nonce', '' !== $sentinel_nonce && false !== wp_verify_nonce( $sentinel_nonce, Tracker::TRACK_NONCE_ACTION ) );

	delete_transient( $cache_key );
	$settings->set( 'performance_caching', false );
	$settings->set( 'analytics_enabled', $analytics_before );
	$settings->set( 'enabled', $enabled_before );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

foreach ( $seeded_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE id = %d", $id ) );
	check( "rolled-back goal {$id} is gone", 0 === $count );
}

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
check( 'pre-existing goals restored on rollback', $goals_before === $goals_after );

check( 'caching transient cleaned up', false === get_transient( $cache_key ) );
$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 6. Suggestions + analytics toggles (P18-T04)
// ---------------------------------------------------------------------------
echo "\n== 6. Performance toggles ==\n";

$wpdb->query( 'START TRANSACTION' );	try {
	$product_id = make_product( 'Settings Suggested Product', 50 );

	$goal = new Goal( array(
		'id'              => 1,
		'name'            => 'Suggestion Settings Goal',
		'status'          => 'active',
		'type'            => 'amount',
		'target'          => 100,
		'calculation_mode' => 'subtotal',
		'products'        => array( $product_id ),
	) );

	$ctx = new CartContext( array( 'subtotal' => 40, 'total' => 40, 'items' => array() ) );
	$result = $engine->evaluate( $goal, $ctx );

	$settings->set( 'performance_suggestions', true );
	$shaped = $frontend->shape_goal( $goal, $result, $ctx );
	$suggestion_ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $shaped['suggestions'] );
	// The site ships other products, so the best-seller fallback may fill
	// the list — what matters is that the explicitly selected product is
	// present and ranks first (manual +3, counts-toward-goal +2, price
	// proximity).
	check( 'suggestions present by default', ! empty( $suggestion_ids ) && $product_id === $suggestion_ids[0] );

	$settings->set( 'performance_suggestions', false );
	$shaped = $frontend->shape_goal( $goal, $result, $ctx );
	check( 'suggestions gated off by setting', array() === $shaped['suggestions'] );

	add_filter( 'goalcart_suggestions_enabled', '__return_true' );
	$shaped = $frontend->shape_goal( $goal, $result, $ctx );
	$suggestion_ids = array_map( function ( $item ) {
		return (int) $item['id'];
	}, $shaped['suggestions'] );
	check( 'suggestions filter overrides the setting', ! empty( $suggestion_ids ) && $product_id === $suggestion_ids[0] );
	remove_all_filters( 'goalcart_suggestions_enabled' );

	$settings->set( 'performance_suggestions', true );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// Analytics toggle gates the tracker.
$settings->set( 'analytics_enabled', false );
check( 'tracking disabled by analytics toggle', false === $tracker->tracking_enabled() );
$settings->set( 'analytics_enabled', true );
check( 'tracking re-enabled', true === $tracker->tracking_enabled() );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 7. Advanced: developer hooks + settings meta (P18-T05)
// ---------------------------------------------------------------------------
echo "\n== 7. Developer hooks meta ==\n";

$resp = $settings_ctrl->handle_get( new \WP_REST_Request( 'GET', '/goalcart/v1/settings' ) );
$data = $resp->get_data();

check( 'GET meta carries hooks', isset( $data['meta']['hooks'] ) && is_array( $data['meta']['hooks'] ) );
check( 'hooks reference is non-empty', count( $data['meta']['hooks'] ) > 0 );

$hooks = array_column( $data['meta']['hooks'], 'hook' );
check( 'suggestions_enabled hook documented', in_array( 'goalcart_suggestions_enabled', $hooks, true ) );
check( 'default_calculation_mode hook documented', in_array( 'goalcart_default_calculation_mode', $hooks, true ) );
check( 'frontend_mobile hook documented', in_array( 'goalcart_frontend_mobile', $hooks, true ) );
check( 'settings_saved action documented', in_array( 'goalcart_settings_saved', $hooks, true ) );
check( 'log path absent when logging off', ! isset( $data['meta']['log_path'] ) );

// ---------------------------------------------------------------------------
// 8. Logger (P18-T05)
// ---------------------------------------------------------------------------
echo "\n== 8. Logger ==\n";

$option_name = Settings::OPTION_NAME;
$log = Logger::path();

// Deterministic start: the option row is gone (section 2 rolled back) and
// the service is back at the defaults snapshot; make sure no stale cache
// entry or log file survives either.
@unlink( $log );
wp_cache_delete( $option_name, 'options' );
wp_cache_delete( 'alloptions', 'options' );
$settings->set_many( array( 'logging_enabled' => false, 'debug_mode' => false ) );

// Logging off: no file is ever touched.
Logger::write( 'should-not-appear', 'error' );
check( 'no file when logging disabled', ! file_exists( $log ) );

// Logging on, debug off: errors write, debug lines are skipped. Persist
// through the Settings service (the production path) so the in-memory
// cache drives handle_get() consistently.
$settings->set_many( array( 'logging_enabled' => true, 'debug_mode' => false ) );
$settings->save();
Logger::error( 'settings-test-error' );
Logger::debug( 'settings-test-debug' );
$content = file_exists( $log ) ? (string) file_get_contents( $log ) : '';
check( 'error level written', false !== strpos( $content, 'settings-test-error' ) );
check( 'debug level skipped without debug mode', false === strpos( $content, 'settings-test-debug' ) );

// Debug mode on: debug lines land too.
$settings->set_many( array( 'logging_enabled' => true, 'debug_mode' => true ) );
$settings->save();
Logger::debug( 'settings-test-debug2' );
$content = (string) file_get_contents( $log );
check( 'debug level written with debug mode', false !== strpos( $content, 'settings-test-debug2' ) );

// Log path is surfaced in the settings meta while logging is on.
$resp = $settings_ctrl->handle_get( new \WP_REST_Request( 'GET', '/goalcart/v1/settings' ) );
check( 'log path in meta when logging on', isset( $resp->get_data()['meta']['log_path'] ) && false !== strpos( $resp->get_data()['meta']['log_path'], 'goalcart-debug.log' ) );

// Restore the option to its top-of-test state and remove the log file;
// drop the caches again so the restored value is visible.
if ( null === $option_before ) {
	delete_option( $option_name );
} else {
	update_option( $option_name, $option_before );
}
wp_cache_delete( $option_name, 'options' );
wp_cache_delete( 'alloptions', 'options' );
$settings->set_many( $all_before );
@unlink( $log );
check( 'log file cleaned up', ! file_exists( $log ) );
check( 'settings option restored', $option_before === get_option( $option_name, null ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "SETTINGS TEST FAILED\n" : "SETTINGS TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
