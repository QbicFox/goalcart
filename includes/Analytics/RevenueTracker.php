<?php
/**
 * Revenue event tracker for FaraCart (Phase 33.1 — Analytics Foundation).
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;
use FaraCart\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class RevenueTracker
 *
 * Phase 33.1 (Analytics Foundation) — the event model + recorder behind the
 * Phase 33 revenue-optimization engine. It owns two raw event logs:
 *
 *  - `revenue_events`  — the attribution funnel (mission_view → mission_progress
 *    → mission_completed → order_paid), each row carrying the cart value, the
 *    mission target and the incremental value. This is the deterministic
 *    input for direct/assisted attribution, incremental cart value and
 *    AOV analysis (Phase 33.2).
 *  - `upsell_events`   — the upsell interaction funnel (impression →
 *    clicked → added → order) per product per session. This is the input
 *    for the historical conversion signal the Smart Upsell Engine reads
 *    (Phase 33.5) and the per-product aggregates (upsell_stats).
 *
 * The Phase 16 Tracker (analytics_events) stays untouched: those rows are
 * the lightweight dashboard counters reported by the storefront JS,
 * while RevenueTracker rows are server-side attribution data gated by the
 * revenue feature flag.
 *
 * Deduplication (P33.1): recording is idempotent by design — every event
 * type has a dedup rule so repeated reports (page refreshes, poll loops,
 * cart sync passes) never double-count:
 *
 *  - mission_view / mission_completed / upsell_impression / upsell_clicked are
 *    deduped per session+mission (+product) within a sliding window (24h);
 *  - mission_progress is deduped within a short window (30 min) — significant
 *    cart moves still record, refresh noise does not;
 *  - order_paid / upsell_order are deduped per order (an order is
 *    attributed exactly once).
 *
 * Privacy (P33.1): rows carry only anonymous session ids (the Session
 * cookie), numeric aggregates (cart_value, mission_target, incremental_value)
 * and plugin/WooCommerce ids — never IPs, emails, addresses or payment
 * data. user_id is stored only when the shopper is logged in (an id, not
 * personal data), mirroring the Phase 16 Tracker.
 *
 * Cleanup (P33.1): a weekly cron event (CLEANUP_EVENT, scheduled by the
 * Installer through cron_events()) purges rows older than the retention
 * window (RETENTION_DAYS, filterable) from both logs, so expired
 * anonymous session data never accumulates indefinitely.
 */
final class RevenueTracker {

	/**
	 * Event types (revenue_events).
	 */
	const EVENT_MISSION_VIEW       = 'mission_view';
	const EVENT_MISSION_PROGRESS   = 'mission_progress';
	const EVENT_MISSION_COMPLETED  = 'mission_completed';
	const EVENT_ORDER_PAID      = 'order_paid';
	const EVENT_CART_VALUE      = 'cart_value';

	/**
	 * Event type (revenue_events) — the admin recommendation feedback
	 * loop (UPSELL_REFACTOR §41): recorded when an administrator applies a
	 * mission-threshold recommendation (POST /revenue/mission-recommendations/
	 * apply). Meta carries the applied threshold and the previous target,
	 * so future analysis can correlate "recommendation applied → mission
	 * changed → mission performance" without any ML machinery.
	 */
	const EVENT_RECOMMENDATION_APPLIED = 'recommendation_applied';

	/**
	 * Event types (upsell_events).
	 */
	const EVENT_UPSELL_IMPRESSION = 'upsell_impression';
	const EVENT_UPSELL_CLICKED    = 'upsell_clicked';
	const EVENT_UPSELL_ADDED      = 'upsell_added';
	const EVENT_UPSELL_ORDER      = 'upsell_order';

	/**
	 * Cron event that runs the weekly retention cleanup.
	 *
	 * @var string
	 */
	const CLEANUP_EVENT = 'faracart_revenue_cleanup';

	/**
	 * Default retention window: event rows older than this are deleted by
	 * the cleanup cron. Filterable with faracart_revenue_retention_days.
	 *
	 * @var int
	 */
	const RETENTION_DAYS = 90;

