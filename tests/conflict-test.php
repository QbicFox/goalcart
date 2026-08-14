<?php
/**
 * Goal Cart conflict & priority engine tests (Phase 26).
 *
 * Boots WordPress and exercises the Phase 26 deterministic behavior when
 * multiple goals/campaigns are active:
 *
 *  - ConflictResolver: cumulative / best / first modes, mutually
 *    exclusive goals, deterministic reasons, reward scoring
 *  - Goal model: the `exclusive` flag parses from any truthy input
 *  - campaign priority participates in the active-goal order
 *    (GoalRepository::active_goals() sorts by campaign priority, then
 *    goal priority, then id; standalone goals compete at priority 10)
 *  - RewardEngine::sync_cart grants only the resolved winners and blocks
 *    suppressed goals with their resolution reason
 *  - the progress payload carries a per-goal `conflict` fragment so the
 *    storefront/admin UI reflects the same resolution — including
 *    display/grant parity: 'best' resolves with the real computed reward
 *    amounts and cumulative stacking suppression reaches the payload
 *    (ConflictResolver::apply_stacking mirror)
 *  - the REST settings schema accepts the conflict_resolution enum
 *
 * The suite reads like the other suites; the only real writes (schema
 * upgrade + seeded goals/campaigns) run inside a transaction that is
 * rolled back, and residue is asserted.
 *
 * Run: php tests/conflict-test.php   (from the plugin directory)
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

use GoalCart\Cart\CartIntegration;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\ConflictResolver;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Goals\MessageEngine;
use GoalCart\REST\FrontendController;
use GoalCart\REST\SettingsController;
use GoalCart\Rewards\Reward;
use GoalCart\Rewards\RewardEngine;
use GoalCart\Rewards\RewardResult;
use GoalCart\Settings\Settings;
use GoalCart\Suggestions\SuggestionEngine;

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

function goal( array $data ) {
	return new Goal( $data );
}

/** A completed GoalResult for a goal (current >= target). */
function completed_result( Goal $g, $current = null ) {
	$target = $g->target();

	return new GoalResult( $g, null !== $current ? $current : $target + 1, $target );
}

// Bare product object: from_cart reads money values from the cart item
// array, so no real product row is needed (same pattern as the other
// read-only suites).
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

$container = \GoalCart\Plugin::instance()->container();

$settings        = $container->get( Settings::class );
$settings_ctrl   = $container->get( SettingsController::class );
$engine          = $container->get( GoalEngine::class );
$ci              = $container->get( CartIntegration::class );
$messages        = $container->get( MessageEngine::class );
$recommendations = $container->get( \GoalCart\Recommendations\ProductRecommendationEngine::class );
$all_before      = $settings->all();

$resolver = new ConflictResolver();

// ---------------------------------------------------------------------------
// 1. ConflictResolver — modes & reasons (pure)
// ---------------------------------------------------------------------------
echo "\n== 1. ConflictResolver modes ==\n";

check( 'modes whitelist', array( 'cumulative', 'best', 'first' ) === ConflictResolver::modes() );

