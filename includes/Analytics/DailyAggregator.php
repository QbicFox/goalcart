<?php
/**
 * Daily revenue aggregation for FaraCart (Phase 33.3 — Aggregation & Performance).
 *
 * @package GoalCart
 */

namespace GoalCart\Analytics;

use GoalCart\Database\Schema;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class DailyAggregator
 *
 * Phase 33.3 (Aggregation & Performance) — the scheduled job that pre-computes
 * the heavy analytics so admin/dashboard reads never scan the raw event log.
 * Two outputs, both built with the exact same definitions as the live reads:
 *
 *  - `revenue_daily`  — one row per goal per day (views, progressions,
 *    completions, conversions, revenue, incremental_revenue, reward_cost,
 *    estimated_profit), filled by aggregate_revenue_day() from the Phase 33.1
 *    revenue_events + goal_attribution logs through the AttributionEngine's
 *    daily_metrics() (same funnel + summary + reward-cost + profit code the
 *    dashboard reads live — the aggregate and the live view can never drift).
 *  - `upsell_stats`   — one row per product (impressions, clicks, adds,
 *    orders, revenue), fully rebuilt by aggregate_upsells() from the
 *    upsell_events log — the historical conversion signal the Smart Upsell
 *    Engine reads (Phase 33.5).
 *
 * Scheduling (P33.3): the job runs on the `daily` cron interval through the
 * Installer's cron_events()/cron_intervals() maps, and is gated on the same
 * revenue-tracking consent chain as the event pipeline (RevenueTracker).
 *
 * Bounded catch-up (large datasets): run() aggregates at most
 * goalcart_aggregate_max_days (default 7) days per tick, starting the day
 * after the last aggregated date (goalcart_revenue_last_aggregated option) or
 * the lookback floor (goalcart_aggregate_lookback_days, default 90 — aligned
 * with the retention window so a stale option can never re-process purged
 * rows). A backlog drains over several runs instead of one unbounded pass.
 *
 * Idempotency: revenue_daily rows for a date are deleted before being
 * rewritten, and upsell_stats is a full delete+rebuild, so re-running the job
 * (cron replays, manual triggers) never duplicates data.
 *
 * After a successful run the goalcart_revenue_aggregated action fires, which
 * the RevenueRepository listens to for cache invalidation.
 */
final class DailyAggregator {

	/**
	 * Cron event name for the daily aggregation job.
	 *
	 * @var string
	 */
	const AGGREGATE_EVENT = 'goalcart_revenue_aggregate';

	/**
	 * Option storing the last date aggregated into revenue_daily ('Y-m-d').
	 *
	 * @var string
	 */
	const LAST_AGGREGATED_OPTION = 'goalcart_revenue_last_aggregated';

	/**
	 * Default maximum days aggregated per cron tick (filterable).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_DAYS = 7;

	/**
	 * Default lookback floor for the first run / stale options (filterable).
	 *
	 * @var int
	 */
	const DEFAULT_LOOKBACK_DAYS = 90;

	/**
	 * Revenue attribution engine (per-goal daily metrics).
	 *
	 * @var AttributionEngine
	 */
	protected $engine;

	/**
	 * Revenue event tracker (consent gate + event constants).
	 *
	 * @var RevenueTracker
	 */
	protected $tracker;

	/**
	 * Constructor.
	 *
	 * @param AttributionEngine $engine  Revenue attribution engine.
	 * @param RevenueTracker    $tracker Revenue event tracker.
	 */
	public function __construct( AttributionEngine $engine, RevenueTracker $tracker ) {
		$this->engine  = $engine;
		$this->tracker = $tracker;
	}