	/**
	 * Max 500-row batches the cleanup drains per table per run.
	 *
	 * 20 batches × 500 rows = 10,000 rows per table per cron tick — enough
	 * to actually shrink a large backlog without ever running one huge
	 * unbounded DELETE.
	 *
	 * @var int
	 */
	const CLEANUP_MAX_BATCHES = 20;

	/**
	 * Dedup window for view/completion/impression events (seconds).
	 *
	 * @var int
	 */
	const DEDUP_WINDOW_DAILY = DAY_IN_SECONDS;

	/**
	 * Dedup window for mission_progress events (seconds).
	 *
	 * @var int
	 */
	const DEDUP_WINDOW_PROGRESS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Session manager (privacy-safe anonymous sessions).
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 * @param Session  $session  Session manager.
	 */
	public function __construct( Settings $settings, Session $session ) {
		$this->settings = $settings;
		$this->session  = $session;
	}

	/**
	 * Register hooks: the weekly cleanup cron callback.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( self::CLEANUP_EVENT, array( $this, 'run_cleanup' ) );
	}

	/**
	 * Whether revenue event recording is enabled for the current request.
	 *
	 * Gated by the master plugin toggle + the analytics setting (the same
	 * base gates as Tracker::tracking_enabled()) plus the dedicated
	 * faracart_revenue_tracking_enabled filter, so stores can run the
	 * lightweight Phase 16 dashboard while switching the revenue
	 * attribution pipeline off.
	 *
	 * @return bool
	 */
	public function tracking_enabled() {
		if ( ! $this->settings->get( 'enabled', true ) || ! $this->settings->get( 'analytics_enabled', true ) ) {
			return false;
		}

		/**
		 * Filter whether revenue event tracking is enabled.
		 *
		 * @param bool           $enabled Whether revenue tracking is on.
		 * @param RevenueTracker $tracker Tracker instance.
		 */
		return (bool) apply_filters( 'faracart_revenue_tracking_enabled', true, $this );
	}

	/**
	 * The whitelist of revenue event types.
	 *
	 * @return string[]
	 */
	public static function revenue_event_types() {
		return array(
			self::EVENT_MISSION_VIEW,
			self::EVENT_MISSION_PROGRESS,
			self::EVENT_MISSION_COMPLETED,
			self::EVENT_ORDER_PAID,
			self::EVENT_CART_VALUE,
			self::EVENT_RECOMMENDATION_APPLIED,
		);
	}

	/**
	 * The whitelist of upsell event types.
	 *
	 * @return string[]
	 */
	public static function upsell_event_types() {
		return array(
			self::EVENT_UPSELL_IMPRESSION,
			self::EVENT_UPSELL_CLICKED,
			self::EVENT_UPSELL_ADDED,
			self::EVENT_UPSELL_ORDER,
		);
	}

	/**
	 * Whether an event type is a revenue_events type.
	 *
	 * @param mixed $event_type Candidate type.
	 * @return bool
	 */
	public static function is_revenue_event( $event_type ) {
		return in_array( $event_type, self::revenue_event_types(), true );
	}

	/**
	 * Whether an event type is an upsell_events type.
	 *
	 * @param mixed $event_type Candidate type.
	 * @return bool
	 */
	public static function is_upsell_event( $event_type ) {
		return in_array( $event_type, self::upsell_event_types(), true );
	}

