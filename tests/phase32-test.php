<?php
/**
 * FaraCart Phase 32 tests (Advanced V2 features).
 *
 * Boots WordPress and exercises the Phase 32 surface:
 *
 *  - goal types: tag / attribute / brand goals evaluate against the
 *    product tags and attribute taxonomies on the cart items
 *  - customer conditions: roles, guest/logged-in state, first-order and
 *    VIP goals gate eligibility (guest semantics for first-order)
 *  - shipping-zone conditions: the goal applies only in configured zones
 *  - cart-state conditions: required coupons and minimum item count
 *  - advanced scheduling: recurring weekdays + day time windows (incl. a
 *    midnight-crossing window)
 *  - campaign folding: campaign display_rules carry recurring schedule
 *    rules that milestones inherit (GoalRepository engine path)
 *  - free gift selection: the Reward model reads gift_products + choose
 *    mode, and the REST goal payload round-trips the new keys
 *  - settings: Phase 32 defaults (countdown / celebration / sticky /
 *    suggestions_ranking) and the REST schema + sanitizer
 *  - frontend payload: countdown_end appears on goals and campaign groups
 *    and the gift picker data rides on the reward
 *
 * Run: php tests/phase32-test.php   (from the plugin directory)
 *
 * The script only reads state; goal/campaign rows are created inside
 * transactions that are rolled back, and settings flips are in-memory
 * and restored.
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

use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Rewards\Reward;
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

function near( $a, $b, $eps = 0.001 ) {
	return abs( (float) $a - (float) $b ) < $eps;
}

function ctx( array $data, array $items = array() ) {
	$data['items'] = $items;
	return new CartContext( $data );
}

function goal( array $data ) {
	return new Goal( $data );
}

$engine = new GoalEngine();

// ---------------------------------------------------------------------------
// 1. Tag / attribute / brand goal types (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 1. Tag / attribute / brand goals ==\n";

$store_cart = ctx(
	array( 'subtotal' => 130, 'total' => 130 ),
	array(
		array( 'product_id' => 1, 'name' => 'Red tee', 'quantity' => 1, 'line_subtotal' => 30, 'line_total' => 30, 'tags' => array( 10 ), 'attributes' => array( 'pa_color' ) ),
		array( 'product_id' => 2, 'name' => 'Blue tee', 'quantity' => 2, 'line_subtotal' => 60, 'line_total' => 60, 'tags' => array( 10, 11 ), 'attributes' => array( 'pa_color', 'pa_size' ) ),
		array( 'product_id' => 3, 'name' => 'Socks', 'quantity' => 1, 'line_subtotal' => 40, 'line_total' => 40, 'tags' => array( 12 ), 'attributes' => array( 'pa_brand' ) ),
	)
);

// Tag goals default to the subtotal money basis; quantity needs the
// explicit calculation_mode (the same contract as category goals).
$r = $engine->evaluate(
	goal( array( 'type' => Goal::TYPE_TAG, 'target' => 3, 'tags' => array( 10 ), 'calculation_mode' => Goal::MODE_QUANTITY ) ),
	$store_cart
);
check( 'tag goal quantity = items with tag 10 (3)', near( $r->current(), 3 ) );
check( 'tag goal completed', $r->completed() );

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_TAG, 'target' => 50, 'tags' => array( 11 ) ) ), $store_cart );
check( 'tag goal amount = line sum with tag 11 (60)', near( $r->current(), 60 ) );

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_TAG, 'target' => 10, 'tags' => array( 999 ) ) ), $store_cart );
check( 'tag goal with no matching tag -> 0, still eligible', $r->eligible() && near( $r->current(), 0 ) );

$r = $engine->evaluate(
	goal( array( 'type' => Goal::TYPE_ATTRIBUTE, 'target' => 3, 'attributes' => array( 'pa_color' ), 'calculation_mode' => Goal::MODE_QUANTITY ) ),
	$store_cart
);
check( 'attribute goal quantity = items with pa_color (3)', near( $r->current(), 3 ) );

$r = $engine->evaluate(
	goal( array( 'type' => Goal::TYPE_ATTRIBUTE, 'target' => 1, 'attributes' => array( 'pa_brand' ), 'calculation_mode' => Goal::MODE_QUANTITY ) ),
	$store_cart
);
check( 'attribute goal matches pa_brand items (qty 1)', near( $r->current(), 1 ) );

$r = $engine->evaluate( goal( array( 'type' => Goal::TYPE_BRAND, 'target' => 40, 'attributes' => array( 'pa_brand' ) ) ), $store_cart );
check( 'brand goal amount = brand line sum (40)', near( $r->current(), 40 ) );

$r = $engine->evaluate(
	goal( array( 'type' => Goal::TYPE_BRAND, 'target' => 1, 'calculation_mode' => Goal::MODE_QUANTITY ) ),
	$store_cart
);
check( 'brand goal defaults to pa_brand taxonomy (qty 1)', near( $r->current(), 1 ) );

check( 'brand_taxonomy accessor', 'pa_brand' === goal( array( 'type' => Goal::TYPE_BRAND ) )->brand_taxonomy() );

$types = $engine->registry()->types();
check( 'registry supports tag', in_array( Goal::TYPE_TAG, $types, true ) );
check( 'registry supports attribute', in_array( Goal::TYPE_ATTRIBUTE, $types, true ) );
check( 'registry supports brand', in_array( Goal::TYPE_BRAND, $types, true ) );

// ---------------------------------------------------------------------------
// 2. Customer conditions (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 2. Customer conditions ==\n";

$guest = ctx( array( 'subtotal' => 50, 'total' => 50, 'user_id' => 0, 'is_guest' => true ) );

// A real subscriber user so get_userdata() resolves a role.
$wpdb_users = $GLOBALS['wpdb'];
$wpdb_users->query( 'START TRANSACTION' );
$user_id = wp_insert_user( array(
	'user_login' => 'phase32_test_user',
	'user_pass'  => wp_generate_password(),
	'user_email' => 'phase32_test_user@example.test',
	'role'       => 'subscriber',
) );
$user = ctx( array( 'subtotal' => 50, 'total' => 50, 'user_id' => (int) $user_id, 'is_guest' => false ) );

// Roles: the subscriber matches a subscriber-restricted goal and never an
// administrator-restricted one.
$role_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'customer_roles' => array( 'administrator' ) ) );
$r = $engine->evaluate( $role_goal, $guest );
check( 'role-restricted goal blocked for guests', ! $r->eligible() && GoalResult::REASON_CUSTOMER_CONDITIONS === $r->reason() );

$r = $engine->evaluate( $role_goal, $user );
check( 'role-restricted goal blocked without the role', ! $r->eligible() );

$sub_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'customer_roles' => array( 'subscriber' ) ) );
$r = $engine->evaluate( $sub_goal, $user );
check( 'role-restricted goal passes with the role', $r->eligible() );

// Customer state.
$state_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'customer_state' => array( 'guest' ) ) );
$r = $engine->evaluate( $state_goal, $user );
check( 'guest-only goal blocked for logged-in user', ! $r->eligible() );
$r = $engine->evaluate( $state_goal, $guest );
check( 'guest-only goal allowed for guests', $r->eligible() );

// First order (guests always qualify — order history is unknowable; the
// fresh subscriber has no orders, so the goal applies).
$first_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'first_order' => true ) );
$r = $engine->evaluate( $first_goal, $guest );
check( 'first-order goal never blocks guests', $r->eligible() );
$r = $engine->evaluate( $first_goal, $user );
check( 'first-order goal applies to an orderless customer', $r->eligible() );

// VIP: guests are always blocked; a logged-in customer with zero-threshold
// VIP config qualifies (threshold checks delegate to wc helpers).
$vip_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'vip' => true, 'vip_min_spend' => 100, 'vip_min_orders' => 1 ) );
$r = $engine->evaluate( $vip_goal, $guest );
check( 'vip goal blocked for guests', ! $r->eligible() && GoalResult::REASON_VIP_ONLY === $r->reason() );

$vip_open = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'vip' => true, 'vip_min_spend' => 0, 'vip_min_orders' => 0 ) );
$r = $engine->evaluate( $vip_open, $user );
check( 'vip goal passes for logged-in customers at zero thresholds', $r->eligible() );
$wpdb_users->query( 'ROLLBACK' );
wp_cache_delete( $user_id, 'users' );

// ---------------------------------------------------------------------------
// 3. Shipping-zone conditions (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 3. Shipping zones ==\n";

$zone_cart = ctx( array( 'subtotal' => 50, 'total' => 50, 'shipping_zone_id' => 4 ) );
$zone_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'shipping_zones' => array( 4 ) ) );
$r = $engine->evaluate( $zone_goal, $zone_cart );
check( 'zone goal matches configured zone', $r->eligible() );

$other = ctx( array( 'subtotal' => 50, 'total' => 50, 'shipping_zone_id' => 9 ) );
$r = $engine->evaluate( $zone_goal, $other );
check( 'zone goal blocked in other zone', ! $r->eligible() && GoalResult::REASON_SHIPPING_ZONE === $r->reason() );

// ---------------------------------------------------------------------------
// 4. Cart-state conditions (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 4. Cart state ==\n";

$coupon_cart = ctx( array( 'subtotal' => 50, 'total' => 50, 'coupons' => array( 'SUMMER' ) ) );
$coupon_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'cart_coupons' => array( 'SUMMER', 'WINTER' ) ) );
$r = $engine->evaluate( $coupon_goal, $coupon_cart );
check( 'cart-coupon goal passes with one required coupon', $r->eligible() );

$no_coupon = ctx( array( 'subtotal' => 50, 'total' => 50, 'coupons' => array() ) );
$r = $engine->evaluate( $coupon_goal, $no_coupon );
check( 'cart-coupon goal blocked without coupon', ! $r->eligible() && GoalResult::REASON_CART_CONDITIONS === $r->reason() );

$min_items = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'cart_min_items' => 3 ) );
$r = $engine->evaluate( $min_items, ctx( array( 'subtotal' => 50, 'total' => 50 ), array( array( 'product_id' => 1, 'quantity' => 2 ) ) ) );
check( 'min-items goal blocked below threshold', ! $r->eligible() && GoalResult::REASON_CART_CONDITIONS === $r->reason() );
$r = $engine->evaluate( $min_items, ctx( array( 'subtotal' => 50, 'total' => 50 ), array( array( 'product_id' => 1, 'quantity' => 3 ) ) ) );
check( 'min-items goal passes at threshold', $r->eligible() );

// ---------------------------------------------------------------------------
// 5. Advanced scheduling (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 5. Advanced scheduling ==\n";

// Weekday gating: 2024-06-01 is a Saturday (date('N') = 6).
$day_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'schedule_days' => array( 6 ) ) );
$r = $engine->evaluate( $day_goal, $user, '2024-06-01 12:00:00' );
check( 'weekday rule passes on the configured day', $r->eligible() );

$day_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'schedule_days' => array( 1 ) ) );
$r = $engine->evaluate( $day_goal, $user, '2024-06-01 12:00:00' );
check( 'weekday rule blocks other days', ! $r->eligible() && GoalResult::REASON_OUT_OF_SCHEDULE === $r->reason() );

// Time window.
$time_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'schedule_start_time' => '09:00', 'schedule_end_time' => '17:00' ) );
$r = $engine->evaluate( $time_goal, $user, '2024-06-01 12:00:00' );
check( 'time window passes inside', $r->eligible() );
$r = $engine->evaluate( $time_goal, $user, '2024-06-01 18:00:00' );
check( 'time window blocks outside', ! $r->eligible() );

// Midnight-crossing window: 22:00–06:00 means "after 22:00 OR before 06:00".
$night_goal = goal( array( 'type' => Goal::TYPE_AMOUNT, 'target' => 10, 'schedule_start_time' => '22:00', 'schedule_end_time' => '06:00' ) );
$r = $engine->evaluate( $night_goal, $user, '2024-06-01 23:30:00' );
check( 'midnight window passes after start', $r->eligible() );
$r = $engine->evaluate( $night_goal, $user, '2024-06-01 03:00:00' );
check( 'midnight window passes before end', $r->eligible() );
$r = $engine->evaluate( $night_goal, $user, '2024-06-01 12:00:00' );
check( 'midnight window blocks midday', ! $r->eligible() );

// ---------------------------------------------------------------------------
// 6. Campaign schedule folding (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 6. Campaign folding ==\n";

$wpdb = $GLOBALS['wpdb'];
$campaigns = \GoalCart\Database\Schema::table( 'campaigns' );
$goals_table = \GoalCart\Database\Schema::table( 'goals' );
$goals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	$wpdb->insert(
		$campaigns,
		array(
			'name'          => 'Phase32 Campaign',
			'description'   => '',
			'status'        => 'active',
			'priority'      => 10,
			'display_rules' => wp_json_encode( array(
				'schedule_days'       => array( 6 ),
				'schedule_start_time' => '09:00',
				'schedule_end_time'   => '17:00',
			) ),
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		)
	);
	$campaign_id = (int) $wpdb->insert_id;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Phase32 Goal',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'campaign_id'      => $campaign_id,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$goal_id = (int) $wpdb->insert_id;

	$repo = new GoalRepository();
	$loaded = $repo->find( $goal_id );

	check( 'campaign milestone loads via engine path', null !== $loaded );
	check( 'milestone inherits campaign weekday rule', array( 6 ) === ( null !== $loaded ? $loaded->schedule_days() : array() ) );
	check( 'milestone inherits campaign time window', '09:00' === ( null !== $loaded ? $loaded->schedule_start_time() : '' ) );

	// The inherited window gates the goal exactly like a native one.
	$r = $engine->evaluate( $loaded, $user, '2024-06-01 12:00:00' );
	check( 'folded campaign goal eligible inside inherited window', $r->eligible() );
	$r = $engine->evaluate( $loaded, $user, '2024-06-02 12:00:00' );
	check( 'folded campaign goal blocked outside inherited days', ! $r->eligible() );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
check( 'goal rows restored on rollback', $goals_before === $goals_after );

// ---------------------------------------------------------------------------
// 7. Free gift selection (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 7. Free gift selection ==\n";

$gift_goal = goal( array(
	'type'        => Goal::TYPE_AMOUNT,
	'target'      => 10,
	'reward_type' => 'free_gift',
	'reward_meta' => array(
		'gift_add_mode' => 'choose',
		'gift_product_id' => 5,
		'gift_products'   => array( 5, 6, 7 ),
	),
) );

$reward = Reward::from_goal( $gift_goal );
check( 'reward type is free_gift', 'free_gift' === $reward->type() );
check( 'reward choose mode read from meta', 'choose' === $reward->gift_add_mode() );
check( 'reward gift_products list read from meta', array( 5, 6, 7 ) === $reward->gift_products() );
check( 'gift allowed for a listed candidate', $reward->is_gift_allowed( 6 ) );
check( 'gift blocked outside the list', ! $reward->is_gift_allowed( 99 ) );

// ---------------------------------------------------------------------------
// 8. Settings (Phase 32)
// ---------------------------------------------------------------------------
echo "\n== 8. Settings ==\n";

$container = \GoalCart\Plugin::instance()->container();
$settings = $container->get( Settings::class );
$all_before = $settings->all();

$d = $settings->defaults();
check( 'frontend_countdown defaults true', true === $d['frontend_countdown'] );
check( 'frontend_celebrate defaults true', true === $d['frontend_celebrate'] );
check( 'sticky_position defaults bottom', 'bottom' === $d['sticky_position'] );
check( 'sticky_behavior defaults dismissible', 'dismissible' === $d['sticky_behavior'] );
check( 'sticky_delay defaults 0', 0 === $d['sticky_delay'] );
check( 'sticky_countdown defaults false', false === $d['sticky_countdown'] );
check( 'sticky_suggestions defaults false', false === $d['sticky_suggestions'] );
check( 'sticky_display defaults compact', 'compact' === $d['sticky_display'] );
check( 'suggestions_ranking defaults balanced', 'balanced' === $d['suggestions_ranking'] );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "PHASE 32 TEST FAILED\n" : "PHASE 32 TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