	/**
	 * Register the cron callback.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( self::AGGREGATE_EVENT, array( $this, 'run' ) );
	}

	/**
	 * Cron callback: aggregate revenue + rebuild product stats, bounded.
	 *
	 * Gated on the revenue tracking consent chain (no events are recorded
	 * when tracking is off, so there is nothing to aggregate). Fires the
	 * goalcart_revenue_aggregated action afterwards for cache invalidation.
	 *
	 * @return int Number of days aggregated (0 when gated off / nothing pending).
	 */
	public function run() {
		if ( ! $this->tracker->tracking_enabled() ) {
			return 0;
		}

		$days     = $this->aggregate_revenue();
		$products = $this->aggregate_upsells();

		/**
		 * Fires after the daily aggregation run completes.
		 *
		 * @param int $days     Number of days aggregated into revenue_daily.
		 * @param int $products Number of products rebuilt in upsell_stats.
		 */
		do_action( 'goalcart_revenue_aggregated', $days, $products );

		return $days;
	}

	/**
	 * Aggregate revenue_events + goal_attribution into revenue_daily.
	 *
	 * Processes at most goalcart_aggregate_max_days days per call, from the
	 * day after the last aggregated date (or the lookback floor on the first
	 * run) through yesterday — today's data is read live by the repository
	 * until tomorrow's tick captures it.
	 *
	 * @return int Number of days aggregated.
	 */
	public function aggregate_revenue() {
		$max_days = (int) apply_filters( 'goalcart_aggregate_max_days', self::DEFAULT_MAX_DAYS );
		$max_days = max( 1, min( 90, $max_days ) );

		$lookback = (int) apply_filters( 'goalcart_aggregate_lookback_days', self::DEFAULT_LOOKBACK_DAYS );
		$lookback = max( 1, min( 730, $lookback ) );

		$today = date( 'Y-m-d', current_time( 'timestamp' ) );
		$last  = (string) get_option( self::LAST_AGGREGATED_OPTION, '' );

		// Start one day after the last aggregated date; the first run (or a
		// cleared option) starts at the lookback floor instead.
		$floor = date( 'Y-m-d', strtotime( $today . ' -' . $lookback . ' days' ) );
		$start = $last ? date( 'Y-m-d', strtotime( $last . ' +1 day' ) ) : $floor;

		// The floor also caps a stale option that points past the retention
		// window (purged events cannot be re-aggregated anyway).
		if ( $start < $floor ) {
			$start = $floor;
		}

		$end = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		if ( $start > $end ) {
			return 0;
		}

		$processed = 0;
		$cursor    = $start;

		while ( $cursor <= $end && $processed < $max_days ) {
			$this->aggregate_revenue_day( $cursor );
			$processed++;
			$cursor = date( 'Y-m-d', strtotime( $cursor . ' +1 day' ) );
		}

		if ( $processed > 0 ) {
			// Record the last day actually aggregated, so the next run picks
			// up the day after it (not the start of this run's window).
			$last_day = date( 'Y-m-d', strtotime( $start . ' +' . ( $processed - 1 ) . ' days' ) );
			update_option( self::LAST_AGGREGATED_OPTION, $last_day, false );
		}

		return $processed;
	}

