<?php
/**
 * FaraCart per-user completion limit tests.
 *
 * Boots WordPress and exercises the per-user mission completion limit end to
 * end:
 *
 *  - Mission model: `max_completions_per_user` parses (null = unlimited,
 *    positive ints only — zero/negative/non-numeric normalize to null)
 *  - MissionRepository: the column round-trips through create/update/get
 *  - CompletionService: COUNT-based per-user counts, batch counts,
 *    status (limit / count / remaining / can_complete), the
 *    can_complete eligibility rule and the transactional
 *    record_completion write path (limit enforced, unique order_mission
 *    idempotency, unlimited pass-through)
 *  - order-time integration: record_order_completions records one
 *    completion per met mission per order (idempotent on replay)
 *  - reward protection: available_missions() drops exhausted missions for the
 *    exhausted identity only; unlimited missions pass through without a
 *    count query
 *  - MessageEngine: the limit-reached state + copy
 *  - FrontendController: the per-mission `completion` payload fragment
 *  - independence: different users and different missions keep separate
 *    counts; a normal progress reset never touches the completion count
 *
 * The suite is self-contained: every row it writes (missions, completions)
 * is deleted at the end, so it stays green on the live store database
 * (record_completion runs its own transaction, so cleanup is explicit
 * rather than a rollback — the same reason the seeded missions get unique
 * names).
 *
 * Run: php tests/completion-limit-test.php (from the plugin directory)
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

// Force English assertions (the storefront strings are translated for the
// site locale, which is fa_IR on this install) — same pattern as
// message-test: switch to en_US and unload the faracart domain so __()
// falls back to the English source strings.
switch_to_locale( 'en_US' );
unload_textdomain( 'faracart' );

use FaraCart\Analytics\Session;
use FaraCart\Cart\CartIntegration;
use FaraCart\Database\Schema;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\CompletionService;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionRepository;
use FaraCart\Missions\MissionResult;
use FaraCart\Missions\MessageEngine;
use FaraCart\REST\FrontendController;
use FaraCart\Rewards\RewardEngine;
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

function fake_session( $seed = 'a' ) {
	return str_repeat( $seed, 32 );
}

$container = \FaraCart\Plugin::instance()->container();
$wpdb      = $GLOBALS['wpdb'];

$settings  = $container->get( Settings::class );
$engine    = $container->get( MissionEngine::class );
$repo      = $container->get( MissionRepository::class );
$ci        = $container->get( CartIntegration::class );
$messages  = $container->get( MessageEngine::class );
$completions = new CompletionService( $settings, $repo, $engine, $container->get( Session::class ) );

$missions_table      = Schema::table( 'missions' );
$completions_table = Schema::table( 'mission_completions' );

// ---------------------------------------------------------------------------
// 1. Mission model parsing (pure)
// ---------------------------------------------------------------------------
echo "\n== 1. Mission model: max_completions_per_user ==\n";

check( 'absent -> unlimited (null)', null === ( new Mission( array() ) )->max_completions_per_user() );
check( 'positive int parsed', 3 === ( new Mission( array( 'max_completions_per_user' => 3 ) ) )->max_completions_per_user() );
check( 'numeric string parsed', 5 === ( new Mission( array( 'max_completions_per_user' => '5' ) ) )->max_completions_per_user() );
check( 'zero normalizes to unlimited', null === ( new Mission( array( 'max_completions_per_user' => 0 ) ) )->max_completions_per_user() );
check( 'negative normalizes to unlimited', null === ( new Mission( array( 'max_completions_per_user' => -2 ) ) )->max_completions_per_user() );
check( 'garbage normalizes to unlimited', null === ( new Mission( array( 'max_completions_per_user' => 'abc' ) ) )->max_completions_per_user() );

// ---------------------------------------------------------------------------
// 2. Repository round-trip (writes deleted explicitly below)
// ---------------------------------------------------------------------------
echo "\n== 2. Repository round-trip ==\n";

$created = $repo->create( array(
	'name'             => 'Phase36 repo round-trip ' . uniqid(),
	'description'      => '',
	'status'           => 'active',
	'type'             => 'amount',
	'target'           => 100,
	'calculation_mode' => 'subtotal',
	'priority'         => 10,
	'max_completions_per_user' => 0, // must normalize to null
) );

check( 'mission created', $created > 0 );

$stored = $repo->get( $created );
check( 'zero limit stored as unlimited', array_key_exists( 'max_completions_per_user', $stored ) && null === $stored['max_completions_per_user'] );

$repo->update( $created, array( 'max_completions_per_user' => 4 ) );
$stored = $repo->get( $created );
check( 'update persists the limit', 4 === (int) $stored['max_completions_per_user'] );

$wpdb->delete( $missions_table, array( 'id' => $created ), array( '%d' ) );

// ---------------------------------------------------------------------------
// 3. CompletionService — counts & status (seeded rows)
// ---------------------------------------------------------------------------
echo "\n== 3. CompletionService: counts & status ==\n";

$seed_mission = function ( array $extra ) use ( $wpdb, $missions_table ) {
	$row = array_merge(
		array(
			'name'             => 'Phase36 ' . uniqid(),
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		),
		$extra
	);
	$wpdb->insert( $missions_table, $row );

	return (int) $wpdb->insert_id;
};

$seed_completion = function ( $mission_id, $user_id, $sid, $order_id = 0 ) use ( $wpdb, $completions_table ) {
	$wpdb->insert(
		$completions_table,
		array(
			'mission_id'    => $mission_id,
			'user_id'    => $user_id > 0 ? $user_id : null,
			'session_id' => '' !== $sid ? $sid : null,
			'order_id'   => $order_id > 0 ? $order_id : null,
			'created_at' => current_time( 'mysql' ),
		)
	);
};

$mission_lim3 = $seed_mission( array( 'max_completions_per_user' => 3 ) );
$mission_lim1 = $seed_mission( array( 'max_completions_per_user' => 1 ) );
$mission_unl  = $seed_mission( array() );

$guest_a = fake_session( 'b' );

// user 7: mission_lim3 x2, mission_unl x1   |   user 8: mission_lim3 x1   |   guest: mission_lim3 x1
$seed_completion( $mission_lim3, 7, '', 101 );
$seed_completion( $mission_lim3, 7, '', 102 );
$seed_completion( $mission_unl, 7, '', 103 );
$seed_completion( $mission_lim3, 8, '', 104 );
$seed_completion( $mission_lim3, 0, $guest_a, 105 );

$status_lim3_u7 = $completions->status( new Mission( array( 'id' => $mission_lim3, 'max_completions_per_user' => 3 ) ), 7, '' );
check( 'status: limit echoed', 3 === $status_lim3_u7['completion_limit'] );
check( 'status: count', 2 === $status_lim3_u7['completion_count'] );
check( 'status: remaining', 1 === $status_lim3_u7['remaining_completions'] );
check( 'status: can_complete', true === $status_lim3_u7['can_complete'] );

$status_lim3_u8 = $completions->status( new Mission( array( 'id' => $mission_lim3, 'max_completions_per_user' => 3 ) ), 8, '' );
check( 'status: different user independent count', 1 === $status_lim3_u8['completion_count'] && 2 === $status_lim3_u8['remaining_completions'] );

$status_lim1_u7 = $completions->status( new Mission( array( 'id' => $mission_lim1, 'max_completions_per_user' => 1 ) ), 7, '' );
check( 'status: untouched mission count 0', 0 === $status_lim1_u7['completion_count'] && 1 === $status_lim1_u7['remaining_completions'] && true === $status_lim1_u7['can_complete'] );

$status_unl_u7 = $completions->status( new Mission( array( 'id' => $mission_unl ) ), 7, '' );
check( 'status: unlimited -> null limit/remaining, can_complete', null === $status_unl_u7['completion_limit'] && null === $status_unl_u7['remaining_completions'] && true === $status_unl_u7['can_complete'] );

$guest_status = $completions->status( new Mission( array( 'id' => $mission_lim3, 'max_completions_per_user' => 3 ) ), 0, $guest_a );
check( 'guest count tracked by session', 1 === $guest_status['completion_count'] );
check( 'guest can still complete', true === $guest_status['can_complete'] );

check( 'count_for matches status count', 2 === $completions->count_for( $mission_lim3, 7, '' ) );

// Batch counts equal the individual counts (one grouped query).
$batch = $completions->counts_for( array( $mission_lim3, $mission_lim1, $mission_unl ), 7, '' );
check( 'batch counts match per-mission counts', 2 === $batch[ $mission_lim3 ] && 0 === $batch[ $mission_lim1 ] && 1 === $batch[ $mission_unl ] );

// can_complete rule.
$g_lim3 = new Mission( array( 'id' => $mission_lim3, 'status' => 'active', 'max_completions_per_user' => 3 ) );
check( 'can_complete true under limit', true === $completions->can_complete( $g_lim3, 7, '' ) );
check( 'can_complete true for other user', true === $completions->can_complete( $g_lim3, 8, '' ) );

// Mission reset: deleting/recreating progress never touches the
// completion history — the count lives in mission_completions, not in any
// progress row. A fresh evaluation of the same mission still sees the count.
check( 'reset: count survives a fresh mission object', 2 === $completions->count_for( $mission_lim3, 7, '', true ) );

// ---------------------------------------------------------------------------
// 4. record_completion — the transactional write path
// ---------------------------------------------------------------------------
echo "\n== 4. record_completion enforcement ==\n";

// Limit 1: first completion records, second is blocked (Test 1 + race
// double-submit equivalent — the row lock + fresh count serialize the two
// attempts the same way two concurrent requests would).
$one = $seed_mission( array( 'max_completions_per_user' => 1 ) );
$g_one = new Mission( array( 'id' => $one, 'status' => 'active', 'max_completions_per_user' => 1 ) );

check( 'limit 1: first completion recorded', true === $completions->record_completion( $g_one, 11, '', 201 ) );
check( 'limit 1: count now 1', 1 === $completions->count_for( $one, 11, '', true ) );
check( 'limit 1: second completion blocked', false === $completions->record_completion( $g_one, 11, '', 202 ) );
check( 'limit 1: count unchanged after block', 1 === $completions->count_for( $one, 11, '', true ) );
check( 'limit 1: can_complete now false', false === $completions->can_complete( $g_one, 11, '' ) );
check( 'limit 1: another user still allowed', true === $completions->record_completion( $g_one, 12, '', 203 ) );

// Limit 3: 3 allowed, 4th blocked (Test 2 sequence).
$three = $seed_mission( array( 'max_completions_per_user' => 3 ) );
$g_three = new Mission( array( 'id' => $three, 'status' => 'active', 'max_completions_per_user' => 3 ) );

$seq = array();
$seq = array();
$seq[] = $completions->record_completion( $g_three, 13, '', 301 );
$seq[] = $completions->record_completion( $g_three, 13, '', 302 );
$seq[] = $completions->record_completion( $g_three, 13, '', 303 );
$seq[] = $completions->record_completion( $g_three, 13, '', 304 );
check( 'limit 3: three completions allowed', array( true, true, true ) === array_slice( $seq, 0, 3 ) );
check( 'limit 3: fourth blocked', false === $seq[3] );
check( 'limit 3: remaining 0', 0 === $completions->status( $g_three, 13, '' )['remaining_completions'] );
check( 'limit 3: can_complete false', false === $completions->can_complete( $g_three, 13, '' ) );

// Unlimited: no cap, but the same (order, mission) is exactly-once.
$unl = $seed_mission( array() );
$g_unl = new Mission( array( 'id' => $unl, 'status' => 'active' ) );
check( 'unlimited: completion recorded', true === $completions->record_completion( $g_unl, 14, '', 401 ) );
check( 'unlimited: can_complete stays true', true === $completions->can_complete( $g_unl, 14, '' ) );
check( 'unlimited: same order replay is a no-op', false === $completions->record_completion( $g_unl, 14, '', 401 ) );
check( 'unlimited: count stays 1', 1 === $completions->count_for( $unl, 14, '', true ) );

// Guest identity: session-keyed counts enforce the limit independently of
// any user row.
$g_one_guest = new Mission( array( 'id' => $one, 'status' => 'active', 'max_completions_per_user' => 1 ) );
check( 'guest: session completion recorded', true === $completions->record_completion( $g_one_guest, 0, $guest_a, 501 ) );
check( 'guest: limit reached for that session', false === $completions->record_completion( $g_one_guest, 0, $guest_a, 502 ) );
check( 'guest: a different session still allowed', true === $completions->record_completion( $g_one_guest, 0, fake_session( 'c' ), 503 ) );

// Different missions stay independent (Test 5).
check( 'different missions: exhausted mission A does not block mission B', true === $completions->record_completion( $g_three, 15, '', 601 ) );

// Cleanup section 3+4 seeded missions and their completion rows.
$cleanup_missions = array( $mission_lim3, $mission_lim1, $mission_unl, $one, $three, $unl );
$in_cleanup    = implode( ',', array_map( 'intval', $cleanup_missions ) );
$wpdb->query( "DELETE FROM {$completions_table} WHERE mission_id IN ({$in_cleanup})" );
$wpdb->query( "DELETE FROM {$missions_table} WHERE id IN ({$in_cleanup})" );

// ---------------------------------------------------------------------------
// 5. Reward protection — available_missions + order-time recording
// ---------------------------------------------------------------------------
echo "\n== 5. Reward protection ==\n";

$protect_a = $seed_mission( array( 'max_completions_per_user' => 1, 'reward_type' => 'fixed_discount', 'reward_value' => 25 ) );
$protect_b = $seed_mission( array() ); // unlimited, reward
$g_protect_a = new Mission( array( 'id' => $protect_a, 'status' => 'active', 'max_completions_per_user' => 1 ) );
$g_protect_b = new Mission( array( 'id' => $protect_b, 'status' => 'active' ) );

check( 'exhausted mission: first completion recorded', true === $completions->record_completion( $g_protect_a, 21, '', 701 ) );

$ctx_user21 = new CartContext( array( 'subtotal' => 200, 'total' => 200, 'user_id' => 21 ) );
$ctx_user22 = new CartContext( array( 'subtotal' => 200, 'total' => 200, 'user_id' => 22 ) );

$for_21 = $completions->available_missions( array( $g_protect_a, $g_protect_b ), $ctx_user21 );
check( 'available_missions: exhausted mission dropped for user 21', 1 === count( $for_21 ) && $protect_b === $for_21[0]->id() );

$for_22 = $completions->available_missions( array( $g_protect_a, $g_protect_b ), $ctx_user22 );
check( 'available_missions: same mission still available to user 22', 2 === count( $for_22 ) );
check( 'available_missions: unlimited mission never dropped', in_array( $protect_b, array_map( function ( Mission $g ) { return $g->id(); }, $for_22 ), true ) );

// Order-time integration: an order that meets an amount mission records one
// completion for the customer (array-path order snapshot). The order is
// evaluated against every active mission on the store, so the assertions
// are scoped to the fixture mission's COUNT — the enforcement invariant —
// rather than the absolute number of rows recorded (which depends on
// the live mission set). All rows created for orders 801-803 are deleted
// below, so the live store is left untouched.
$order = array(
	'user_id'    => 31,
	'status'     => 'completed',
	'total'      => 200.0,
	'session_id' => fake_session( 'd' ),
);

$order_mission = $seed_mission( array( 'max_completions_per_user' => 2, 'target' => 100 ) );
$g_order_mission = new Mission( array( 'id' => $order_mission, 'status' => 'active', 'max_completions_per_user' => 2 ) );

$completions->record_order_completions( 801, $order );
check( 'order recording: count for the customer', 1 === $completions->count_for( $order_mission, 31, '', true ) );

$completions->record_order_completions( 801, $order );
check( 'order recording: same order replayed adds nothing', 1 === $completions->count_for( $order_mission, 31, '', true ) );

$completions->record_order_completions( 802, $order );
check( 'order recording: second order reaches limit 2', 2 === $completions->count_for( $order_mission, 31, '', true ) );

$completions->record_order_completions( 803, $order );
check( 'order recording: third order blocked at the limit', 2 === $completions->count_for( $order_mission, 31, '', true ) );
check( 'order recording: can_complete false for the customer', false === $completions->can_complete( $g_order_mission, 31, '' ) );

// RewardEngine grant gate: with the completion service injected, an
// exhausted mission never reaches the reward evaluation. The engine is
// constructed with a fresh repository so active_missions() re-reads the DB
// (the seeded missions are visible without the per-request cache).
$reward_engine = new RewardEngine( $engine, new MissionRepository(), $settings, $ci, null, $completions );

// Cleanup section 5 rows: the fixture missions, their completion rows, and
// the order-based rows the fixture order runs created (for every active
// mission the order met).
$cleanup2 = array( $protect_a, $protect_b, $order_mission );
$in2      = implode( ',', array_map( 'intval', $cleanup2 ) );
$wpdb->query( "DELETE FROM {$completions_table} WHERE mission_id IN ({$in2}) OR order_id IN (801, 802, 803)" );
$wpdb->query( "DELETE FROM {$missions_table} WHERE id IN ({$in2})" );

// ---------------------------------------------------------------------------
// 6. MessageEngine — limit-reached state & copy
// ---------------------------------------------------------------------------
echo "\n== 6. MessageEngine limit copy ==\n";

$g_mission = new Mission( array(
	'id'     => 900,
	'name'   => 'Phase36 message mission',
	'status' => 'active',
	'type'   => 'amount',
	'target' => 100,
) );
$done = new MissionResult( $g_mission, 120, 100 );
$blocked = array(
	'completion_limit'      => 1,
	'completion_count'      => 1,
	'remaining_completions' => 0,
	'can_complete'          => false,
);

check( 'state: limit reached', MessageEngine::STATE_COMPLETION_LIMIT === $messages->state( $g_mission, $done, $blocked ) );
check( 'message: limit copy', 'You have already completed this mission.' === $messages->message( $g_mission, $done, array(), $blocked ) );
check( 'state: without completion info unchanged', MessageEngine::STATE_COMPLETED === $messages->state( $g_mission, $done ) );
check( 'state: unlimited completion keeps reward copy', MessageEngine::STATE_REWARD_ACTIVATED === $messages->state(
	new Mission( array( 'id' => 901, 'status' => 'active', 'type' => 'amount', 'target' => 100, 'reward_type' => 'free_shipping' ) ),
	$done,
	array( 'can_complete' => true )
) );

// ---------------------------------------------------------------------------
// 7. Frontend payload — the per-mission completion fragment
// ---------------------------------------------------------------------------
echo "\n== 7. Frontend payload completion fragment ==\n";

$frontend = new FrontendController(
	$engine,
	new MissionRepository(),
	$ci,
	$messages,
	$container->get( \FaraCart\Recommendations\ProductRecommendationEngine::class ),
	$settings,
	new RewardEngine( $engine, new MissionRepository(), $settings, $ci ),
	null,
	$completions
);

$ctx = new CartContext( array( 'subtotal' => 120, 'total' => 120, 'user_id' => 41 ) );
$result = $engine->evaluate( $g_mission, $ctx );
$shaped = $frontend->shape_mission( $g_mission, $result, $ctx );

check( 'payload carries completion fragment', isset( $shaped['completion'] ) && is_array( $shaped['completion'] ) );
check( 'payload completion: unlimited limit null', null === $shaped['completion']['completion_limit'] && null === $shaped['completion']['remaining_completions'] );
check( 'payload completion: count 0, can_complete true', 0 === $shaped['completion']['completion_count'] && true === $shaped['completion']['can_complete'] );

// An exhausted mission: state switches to the limit copy and the reward chip
// renders locked (conflict fragment overridden) — the storefront never
// claims a reward the server will not grant. The mission is a real DB row so
// record_completion can lock it.
$lim_id = $seed_mission( array(
	'reward_type' => 'fixed_discount',
	'reward_value' => 20,
	'max_completions_per_user' => 1,
) );
$g_lim = new Mission( array(
	'id'     => $lim_id,
	'name'   => 'Phase36 limit payload mission',
	'status' => 'active',
	'type'   => 'amount',
	'target' => 100,
	'reward_type' => 'fixed_discount',
	'reward_value' => 20,
	'max_completions_per_user' => 1,
) );
$result_lim = $engine->evaluate( $g_lim, $ctx );
$shaped_lim = $frontend->shape_mission( $g_lim, $result_lim, $ctx );

check( 'payload limit: limit 1', 1 === $shaped_lim['completion']['completion_limit'] );
check( 'payload limit: count 0 (no history for user 41)', 0 === $shaped_lim['completion']['completion_count'] && true === $shaped_lim['completion']['can_complete'] );
check( 'payload limit: state still reward_activated while allowed', MessageEngine::STATE_REWARD_ACTIVATED === $shaped_lim['state'] );

// Record one completion for user 41 on the mission, re-evaluate: now blocked
// (the per-request count cache must reflect the write immediately).
$completions->record_completion( $g_lim, 41, '', 901 );
$shaped_blocked = $frontend->shape_mission( $g_lim, $result_lim, $ctx );

check( 'payload blocked: can_complete false', false === $shaped_blocked['completion']['can_complete'] );
check( 'payload blocked: limit state', MessageEngine::STATE_COMPLETION_LIMIT === $shaped_blocked['state'] );
check( 'payload blocked: limit message', 'You have already completed this mission.' === $shaped_blocked['message'] );
check( 'payload blocked: reward chip locked via conflict', false === $shaped_blocked['conflict']['resolved'] && 'completion_limit' === $shaped_blocked['conflict']['reason'] );

$wpdb->query( "DELETE FROM {$completions_table} WHERE mission_id IN ({$lim_id})" );
$wpdb->query( "DELETE FROM {$missions_table} WHERE id IN ({$lim_id})" );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n============================================================\n";
echo "Checks: {$checks}   Failures: {$failures}\n";

exit( $failures > 0 ? 1 : 0 );
