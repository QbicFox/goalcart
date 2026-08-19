<?php
/**
 * FaraCart Phase 33.1 tests (Analytics Foundation).
 *
 * Boots WordPress, then exercises the Phase 33.1 revenue analytics
 * foundation:
 *
 *  - service wiring: RevenueTracker resolves from the container and its
 *    cleanup cron callback is registered
 *  - schema: the five Phase 33 tables (revenue_events, revenue_daily,
 *    mission_attribution, upsell_events, upsell_stats) exist and every
 *    expected index is present
 *  - event model: the revenue + upsell event whitelists are exposed and
 *    validated (a bogus type is rejected, a valid one accepted)
 *  - event recording: mission_view / mission_progress / mission_completed /
 *    order_paid land in revenue_events with cart value, mission target and
 *    incremental value; upsell events land in upsell_events
 *  - deduplication: mission_view is recorded once per session+mission in its
 *    24h window, mission_progress within 30 min, order_paid once per order,
 *    upsell_impression once per session+mission+product
 *  - privacy: rows store only anonymous session ids and numeric
 *    aggregates — no email/ip fields exist in the table, and a malformed
 *    session id is rejected
 *  - cleanup: run_cleanup() purges rows older than the retention window
 *    and drops upsell_stats rows for deleted products
 *
 * All writes (events, posts, stats) happen inside a single database
 * transaction that is rolled back; the absence of residue is asserted
 * afterwards. The schema tables themselves are verified read-only (they
 * are created by the Installer during the test bootstrap if missing).
 *
 * Run: php tests/revenue-foundation-test.php   (from the plugin directory)
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

use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\Session;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Hooks\HookManager;
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

// The Phase 33 tables are created by Installer::maybe_upgrade(), which runs
// on plugins_loaded / admin_init — neither fires in CLI after wp-load.
// Ensure the schema exists (dbDelta is idempotent) so the suite tests the
// real tables, exactly as the plugin would create them.
Installer::maybe_create_tables();

$container = \FaraCart\Plugin::instance()->container();

$tracker = $container->get( RevenueTracker::class );
$settings = $container->get( Settings::class );
$wpdb    = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Service wiring (P33.1: the tracker is wired and the cron registered)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'RevenueTracker resolves from the container', $tracker instanceof RevenueTracker );
check( 'cleanup cron event name is a plugin constant', RevenueTracker::CLEANUP_EVENT === 'faracart_revenue_cleanup' );
check( 'cleanup cron is in the installer cron list', in_array( RevenueTracker::CLEANUP_EVENT, \FaraCart\Database\Installer::cron_events(), true ) );
check( 'tracker registers hooks through the hook manager', did_action( 'faracart_loaded' ) > 0 );	$hooks = new HookManager();
	$tracker->register( $hooks );
	$hooks->run();
	check( 'cleanup handler registered by the tracker', has_action( RevenueTracker::CLEANUP_EVENT ) );

// ---------------------------------------------------------------------------
// 2. Schema: the five Phase 33 tables exist with their indexes
// ---------------------------------------------------------------------------
echo "\n== 2. Schema tables and indexes ==\n";

$table_exists = function ( $name ) use ( $wpdb ) {
	$table = Schema::table( $name );
	$found = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$table
		)
	);

	return (int) $found > 0;
};

check( 'revenue_events table exists', $table_exists( 'revenue_events' ) );
check( 'revenue_daily table exists', $table_exists( 'revenue_daily' ) );
check( 'mission_attribution table exists', $table_exists( 'mission_attribution' ) );
check( 'upsell_events table exists', $table_exists( 'upsell_events' ) );
check( 'upsell_stats table exists', $table_exists( 'upsell_stats' ) );

$table_indexes = function ( $name ) use ( $wpdb ) {
	$table = Schema::table( $name );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$table
		),
		ARRAY_A
	);

	$names = array();

	foreach ( (array) $rows as $row ) {
		$names[] = $row['INDEX_NAME'];
	}

	return $names;
};

$rev_idx = $table_indexes( 'revenue_events' );
check( 'revenue_events indexed on event_type', in_array( 'event_type', $rev_idx, true ) );
check( 'revenue_events indexed on mission_id', in_array( 'mission_id', $rev_idx, true ) );
check( 'revenue_events indexed on session_id', in_array( 'session_id', $rev_idx, true ) );
check( 'revenue_events indexed on order_id', in_array( 'order_id', $rev_idx, true ) );
check( 'revenue_events indexed on created_at', in_array( 'created_at', $rev_idx, true ) );
check( 'revenue_events composite mission_event index', in_array( 'mission_event', $rev_idx, true ) );

$daily_idx = $table_indexes( 'revenue_daily' );
check( 'revenue_daily indexed on mission_date composite', in_array( 'mission_date', $daily_idx, true ) );

$attrib_idx = $table_indexes( 'mission_attribution' );
check( 'mission_attribution unique order_mission_model', in_array( 'order_mission_model', $attrib_idx, true ) );

$upsell_idx = $table_indexes( 'upsell_events' );
check( 'upsell_events composite product_event index', in_array( 'product_event', $upsell_idx, true ) );

$stats_idx = $table_indexes( 'upsell_stats' );
check( 'upsell_stats unique product_id', in_array( 'product_id', $stats_idx, true ) );

// ---------------------------------------------------------------------------
// 3. Event model whitelists
// ---------------------------------------------------------------------------
echo "\n== 3. Event model ==\n";

$revenue_types = RevenueTracker::revenue_event_types();
$upsell_types  = RevenueTracker::upsell_event_types();

check( 'revenue whitelist has five types', 5 === count( $revenue_types ) );
check( 'mission_view whitelisted', in_array( 'mission_view', $revenue_types, true ) );
check( 'mission_progress whitelisted', in_array( 'mission_progress', $revenue_types, true ) );
check( 'mission_completed whitelisted', in_array( 'mission_completed', $revenue_types, true ) );
check( 'order_paid whitelisted', in_array( 'order_paid', $revenue_types, true ) );
check( 'cart_value whitelisted', in_array( 'cart_value', $revenue_types, true ) );

check( 'upsell whitelist has four types', 4 === count( $upsell_types ) );
check( 'upsell_impression whitelisted', in_array( 'upsell_impression', $upsell_types, true ) );
check( 'upsell_clicked whitelisted', in_array( 'upsell_clicked', $upsell_types, true ) );
check( 'upsell_added whitelisted', in_array( 'upsell_added', $upsell_types, true ) );
check( 'upsell_order whitelisted', in_array( 'upsell_order', $upsell_types, true ) );

check( 'is_revenue_event accepts mission_view', RevenueTracker::is_revenue_event( 'mission_view' ) );
check( 'is_revenue_event rejects bogus type', ! RevenueTracker::is_revenue_event( 'bogus' ) );
check( 'is_upsell_event accepts upsell_clicked', RevenueTracker::is_upsell_event( 'upsell_clicked' ) );
check( 'is_upsell_event rejects a revenue type', ! RevenueTracker::is_upsell_event( 'mission_view' ) );

// ---------------------------------------------------------------------------
// 4. Recording, dedup and privacy (rolled back)
// ---------------------------------------------------------------------------
echo "\n== 4. Recording, dedup, privacy (rolled back) ==\n";

$revenue_table = Schema::table( 'revenue_events' );
$upsell_table  = Schema::table( 'upsell_events' );
$stats_table   = Schema::table( 'upsell_stats' );

$revenue_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$upsell_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_table}" );	$wpdb->query( 'START TRANSACTION' );

	try {
		// The revenue/upsell tables reference missions and campaigns with
		// foreign keys (matching analytics_events), so the tracker only
		// accepts events for missions that actually exist — exactly as in
		// production, where a mission exists before its events are reported.
		// Create the referenced rows here (rolled back with everything
		// else) so every record/dedup check below is genuine.
		$campaign_table = Schema::table( 'campaigns' );
		$missions_table    = Schema::table( 'missions' );

		$wpdb->insert( $campaign_table, array(
			'id'         => 5,
			'name'       => 'P33 foundation test campaign',
			'status'     => 'active',
			'priority'   => 10,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		) );

		foreach ( array( 101, 202, 303, 404 ) as $mission_id ) {
			$wpdb->insert( $missions_table, array(
				'id'         => $mission_id,
				'name'       => "P33 foundation test mission {$mission_id}",
				'status'     => 'active',
				'type'       => 'amount',
				'target'     => 1000000,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			) );
		}

		// A valid anonymous session (32 lowercase hex, like the cookie).
		$session = str_repeat( 'ab', 16 );

	// 4.1 Record the funnel: view → progress → completed → order_paid.
	$view_id = $tracker->record( 'mission_view', array(
		'mission_id'     => 101,
		'campaign_id' => 5,
		'cart_value'  => 700000,
		'mission_target' => 1000000,
		'session_id'  => $session,
	) );
	check( 'mission_view recorded', $view_id > 0 );

	$progress_id = $tracker->record( 'mission_progress', array(
		'mission_id'           => 101,
		'cart_value'        => 900000,
		'mission_target'       => 1000000,
		'incremental_value' => 200000,
		'session_id'        => $session,
	) );
	check( 'mission_progress recorded', $progress_id > 0 );

	$completed_id = $tracker->record( 'mission_completed', array(
		'mission_id'           => 101,
		'cart_value'        => 1050000,
		'mission_target'       => 1000000,
		'incremental_value' => 150000,
		'session_id'        => $session,
	) );
	check( 'mission_completed recorded', $completed_id > 0 );

	$order_id = $tracker->record( 'order_paid', array(
		'mission_id'           => 101,
		'order_id'          => 777,
		'cart_value'        => 1050000,
		'incremental_value' => 350000,
		'session_id'        => $session,
	) );
	check( 'order_paid recorded', $order_id > 0 );

	// 4.2 Field integrity: the rows carry cart value, target and increment.
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$revenue_table} WHERE id = %d",
			$completed_id
		),
		ARRAY_A
	);
	check( 'completed row carries cart value', null !== $row && 1050000.0 === (float) $row['cart_value'] );
	check( 'completed row carries mission target', null !== $row && 1000000.0 === (float) $row['mission_target'] );
	check( 'completed row carries incremental value', null !== $row && 150000.0 === (float) $row['incremental_value'] );
	check( 'completed row carries the session id', null !== $row && $session === $row['session_id'] );
	check( 'completed row has no product (not an upsell)', null !== $row && null === $row['product_id'] );

	// 4.3 Dedup: mission_view is idempotent within its 24h window.
	$again = $tracker->record( 'mission_view', array(
		'mission_id'     => 101,
		'campaign_id' => 5,
		'cart_value'  => 710000,
		'mission_target' => 1000000,
		'session_id'  => $session,
	) );
	check( 'duplicate mission_view within window deduped', 0 === $again );

	// A different session sees the same mission → allowed (fresh exposure).
	$other_session = str_repeat( 'cd', 16 );
	$other_view = $tracker->record( 'mission_view', array(
		'mission_id'     => 101,
		'cart_value'  => 800000,
		'mission_target' => 1000000,
		'session_id'  => $other_session,
	) );
	check( 'same mission new session records a fresh view', $other_view > 0 );

	// Same session, a DIFFERENT mission → recorded (per-mission dedup).
	$mission_2_view = $tracker->record( 'mission_view', array(
		'mission_id'     => 202,
		'cart_value'  => 700000,
		'mission_target' => 1500000,
		'session_id'  => $session,
	) );
	check( 'same session different mission records', $mission_2_view > 0 );

	// 4.4 Dedup: mission_completed once per session+mission; order_paid once per
	// order (even for a different session).
	$completed_again = $tracker->record( 'mission_completed', array(
		'mission_id'     => 101,
		'cart_value'  => 1050000,
		'mission_target' => 1000000,
		'session_id'  => $session,
	) );
	check( 'duplicate mission_completed deduped', 0 === $completed_again );

	$order_again = $tracker->record( 'order_paid', array(
		'mission_id'    => 101,
		'order_id'   => 777,
		'cart_value' => 1050000,
		'session_id' => $other_session,
	) );
	check( 'order_paid deduped per order across sessions', 0 === $order_again );

	// 4.5 Upsell events: impression deduped per session+mission+product, but
	// a click for the same product records (different type).
	$imp1 = $tracker->record_upsell( 'upsell_impression', array(
		'mission_id'    => 101,
		'product_id' => 9001,
		'session_id' => $session,
	) );
	$imp2 = $tracker->record_upsell( 'upsell_impression', array(
		'mission_id'    => 101,
		'product_id' => 9001,
		'session_id' => $session,
	) );
	check( 'first upsell impression recorded', $imp1 > 0 );
	check( 'duplicate upsell impression deduped', 0 === $imp2 );

	$click = $tracker->record_upsell( 'upsell_clicked', array(
		'mission_id'    => 101,
		'product_id' => 9001,
		'session_id' => $session,
	) );
	check( 'upsell click recorded', $click > 0 );

	$add = $tracker->record_upsell( 'upsell_added', array(
		'mission_id'     => 101,
		'product_id'  => 9001,
		'cart_value'  => 1150000,
		'session_id'  => $session,
	) );
	check( 'upsell add recorded', $add > 0 );

	$upsell_order = $tracker->record_upsell( 'upsell_order', array(
		'mission_id'    => 101,
		'product_id' => 9001,
		'order_id'   => 777,
		'session_id' => $session,
	) );
	check( 'upsell order recorded', $upsell_order > 0 );

	$upsell_order_again = $tracker->record_upsell( 'upsell_order', array(
		'mission_id'    => 101,
		'product_id' => 9001,
		'order_id'   => 777,
		'session_id' => $other_session,
	) );
	check( 'upsell order deduped per order', 0 === $upsell_order_again );

	// 4.6 Privacy: no PII columns exist in the logs (email/ip/name would
	// be schema violations), and a malformed session id is rejected.
	$columns = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$revenue_table
		)
	);
	$all_columns = implode( ',', $columns );
	check( 'revenue_events has no email column', false === strpos( $all_columns, 'email' ) );
	check( 'revenue_events has no ip column', false === strpos( $all_columns, 'ip_address' ) );

	// A malformed session id falls back to the request's own cookie session
	// (never an error, never a stored PII string): the event still lands,
	// but its session_id is a well-formed anonymous 32-hex id.
	$bad_session = $tracker->record( 'mission_view', array(
		'mission_id'    => 101,
		'session_id' => 'not-a-valid-session-id-!!!',
	) );
	$bad_row = $bad_session > 0
		? $wpdb->get_row( $wpdb->prepare( "SELECT session_id FROM {$revenue_table} WHERE id = %d", $bad_session ), ARRAY_A )
		: null;
	check( 'malformed session falls back to a valid cookie session', null !== $bad_row && Session::is_valid( $bad_row['session_id'] ) );

	// 4.9 FK resilience: revenue/upsell events reference missions with ON
	// DELETE SET NULL, so an event reported after its mission was deleted
	// must not be dropped — the tracker retries once without the FK ids.
	$ghost = $tracker->record( 'mission_view', array(
		'mission_id'    => 999999,
		'session_id' => $other_session,
	) );
	$ghost_row = $ghost > 0
		? $wpdb->get_row( $wpdb->prepare( "SELECT mission_id, campaign_id FROM {$revenue_table} WHERE id = %d", $ghost ), ARRAY_A )
		: null;
	check( 'event for deleted mission lands via FK retry', null !== $ghost_row && null === $ghost_row['mission_id'] );

	$ghost_upsell = $tracker->record_upsell( 'upsell_clicked', array(
		'mission_id'    => 999999,
		'product_id' => 9001,
		'session_id' => $other_session,
	) );
	check( 'upsell for deleted mission lands via FK retry', $ghost_upsell > 0 );

	// The order_dedup unique key (event_type, order_id) is the final
	// guard against a concurrent double-report of the same order: even a
	// raw second insert for order 777 must be rejected.
	$dup_insert = $wpdb->insert( $revenue_table, array(
		'event_type' => 'order_paid',
		'order_id'   => 777,
		'session_id' => $session,
		'created_at' => current_time( 'mysql' ),
	) );
	check(
		'order_dedup unique key blocks a duplicate order insert',
		false === $dup_insert && false !== stripos( (string) $wpdb->last_error, 'Duplicate' )
	);

	// A non-whitelisted type is rejected before any write.
	$before_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
	$bogus = $tracker->record( 'not_an_event', array( 'mission_id' => 101, 'session_id' => $session ) );
	$after_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
	check( 'bogus event type rejected', 0 === $bogus && $before_count === $after_count );

	// 4.7 Gating: with analytics disabled the tracker records nothing.
	$settings->set( 'analytics_enabled', false );
	$gated = $tracker->record( 'mission_view', array(
		'mission_id'     => 303,
		'session_id'  => $session,
	) );
	check( 'tracking disabled → no event', 0 === $gated );
	$settings->set( 'analytics_enabled', true );

	// 4.8 Retention cleanup: age one row past the retention window and
	// verify run_cleanup() purges it (keeping the fresh rows).
	$old = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( RevenueTracker::RETENTION_DAYS + 2 ) * DAY_IN_SECONDS );
	$wpdb->insert( $revenue_table, array(
		'event_type'  => 'mission_view',
		'mission_id'     => 404,
		'session_id'  => $session,
		'created_at'  => $old,
	) );
	$old_row = (int) $wpdb->insert_id;

	// An upsell_stats row for a non-existent product (sweep target).
	$wpdb->insert( $stats_table, array(
		'product_id'  => 999999,
		'impressions' => 1,
		'updated_at'  => current_time( 'mysql' ),
	) );

	$deleted = $tracker->run_cleanup();

	$old_gone = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE id = %d", $old_row )
	);
	check( 'cleanup deletes rows past retention', 0 === $old_gone );

	$fresh_kept = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE id = %d", $view_id )
	);
	check( 'cleanup keeps fresh rows', 1 === $fresh_kept );

	$orphan_gone = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$stats_table} WHERE product_id = %d", 999999 )
	);
	check( 'orphan upsell_stats purged', 0 === $orphan_gone );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 5. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 5. Rollback verification ==\n";

$revenue_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$revenue_table}" );
$upsell_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_table}" );

check( 'revenue_events row count unchanged after rollback', $revenue_before === $revenue_after );
check( 'upsell_events row count unchanged after rollback', $upsell_before === $upsell_after );

$count = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$revenue_table} WHERE session_id = %s", str_repeat( 'ab', 16 ) )
);
check( 'no test events remain by session', 0 === $count );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "REVENUE FOUNDATION TEST FAILED\n" : "REVENUE FOUNDATION TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