$g1 = goal( array( 'id' => 1, 'name' => 'Goal 1', 'type' => 'amount', 'target' => 100, 'priority' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$g2 = goal( array( 'id' => 2, 'name' => 'Goal 2', 'type' => 'amount', 'target' => 200, 'priority' => 2, 'reward_type' => 'fixed_discount', 'reward_value' => 100 ) );

$r1 = completed_result( $g1 );
$r2 = completed_result( $g2 );

// cumulative (default): every completed goal wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'cumulative: both goals win', ConflictResolver::REASON_NONE === $resolution[1] && ConflictResolver::REASON_NONE === $resolution[2] );

// first: only the first matching goal wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_FIRST );
check( 'first: highest-priority goal wins', ConflictResolver::REASON_NONE === $resolution[1] );
check( 'first: later goal suppressed (not_first)', ConflictResolver::REASON_NOT_FIRST === $resolution[2] );

// best with computed amounts: the higher-value reward wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_BEST, array( 1 => 50.0, 2 => 100.0 ) );
check( 'best: higher reward amount wins', ConflictResolver::REASON_NONE === $resolution[2] );
check( 'best: lower reward suppressed (not_best)', ConflictResolver::REASON_NOT_BEST === $resolution[1] );

// best without amounts: static fallback (fixed values 50 vs 100).
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_BEST );
check( 'best static fallback picks the higher configured value', ConflictResolver::REASON_NONE === $resolution[2] );

// best tie: priority order breaks the tie (earlier goal wins).
$g3 = goal( array( 'id' => 3, 'name' => 'Goal 3', 'type' => 'amount', 'target' => 150, 'priority' => 3, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$resolution = $resolver->resolve( array( $g1, $g3 ), array( 1 => $r1, 3 => completed_result( $g3 ) ), ConflictResolver::MODE_BEST, array( 1 => 50.0, 3 => 50.0 ) );
check( 'best tie resolves by priority order', ConflictResolver::REASON_NONE === $resolution[1] && ConflictResolver::REASON_NOT_BEST === $resolution[3] );

// Incomplete / absent goals are ignored entirely.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1 ), ConflictResolver::MODE_FIRST );
check( 'incomplete goal absent from resolution', ! isset( $resolution[2] ) && ConflictResolver::REASON_NONE === $resolution[1] );

check( 'empty goals resolve to empty', array() === $resolver->resolve( array(), array(), ConflictResolver::MODE_BEST ) );

// ---------------------------------------------------------------------------
// 2. ConflictResolver — mutually exclusive goals
// ---------------------------------------------------------------------------
echo "\n== 2. Mutually exclusive goals ==\n";

$gx = goal( array( 'id' => 10, 'name' => 'Exclusive', 'type' => 'amount', 'target' => 100, 'priority' => 1, 'exclusive' => true, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$rx = completed_result( $gx );

// An exclusive completed goal suppresses every lower-priority goal.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'exclusive goal wins', ConflictResolver::REASON_NONE === $resolution[10] );
check( 'exclusive suppresses lower-priority goal', ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// Priority above the exclusive goal is respected.
$g0 = goal( array( 'id' => 0, 'name' => 'Higher', 'type' => 'amount', 'target' => 50, 'priority' => 0, 'reward_type' => 'fixed_discount', 'reward_value' => 25 ) );
$resolution = $resolver->resolve( array( $g0, $gx ), array( 0 => completed_result( $g0 ), 10 => $rx ), ConflictResolver::MODE_CUMULATIVE );
check( 'higher-priority goal above exclusive unaffected', ConflictResolver::REASON_NONE === $resolution[0] && ConflictResolver::REASON_NONE === $resolution[10] );

// Exclusive applies in best mode too: it suppresses the better lower-priority goal.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_BEST, array( 10 => 50.0, 2 => 100.0 ) );
check( 'best + exclusive: exclusive beats the better lower-priority goal', ConflictResolver::REASON_NONE === $resolution[10] && ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// Exclusive applies in first mode: everything after the winner is skipped anyway.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_FIRST );
check( 'first + exclusive: first wins, later suppressed', ConflictResolver::REASON_NONE === $resolution[10] && ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// An exclusive goal that is NOT completed suppresses nothing.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'uncompleted exclusive goal suppresses nothing', ConflictResolver::REASON_NONE === $resolution[2] );

// ---------------------------------------------------------------------------
// 3. reward_score + Goal exclusive parsing
// ---------------------------------------------------------------------------
echo "\n== 3. Reward score & Goal flag ==\n";

check( 'reward_score uses computed amount', near( ConflictResolver::reward_score( $r1, 80 ), 80 ) );
check( 'reward_score falls back to fixed value', near( ConflictResolver::reward_score( $r1 ), 50 ) );
$gp = goal( array( 'id' => 4, 'type' => 'amount', 'target' => 10, 'reward_type' => 'percent_discount', 'reward_value' => 10 ) );
check( 'reward_score percent value', near( ConflictResolver::reward_score( completed_result( $gp ) ), 10 ) );
$gfs = goal( array( 'id' => 5, 'type' => 'amount', 'target' => 10, 'reward_type' => 'free_shipping' ) );
check( 'reward_score free shipping = 0', near( ConflictResolver::reward_score( completed_result( $gfs ) ), 0 ) );

check( 'exclusive parses true', goal( array( 'exclusive' => true ) )->is_exclusive() );
check( 'exclusive parses 1', goal( array( 'exclusive' => 1 ) )->is_exclusive() );
check( 'exclusive defaults false', ! goal( array() )->is_exclusive() );
check( 'exclusive parses false', ! goal( array( 'exclusive' => false ) )->is_exclusive() );

// apply_stacking — the display paths' pass-2 mirror (stacking safety is
// applied to the winners in priority order, exactly like the cart grant).
$ga = goal( array( 'id' => 20, 'type' => 'amount', 'target' => 10, 'priority' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 5 ) );
$gb = goal( array( 'id' => 21, 'type' => 'amount', 'target' => 10, 'priority' => 2, 'reward_type' => 'fixed_discount', 'reward_value' => 5 ) );
$gs = goal( array( 'id' => 22, 'type' => 'amount', 'target' => 10, 'priority' => 3, 'reward_type' => 'fixed_discount', 'reward_value' => 5, 'reward_meta' => array( 'stacking' => 'stack' ) ) );

$ra = RewardResult::available( Reward::from_goal( $ga ), 20, 5.0 );
$rb = RewardResult::available( Reward::from_goal( $gb ), 21, 5.0 );
$rs = RewardResult::available( Reward::from_goal( $gs ), 22, 5.0 );

$resolution = $resolver->resolve(
	array( $ga, $gb, $gs ),
	array( 20 => completed_result( $ga ), 21 => completed_result( $gb ), 22 => completed_result( $gs ) ),
	ConflictResolver::MODE_CUMULATIVE
);
$resolution = $resolver->apply_stacking( array( $ga, $gb, $gs ), $resolution, array( 20 => $ra, 21 => $rb, 22 => $rs ) );
check( 'apply_stacking: first same-type reward wins', ConflictResolver::REASON_NONE === $resolution[20] );
check( 'apply_stacking: second same-type blocked (stacking)', RewardResult::REASON_STACKING === $resolution[21] );
check( 'apply_stacking: stacking=stack reward still grants', ConflictResolver::REASON_NONE === $resolution[22] );

// ---------------------------------------------------------------------------
// 4. Settings + REST schema + goals schema
// ---------------------------------------------------------------------------
echo "\n== 4. Settings & schema ==\n";

check( 'conflict_resolution defaults to cumulative', 'cumulative' === $settings->defaults()['conflict_resolution'] );

$save = $settings_ctrl->save_args();
check( 'conflict_resolution schema present', isset( $save['conflict_resolution']['enum'] ) );
check( 'invalid mode rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['conflict_resolution'], 'conflict_resolution' ) ) );
check( 'valid modes accepted', true === rest_validate_value_from_schema( 'best', $save['conflict_resolution'], 'conflict_resolution' ) );
check( 'valid modes accepted (first)', true === rest_validate_value_from_schema( 'first', $save['conflict_resolution'], 'conflict_resolution' ) );

$statements = Schema::create_statements();
$goals_stmt = $statements[ Schema::table( 'goals' ) ];
check( 'goals schema declares exclusive column', false !== strpos( $goals_stmt, 'exclusive tinyint(1)' ) );

// ---------------------------------------------------------------------------
// 5. Database behavior: campaign-priority ordering + reward/display
//    resolution (inside a transaction, rolled back)
// ---------------------------------------------------------------------------
echo "\n== 5. Campaign priority, rewards & payload (rolled back) ==\n";

$wpdb        = $GLOBALS['wpdb'];
$goals_table = Schema::table( 'goals' );
$camp_table  = Schema::table( 'campaigns' );

// Ensure the Phase 26 schema is applied (the goals.exclusive column is
// declared by Schema::create_statements() and dbDelta adds missing
// columns idempotently — the same upgrade a live install runs).
Installer::maybe_create_tables();

$has_exclusive = (bool) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
	$wpdb->dbname,
	$goals_table,
	'exclusive'
) );
check( 'goals.exclusive column present after upgrade', $has_exclusive );

$goals_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
$camps_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$camp_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	// Clean slate for deterministic ordering checks; the rollback restores
	// every deleted row.
	$wpdb->query( "DELETE FROM {$goals_table}" );
	$wpdb->query( "DELETE FROM {$camp_table}" );

	// Campaign A (priority 5) < campaign B (priority 20); campaign C is
	// inactive (its goals must be gated out). Standalone goal S competes
	// at the default campaign priority 10.
	$wpdb->insert( $camp_table, array( 'name' => 'Camp A', 'priority' => 5, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_a = (int) $wpdb->insert_id;

	$wpdb->insert( $camp_table, array( 'name' => 'Camp B', 'priority' => 20, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_b = (int) $wpdb->insert_id;

	$wpdb->insert( $camp_table, array( 'name' => 'Camp C', 'status' => 'inactive', 'priority' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_c = (int) $wpdb->insert_id;

	$insert_goal = function ( $name, $campaign_id, $priority, $extra = array() ) use ( $wpdb, $goals_table ) {
		$row = array_merge(
			array(
				'name'             => $name,
				'description'      => '',
				'status'           => 'active',
				'type'             => 'amount',
				'target'           => 100,
				'calculation_mode' => 'subtotal',
				'priority'         => $priority,
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			$extra
		);

		if ( null !== $campaign_id ) {
			$row['campaign_id'] = $campaign_id;
		}

		$wpdb->insert( $goals_table, $row );

		return (int) $wpdb->insert_id;
	};

	$goal_a1 = $insert_goal( 'Camp A goal', $camp_a, 10 );
	$goal_b1 = $insert_goal( 'Camp B goal', $camp_b, 1 );
	$goal_s  = $insert_goal( 'Standalone goal', null, 1 );
	$goal_c1 = $insert_goal( 'Inactive campaign goal', $camp_c, 1 );

	// 5.1 Deterministic order: campaign priority, then goal priority, then id.
	$repo    = new GoalRepository();
	$active  = $repo->active_goals();
	$names   = array_map( function ( Goal $g ) {
		return $g->name();
	}, $active );

	check( 'campaign-priority ordering (A, standalone, B)', array( 'Camp A goal', 'Standalone goal', 'Camp B goal' ) === $names );
	check( 'goal in inactive campaign gated out', ! in_array( 'Inactive campaign goal', $names, true ) );

	// 5.2 RewardEngine::sync_cart — conflict resolution on the live path.
	// Two completed fixed-discount goals (X: 50, Y: 100); cart of 200. Y
	// opts into stacking so the cumulative scenario really exercises
	// resolution (two same-type non-stacking rewards would block Y by
	// stacking safety, not by conflict resolution).
	$wpdb->query( "DELETE FROM {$goals_table}" );

	$goal_x = $insert_goal( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$goal_y = $insert_goal( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

	$cart = new \WC_Cart();
	$cart->cart_contents['c1'] = cart_line( 'c1', 0, 0, 1, 200.0, 200.0 );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
	$reward_engine = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'cumulative: both rewards available', RewardResult::STATE_AVAILABLE === $results[ $goal_x ]->state() && RewardResult::STATE_AVAILABLE === $results[ $goal_y ]->state() );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_FIRST );
	$reward_engine = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'first: only the first matching goal available', RewardResult::STATE_AVAILABLE === $results[ $goal_x ]->state() );
	check( 'first: later goal blocked (not_first)', RewardResult::STATE_BLOCKED === $results[ $goal_y ]->state() && ConflictResolver::REASON_NOT_FIRST === $results[ $goal_y ]->reason() );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_BEST );
	$reward_engine = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'best: higher-value reward available', RewardResult::STATE_AVAILABLE === $results[ $goal_y ]->state() );
	check( 'best: lower-value reward blocked (not_best)', RewardResult::STATE_BLOCKED === $results[ $goal_x ]->state() && ConflictResolver::REASON_NOT_BEST === $results[ $goal_x ]->reason() );

	// Exclusive goal (needs the goals.exclusive column). Give it a HIGHER
	// priority (5 < 10) than Y so it sorts first and suppresses Y.
	if ( $has_exclusive ) {
		$wpdb->query( "DELETE FROM {$goals_table}" );

		$goal_x = $insert_goal( 'Reward X exclusive', null, 5, array( 'target' => 100, 'exclusive' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
		$goal_y = $insert_goal( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

		$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
		$reward_engine = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
		$results       = $reward_engine->sync_cart( $cart );
		check( 'exclusive goal available', RewardResult::STATE_AVAILABLE === $results[ $goal_x ]->state() );
		check( 'exclusive suppresses lower-priority reward', RewardResult::STATE_BLOCKED === $results[ $goal_y ]->state() && ConflictResolver::REASON_EXCLUSIVE === $results[ $goal_y ]->reason() );
	}

	// 5.3 Progress payload — the conflict fragment reaches the frontend.
	// A clean two-goal slate so the payload has exactly X and Y.
	$wpdb->query( "DELETE FROM {$goals_table}" );

	$goal_x = $insert_goal( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$goal_y = $insert_goal( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

	$settings->set( 'default_goal_behavior', 'all' );
	$settings->set( 'conflict_resolution', ConflictResolver::MODE_FIRST );

	$frontend = new FrontendController( $engine, new GoalRepository(), $ci, $messages, $recommendations, $settings, new RewardEngine( $engine, new GoalRepository(), $settings, $ci ) );
	$req      = new \WP_REST_Request( 'GET', '/goalcart/v1/progress' );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	check( 'payload carries both goals', 2 === count( $data['goals'] ) );
	check( 'winner payload resolved', true === $data['goals'][0]['conflict']['resolved'] );
	check( 'loser payload suppressed with reason', false === $data['goals'][1]['conflict']['resolved'] && ConflictResolver::REASON_NOT_FIRST === $data['goals'][1]['conflict']['reason'] );

	// 5.4 Display/grant parity — 'best' compares the REAL computed amounts.
	// On a 200 cart, 40% = 80 beats fixed 50, so the percent goal wins even
	// though its STATIC score (40) is lower than the fixed goal's (50); the
	// payload must agree with the cart's computed comparison.
	$wpdb->query( "DELETE FROM {$goals_table}" );

	$goal_p = $insert_goal( 'Percent 40', null, 10, array( 'target' => 100, 'reward_type' => 'percent_discount', 'reward_value' => 40 ) );
	$goal_f = $insert_goal( 'Fixed 50', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_BEST );

	$reward_engine  = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
	$engine_results = $reward_engine->sync_cart( $cart );

	$frontend = new FrontendController( $engine, new GoalRepository(), $ci, $messages, $recommendations, $settings, $reward_engine );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	$by_id = array();

	foreach ( $data['goals'] as $g ) {
		$by_id[ (int) $g['goal_id'] ] = $g;
	}

	check( 'best parity: computed percent (80) beats fixed (50) on the cart', RewardResult::STATE_AVAILABLE === $engine_results[ $goal_p ]->state() && RewardResult::STATE_BLOCKED === $engine_results[ $goal_f ]->state() );
	check( 'best parity: percent goal wins the payload', true === $by_id[ $goal_p ]['conflict']['resolved'] );
	check( 'best parity: fixed goal suppressed in the payload (not_best)', false === $by_id[ $goal_f ]['conflict']['resolved'] && ConflictResolver::REASON_NOT_BEST === $by_id[ $goal_f ]['conflict']['reason'] );

	foreach ( $engine_results as $id => $rr ) {
		check( "best parity: engine/payload agree (goal {$id})", ( RewardResult::STATE_BLOCKED === $rr->state() ) === ( false === $by_id[ $id ]['conflict']['resolved'] ) );
	}

	// 5.5 Display/grant parity — cumulative stacking suppression reaches
	// the payload: a same-type non-stacking loser never renders as won.
	$wpdb->query( "DELETE FROM {$goals_table}" );

	$goal_x = $insert_goal( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$goal_y = $insert_goal( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100 ) );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );

	$reward_engine  = new RewardEngine( $engine, new GoalRepository(), $settings, $ci );
	$engine_results = $reward_engine->sync_cart( $cart );

	$frontend = new FrontendController( $engine, new GoalRepository(), $ci, $messages, $recommendations, $settings, $reward_engine );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	$by_id = array();

	foreach ( $data['goals'] as $g ) {
		$by_id[ (int) $g['goal_id'] ] = $g;
	}

	check( 'stacking parity: engine grants the first same-type reward', RewardResult::STATE_AVAILABLE === $engine_results[ $goal_x ]->state() );
	check( 'stacking parity: engine blocks the second (stacking)', RewardResult::STATE_BLOCKED === $engine_results[ $goal_y ]->state() && RewardResult::REASON_STACKING === $engine_results[ $goal_y ]->reason() );
	check( 'stacking parity: payload shows X won', true === $by_id[ $goal_x ]['conflict']['resolved'] );
	check( 'stacking parity: payload shows Y stacking-blocked', false === $by_id[ $goal_y ]['conflict']['resolved'] && RewardResult::REASON_STACKING === $by_id[ $goal_y ]['conflict']['reason'] );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

$settings->set_many( $all_before );

$goals_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$goals_table}" );
$camps_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$camp_table}" );
check( 'goals restored on rollback', $goals_before === $goals_after );
check( 'campaigns restored on rollback', $camps_before === $camps_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "CONFLICT TEST FAILED\n" : "CONFLICT TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
