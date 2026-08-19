<?php
/**
 * FaraCart conflict & priority engine tests.
 *
 * Boots WordPress and exercises the deterministic behavior when
 * multiple missions/campaigns are active:
 *
 *  - ConflictResolver: cumulative / best / first modes, mutually
 *    exclusive missions, deterministic reasons, reward scoring
 *  - Mission model: the `exclusive` flag parses from any truthy input
 *  - campaign priority participates in the active-mission order
 *    (MissionRepository::active_missions() sorts by campaign priority, then
 *    mission priority, then id; standalone missions compete at priority 10)
 *  - RewardEngine::sync_cart grants only the resolved winners and blocks
 *    suppressed missions with their resolution reason
 *  - the progress payload carries a per-mission `conflict` fragment so the
 *    storefront/admin UI reflects the same resolution — including
 *    display/grant parity: 'best' resolves with the real computed reward
 *    amounts and cumulative stacking suppression reaches the payload
 *    (ConflictResolver::apply_stacking mirror)
 *  - the REST settings schema accepts the conflict_resolution enum
 *
 * The suite reads like the other suites; the only real writes (schema
 * upgrade + seeded missions/campaigns) run inside a transaction that is
 * rolled back, and residue is asserted.
 *
 * Run: php tests/conflict-test.php (from the plugin directory)
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

use FaraCart\Cart\CartIntegration;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\ConflictResolver;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionRepository;
use FaraCart\Missions\MissionResult;
use FaraCart\Missions\MessageEngine;
use FaraCart\REST\FrontendController;
use FaraCart\REST\SettingsController;
use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardEngine;
use FaraCart\Rewards\RewardResult;
use FaraCart\Settings\Settings;
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

function near( $a, $b, $eps = 0.001 ) {
	return abs( (float) $a - (float) $b ) < $eps;
}

function mission( array $data ) {
	return new Mission( $data );
}

/** A completed MissionResult for a mission (current >= target). */
function completed_result( Mission $g, $current = null ) {
	$target = $g->target();

	return new MissionResult( $g, null !== $current ? $current : $target + 1, $target );
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

$container = \FaraCart\Plugin::instance()->container();

$settings        = $container->get( Settings::class );
$settings_ctrl   = $container->get( SettingsController::class );
$engine          = $container->get( MissionEngine::class );
$ci              = $container->get( CartIntegration::class );
$messages        = $container->get( MessageEngine::class );
$recommendations = $container->get( \FaraCart\Recommendations\ProductRecommendationEngine::class );
$all_before      = $settings->all();

$resolver = new ConflictResolver();

// ---------------------------------------------------------------------------
// 1. ConflictResolver — modes & reasons (pure)
// ---------------------------------------------------------------------------
echo "\n== 1. ConflictResolver modes ==\n";

check( 'modes whitelist', array( 'cumulative', 'best', 'first' ) === ConflictResolver::modes() );

$g1 = mission( array( 'id' => 1, 'name' => 'Mission 1', 'type' => 'amount', 'target' => 100, 'priority' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$g2 = mission( array( 'id' => 2, 'name' => 'Mission 2', 'type' => 'amount', 'target' => 200, 'priority' => 2, 'reward_type' => 'fixed_discount', 'reward_value' => 100 ) );

$r1 = completed_result( $g1 );
$r2 = completed_result( $g2 );

// cumulative (default): every completed mission wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'cumulative: both missions win', ConflictResolver::REASON_NONE === $resolution[1] && ConflictResolver::REASON_NONE === $resolution[2] );

// first: only the first matching mission wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_FIRST );
check( 'first: highest-priority mission wins', ConflictResolver::REASON_NONE === $resolution[1] );
check( 'first: later mission suppressed (not_first)', ConflictResolver::REASON_NOT_FIRST === $resolution[2] );

// best with computed amounts: the higher-value reward wins.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_BEST, array( 1 => 50.0, 2 => 100.0 ) );
check( 'best: higher reward amount wins', ConflictResolver::REASON_NONE === $resolution[2] );
check( 'best: lower reward suppressed (not_best)', ConflictResolver::REASON_NOT_BEST === $resolution[1] );

// best without amounts: static fallback (fixed values 50 vs 100).
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1, 2 => $r2 ), ConflictResolver::MODE_BEST );
check( 'best static fallback picks the higher configured value', ConflictResolver::REASON_NONE === $resolution[2] );

// best tie: priority order breaks the tie (earlier mission wins).
$g3 = mission( array( 'id' => 3, 'name' => 'Mission 3', 'type' => 'amount', 'target' => 150, 'priority' => 3, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$resolution = $resolver->resolve( array( $g1, $g3 ), array( 1 => $r1, 3 => completed_result( $g3 ) ), ConflictResolver::MODE_BEST, array( 1 => 50.0, 3 => 50.0 ) );
check( 'best tie resolves by priority order', ConflictResolver::REASON_NONE === $resolution[1] && ConflictResolver::REASON_NOT_BEST === $resolution[3] );

// Incomplete / absent missions are ignored entirely.
$resolution = $resolver->resolve( array( $g1, $g2 ), array( 1 => $r1 ), ConflictResolver::MODE_FIRST );
check( 'incomplete mission absent from resolution', ! isset( $resolution[2] ) && ConflictResolver::REASON_NONE === $resolution[1] );

check( 'empty missions resolve to empty', array() === $resolver->resolve( array(), array(), ConflictResolver::MODE_BEST ) );

// ---------------------------------------------------------------------------
// 2. ConflictResolver — mutually exclusive missions
// ---------------------------------------------------------------------------
echo "\n== 2. Mutually exclusive missions ==\n";

$gx = mission( array( 'id' => 10, 'name' => 'Exclusive', 'type' => 'amount', 'target' => 100, 'priority' => 1, 'exclusive' => true, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
$rx = completed_result( $gx );

// An exclusive completed mission suppresses every lower-priority mission.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'exclusive mission wins', ConflictResolver::REASON_NONE === $resolution[10] );
check( 'exclusive suppresses lower-priority mission', ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// Priority above the exclusive mission is respected.
$g0 = mission( array( 'id' => 0, 'name' => 'Higher', 'type' => 'amount', 'target' => 50, 'priority' => 0, 'reward_type' => 'fixed_discount', 'reward_value' => 25 ) );
$resolution = $resolver->resolve( array( $g0, $gx ), array( 0 => completed_result( $g0 ), 10 => $rx ), ConflictResolver::MODE_CUMULATIVE );
check( 'higher-priority mission above exclusive unaffected', ConflictResolver::REASON_NONE === $resolution[0] && ConflictResolver::REASON_NONE === $resolution[10] );

// Exclusive applies in best mode too: it suppresses the better lower-priority mission.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_BEST, array( 10 => 50.0, 2 => 100.0 ) );
check( 'best + exclusive: exclusive beats the better lower-priority mission', ConflictResolver::REASON_NONE === $resolution[10] && ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// Exclusive applies in first mode: everything after the winner is skipped anyway.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 10 => $rx, 2 => $r2 ), ConflictResolver::MODE_FIRST );
check( 'first + exclusive: first wins, later suppressed', ConflictResolver::REASON_NONE === $resolution[10] && ConflictResolver::REASON_EXCLUSIVE === $resolution[2] );

// An exclusive mission that is NOT completed suppresses nothing.
$resolution = $resolver->resolve( array( $gx, $g2 ), array( 2 => $r2 ), ConflictResolver::MODE_CUMULATIVE );
check( 'uncompleted exclusive mission suppresses nothing', ConflictResolver::REASON_NONE === $resolution[2] );

// ---------------------------------------------------------------------------
// 3. reward_score + Mission exclusive parsing
// ---------------------------------------------------------------------------
echo "\n== 3. Reward score & Mission flag ==\n";

check( 'reward_score uses computed amount', near( ConflictResolver::reward_score( $r1, 80 ), 80 ) );
check( 'reward_score falls back to fixed value', near( ConflictResolver::reward_score( $r1 ), 50 ) );
$gp = mission( array( 'id' => 4, 'type' => 'amount', 'target' => 10, 'reward_type' => 'percent_discount', 'reward_value' => 10 ) );
check( 'reward_score percent value', near( ConflictResolver::reward_score( completed_result( $gp ) ), 10 ) );
$gfs = mission( array( 'id' => 5, 'type' => 'amount', 'target' => 10, 'reward_type' => 'free_shipping' ) );
check( 'reward_score free shipping = 0', near( ConflictResolver::reward_score( completed_result( $gfs ) ), 0 ) );

check( 'exclusive parses true', mission( array( 'exclusive' => true ) )->is_exclusive() );
check( 'exclusive parses 1', mission( array( 'exclusive' => 1 ) )->is_exclusive() );
check( 'exclusive defaults false', ! mission( array() )->is_exclusive() );
check( 'exclusive parses false', ! mission( array( 'exclusive' => false ) )->is_exclusive() );

// apply_stacking — the display paths' pass-2 mirror (stacking safety is
// applied to the winners in priority order, exactly like the cart grant).
$ga = mission( array( 'id' => 20, 'type' => 'amount', 'target' => 10, 'priority' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 5 ) );
$gb = mission( array( 'id' => 21, 'type' => 'amount', 'target' => 10, 'priority' => 2, 'reward_type' => 'fixed_discount', 'reward_value' => 5 ) );
$gs = mission( array( 'id' => 22, 'type' => 'amount', 'target' => 10, 'priority' => 3, 'reward_type' => 'fixed_discount', 'reward_value' => 5, 'reward_meta' => array( 'stacking' => 'stack' ) ) );

$ra = RewardResult::available( Reward::from_mission( $ga ), 20, 5.0 );
$rb = RewardResult::available( Reward::from_mission( $gb ), 21, 5.0 );
$rs = RewardResult::available( Reward::from_mission( $gs ), 22, 5.0 );

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
// 4. Settings + REST schema + missions schema
// ---------------------------------------------------------------------------
echo "\n== 4. Settings & schema ==\n";

check( 'conflict_resolution defaults to cumulative', 'cumulative' === $settings->defaults()['conflict_resolution'] );

$save = $settings_ctrl->save_args();
check( 'conflict_resolution schema present', isset( $save['conflict_resolution']['enum'] ) );
check( 'invalid mode rejected', is_wp_error( rest_validate_value_from_schema( 'bogus', $save['conflict_resolution'], 'conflict_resolution' ) ) );
check( 'valid modes accepted', true === rest_validate_value_from_schema( 'best', $save['conflict_resolution'], 'conflict_resolution' ) );
check( 'valid modes accepted (first)', true === rest_validate_value_from_schema( 'first', $save['conflict_resolution'], 'conflict_resolution' ) );

$statements = Schema::create_statements();
$missions_stmt = $statements[ Schema::table( 'missions' ) ];
check( 'missions schema declares exclusive column', false !== strpos( $missions_stmt, 'exclusive tinyint(1)' ) );

// ---------------------------------------------------------------------------
// 5. Database behavior: campaign-priority ordering + reward/display
//    resolution (inside a transaction, rolled back)
// ---------------------------------------------------------------------------
echo "\n== 5. Campaign priority, rewards & payload (rolled back) ==\n";

$wpdb        = $GLOBALS['wpdb'];
$missions_table = Schema::table( 'missions' );
$camp_table  = Schema::table( 'campaigns' );

// Ensure the schema is applied (the missions.exclusive column is
// declared by Schema::create_statements() and dbDelta adds missing
// columns idempotently — the same upgrade a live install runs).
Installer::maybe_create_tables();

$has_exclusive = (bool) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
	$wpdb->dbname,
	$missions_table,
	'exclusive'
) );
check( 'missions.exclusive column present after upgrade', $has_exclusive );

$missions_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" );
$camps_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$camp_table}" );

$wpdb->query( 'START TRANSACTION' );

try {
	// Clean slate for deterministic ordering checks; the rollback restores
	// every deleted row.
	$wpdb->query( "DELETE FROM {$missions_table}" );
	$wpdb->query( "DELETE FROM {$camp_table}" );

	// Campaign A (priority 5) < campaign B (priority 20); campaign C is
	// inactive (its missions must be gated out). Standalone mission S competes
	// at the default campaign priority 10.
	$wpdb->insert( $camp_table, array( 'name' => 'Camp A', 'priority' => 5, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_a = (int) $wpdb->insert_id;

	$wpdb->insert( $camp_table, array( 'name' => 'Camp B', 'priority' => 20, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_b = (int) $wpdb->insert_id;

	$wpdb->insert( $camp_table, array( 'name' => 'Camp C', 'status' => 'inactive', 'priority' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$camp_c = (int) $wpdb->insert_id;

	$insert_mission = function ( $name, $campaign_id, $priority, $extra = array() ) use ( $wpdb, $missions_table ) {
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

		$wpdb->insert( $missions_table, $row );

		return (int) $wpdb->insert_id;
	};

	$mission_a1 = $insert_mission( 'Camp A mission', $camp_a, 10 );
	$mission_b1 = $insert_mission( 'Camp B mission', $camp_b, 1 );
	$mission_s  = $insert_mission( 'Standalone mission', null, 1 );
	$mission_c1 = $insert_mission( 'Inactive campaign mission', $camp_c, 1 );

	// 5.1 Deterministic order: campaign priority, then mission priority, then id.
	$repo    = new MissionRepository();
	$active  = $repo->active_missions();
	$names   = array_map( function ( Mission $g ) {
		return $g->name();
	}, $active );

	check( 'campaign-priority ordering (A, standalone, B)', array( 'Camp A mission', 'Standalone mission', 'Camp B mission' ) === $names );
	check( 'mission in inactive campaign gated out', ! in_array( 'Inactive campaign mission', $names, true ) );

	// 5.2 RewardEngine::sync_cart — conflict resolution on the live path.
	// Two completed fixed-discount missions (X: 50, Y: 100); cart of 200. Y
	// opts into stacking so the cumulative scenario really exercises
	// resolution (two same-type non-stacking rewards would block Y by
	// stacking safety, not by conflict resolution).
	$wpdb->query( "DELETE FROM {$missions_table}" );

	$mission_x = $insert_mission( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$mission_y = $insert_mission( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

	$cart = new \WC_Cart();
	$cart->cart_contents['c1'] = cart_line( 'c1', 0, 0, 1, 200.0, 200.0 );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
	$reward_engine = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'cumulative: both rewards available', RewardResult::STATE_AVAILABLE === $results[ $mission_x ]->state() && RewardResult::STATE_AVAILABLE === $results[ $mission_y ]->state() );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_FIRST );
	$reward_engine = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'first: only the first matching mission available', RewardResult::STATE_AVAILABLE === $results[ $mission_x ]->state() );
	check( 'first: later mission blocked (not_first)', RewardResult::STATE_BLOCKED === $results[ $mission_y ]->state() && ConflictResolver::REASON_NOT_FIRST === $results[ $mission_y ]->reason() );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_BEST );
	$reward_engine = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
	$results       = $reward_engine->sync_cart( $cart );
	check( 'best: higher-value reward available', RewardResult::STATE_AVAILABLE === $results[ $mission_y ]->state() );
	check( 'best: lower-value reward blocked (not_best)', RewardResult::STATE_BLOCKED === $results[ $mission_x ]->state() && ConflictResolver::REASON_NOT_BEST === $results[ $mission_x ]->reason() );

	// Exclusive mission (needs the missions.exclusive column). Give it a HIGHER
	// priority (5 < 10) than Y so it sorts first and suppresses Y.
	if ( $has_exclusive ) {
		$wpdb->query( "DELETE FROM {$missions_table}" );

		$mission_x = $insert_mission( 'Reward X exclusive', null, 5, array( 'target' => 100, 'exclusive' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
		$mission_y = $insert_mission( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

		$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
		$reward_engine = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
		$results       = $reward_engine->sync_cart( $cart );
		check( 'exclusive mission available', RewardResult::STATE_AVAILABLE === $results[ $mission_x ]->state() );
		check( 'exclusive suppresses lower-priority reward', RewardResult::STATE_BLOCKED === $results[ $mission_y ]->state() && ConflictResolver::REASON_EXCLUSIVE === $results[ $mission_y ]->reason() );
	}

	// 5.3 Progress payload — the conflict fragment reaches the frontend.
	// A clean two-mission slate so the payload has exactly X and Y.
	$wpdb->query( "DELETE FROM {$missions_table}" );

	$mission_x = $insert_mission( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$mission_y = $insert_mission( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100, 'reward_meta' => wp_json_encode( array( 'stacking' => 'stack' ) ) ) );

	$settings->set( 'default_mission_behavior', 'all' );
	$settings->set( 'conflict_resolution', ConflictResolver::MODE_FIRST );

	$frontend = new FrontendController( $engine, new MissionRepository(), $ci, $messages, $recommendations, $settings, new RewardEngine( $engine, new MissionRepository(), $settings, $ci ) );
	$req      = new \WP_REST_Request( 'GET', '/faracart/v1/progress' );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	check( 'payload carries both missions', 2 === count( $data['missions'] ) );
	check( 'winner payload resolved', true === $data['missions'][0]['conflict']['resolved'] );
	check( 'loser payload suppressed with reason', false === $data['missions'][1]['conflict']['resolved'] && ConflictResolver::REASON_NOT_FIRST === $data['missions'][1]['conflict']['reason'] );

	// 5.4 Display/grant parity — 'best' compares the REAL computed amounts.
	// On a 200 cart, 40% = 80 beats fixed 50, so the percent mission wins even
	// though its STATIC score (40) is lower than the fixed mission's (50); the
	// payload must agree with the cart's computed comparison.
	$wpdb->query( "DELETE FROM {$missions_table}" );

	$mission_p = $insert_mission( 'Percent 40', null, 10, array( 'target' => 100, 'reward_type' => 'percent_discount', 'reward_value' => 40 ) );
	$mission_f = $insert_mission( 'Fixed 50', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_BEST );

	$reward_engine  = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
	$engine_results = $reward_engine->sync_cart( $cart );

	$frontend = new FrontendController( $engine, new MissionRepository(), $ci, $messages, $recommendations, $settings, $reward_engine );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	$by_id = array();

	foreach ( $data['missions'] as $g ) {
		$by_id[ (int) $g['mission_id'] ] = $g;
	}

	check( 'best parity: computed percent (80) beats fixed (50) on the cart', RewardResult::STATE_AVAILABLE === $engine_results[ $mission_p ]->state() && RewardResult::STATE_BLOCKED === $engine_results[ $mission_f ]->state() );
	check( 'best parity: percent mission wins the payload', true === $by_id[ $mission_p ]['conflict']['resolved'] );
	check( 'best parity: fixed mission suppressed in the payload (not_best)', false === $by_id[ $mission_f ]['conflict']['resolved'] && ConflictResolver::REASON_NOT_BEST === $by_id[ $mission_f ]['conflict']['reason'] );

	foreach ( $engine_results as $id => $rr ) {
		check( "best parity: engine/payload agree (mission {$id})", ( RewardResult::STATE_BLOCKED === $rr->state() ) === ( false === $by_id[ $id ]['conflict']['resolved'] ) );
	}

	// 5.5 Display/grant parity — cumulative stacking suppression reaches
	// the payload: a same-type non-stacking loser never renders as won.
	$wpdb->query( "DELETE FROM {$missions_table}" );

	$mission_x = $insert_mission( 'Reward X', null, 10, array( 'target' => 100, 'reward_type' => 'fixed_discount', 'reward_value' => 50 ) );
	$mission_y = $insert_mission( 'Reward Y', null, 10, array( 'target' => 200, 'reward_type' => 'fixed_discount', 'reward_value' => 100 ) );

	$settings->set( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );

	$reward_engine  = new RewardEngine( $engine, new MissionRepository(), $settings, $ci );
	$engine_results = $reward_engine->sync_cart( $cart );

	$frontend = new FrontendController( $engine, new MissionRepository(), $ci, $messages, $recommendations, $settings, $reward_engine );
	$resp     = $frontend->handle_progress( $req, $cart );
	$data     = $resp->get_data()['data'];

	$by_id = array();

	foreach ( $data['missions'] as $g ) {
		$by_id[ (int) $g['mission_id'] ] = $g;
	}

	check( 'stacking parity: engine grants the first same-type reward', RewardResult::STATE_AVAILABLE === $engine_results[ $mission_x ]->state() );
	check( 'stacking parity: engine blocks the second (stacking)', RewardResult::STATE_BLOCKED === $engine_results[ $mission_y ]->state() && RewardResult::REASON_STACKING === $engine_results[ $mission_y ]->reason() );
	check( 'stacking parity: payload shows X won', true === $by_id[ $mission_x ]['conflict']['resolved'] );
	check( 'stacking parity: payload shows Y stacking-blocked', false === $by_id[ $mission_y ]['conflict']['resolved'] && RewardResult::REASON_STACKING === $by_id[ $mission_y ]['conflict']['reason'] );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

$settings->set_many( $all_before );

$missions_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" );
$camps_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$camp_table}" );
check( 'missions restored on rollback', $missions_before === $missions_after );
check( 'campaigns restored on rollback', $camps_before === $camps_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "CONFLICT TEST FAILED\n" : "CONFLICT TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
