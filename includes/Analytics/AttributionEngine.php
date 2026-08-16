<?php
/**
 * Revenue attribution engine for FaraCart (Phase 33.2).
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Database\Schema;
use FaraCart\Goals\Goal;
use FaraCart\Goals\GoalRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class AttributionEngine
 *
 * Phase 33.2 (Revenue Attribution) — turns the Phase 33.1 revenue event
 * funnel into per-order goal attribution and measurable revenue metrics.
 *
 * Order association (P33.2): when an order becomes revenue-producing
 * (`woocommerce_payment_complete`, plus `woocommerce_order_status_completed`
 * as a backstop for manual transitions — both idempotent), the engine
 * records the order_paid event through the RevenueTracker and associates
 * the order with the goals that influenced its session:
 *
 *  - direct   → the session progressed and/or completed the goal before
 *               ordering; the order's incremental value is attributed
 *               (split equally across the direct goals — deterministic,
 *               never double counted)
 *  - assisted → the session was exposed to the goal (goal_view) but never
 *               progressed it; the order total is recorded as assisted
 *               revenue with zero incremental value
 *
 * Rows land in `goal_attribution` guarded by the order_goal_model unique
 * key, so re-processing (both hooks firing, cron replays) never duplicates
 * an order's attribution.
 *
 * Metrics (P33.2): funnel counts + completion/conversion rates, incremental
 * cart value (cart value after goal exposure − value at first exposure, per
 * session), goal-driven (direct incremental) revenue, goal-assisted
 * revenue, AOV analysis (store-wide vs goal-exposed orders — labeled
 * "observed", never causality), reward cost (via RewardCostEstimator) and
 * estimated profit impact (graceful: unavailable without margin data).
 *
 * Every read is SQL-aggregated and bounded (METRIC_MAX_ROWS, filterable) so
 * admin/dashboard requests never scan unbounded data; the Phase 33.3
 * aggregator later pre-computes these summaries.
 *
 * Data accuracy (P33.2): only revenue-producing order statuses are
 * attributed (REVENUE_STATUSES — processing/completed per WooCommerce
 * convention); refunded/cancelled/failed orders are excluded; order_paid
 * and goal_attribution are each exactly-once per order by design.
 */
final class AttributionEngine {

	/**
	 * Attribution model constants (goal_attribution.model).
	 */
	const MODEL_DIRECT   = 'direct';
	const MODEL_ASSISTED = 'assisted';

	/**
	 * Order statuses considered revenue-producing (WooCommerce convention:
	 * orders that have been paid for / fulfilled). Refunded, cancelled,
	 * failed and on-hold orders are never attributed.
	 *
	 * @var string[]
	 */
	const REVENUE_STATUSES = array( 'processing', 'completed' );

	/**
	 * Lookback window for session-funnel association (seconds).
	 *
	 * Only goal events within this window before the order are attributed —
	 * a stale exposure (weeks old) does not count as influencing an order.
	 *
	 * @var int
	 */
	const ATTRIBUTION_WINDOW = 30 * DAY_IN_SECONDS;

	/**
	 * Default cap for bounded metric reads (rows per query).
	 *
	 * Filterable with faracart_attribution_metric_rows.
	 *
	 * @var int
	 */
	const METRIC_MAX_ROWS = 5000;

	/**
	 * Page cap for the paginated store-wide order scans (AOV, shipping).
	 * 100 pages × 100 orders = 10,000 orders per window, filterable.
	 *
	 * @var int
	 */
	const ORDER_SCAN_PAGES = 100;

	/**
	 * Revenue event tracker (owns the revenue_events log).
	 *
	 * @var RevenueTracker
	 */
	protected $tracker;

	/**
	 * Session manager (anonymous sessions).
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Reward cost / profit impact estimator.
	 *
	 * @var RewardCostEstimator
	 */
	protected $costs;

	/**
	 * Goal repository (reward config + goal names for summaries).
	 *
	 * @var GoalRepository
	 */
	protected $repository;

	/**
	 * Per-request goal cache (id => Goal).
	 *
	 * @var array<int, Goal>
	 */
	protected $goal_cache = array();

	/**
	 * Per-request cache of fetched orders (id => WC_Order|null).
	 *
	 * @var array<int, \WC_Order|null>
	 */
	protected $order_cache = array();

	/**
	 * Per-request cache of the store-wide order scans (from|to => result),
	 * so AOV and shipping reads never run the paginated scan twice.
	 *
	 * @var array<string, array{available: bool, orders: \WC_Order[]}>
	 */
	protected $order_scan_cache = array();

	/**
	 * Constructor.
	 *
	 * @param RevenueTracker     $tracker    Revenue event tracker.
	 * @param Session            $session    Session manager.
	 * @param Settings           $settings   Plugin settings.
	 * @param RewardCostEstimator $costs     Reward cost / profit estimator.
	 * @param GoalRepository     $repository Goal repository.
	 */
	public function __construct( RevenueTracker $tracker, Session $session, Settings $settings, RewardCostEstimator $costs, GoalRepository $repository ) {
		$this->tracker    = $tracker;
		$this->session    = $session;
		$this->settings   = $settings;
		$this->costs      = $costs;
		$this->repository = $repository;
	}

	/**
	 * Register the order-attribution hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// Primary: fired by gateways when an order is paid for.
		$hooks->add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_paid' ), 10, 1 );

		// Backstop: manual admin transitions straight to completed (COD,
		// offline flows) never fire payment_complete. Both paths are
		// idempotent — the order_paid dedup and the order_goal_model unique
		// key make double processing a no-op.
		$hooks->add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_paid' ), 10, 1 );
	}

	/**
	 * Whether order attribution is enabled.
	 *
	 * Gates on the master + analytics toggles through the RevenueTracker
	 * (same consent chain as the event pipeline) plus the dedicated
	 * faracart_attribution_enabled filter.
	 *
	 * @return bool
	 */
	public function enabled() {
		if ( ! $this->tracker->tracking_enabled() ) {
			return false;
		}

		/**
		 * Filter whether revenue attribution (order association) is on.
		 *
		 * @param bool              $enabled Whether attribution is enabled.
		 * @param AttributionEngine $engine  Engine instance.
		 */
		return (bool) apply_filters( 'faracart_attribution_enabled', true, $this );
	}

	/**
	 * WooCommerce hook handler: attribute a paid order.
	 *
	 * @param int $order_id Order id.
	 * @return int Number of attribution rows written (0 = none/deduped).
	 */
	public function handle_order_paid( $order_id ) {
		if ( ! $this->enabled() ) {
			return 0;
		}

		return $this->attribute_order( (int) $order_id );
	}