	/**
	 * Record a revenue event into revenue_events.
	 *
	 * Idempotent: the dedup window for the event type is checked before the
	 * insert, so repeated calls for the same session+mission(+order) within
	 * the window record nothing. Returns the row id (0 = deduped / gated
	 * off / failed).
	 *
	 * @param string               $event_type One of the EVENT_* constants.
	 * @param array<string, mixed> $context    mission_id, campaign_id, product_id,
	 *                                         order_id, cart_value, mission_target,
	 *                                         incremental_value, session_id, meta.
	 * @return int
	 */
	public function record( $event_type, array $context = array() ) {
		if ( ! self::is_revenue_event( $event_type ) || ! $this->tracking_enabled() ) {
			return 0;
		}

		$session_id = isset( $context['session_id'] ) && Session::is_valid( $context['session_id'] )
			? $context['session_id']
			: $this->session->id();

		$mission_id     = isset( $context['mission_id'] ) ? absint( $context['mission_id'] ) : 0;
		$campaign_id = isset( $context['campaign_id'] ) ? absint( $context['campaign_id'] ) : 0;
		$product_id  = isset( $context['product_id'] ) ? absint( $context['product_id'] ) : 0;
		$order_id    = isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0;

		if ( $this->is_duplicate( $event_type, $session_id, $mission_id, $order_id ) ) {
			return 0;
		}

		global $wpdb;

		$table = Schema::table( 'revenue_events' );

		$cart_value   = isset( $context['cart_value'] ) ? round( (float) $context['cart_value'], 4 ) : null;
		$mission_target  = isset( $context['mission_target'] ) ? round( (float) $context['mission_target'], 4 ) : null;
		$incremental  = isset( $context['incremental_value'] ) ? round( (float) $context['incremental_value'], 4 ) : null;
		$meta         = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $this->sanitize_meta( $context['meta'] ) : array();
		$user_id      = get_current_user_id();

		$data = array(
			'event_type'        => $event_type,
			'mission_id'           => $mission_id > 0 ? $mission_id : null,
			'campaign_id'       => $campaign_id > 0 ? $campaign_id : null,
			'product_id'        => $product_id > 0 ? $product_id : null,
			'order_id'          => $order_id > 0 ? $order_id : null,
			'session_id'        => $session_id,
			'user_id'           => $user_id > 0 ? $user_id : null,
			'cart_value'        => $cart_value,
			'mission_target'       => $mission_target,
			'incremental_value' => $incremental,
			'meta'              => empty( $meta ) ? null : wp_json_encode( $meta ),
			'created_at'        => current_time( 'mysql' ),
		);

		$formats = array( '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%f', '%f', '%f', '%s', '%s' );

		$inserted = $wpdb->insert( $table, $data, $formats );

		// FK resilience (mirrors Tracker): revenue_events references
		// missions/campaigns with ON DELETE SET NULL, but a mission deleted
		// between the impression and this event report would make the
		// INSERT fail and silently drop the event. Retry once without the
		// FK ids so the event survives (the same outcome SET NULL implies
		// for deletion). Only an actual foreign-key failure triggers the
		// retry.
		if (
			! $inserted
			&& ( $mission_id > 0 || $campaign_id > 0 )
			&& false !== stripos( (string) $wpdb->last_error, 'foreign key' )
		) {
			$data['mission_id']     = null;
			$data['campaign_id'] = null;
			$inserted = $wpdb->insert( $table, $data, $formats );
		}

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Record an upsell interaction event into upsell_events.
	 *
	 * Same idempotency contract as record(): impression/clicked are deduped
	 * per session+mission+product within 24h, order events once per order.
	 *
	 * @param string               $event_type One of the EVENT_UPSELL_* constants.
	 * @param array<string, mixed> $context    mission_id, product_id, order_id,
	 *                                         cart_value, session_id, meta.
	 * @return int
	 */
	public function record_upsell( $event_type, array $context = array() ) {
		if ( ! self::is_upsell_event( $event_type ) || ! $this->tracking_enabled() ) {
			return 0;
		}

		$session_id = isset( $context['session_id'] ) && Session::is_valid( $context['session_id'] )
			? $context['session_id']
			: $this->session->id();

		$mission_id    = isset( $context['mission_id'] ) ? absint( $context['mission_id'] ) : 0;
		$product_id = isset( $context['product_id'] ) ? absint( $context['product_id'] ) : 0;
		$order_id   = isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0;

		if ( $this->is_upsell_duplicate( $event_type, $session_id, $mission_id, $product_id, $order_id ) ) {
			return 0;
		}

		global $wpdb;

		$table = Schema::table( 'upsell_events' );

		$cart_value = isset( $context['cart_value'] ) ? round( (float) $context['cart_value'], 4 ) : null;
		$meta       = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $this->sanitize_meta( $context['meta'] ) : array();
		$user_id    = get_current_user_id();

		$data = array(
			'event_type'  => $event_type,
			'mission_id'     => $mission_id > 0 ? $mission_id : null,
			'product_id'  => $product_id > 0 ? $product_id : null,
			'order_id'    => $order_id > 0 ? $order_id : null,
			'session_id'  => $session_id,
			'user_id'     => $user_id > 0 ? $user_id : null,
			'cart_value'  => $cart_value,
			'meta'        => empty( $meta ) ? null : wp_json_encode( $meta ),
			'created_at'  => current_time( 'mysql' ),
		);

		$formats = array( '%s', '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%s' );

		$inserted = $wpdb->insert( $table, $data, $formats );

		// FK resilience (mirrors record()): upsell_events references missions
		// with ON DELETE SET NULL, so a mission deleted between the
		// impression and a click report would otherwise drop the event.
		// Retry once without the FK ids on a genuine foreign-key failure.
		if (
			! $inserted
			&& $mission_id > 0
			&& false !== stripos( (string) $wpdb->last_error, 'foreign key' )
		) {
			$data['mission_id'] = null;
			$inserted = $wpdb->insert( $table, $data, $formats );
		}

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Get the current anonymous session id (creates the cookie if needed).
	 *
	 * @return string
	 */
	public function get_session_id() {
		return $this->session->id();
	}

	/**
	 * Whether a duplicate revenue event exists within its dedup window.
	 *
	 * mission_view / mission_completed dedup per session+mission in 24h; progress
	 * in 30 min; order events once per order. The query is a single
	 * indexed lookup (session_id + mission_id + event_type, created_at).
	 *
	 * @param string $event_type Event type.
	 * @param string $session_id Session id.
	 * @param int    $mission_id    Mission id.
	 * @param int    $order_id   Order id.
	 * @return bool
	 */
	protected function is_duplicate( $event_type, $session_id, $mission_id, $order_id ) {
		global $wpdb;

		$table = Schema::table( 'revenue_events' );

		if ( self::EVENT_ORDER_PAID === $event_type ) {
			if ( $order_id < 1 ) {
				return false;
			}

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND order_id = %d",
					$event_type,
					$order_id
				)
			);

			return $count > 0;
		}

		// Cart-value snapshots carry no mission: dedup by session only, on the
		// short progress window (a cart can legitimately move several times
		// a day; the 30-minute window keeps refresh noise quiet).
		if ( self::EVENT_CART_VALUE === $event_type ) {
			$cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::DEDUP_WINDOW_PROGRESS );

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE event_type = %s AND session_id = %s AND created_at >= %s",
					$event_type,
					$session_id,
					$cutoff
				)
			);

			return $count > 0;
		}

		// Recommendation applies carry a mission but no order: dedup per
		// session+mission within the daily window (an admin re-applying the
		// same recommendation from a fresh page load records once a day —
		// enough signal for the feedback loop without event spam).
		if ( self::EVENT_RECOMMENDATION_APPLIED === $event_type ) {
			$cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::DEDUP_WINDOW_DAILY );

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					WHERE event_type = %s AND session_id = %s AND mission_id = %s AND created_at >= %s",
					$event_type,
					$session_id,
					$mission_id > 0 ? $mission_id : null,
					$cutoff
				)
			);

			return $count > 0;
		}

		$window = self::EVENT_MISSION_PROGRESS === $event_type
			? self::DEDUP_WINDOW_PROGRESS
			: self::DEDUP_WINDOW_DAILY;

		$cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $window );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE event_type = %s AND session_id = %s AND mission_id = %s AND created_at >= %s",
				$event_type,
				$session_id,
				$mission_id > 0 ? $mission_id : null,
				$cutoff
			)
		);

		return $count > 0;
	}

	/**
	 * Whether a duplicate upsell event exists within its dedup window.
	 *
	 * impression/clicked dedup per session+mission+product in 24h; order
	 * events once per order.
	 *
	 * @param string $event_type Event type.
	 * @param string $session_id Session id.
	 * @param int    $mission_id    Mission id.
	 * @param int    $product_id Product id.
	 * @param int    $order_id   Order id.
	 * @return bool
	 */
	protected function is_upsell_duplicate( $event_type, $session_id, $mission_id, $product_id, $order_id ) {
		global $wpdb;

		$table = Schema::table( 'upsell_events' );

		if ( self::EVENT_UPSELL_ORDER === $event_type ) {
			if ( $order_id < 1 ) {
				return false;
			}

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND order_id = %d",
					$event_type,
					$order_id
				)
			);

			return $count > 0;
		}

		$cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - self::DEDUP_WINDOW_DAILY );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE event_type = %s AND session_id = %s AND mission_id = %s AND product_id = %s AND created_at >= %s",
				$event_type,
				$session_id,
				$mission_id > 0 ? $mission_id : null,
				$product_id > 0 ? $product_id : null,
				$cutoff
			)
		);

		return $count > 0;
	}

	/**
	 * Sanitize event meta to scalar values only (mirrors Tracker).
	 *
	 * @param array<string, mixed> $meta Raw meta.
	 * @return array<string, scalar>
	 */
	protected function sanitize_meta( array $meta ) {
		$clean = array();

		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ sanitize_key( $key ) ] = is_bool( $value ) ? (int) $value : $value;
		}

		return $clean;
	}

	/**
	 * Run the retention cleanup (weekly cron callback).
	 *
	 * Deletes revenue_events and upsell_events rows older than the
	 * retention window, bounding the query with LIMIT so a very large log
	 * is drained over several runs instead of locking the table. Also
	 * clears the aggregated rows that reference purged sessions' products
	 * (upsell_stats are rebuilt by the Phase 33.3 aggregator; stale rows
	 * for deleted products are dropped here as a best-effort sweep).
	 *
	 * @return int Number of deleted rows.
	 */
	public function run_cleanup() {
		$retention = (int) apply_filters( 'faracart_revenue_retention_days', self::RETENTION_DAYS );
		$retention = max( 1, min( 730, $retention ) );

		$cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $retention * DAY_IN_SECONDS );

		global $wpdb;

		$deleted = 0;

		foreach ( array( 'revenue_events', 'upsell_events' ) as $table_name ) {
			$table = Schema::table( $table_name );

			// Drain the backlog in bounded batches so a single cron tick
			// never runs one unbounded DELETE against a huge log — but loop
			// until a batch comes back short (or the per-run cap is hit) so
			// a large backlog actually shrinks instead of leaking 500 rows
			// per weekly run forever.
			for ( $batch = 0; $batch < self::CLEANUP_MAX_BATCHES; $batch++ ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a plugin constant.
				$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 500", $cutoff ) );

				if ( false === $removed ) {
					break;
				}

				$deleted += (int) $removed;

				// A short batch means the backlog is drained.
				if ( (int) $removed < 500 ) {
					break;
				}
			}
		}

		// Best-effort sweep of upsell_stats for products that no longer
		// exist (their upsell_events rows were just purged).
		$this->cleanup_orphan_upsell_stats();

		return $deleted;
	}

	/**
	 * Drop upsell_stats rows whose product no longer exists.
	 *
	 * @return int Number of deleted rows.
	 */
	protected function cleanup_orphan_upsell_stats() {
		global $wpdb;

		$stats = Schema::table( 'upsell_stats' );
		$posts = $wpdb->posts;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are plugin constants.
		$removed = $wpdb->query( "DELETE s FROM {$stats} s LEFT JOIN {$posts} p ON p.ID = s.product_id WHERE p.ID IS NULL" );

		return (int) $removed;
	}
}