	/**
	 * Aggregate a single date into revenue_daily (idempotent).
	 *
	 * Only goals with revenue activity on that date (events and/or
	 * attribution rows) get rows — no all-goals scan. Existing rows for the
	 * date are removed first, so re-runs never duplicate.
	 *
	 * @param string $date Date 'Y-m-d'.
	 * @return int Number of goals aggregated.
	 */
	public function aggregate_revenue_day( $date ) {
		global $wpdb;

		$revenue = Schema::table( 'revenue_events' );
		$attrib  = Schema::table( 'goal_attribution' );
		$daily   = Schema::table( 'revenue_daily' );

		$from = $date . ' 00:00:00';
		$to   = $date . ' 23:59:59';

		// Goal ids with any revenue activity on this date (events or
		// attribution rows). UNION dedupes; NULL goal ids (deleted goals —
		// the FK is ON DELETE SET NULL) are excluded — there is nothing to
		// attribute them to.
		$goal_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT goal_id FROM {$revenue}
				 WHERE created_at >= %s AND created_at <= %s AND goal_id IS NOT NULL
				 UNION
				 SELECT goal_id FROM {$attrib}
				 WHERE created_at >= %s AND created_at <= %s AND goal_id IS NOT NULL",
				$from,
				$to,
				$from,
				$to
			)
		);

		// Idempotent rewrite: drop the date's rows, then write fresh ones.
		$wpdb->delete( $daily, array( 'report_date' => $date ), array( '%s' ) );

		$goals = array();

		foreach ( array_unique( array_map( 'intval', (array) $goal_ids ) ) as $goal_id ) {
			if ( $goal_id < 1 ) {
				continue;
			}

			$goals[] = $goal_id;

			// Same code path the live dashboard reads (funnel + summary +
			// reward cost + profit scoped to this goal and date).
			$metrics = $this->engine->daily_metrics(
				$goal_id,
				array( 'from' => $date, 'to' => $date )
			);

			$wpdb->insert(
				$daily,
				array(
					'report_date'         => $date,
					'goal_id'             => $goal_id,
					'views'               => (int) $metrics['views'],
					'progressions'        => (int) $metrics['progressions'],
					'completions'         => (int) $metrics['completions'],
					'conversions'         => (int) $metrics['conversions'],
					'revenue'             => round( (float) $metrics['revenue'], 4 ),
					'incremental_revenue' => round( (float) $metrics['incremental_revenue'], 4 ),
					'reward_cost'         => round( (float) $metrics['reward_cost'], 4 ),
					'estimated_profit'    => null !== $metrics['estimated_profit']
						? round( (float) $metrics['estimated_profit'], 4 )
						: 0.0,
					'created_at'          => current_time( 'mysql' ),
					'updated_at'          => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s' )
			);
		}

		return count( $goals );
	}

	/**
	 * Rebuild upsell_stats from the upsell_events log (full rebuild).
	 *
	 * The stats table is a pure aggregate of the (retention-bounded) event
	 * log, so one grouped INSERT...SELECT replaces it wholesale — idempotent,
	 * and never drifts from the raw rows. Products with no events disappear,
	 * mirroring the cleanup sweep's semantics.
	 *
	 * Atomicity: the delete + insert run under a savepoint, so a failure
	 * between them rolls the stats table back to its previous state instead
	 * of leaving it empty until the next run. A savepoint (not a nested
	 * START TRANSACTION) is used deliberately: the aggregator may run inside
	 * an outer transaction (tests, admin-driven backfills), and a nested
	 * START TRANSACTION would implicitly commit it.
	 *
	 * @return int Number of product rows rebuilt (0 on failure).
	 */
	public function aggregate_upsells() {
		global $wpdb;

		$stats  = Schema::table( 'upsell_stats' );
		$events = Schema::table( 'upsell_events' );

		$impression = RevenueTracker::EVENT_UPSELL_IMPRESSION;
		$clicked    = RevenueTracker::EVENT_UPSELL_CLICKED;
		$added      = RevenueTracker::EVENT_UPSELL_ADDED;
		$order      = RevenueTracker::EVENT_UPSELL_ORDER;

		$wpdb->query( 'SAVEPOINT goalcart_upsell_rebuild' );

		$deleted = $wpdb->query( "DELETE FROM {$stats}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin constant.

		$rebuilt = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$stats}
				 (product_id, impressions, clicks, adds, orders, revenue, updated_at)
				 SELECT product_id,
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
					SUM( CASE WHEN event_type = %s THEN 1 ELSE 0 END ),
					COUNT( DISTINCT CASE WHEN event_type = %s THEN order_id END ),
					SUM( CASE WHEN event_type = %s THEN COALESCE(cart_value, 0) ELSE 0 END ),
					%s
				 FROM {$events}
				 WHERE product_id IS NOT NULL
				 GROUP BY product_id",
				$impression,
				$clicked,
				$added,
				$order,
				$order,
				current_time( 'mysql' )
			)
		);

		if ( false === $deleted || false === $rebuilt ) {
			// Restore the previous stats state on any failure.
			$wpdb->query( 'ROLLBACK TO SAVEPOINT goalcart_upsell_rebuild' );
			$wpdb->query( 'RELEASE SAVEPOINT goalcart_upsell_rebuild' );

			return 0;
		}

		$wpdb->query( 'RELEASE SAVEPOINT goalcart_upsell_rebuild' );

		return max( 0, (int) $rebuilt );
	}
}