	/**
	 * Attribute an order to the goals that influenced its session.
	 *
	 * Idempotent: re-running for the same order (both hooks, cron replays)
	 * finds the recorded order_paid session and skips existing
	 * goal_attribution rows via the order_goal_model unique key.
	 *
	 * Accepts a WC_Order object or a plain data array (total, status,
	 * user_id, shipping_total, date, session_id) so headless/tests can
	 * drive the same code path without WooCommerce.
	 *
	 * @param int                     $order_id Order id.
	 * @param \WC_Order|array|null    $order    Order object or data array.
	 * @return int Number of attribution rows written.
	 */
	public function attribute_order( $order_id, $order = null ) {
		$order_id = (int) $order_id;

		if ( $order_id < 1 || ! $this->enabled() ) {
			return 0;
		}

		if ( null === $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$data = $this->order_data( $order );

		if ( empty( $data ) || ! $this->is_revenue_order( $data ) ) {
			return 0;
		}

		$session_id = $this->resolve_session( $order_id, $data );

		// An explicit session in the data array (test/headless path) wins —
		// production WC_Order path has no such key and uses the resolved one.
		if ( isset( $data['session_id'] ) && Session::is_valid( $data['session_id'] ) ) {
			$session_id = $data['session_id'];
		}

		$order_total = (float) $data['total'];
		$order_date  = (string) $data['date'];

		// 1. Record the order_paid event (exactly once per order — the
		// tracker dedups by order id and the order_dedup unique key backs it).
		$this->tracker->record(
			RevenueTracker::EVENT_ORDER_PAID,
			array(
				'order_id'   => $order_id,
				'session_id' => $session_id,
				'cart_value' => $order_total,
				'meta'       => array(
					'status'  => $data['status'],
					'shipping' => round( (float) $data['shipping_total'], 4 ),
				),
			)
		);

		// 2. The session funnel before the order.
		$funnel = $this->session_funnel( $session_id, $order_date );

		if ( empty( $funnel['goals'] ) ) {
			return 0;
		}

		// 3. Model selection: progressed/completed goals are direct; goals
		// with only exposure (goal_view) are assisted. The incremental
		// amount is the order total above the cart value at first exposure,
		// split equally across the direct goals (never double counted).
		$direct   = array();
		$assisted = array();

		foreach ( $funnel['goals'] as $goal_id => $goal_funnel ) {
			if ( $goal_funnel['progressed'] || $goal_funnel['completed'] ) {
				$direct[ (int) $goal_id ] = $goal_funnel;
			} else {
				$assisted[ (int) $goal_id ] = $goal_funnel;
			}
		}

		// A missing baseline (no view event carried a cart value) means no
		// measurable "before" — attribute zero rather than inflating the
		// incremental to the full order total.
		$incremental = null !== $funnel['baseline']
			? max( 0.0, $order_total - (float) $funnel['baseline'] )
			: 0.0;
		$per_direct   = ! empty( $direct ) ? $incremental / count( $direct ) : 0.0;
		$user_id      = (int) $data['user_id'];

		// 4. Write the attribution rows (idempotent via unique key).
		$written = 0;

		foreach ( $direct as $goal_id => $goal_funnel ) {
			$written += $this->upsert_attribution(
				$order_id,
				$goal_id,
				$session_id,
				$user_id,
				self::MODEL_DIRECT,
				$order_total,
				$per_direct,
				$goal_funnel['completed'] ? 1 : 0,
				$order_date
			);
		}

		foreach ( $assisted as $goal_id => $goal_funnel ) {
			$written += $this->upsert_attribution(
				$order_id,
				$goal_id,
				$session_id,
				$user_id,
				self::MODEL_ASSISTED,
				$order_total,
				0.0,
				0,
				$order_date
			);
		}

		return $written;
	}

	/**
	 * Normalize an order into a plain data array.
	 *
	 * @param \WC_Order|array|null $order Order object or data array.
	 * @return array<string, mixed> Empty when unrecognized.
	 */
	protected function order_data( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
			$date = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

			return array(
				'total'          => (float) $order->get_total(),
				'shipping_total' => (float) $order->get_shipping_total(),
				'user_id'        => (int) $order->get_user_id(),
				'status'         => (string) $order->get_status(),
				'date'           => $date ? $date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
			);
		}

		if ( is_array( $order ) ) {
			return array(
				'total'          => isset( $order['total'] ) ? (float) $order['total'] : 0.0,
				'shipping_total' => isset( $order['shipping_total'] ) ? (float) $order['shipping_total'] : 0.0,
				'user_id'        => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
				'status'         => isset( $order['status'] ) ? (string) $order['status'] : 'completed',
				'date'           => isset( $order['date'] ) ? (string) $order['date'] : current_time( 'mysql' ),
				'session_id'     => isset( $order['session_id'] ) ? (string) $order['session_id'] : '',
			);
		}

		return array();
	}

	/**
	 * Whether an order's status is revenue-producing.
	 *
	 * @param array<string, mixed> $data Order data.
	 * @return bool
	 */
	protected function is_revenue_order( array $data ) {
		return in_array( (string) $data['status'], self::REVENUE_STATUSES, true );
	}

	/**
	 * Resolve the attribution session for an order.
	 *
	 * Priority: the session recorded on the order_paid event (idempotent
	 * re-runs keep the original session) → the live cookie session
	 * (checkout-time attribution) → the most recent goal session of a
	 * logged-in user (cookie rotation fallback).
	 *
	 * @param int                 $order_id Order id.
	 * @param array<string, mixed> $data     Order data.
	 * @return string Anonymous session id ('' when unresolvable).
	 */
	protected function resolve_session( $order_id, array $data ) {
		global $wpdb;

		$events = Schema::table( 'revenue_events' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT session_id FROM {$events} WHERE event_type = %s AND order_id = %d ORDER BY id DESC LIMIT 1",
				RevenueTracker::EVENT_ORDER_PAID,
				$order_id
			),
			ARRAY_A
		);

		if ( is_array( $row ) && Session::is_valid( (string) $row['session_id'] ) ) {
			return (string) $row['session_id'];
		}

		$cookie = $this->session->id();

		if ( Session::is_valid( $cookie ) ) {
			return $cookie;
		}

		if ( (int) $data['user_id'] > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT session_id FROM {$events}
					 WHERE user_id = %d AND event_type IN (%s, %s, %s, %s)
					 ORDER BY created_at DESC, id DESC LIMIT 1",
					(int) $data['user_id'],
					RevenueTracker::EVENT_GOAL_VIEW,
					RevenueTracker::EVENT_GOAL_PROGRESS,
					RevenueTracker::EVENT_GOAL_COMPLETED,
					RevenueTracker::EVENT_CART_VALUE
				),
				ARRAY_A
			);

			if ( is_array( $row ) && Session::is_valid( (string) $row['session_id'] ) ) {
				return (string) $row['session_id'];
			}
		}

		return '';
	}

	/**
	 * Load the session's goal funnel before an order.
	 *
	 * Walks the session's revenue events (within the attribution window
	 * before the order date) and returns the baseline cart value at first
	 * exposure (null when no view event carried a cart value — a
	 * measurable "before" does not exist, so no incremental is computed)
	 * plus, per goal, whether it was viewed / progressed / completed.
	 *
	 * @param string $session_id Anonymous session id.
	 * @param string $order_date Order date ('Y-m-d H:i:s').
	 * @return array{baseline: float|null, goals: array<int, array{viewed: bool, progressed: bool, completed: bool}>}
	 */
	protected function session_funnel( $session_id, $order_date ) {
		$empty = array( 'baseline' => null, 'goals' => array() );

		if ( ! Session::is_valid( $session_id ) || '' === (string) $order_date ) {
			return $empty;
		}

		global $wpdb;

		$events = Schema::table( 'revenue_events' );
		$cutoff = date( 'Y-m-d H:i:s', strtotime( (string) $order_date ) - self::ATTRIBUTION_WINDOW );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT goal_id, event_type, cart_value
				 FROM {$events}
				 WHERE session_id = %s AND event_type IN (%s, %s, %s)
				   AND created_at >= %s AND created_at <= %s
				 ORDER BY created_at ASC, id ASC",
				$session_id,
				RevenueTracker::EVENT_GOAL_VIEW,
				RevenueTracker::EVENT_GOAL_PROGRESS,
				RevenueTracker::EVENT_GOAL_COMPLETED,
				$cutoff,
				$order_date
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $empty;
		}

		$goals   = array();
		$baseline = null;

		foreach ( $rows as $row ) {
			$goal_id = (int) $row['goal_id'];

			if ( ! isset( $goals[ $goal_id ] ) ) {
				$goals[ $goal_id ] = array( 'viewed' => false, 'progressed' => false, 'completed' => false );
			}

			switch ( $row['event_type'] ) {
				case RevenueTracker::EVENT_GOAL_VIEW:
					$goals[ $goal_id ]['viewed'] = true;

					// Baseline = cart value at first exposure.
					if ( null === $baseline && null !== $row['cart_value'] ) {
						$baseline = (float) $row['cart_value'];
					}
					break;

				case RevenueTracker::EVENT_GOAL_PROGRESS:
					$goals[ $goal_id ]['progressed'] = true;
					break;

				case RevenueTracker::EVENT_GOAL_COMPLETED:
					$goals[ $goal_id ]['completed'] = true;
					break;
			}
		}

		// Only goals the session was actually exposed to participate.
		foreach ( $goals as $goal_id => $funnel ) {
			if ( ! $funnel['viewed'] ) {
				unset( $goals[ $goal_id ] );
			}
		}

		return array(
			'baseline' => $baseline,
			'goals'    => $goals,
		);
	}

	/**
	 * Insert a goal_attribution row, skipping duplicates.
	 *
	 * The order_goal_model unique key makes this exactly-once per
	 * (order, goal, model) — the concurrency-safe guard behind the
	 * idempotent attribution contract.
	 *
	 * @param int    $order_id      Order id.
	 * @param int    $goal_id       Goal id.
	 * @param string $session_id    Session id.
	 * @param int    $user_id       User id (0 = guest).
	 * @param string $model         self::MODEL_DIRECT|MODEL_ASSISTED.
	 * @param float  $order_total   Order total.
	 * @param float  $incremental   Attributed incremental value.
	 * @param int    $goal_completed Whether the goal was completed pre-order.
	 * @param string $date          Created-at date.
	 * @return int 1 when a row was written, 0 when deduped/failed.
	 */
	protected function upsert_attribution( $order_id, $goal_id, $session_id, $user_id, $model, $order_total, $incremental, $goal_completed, $date ) {
		global $wpdb;

		$table = Schema::table( 'goal_attribution' );

		$data = array(
			'order_id'         => (int) $order_id,
			'goal_id'          => (int) $goal_id,
			'session_id'       => $session_id,
			'user_id'          => $user_id > 0 ? $user_id : null,
			'model'            => $model,
			'order_total'      => round( (float) $order_total, 4 ),
			'incremental_value'=> round( (float) $incremental, 4 ),
			'goal_completed'   => (int) $goal_completed,
			'created_at'       => '' !== (string) $date ? (string) $date : current_time( 'mysql' ),
		);

		$formats = array( '%d', '%d', '%s', '%d', '%s', '%f', '%f', '%d', '%s' );

		$inserted = $wpdb->insert( $table, $data, $formats );

		// Success → 1 (a row written); the order_goal_model unique key hit
		// (or a genuine failure) → 0, keeping attribution idempotent. The
		// count semantics let attribute_order() report rows written, not
		// summed auto-increment ids.
		return $inserted ? 1 : 0;
	}

	/**
	 * Funnel counts and rates for the goal funnel (views → progressed →
	 * completed → converted), optionally per goal and within a date range.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<string, mixed>
	 */
	public function funnel( array $args = array() ) {
		global $wpdb;

		$events = Schema::table( 'revenue_events' );
		$attrib = Schema::table( 'goal_attribution' );

		list( $event_sql, $event_params ) = $this->revenue_where( $args );
		list( $attrib_sql, $attrib_params ) = $this->attribution_where( $args );

		$counts = array(
			RevenueTracker::EVENT_GOAL_VIEW      => 0,
			RevenueTracker::EVENT_GOAL_PROGRESS  => 0,
			RevenueTracker::EVENT_GOAL_COMPLETED => 0,
		);

		foreach ( $counts as $type => $_unused ) {
			$counts[ $type ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$events} WHERE event_type = %s AND {$event_sql}",
					array_merge( array( $type ), $event_params )
				)
			);
		}

		$converted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT order_id) FROM {$attrib} WHERE {$attrib_sql}",
				$attrib_params
			)
		);

		$views = $counts[ RevenueTracker::EVENT_GOAL_VIEW ];
		$completed = $counts[ RevenueTracker::EVENT_GOAL_COMPLETED ];

		return array(
			'views'            => $views,
			'progressed'       => $counts[ RevenueTracker::EVENT_GOAL_PROGRESS ],
			'completed'        => $completed,
			'converted'        => $converted,
			'completion_rate'  => $views > 0 ? round( $completed / $views, 4 ) : null,
			'conversion_rate'  => $completed > 0 ? round( $converted / $completed, 4 ) : null,
		);
	}

	/**
	 * Incremental cart value: cart value after goal exposure minus the
	 * value at first exposure, averaged per session.
	 *
	 * Bounded (METRIC_MAX_ROWS, filterable) — the Phase 33.3 aggregator
	 * pre-computes this daily for large stores.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<string, mixed>
	 */
	public function incremental_cart_value( array $args = array() ) {
		global $wpdb;

		$events = Schema::table( 'revenue_events' );
		$limit  = (int) apply_filters( 'faracart_attribution_metric_rows', self::METRIC_MAX_ROWS );

		list( $where, $params ) = $this->revenue_where( $args );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, event_type, cart_value
				 FROM {$events}
				 WHERE event_type IN (%s, %s, %s) AND {$where}
				 ORDER BY session_id ASC, created_at ASC, id ASC
				 LIMIT %d",
				array_merge(
					array(
						RevenueTracker::EVENT_GOAL_VIEW,
						RevenueTracker::EVENT_GOAL_PROGRESS,
						RevenueTracker::EVENT_GOAL_COMPLETED,
					),
					$params,
					array( max( 1, $limit ) )
				)
			),
			ARRAY_A
		);

		// Per session: baseline = cart value at first exposure; peak = the
		// highest cart value recorded afterwards.
		$sessions = array();

		foreach ( $rows as $row ) {
			$session_id = (string) $row['session_id'];

			if ( ! isset( $sessions[ $session_id ] ) ) {
				$sessions[ $session_id ] = array( 'baseline' => null, 'peak' => 0.0, 'events' => 0 );
			}

			$cart_value = null !== $row['cart_value'] ? (float) $row['cart_value'] : 0.0;

			if ( null === $sessions[ $session_id ]['baseline'] ) {
				$sessions[ $session_id ]['baseline'] = $cart_value;
			}

			$sessions[ $session_id ]['peak']   = max( $sessions[ $session_id ]['peak'], $cart_value );
			$sessions[ $session_id ]['events']++;
		}

		$gains          = array();
		$total_gain     = 0.0;
		$baseline_total = 0.0;
		$with_gain      = 0;

		foreach ( $sessions as $session ) {
			// A single snapshot says nothing about movement.
			if ( null === $session['baseline'] || $session['events'] < 2 ) {
				continue;
			}

			$baseline_total += $session['baseline'];
			$gain = max( 0.0, $session['peak'] - $session['baseline'] );
			$gains[] = $gain;
			$total_gain += $gain;

			if ( $gain > 0 ) {
				$with_gain++;
			}
		}

		$count = count( $gains );

		return array(
			'average'            => $count > 0 ? round( $total_gain / $count, 4 ) : 0.0,
			'total'              => round( $total_gain, 4 ),
			'average_baseline'   => $count > 0 ? round( $baseline_total / $count, 4 ) : 0.0,
			'sessions'           => $count,
			'sessions_with_gain' => $with_gain,
			'data_sufficiency'   => $count < 10 ? 'low' : ( $count < 50 ? 'medium' : 'high' ),
		);
	}

	/**
	 * Revenue attribution summary: goal-driven (direct incremental) revenue,
	 * goal-assisted revenue, total goal-influenced revenue, reward cost and
	 * estimated profit impact.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array<string, mixed>
	 */
	public function attribution_summary( array $args = array() ) {
		global $wpdb;

		$attrib = Schema::table( 'goal_attribution' );

		list( $where, $params ) = $this->attribution_where( $args );

		// Goal-driven revenue = sum of direct incremental value (additive
		// across goals — it is the amount the goals moved the carts).
		$direct_revenue = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(incremental_value), 0) FROM {$attrib} WHERE model = %s AND {$where}",
				array_merge( array( self::MODEL_DIRECT ), $params )
			)
		);

		// Goal-influenced revenue = sum of order totals of every order with
		// any attribution row (distinct orders — never double counted).
		$influenced_revenue = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(t.total), 0) FROM (SELECT order_id, MAX(order_total) AS total FROM {$attrib} WHERE {$where} GROUP BY order_id) t",
				$params
			)
		);

		// Goal-assisted revenue = order totals of orders whose attribution
		// rows are ALL assisted (no direct row for any goal).
		$assisted_revenue = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(a.order_total), 0)
				 FROM {$attrib} a
				 WHERE a.model = %s AND {$where}
				   AND NOT EXISTS (SELECT 1 FROM {$attrib} d WHERE d.order_id = a.order_id AND d.model = %s)",
				array_merge(
					array( self::MODEL_ASSISTED, self::MODEL_DIRECT ),
					$params
				)
			)
		);

		$orders = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT order_id) FROM {$attrib} WHERE {$where}",
				$params
			)
		);

		$reward_cost   = $this->reward_cost_for_rows( $args );
		$profit_result = $this->profit_impact_for_rows( $args, $reward_cost['estimated_cost'] );
		$profit        = $profit_result['profit'];

		// Profit availability states (Improvement.md §13 / §39): a stable
		// machine-readable reason code the React layer translates, never
		// shown raw. The strict profit model is unchanged — only the
		// explanation is enriched.
		if ( 0 === $profit_result['orders_total'] ) {
			$reason_code = 'insufficient_data';
			$profit      = array_merge( $profit, array(
				'reason' => __( 'Not enough attributed order data to estimate profit yet.', 'faracart' ),
			) );
		} elseif ( ! $profit['available'] ) {
			$reason_code = 'missing_product_cost';
		} elseif ( $profit_result['orders_with_cost'] < $profit_result['orders_total'] ) {
			$reason_code = 'incomplete_product_cost';
		} else {
			$reason_code = 'available';
		}

		// Profit-model building blocks (§12 profit details panel) — all
		// values already computed by the bounded reads above; nothing is
		// fabricated or re-queried.
		$profit_details = array(
			'incremental_revenue' => round( (float) $profit['incremental_revenue'], 4 ),
			'margin_pct'          => null !== $profit['margin_pct'] ? round( (float) $profit['margin_pct'], 6 ) : null,
			'reward_cost'         => round( (float) $profit['reward_cost'], 4 ),
			'shipping_cost'       => null !== $profit['shipping_cost'] ? round( (float) $profit['shipping_cost'], 4 ) : null,
		);

		return array(
			'goal_driven_revenue'   => round( $direct_revenue, 4 ),
			'goal_assisted_revenue' => round( $assisted_revenue, 4 ),
			'goal_influenced_revenue' => round( $influenced_revenue, 4 ),
			'orders'                => $orders,
			'reward_cost'           => $reward_cost['estimated_cost'],
			'reward_cost_available' => $reward_cost['available'],
			'profit_impact'         => $profit['estimated_profit'],
			'profit_available'      => $profit['available'],
			'profit_reason'         => $profit['reason'],
			'profit_reason_code'    => $reason_code,
			'profit_details'        => $profit_details,
			// Cost-data coverage over the incremental-revenue (direct)
			// orders (§11) — informational only; the profit calculation
			// itself keeps the strict all-lines-costed model.
			'cost_coverage'         => array(
				'attributed_orders'     => $profit_result['orders_total'],
				'orders_with_cost_data' => $profit_result['orders_with_cost'],
				'coverage_pct'          => $profit_result['orders_total'] > 0
					? round( $profit_result['orders_with_cost'] / $profit_result['orders_total'] * 100, 1 )
					: null,
				// "available" means "there are attributed orders to measure
				// coverage against" — NOT that coverage is 100%. With 0%
				// coverage it is true and coverage_pct is 0; with no orders
				// it is false and coverage_pct is null.
				'available'             => $profit_result['orders_total'] > 0,
			),
			// UI-ready availability metadata (Phase 3): the cost sources the
			// estimator consults and whether the store carries ANY cost data
			// — the help panel uses this to explain how to make Estimated
			// Profit available (§10) vs how much of the data is covered (§11).
			'cost_sources'          => RewardCostEstimator::COST_SOURCES,
			'store_has_cost_data'   => $this->costs->store_has_cost_data(),
			'funnel'                => $this->funnel( $args ),
		);
	}

	/**
	 * Per-goal daily aggregate row (the revenue_daily row shape).
	 *
	 * Consumed by the Phase 33.3 DailyAggregator so the aggregated table is
	 * computed with the exact same definitions as the live reads
	 * (funnel counts + attribution summary), keeping the dashboard and the
	 * pre-aggregated history consistent. The window (from/to) is typically
	 * a single day.
	 *
	 * @param int                  $goal_id Goal id.
	 * @param array<string, mixed> $args    Optional: from, to.
	 * @return array{views: int, progressions: int, completions: int, conversions: int, revenue: float, incremental_revenue: float, reward_cost: float, reward_cost_available: bool, estimated_profit: float|null, profit_available: bool}
	 */
	public function daily_metrics( $goal_id, array $args = array() ) {
		$summary = $this->attribution_summary(
			array_merge( $args, array( 'goal_id' => (int) $goal_id ) )
		);

		$funnel = $summary['funnel'];

		return array(
			'views'                => $funnel['views'],
			'progressions'         => $funnel['progressed'],
			'completions'          => $funnel['completed'],
			'conversions'          => $funnel['converted'],
			// revenue = totals of the orders influenced by the goal that
			// day; incremental_revenue = the direct (driven) increment.
			'revenue'              => $summary['goal_influenced_revenue'],
			'incremental_revenue'  => $summary['goal_driven_revenue'],
			'reward_cost'          => $summary['reward_cost'],
			'reward_cost_available'=> $summary['reward_cost_available'],
			'estimated_profit'     => $summary['profit_impact'],
			'profit_available'     => $summary['profit_available'],
		);
	}

	/**
	 * Per-goal metrics (the Goal Performance row shape).
	 *
	 * @param int                   $goal_id Goal id.
	 * @param array<string, mixed>  $args    Optional: from, to.
	 * @return array<string, mixed>|null Null when the goal does not exist.
	 */
	public function goal_metrics( $goal_id, array $args = array() ) {
		$goal = $this->goal( (int) $goal_id );

		if ( null === $goal ) {
			return null;
		}

		$scoped = array_merge( $args, array( 'goal_id' => (int) $goal_id ) );
		$summary = $this->attribution_summary( $scoped );
		$funnel  = $summary['funnel'];

		// Incremental cart value scoped to the goal.
		$incremental = $this->incremental_cart_value( $scoped );

		// Smart Upsell linkage (UPSELL_REFACTOR §29/§30/§31): the goal's
		// upsell funnel (impressions/clicks/adds/purchases) plus the
		// upsell-assisted completion count — completions whose session also
		// saw a product recommendation for this goal. Only counted when the
		// event linkage is reliable (same session + goal), never fabricated.
		$upsell = $this->goal_upsell_stats( (int) $goal_id, $args );

		return array(
			'goal_id'             => $goal->id(),
			'name'                => $goal->name(),
			'reward_type'         => $goal->reward_type(),
			'target'              => $goal->target(),
			'views'               => $funnel['views'],
			'progressed'          => $funnel['progressed'],
			'completed'           => $funnel['completed'],
			'converted'           => $funnel['converted'],
			'completion_rate'     => $funnel['completion_rate'],
			'conversion_rate'     => $funnel['conversion_rate'],
			'average_cart_value'  => $incremental['average_baseline'],
			'incremental_cart_value' => $incremental['average'],
			// Smart Upsell linkage (UPSELL_REFACTOR §30/§32/§33): how many of
			// this goal's completions were assisted by a product
			// recommendation, the assisted rate, and the full per-goal
			// upsell funnel (impressions → clicks → adds → purchases).
			'upsell_assisted'       => $upsell['assisted'],
			'upsell_assisted_rate'  => $upsell['assisted_rate'],
			'upsell_funnel'         => array(
				'impressions' => $upsell['impressions'],
				'clicks'      => $upsell['clicks'],
				'adds'        => $upsell['adds'],
				'orders'      => $upsell['orders'],
			),
			// Phase 5 (Goal Performance Redesign) — commercial-outcome and
			// detail-drawer fields. All derived from the already-computed
			// attribution summary + incremental read above (Improvement.md
			// §20): total influenced order value, the engine's attribution
			// window, and the session-count data-sufficiency signal. Nothing
			// new is queried.
			'influenced_revenue'    => $summary['goal_influenced_revenue'],
			'attribution_window_days' => (int) ( self::ATTRIBUTION_WINDOW / DAY_IN_SECONDS ),
			'data_sufficiency'      => $incremental['data_sufficiency'],
			'attributed_revenue'  => $summary['goal_driven_revenue'],
			'assisted_revenue'    => $summary['goal_assisted_revenue'],
			'reward_cost'         => $summary['reward_cost'],
			'reward_cost_available' => $summary['reward_cost_available'],
			'profit_impact'       => $summary['profit_impact'],
			'profit_available'    => $summary['profit_available'],
			'profit_reason'       => $summary['profit_reason'],
			'profit_reason_code'  => $summary['profit_reason_code'],
			'profit_details'      => $summary['profit_details'],
			'cost_coverage'       => $summary['cost_coverage'],
			'cost_sources'        => $summary['cost_sources'],
			'store_has_cost_data' => $summary['store_has_cost_data'],
		);
	}

	/**
	 * The goal's Smart Upsell linkage over the window.
	 *
	 * Returns the per-goal upsell funnel counts (impressions / clicked /
	 * added / upsell_order purchases from the raw upsell_events log — the
	 * same source the Upsell Performance page reads, so the numbers always
	 * agree) and the upsell-assisted completion count: distinct sessions
	 * that completed the goal (a goal_completed revenue event in the
	 * window) AND saw a product recommendation for that goal (any upsell
	 * funnel event in the same session+goal). This is a reliable linkage —
	 * both events are session-scoped and goal-scoped — so the metric is
	 * never fabricated (UPSELL_REFACTOR §30).
	 *
	 * @param int                   $goal_id Goal id.
	 * @param array<string, mixed>  $args    Optional: from, to.
	 * @return array{impressions: int, clicks: int, adds: int, orders: int, assisted: int, assisted_rate: float|null}
	 */
	protected function goal_upsell_stats( $goal_id, array $args ) {
		global $wpdb;

		$upsell = Schema::table( 'upsell_events' );
		$revenue = Schema::table( 'revenue_events' );

		// Window bounds for the funnel counts (upsell_events has no goal
		// window helper of its own — same semantics as revenue_where).
		$funnel_where  = '1=1';
		$funnel_params = array();

		if ( ! empty( $args['from'] ) ) {
			$funnel_where  .= ' AND created_at >= %s';
			$funnel_params[] = date( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$funnel_where  .= ' AND created_at <= %s';
			$funnel_params[] = date( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		$types  = array( 'impressions' => RevenueTracker::EVENT_UPSELL_IMPRESSION, 'clicks' => RevenueTracker::EVENT_UPSELL_CLICKED, 'adds' => RevenueTracker::EVENT_UPSELL_ADDED, 'orders' => RevenueTracker::EVENT_UPSELL_ORDER );
		$counts = array( 'impressions' => 0, 'clicks' => 0, 'adds' => 0, 'orders' => 0 );

		foreach ( $types as $key => $event_type ) {
			$counts[ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$upsell} WHERE goal_id = %d AND event_type = %s AND {$funnel_where}",
					array_merge( array( (int) $goal_id, $event_type ), $funnel_params )
				)
			);
		}

		// Assisted completions: sessions with a goal_completed event that
		// also saw a recommendation (impression/clicked/added) for the goal.
		// The window applies to the completion event (revenue_where); the
		// upsell join is window-free but session+goal-scoped, which is the
		// reliable linkage the metric requires.
		list( $event_sql, $event_params ) = $this->revenue_where( $args );

		// The completion is always scoped to THIS goal (`r.goal_id = %d`) —
		// the join pins the upsell side to the same goal via
		// `u.goal_id = r.goal_id`, so a session counts only when it both
		// completed this goal and saw a recommendation for this goal.
		$assisted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT r.session_id)
				 FROM {$revenue} r
				 INNER JOIN {$upsell} u ON u.session_id = r.session_id AND u.goal_id = r.goal_id
				  AND u.event_type IN (%s, %s, %s)
				 WHERE r.event_type = %s AND r.goal_id = %d AND {$event_sql}",
				array_merge(
					array(
						RevenueTracker::EVENT_UPSELL_IMPRESSION,
						RevenueTracker::EVENT_UPSELL_CLICKED,
						RevenueTracker::EVENT_UPSELL_ADDED,
						RevenueTracker::EVENT_GOAL_COMPLETED,
						(int) $goal_id,
					),
					$event_params
				)
			)
		);

		$completed = $this->goal_completions( (int) $goal_id, $args );

		return array(
			'impressions'   => $counts['impressions'],
			'clicks'        => $counts['clicks'],
			'adds'          => $counts['adds'],
			'orders'        => $counts['orders'],
			'assisted'      => $assisted,
			'assisted_rate' => $completed > 0 ? round( $assisted / $completed, 4 ) : null,
		);
	}

	/**
	 * The goal's completion count over the window (revenue_events).
	 *
	 * Always scoped to the requested goal (`goal_id`), like the assisted
	 * count it is the denominator for — a store-wide completion count
	 * would dilute the rate with unrelated goals.
	 *
	 * @param int                   $goal_id Goal id.
	 * @param array<string, mixed>  $args    Optional: from, to.
	 * @return int
	 */
	protected function goal_completions( $goal_id, array $args ) {
		global $wpdb;

		$events = Schema::table( 'revenue_events' );

		list( $where, $params ) = $this->revenue_where( $args );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$events} WHERE event_type = %s AND goal_id = %d AND {$where}",
				array_merge( array( RevenueTracker::EVENT_GOAL_COMPLETED, (int) $goal_id ), $params )
			)
		);
	}

	/**
	 * AOV analysis: store-wide AOV vs goal-exposed AOV (observed impact).
	 *
	 * Never claims causality — the returned label is 'observed_impact'. The
	 * store-wide scan is paginated and capped (ORDER_SCAN_PAGES); when
	 * WooCommerce order data is unavailable the comparison falls back to
	 * the attributed orders only and marks the comparison unavailable.
	 *
	 * @param array<string, mixed> $args Optional: from, to.
	 * @return array<string, mixed>
	 */
	public function aov_analysis( array $args = array() ) {
		global $wpdb;

		$attrib = Schema::table( 'goal_attribution' );

		list( $where, $params ) = $this->attribution_where( $args );

		$exposed_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(t.total), 0) FROM (SELECT order_id, MAX(order_total) AS total FROM {$attrib} WHERE {$where} GROUP BY order_id) t",
				$params
			)
		);

		$exposed_orders = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT order_id) FROM {$attrib} WHERE {$where}",
				$params
			)
		);

		$store = $this->store_order_totals( $args );

		$exposed_aov = $exposed_orders > 0 ? $exposed_total / $exposed_orders : 0.0;

		if ( ! $store['available'] ) {
			return array(
				'overall_aov'     => round( $exposed_aov, 4 ),
				'exposed_aov'     => round( $exposed_aov, 4 ),
				'non_exposed_aov' => null,
				'absolute_change' => 0.0,
				'percentage_change' => 0.0,
				'exposed_orders'  => $exposed_orders,
				'total_orders'    => $exposed_orders,
				'label'           => 'observed_impact',
				'comparison_available' => false,
			);
		}

		$store_orders = (int) $store['orders'];
		$store_total  = (float) $store['total'];
		$overall_aov  = $store_orders > 0 ? $store_total / $store_orders : 0.0;

		$non_exposed_orders = max( 0, $store_orders - $exposed_orders );
		$non_exposed_total  = max( 0.0, $store_total - $exposed_total );
		$non_exposed_aov    = $non_exposed_orders > 0 ? $non_exposed_total / $non_exposed_orders : null;

		$change = $overall_aov > 0 ? $exposed_aov - $overall_aov : 0.0;

		return array(
			'overall_aov'     => round( $overall_aov, 4 ),
			'exposed_aov'     => round( $exposed_aov, 4 ),
			'non_exposed_aov' => null !== $non_exposed_aov ? round( $non_exposed_aov, 4 ) : null,
			'absolute_change' => round( $change, 4 ),
			'percentage_change' => $overall_aov > 0 ? round( $change / $overall_aov, 4 ) : 0.0,
			'exposed_orders'  => $exposed_orders,
			'total_orders'    => $store_orders,
			'label'           => 'observed_impact',
			'comparison_available' => true,
		);
	}

	/**
	 * Shipping statistics over the store's orders in the window.
	 *
	 * Feeds the Phase 33.4 shipping-aware goal recommendations: average
	 * shipping cost, free-shipping share and per-method averages.
	 *
	 * @param array<string, mixed> $args Optional: from, to.
	 * @return array<string, mixed>
	 */
	public function shipping_stats( array $args = array() ) {
		$orders = $this->store_orders( $args );

		if ( ! $orders['available'] ) {
			return array(
				'available' => false,
				'average_shipping' => 0.0,
				'orders_with_shipping' => 0,
				'free_shipping_orders' => 0,
				'by_method' => array(),
			);
		}

		$shipping_total = 0.0;
		$with_shipping  = 0;
		$free_shipping  = 0;
		$by_method      = array();

		foreach ( $orders['orders'] as $order ) {
			$shipping = (float) $order->get_shipping_total();

			if ( $shipping > 0 ) {
				$shipping_total += $shipping;
				$with_shipping++;
			} else {
				$free_shipping++;
			}

			foreach ( $order->get_shipping_methods() as $method ) {
				$title = (string) $method->get_method_title();
				$title = '' !== $title ? $title : __( 'Unknown method', 'faracart' );

				if ( ! isset( $by_method[ $title ] ) ) {
					$by_method[ $title ] = array( 'orders' => 0, 'total' => 0.0 );
				}

				$by_method[ $title ]['orders']++;
				$by_method[ $title ]['total'] += (float) $method->get_total();
			}
		}

		foreach ( $by_method as $title => $data ) {
			$by_method[ $title ]['average'] = $data['orders'] > 0 ? round( $data['total'] / $data['orders'], 4 ) : 0.0;
			unset( $by_method[ $title ]['total'] );
		}

		$count = count( $orders['orders'] );

		return array(
			'available'            => true,
			'average_shipping'     => $count > 0 ? round( $shipping_total / $count, 4 ) : 0.0,
			'orders_with_shipping' => $with_shipping,
			'free_shipping_orders' => $free_shipping,
			'orders'               => $count,
			'by_method'            => $by_method,
		);
	}

	/**
	 * Estimated reward cost for the attributed (completed-goal) rows.
	 *
	 * Only completed goals grant rewards, so only rows with
	 * goal_completed = 1 contribute. Bounded and per-goal cached.
	 *
	 * @param array<string, mixed> $args Optional: goal_id, from, to.
	 * @return array{estimated_cost: float, available: bool}
	 */
	protected function reward_cost_for_rows( array $args ) {
		global $wpdb;

		$attrib = Schema::table( 'goal_attribution' );
		$limit  = (int) apply_filters( 'faracart_attribution_metric_rows', self::METRIC_MAX_ROWS );

		list( $where, $params ) = $this->attribution_where( $args );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT goal_id, order_id, order_total
				 FROM {$attrib}
				 WHERE goal_completed = 1 AND model = %s AND {$where}
				 GROUP BY goal_id, order_id
				 ORDER BY order_id ASC
				 LIMIT %d",
				array_merge( array( self::MODEL_DIRECT ), $params, array( max( 1, $limit ) ) )
			),
			ARRAY_A
		);

		// Group by order so the free-shipping model reads each order's
		// shipping total once.
		$order_shipping = array();
		$total          = 0.0;
		$estimated      = 0;
		$available      = 0;

		foreach ( $rows as $row ) {
			$goal = $this->goal( (int) $row['goal_id'] );

			if ( null === $goal ) {
				continue;
			}

			$order_id = (int) $row['order_id'];

			if ( ! array_key_exists( $order_id, $order_shipping ) ) {
				$order_shipping[ $order_id ] = $this->costs->order_shipping_total( $order_id );
			}

			$cost = $this->costs->estimate_reward_cost(
				$goal,
				(float) $row['order_total'],
				array(
					'order_id'       => $order_id,
					'shipping_total' => $order_shipping[ $order_id ],
				)
			);

			$total += $cost['estimated_cost'];

			if ( $cost['available'] ) {
				$available++;
			}

			$estimated++;
		}

		return array(
			'estimated_cost' => round( $total, 4 ),
			'available'      => $estimated > 0 ? $available === $estimated : true,
		);
	}

	/**
	 * Estimated profit impact over the direct-attribution orders.
	 *
	 * Margin data comes from the store's product costs (RewardCostEstimator)
	 * per direct order; when no direct order has margin data the profit is
	 * unavailable (revenue-only analytics) — never invented.
	 *
	 * Also returns the cost-data coverage of the same direct orders
	 * (Improvement.md §11): how many of the orders that carry incremental
	 * revenue have usable cost data. The strict profit model is unchanged —
	 * the order margin still requires cost data on every line item — the
	 * counts just let the UI explain "estimated profit is calculated only
	 * for orders with complete cost data".
	 *
	 * @param array<string, mixed> $args       Optional: goal_id, goal_ids,
	 *                                         from, to.
	 * @param float                $reward_cost Pre-computed reward cost (avoids
	 *                                          re-running the completed-rows
	 *                                          query — attribution_summary
	 *                                          already computed it). Part of
	 *                                          the profit model (§9):
	 *                                          estimated_profit = incremental ×
	 *                                          margin% − reward_cost −
	 *                                          shipping_cost.
	 * @return array{profit: array{estimated_profit: float|null, available: bool, reason: string|null, reason_code: string, incremental_revenue: float, reward_cost: float, shipping_cost: float|null, margin_pct: float|null}, orders_total: int, orders_with_cost: int}
	 */
	protected function profit_impact_for_rows( array $args, $reward_cost = 0.0 ) {
		global $wpdb;

		$attrib = Schema::table( 'goal_attribution' );
		$limit  = (int) apply_filters( 'faracart_attribution_metric_rows', self::METRIC_MAX_ROWS );

		list( $where, $params ) = $this->attribution_where( $args );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, order_total, SUM(incremental_value) AS incremental
				 FROM {$attrib}
				 WHERE model = %s AND {$where}
				 GROUP BY order_id, order_total
				 ORDER BY order_id ASC
				 LIMIT %d",
				array_merge( array( self::MODEL_DIRECT ), $params, array( max( 1, $limit ) ) )
			),
			ARRAY_A
		);

		$incremental_revenue = 0.0;
		$shipping_cost       = 0.0;
		$margin_pcts         = array();
		$with_margin         = 0;

		foreach ( $rows as $row ) {
			$incremental_revenue += (float) $row['incremental'];

			$order = $this->wc_order( (int) $row['order_id'] );
			$shipping_cost += null !== $order ? (float) $order->get_shipping_total() : 0.0;

			$margin = null !== $order ? $this->costs->order_margin_stats( $order ) : null;

			if ( null !== $margin ) {
				$margin_pcts[] = (float) $margin['margin_pct'];
				$with_margin++;
			}
		}

		$margin_pct = $with_margin > 0 ? array_sum( $margin_pcts ) / $with_margin : null;

		// The documented profit model (Improvement.md §9) subtracts both the
		// reward cost AND the order shipping cost. For free_shipping rewards
		// the reward cost equals the shipping total, so it is counted in
		// both lines by design — do not "fix" this into a single deduction.
		return array(
			'profit' => $this->costs->profit_impact(
				array(
					'incremental_revenue' => $incremental_revenue,
					'margin_pct'          => $margin_pct,
					'reward_cost'         => max( 0.0, (float) $reward_cost ),
					'shipping_cost'       => $with_margin > 0 ? $shipping_cost : null,
				)
			),
			'orders_total'     => count( $rows ),
			'orders_with_cost' => $with_margin,
		);
	}

	/**
	 * Store-wide order totals within the window, as plain floats.
	 *
	 * Phase 33.4 (Smart Goal Recommendation) entry point: feeds the AOV /
	 * median / order-distribution analyzers with the same bounded,
	 * memoized store scan the AOV and shipping metrics already use — one
	 * paginated pass per window, never a full-table load. Zero/negative
	 * totals are excluded (non-revenue orders add noise to AOV/median).
	 *
	 * @param array<string, mixed> $args Optional: from, to.
	 * @return array{available: bool, totals: float[], count: int}
	 */
	public function store_order_values( array $args = array() ) {
		$orders = $this->store_orders( $args );

		if ( ! $orders['available'] ) {
			return array( 'available' => false, 'totals' => array(), 'count' => 0 );
		}

		$totals = array();

		foreach ( $orders['orders'] as $order ) {
			$total = (float) $order->get_total();

			if ( $total > 0 ) {
				$totals[] = $total;
			}
		}

		return array(
			'available' => true,
			'totals'    => $totals,
			'count'     => count( $totals ),
		);
	}

	/**
	 * Store-wide order totals within the window (bounded, paginated).
	 *
	 * @param array<string, mixed> $args Optional: from, to.
	 * @return array{available: bool, total: float, orders: int}
	 */
	protected function store_order_totals( array $args ) {
		$orders = $this->store_orders( $args );

		if ( ! $orders['available'] ) {
			return array( 'available' => false, 'total' => 0.0, 'orders' => 0 );
		}

		$total = 0.0;

		foreach ( $orders['orders'] as $order ) {
			$total += (float) $order->get_total();
		}

		return array(
			'available' => true,
			'total'     => $total,
			'orders'    => count( $orders['orders'] ),
		);
	}

	/**
	 * Fetch the store's revenue orders within the window, paginated.
	 *
	 * Uses WooCommerce's HPOS-aware order query with a page cap so a large
	 * store never loads all orders into memory. Returns only
	 * revenue-producing statuses (matching the attribution policy).
	 *
	 * @param array<string, mixed> $args Optional: from, to.
	 * @return array{available: bool, orders: \WC_Order[]}
	 */
	protected function store_orders( array $args ) {
		if ( ! class_exists( '\\WC_Order_Query' ) ) {
			return array( 'available' => false, 'orders' => array() );
		}

		$from = ! empty( $args['from'] ) ? date( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) ) : '';
		$to   = ! empty( $args['to'] ) ? date( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) ) : '';

		// AOV and shipping both scan the store; memoize per window so one
		// dashboard render never runs the paginated scan twice.
		$cache_key = (string) $from . '|' . (string) $to;

		if ( array_key_exists( $cache_key, $this->order_scan_cache ) ) {
			return $this->order_scan_cache[ $cache_key ];
		}

		$pages  = (int) apply_filters( 'faracart_attribution_order_scan_pages', self::ORDER_SCAN_PAGES );
		$orders = array();

		for ( $page = 1; $page <= $pages; $page++ ) {
			$query_args = array(
				'status'  => self::REVENUE_STATUSES,
				'limit'   => 100,
				'page'    => $page,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'objects',
			);

			// wc_get_orders date_created accepts a single comparison string
			// ('>=' / '<=') or the documented 'from...to' range form.
			if ( $from && $to ) {
				$query_args['date_created'] = $from . '...' . $to;
			} elseif ( $from ) {
				$query_args['date_created'] = '>=' . $from;
			} elseif ( $to ) {
				$query_args['date_created'] = '<=' . $to;
			}

			$batch = wc_get_orders( $query_args );

			if ( empty( $batch ) ) {
				break;
			}

			$orders = array_merge( $orders, $batch );

			if ( count( $batch ) < 100 ) {
				break;
			}
		}

		$result = array( 'available' => true, 'orders' => $orders );
		$this->order_scan_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Memoized wc_get_order().
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	protected function wc_order( $order_id ) {
		if ( ! isset( $this->order_cache[ $order_id ] ) ) {
			$this->order_cache[ $order_id ] = function_exists( 'wc_get_order' )
				? wc_get_order( (int) $order_id )
				: null;
		}

		return $this->order_cache[ $order_id ] ? $this->order_cache[ $order_id ] : null;
	}

	/**
	 * Memoized goal lookup.
	 *
	 * @param int $goal_id Goal id.
	 * @return Goal|null
	 */
	protected function goal( $goal_id ) {
		$goal_id = (int) $goal_id;

		if ( array_key_exists( $goal_id, $this->goal_cache ) ) {
			return $this->goal_cache[ $goal_id ];
		}

		$goal = $this->repository->find( $goal_id );
		$this->goal_cache[ $goal_id ] = $goal ? $goal : null;

		return $this->goal_cache[ $goal_id ];
	}

	/**
	 * Whether the store carries any product cost data.
	 *
	 * Phase 3 availability metadata — delegates to the shared
	 * RewardCostEstimator so every profit surface (summary, goal metrics,
	 * empty windows) reports the same store-wide signal.
	 *
	 * @return bool
	 */
	public function store_has_cost_data() {
		return $this->costs->store_has_cost_data();
	}

	/**
	 * WHERE clause for revenue_events reads (goal_id + date range).
	 *
	 * @param array<string, mixed> $args Optional: goal_id, goal_ids, from, to.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	protected function revenue_where( array $args ) {
		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['goal_id'] ) ) {
			$where .= ' AND goal_id = %d';
			$params[] = (int) $args['goal_id'];
		}

		list( $where, $params ) = $this->append_goal_ids( $args, $where, $params );

		if ( ! empty( $args['from'] ) ) {
			$where .= ' AND created_at >= %s';
			$params[] = date( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$where .= ' AND created_at <= %s';
			$params[] = date( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		return array( $where, $params );
	}

	/**
	 * WHERE clause for goal_attribution reads (goal_id + date range).
	 *
	 * @param array<string, mixed> $args Optional: goal_id, goal_ids, from, to.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	protected function attribution_where( array $args ) {
		$where  = '1=1';
		$params = array();

		if ( ! empty( $args['goal_id'] ) ) {
			$where .= ' AND goal_id = %d';
			$params[] = (int) $args['goal_id'];
		}

		list( $where, $params ) = $this->append_goal_ids( $args, $where, $params );

		if ( ! empty( $args['from'] ) ) {
			$where .= ' AND created_at >= %s';
			$params[] = date( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$where .= ' AND created_at <= %s';
			$params[] = date( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		return array( $where, $params );
	}

	/**
	 * Append an explicit goal_ids IN-clause to a WHERE builder.
	 *
	 * Lets callers filter attribution reads by a set of goals (e.g. the
	 * campaign/reward-filtered purchase metrics on the legacy Analytics
	 * endpoint) without changing the single-goal semantics. Bound through
	 * $wpdb->prepare like every other value.
	 *
	 * @param array<string, mixed> $args   Args (may carry goal_ids).
	 * @param string               $where  Current WHERE string.
	 * @param array<int, mixed>    $params Current bound values.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	protected function append_goal_ids( array $args, $where, array $params ) {
		if ( empty( $args['goal_ids'] ) || ! is_array( $args['goal_ids'] ) ) {
			return array( $where, $params );
		}

		$ids = array_values( array_filter( array_map( 'absint', $args['goal_ids'] ), function ( $id ) {
			return $id > 0;
		} ) );

		if ( empty( $ids ) ) {
			return array( $where, $params );
		}

		$where .= ' AND goal_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		$params = array_merge( $params, $ids );

		return array( $where, $params );
	}
}
