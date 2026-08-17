<?php
/**
 * Per-user mission completion limit service (Phase 36).
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

use FaraCart\Analytics\Session;
use FaraCart\Database\Schema;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class CompletionService
 *
 * The single authoritative implementation of the per-user mission completion
 * limit ("how many times may each shopper complete this mission?").
 *
 * Every mission may configure `max_completions_per_user` (NULL = unlimited).
 * The rule lives HERE — never duplicated in the frontend, AJAX handlers,
 * REST endpoints, reward handlers or checkout hooks:
 *
 *  - `can_complete()`      — the business rule: mission active? unlimited?
 *                            count < limit?
 *  - `count_for()` / `counts_for()` — efficient COUNT queries over the
 *                            `mission_completions` history (indexed by
 *                            mission + identity), never a full-table load.
 *  - `record_completion()` — the write path: count + insert inside a
 *                            transaction with a row lock on the mission, so
 *                            concurrent requests cannot exceed the limit
 *                            (the `order_mission` unique key additionally
 *                            makes the same order exactly-once).
 *
 * Completion history (Task 2.2): the analytics_events log is
 * client-reported (documented as unreliable) and the revenue_events log
 * is analytics-gated + window-deduped, so neither can back an
 * enforcement limit. `mission_completions` is a dedicated, server-written
 * log: one row per (mission, order, identity) recorded when an order
 * becomes revenue-producing.
 *
 * Identity (Phase 18/19): logged-in shoppers count by `user_id` (their
 * WordPress/WooCommerce id); guests count by the existing anonymous
 * `Session` id, which is stamped onto the order at checkout
 * (`_faracart_session`) so a guest order is always attributable to the
 * browsing session that placed it — no new tracking system. Counts are
 * never merged across the two identities (a guest who logs in starts a
 * separate logged-in count — Phase 20 limitation, deliberately not
 * guessed via heuristics).
 *
 * Completion cycles (Phase 12/13): progress is per-cart and reset by the
 * normal mission lifecycle; a completion is ONE successful cycle (an order
 * that met the mission). Progress and the completion count stay separate —
 * recording here never touches progress, and resetting progress never
 * resets this history.
 */
final class CompletionService {

	/**
	 * Order meta key holding the anonymous session id stamped at checkout.
	 *
	 * @var string
	 */
	const ORDER_SESSION_META = '_faracart_session';

	/**
	 * Plugin settings (master toggle).
	 *
	 * @var Settings|null
	 */
	protected $settings;

	/**
	 * Mission repository (active missions for order-time recording).
	 *
	 * @var MissionRepository|null
	 */
	protected $repository;

	/**
	 * Mission engine (order-time evaluation).
	 *
	 * @var MissionEngine|null
	 */
	protected $engine;

	/**
	 * Session manager (anonymous guest identity).
	 *
	 * @var Session|null
	 */
	protected $session;

	/**
	 * Whether the mission_completions table exists (checked once per request).
	 *
	 * @var bool|null
	 */
	protected $table_ready;

	/**
	 * Per-request completion-count cache: mission_id => count for the
	 * current identity (so repeated checks in one request run one query).
	 *
	 * @var array<int, int>
	 */
	protected $count_cache = array();

	/**
	 * Per-request cache of the identity the counts were cached for.
	 *
	 * @var string
	 */
	protected $count_cache_identity = '';

	/**
	 * Constructor.
	 *
	 * @param Settings|null        $settings   Plugin settings.
	 * @param MissionRepository|null  $repository Mission repository.
	 * @param MissionEngine|null      $engine     Mission engine.
	 * @param Session|null         $session    Session manager.
	 */
	public function __construct( ?Settings $settings = null, ?MissionRepository $repository = null, ?MissionEngine $engine = null, ?Session $session = null ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->engine     = $engine;
		$this->session    = $session;
	}

	/**
	 * Register the order-lifecycle hooks.
	 *
	 *  - `woocommerce_checkout_create_order`   stamps the anonymous session
	 *    id on the order so a guest order is attributable to the session
	 *    that placed it (reliable guest counting, Phase 18).
	 *  - `woocommerce_payment_complete`        records completions when a
	 *    gateway marks the order paid (primary).
	 *  - `woocommerce_order_status_completed`  backstop for manual/offline
	 *    transitions straight to completed — both paths are idempotent
	 *    (the `order_mission` unique key makes re-processing a no-op).
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order_session' ), 10, 2 );
		$hooks->add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_completed' ), 20, 1 );
		$hooks->add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 20, 1 );
	}

	/**
	 * Whether the completion-limit pipeline is active (master toggle).
	 *
	 * Gated on the same master `enabled` setting every other mission feature
	 * respects — a disabled plugin records no completions and grants no
	 * mission rewards, so the limit stays consistent with the storefront.
	 *
	 * @return bool
	 */
	public function enabled() {
		if ( null !== $this->settings ) {
			return (bool) $this->settings->get( 'enabled', true );
		}

		try {
			$this->settings = \FaraCart\Plugin::instance()->container()->get( Settings::class );
		} catch ( \Throwable $e ) {
			return true;
		}

		return (bool) $this->settings->get( 'enabled', true );
	}

	/**
	 * Resolve the current anonymous session id (guests only).
	 *
	 * @return string Session id ('' when unavailable).
	 */
	public function session_id() {
		if ( null === $this->session ) {
			try {
				$this->session = \FaraCart\Plugin::instance()->container()->get( Session::class );
			} catch ( \Throwable $e ) {
				return '';
			}
		}

		return $this->session->id();
	}

	/**
	 * The countable identity for a cart context.
	 *
	 * Logged-in shoppers (user_id > 0) count by user_id; guests count by
	 * the anonymous session id. Counts are never mixed across the two.
	 *
	 * @param CartContext $context Cart snapshot.
	 * @return array{user_id: int, session_id: string}
	 */
	public function context_identity( CartContext $context ) {
		$user_id = (int) $context->user_id();

		if ( $user_id > 0 ) {
			return array( 'user_id' => $user_id, 'session_id' => '' );
		}

		return array(
			'user_id'    => 0,
			'session_id' => $this->session_id(),
		);
	}

	/**
	 * How many times this identity has completed the mission.
	 *
	 * A single indexed COUNT — never a full-table load (Phase 22/24).
	 * Results are cached per request for the current identity.
	 *
	 * @param int    $mission_id    Mission id.
	 * @param int    $user_id    Logged-in user id (0 = guest).
	 * @param string $session_id Anonymous session id (guests).
	 * @param bool   $fresh      Bypass the per-request cache (used inside
	 *                           the transactional write path, where the
	 *                           lock must see the committed count).
	 * @return int
	 */
	public function count_for( $mission_id, $user_id, $session_id, $fresh = false ) {
		$mission_id    = (int) $mission_id;
		$user_id    = (int) $user_id;
		$session_id = Session::is_valid( $session_id ) ? $session_id : '';

		$identity_key = ( $user_id > 0 ? 'u' . $user_id : 's' . $session_id );

		if ( ! $fresh && $this->count_cache_identity === $identity_key && isset( $this->count_cache[ $mission_id ] ) ) {
			return $this->count_cache[ $mission_id ];
		}

		if ( '' === $identity_key || ! $this->table_ready() ) {
			return 0;
		}

		global $wpdb;

		$table = Schema::table( 'mission_completions' );

		if ( $user_id > 0 ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE mission_id = %d AND user_id = %d",
					$mission_id,
					$user_id
				)
			);
		} else {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE mission_id = %d AND session_id = %s",
					$mission_id,
					$session_id
				)
			);
		}

		if ( ! $fresh ) {
			$this->count_cache_identity = $identity_key;
			$this->count_cache[ $mission_id ] = $count;
		}

		return $count;
	}

	/**
	 * Batch completion counts for several missions and one identity.
	 *
	 * One grouped COUNT query instead of one per mission — the storefront
	 * payload path (Phase 22: only calculate when mission/user context
	 * requires it, and then cheaply).
	 *
	 * @param int[]  $mission_ids    Mission ids.
	 * @param int    $user_id     Logged-in user id (0 = guest).
	 * @param string $session_id  Anonymous session id (guests).
	 * @return array<int, int> mission_id => completion count.
	 */
	public function counts_for( array $mission_ids, $user_id, $session_id ) {
		$user_id    = (int) $user_id;
		$session_id = Session::is_valid( $session_id ) ? $session_id : '';
		$identity_key = ( $user_id > 0 ? 'u' . $user_id : 's' . $session_id );

		$ids = array_values( array_filter( array_map( 'intval', $mission_ids ), function ( $id ) {
			return $id > 0;
		} ) );

		if ( empty( $ids ) || '' === $identity_key || ! $this->table_ready() ) {
			return array();
		}

		// Everything served from the cache when the same identity already
		// primed it this request.
		if ( $this->count_cache_identity === $identity_key ) {
			$result = array();

			foreach ( $ids as $mission_id ) {
				if ( isset( $this->count_cache[ $mission_id ] ) ) {
					$result[ $mission_id ] = $this->count_cache[ $mission_id ];
				}
			}

			$uncached = array_values( array_diff( $ids, array_keys( $result ) ) );

			if ( empty( $uncached ) ) {
				return $result;
			}

			$ids = $uncached;
		}

		global $wpdb;

		$table = Schema::table( 'mission_completions' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( $user_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT mission_id, COUNT(*) AS cnt FROM {$table} WHERE mission_id IN ({$in}) AND user_id = %d GROUP BY mission_id",
					array_merge( $ids, array( $user_id ) )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT mission_id, COUNT(*) AS cnt FROM {$table} WHERE mission_id IN ({$in}) AND session_id = %s GROUP BY mission_id",
					array_merge( $ids, array( $session_id ) )
				),
				ARRAY_A
			);
		}

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['mission_id'] ] = (int) $row['cnt'];
		}

		// Prime the per-request cache so later single-mission reads reuse this.
		$this->count_cache_identity = $identity_key;

		foreach ( $ids as $mission_id ) {
			$this->count_cache[ $mission_id ] = isset( $counts[ $mission_id ] ) ? $counts[ $mission_id ] : 0;
		}

		foreach ( $counts as $mission_id => $count ) {
			$this->count_cache[ $mission_id ] = $count;
		}

		return $counts;
	}

	/**
	 * The authoritative completion status for a mission + identity.
	 *
	 * @param Mission   $mission       Mission.
	 * @param int    $user_id    Logged-in user id (0 = guest).
	 * @param string $session_id Anonymous session id (guests).
	 * @return array{completion_limit: int|null, completion_count: int, remaining_completions: int|null, can_complete: bool}
	 */
	public function status( Mission $mission, $user_id, $session_id ) {
		$limit = $mission->max_completions_per_user();
		$count = $this->count_for( $mission->id(), $user_id, $session_id );

		return array(
			'completion_limit'      => $limit,
			'completion_count'      => $count,
			'remaining_completions' => null === $limit ? null : max( 0, $limit - $count ),
			'can_complete'          => null === $limit || $count < $limit,
		);
	}

	/**
	 * Completion status for a mission + cart context.
	 *
	 * @param Mission        $mission    Mission.
	 * @param CartContext $context Cart snapshot.
	 * @return array{completion_limit: int|null, completion_count: int, remaining_completions: int|null, can_complete: bool}
	 */
	public function context_status( Mission $mission, CartContext $context ) {
		$identity = $this->context_identity( $context );

		return $this->status( $mission, $identity['user_id'], $identity['session_id'] );
	}

	/**
	 * Batch completion statuses for several missions + one cart context.
	 *
	 * @param Mission[]      $missions   Missions.
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, array{completion_limit: int|null, completion_count: int, remaining_completions: int|null, can_complete: bool}> mission_id => status.
	 */
	public function context_statuses( array $missions, CartContext $context ) {
		$identity = $this->context_identity( $context );

		$ids   = array();
		$missions_by_id = array();

		foreach ( $missions as $mission ) {
			$ids[]                      = $mission->id();
			$missions_by_id[ $mission->id() ] = $mission;
		}

		$counts = $this->counts_for( $ids, $identity['user_id'], $identity['session_id'] );
		$map    = array();

		foreach ( $missions_by_id as $mission_id => $mission ) {
			$limit = $mission->max_completions_per_user();
			$count = isset( $counts[ $mission_id ] ) ? $counts[ $mission_id ] : 0;

			$map[ $mission_id ] = array(
				'completion_limit'      => $limit,
				'completion_count'      => $count,
				'remaining_completions' => null === $limit ? null : max( 0, $limit - $count ),
				'can_complete'          => null === $limit || $count < $limit,
			);
		}

		return $map;
	}

	/**
	 * The authoritative business rule: can this identity complete this
	 * mission?
	 *
	 *   mission inactive?           -> cannot complete
	 *   mission unlimited?          -> allowed (no count query — the default
	 *                                for every existing mission, so the limit
	 *                                adds zero overhead to legacy installs)
	 *   completion count < limit -> allowed
	 *   otherwise                -> blocked
	 *
	 * @param Mission   $mission       Mission.
	 * @param int    $user_id    Logged-in user id (0 = guest).
	 * @param string $session_id Anonymous session id (guests).
	 * @return bool
	 */
	public function can_complete( Mission $mission, $user_id, $session_id ) {
		if ( ! $mission->is_active() ) {
			return false;
		}

		$limit = $mission->max_completions_per_user();

		if ( null === $limit ) {
			return true;
		}

		return $this->count_for( $mission->id(), $user_id, $session_id ) < $limit;
	}

	/**
	 * Whether a mission is still completable for the cart context.
	 *
	 * @param Mission        $mission    Mission.
	 * @param CartContext $context Cart snapshot.
	 * @return bool
	 */
	public function context_allows( Mission $mission, CartContext $context ) {
		$identity = $this->context_identity( $context );

		return $this->can_complete( $mission, $identity['user_id'], $identity['session_id'] );
	}

	/**
	 * Filter missions down to those the context's identity may still complete.
	 *
	 * Used by the reward engine so an exhausted mission can never grant its
	 * reward (and any previously applied reward is revoked by the normal
	 * reconcile pass). Unlimited missions pass through without a query.
	 *
	 * @param Mission[]      $missions   Missions.
	 * @param CartContext $context Cart snapshot.
	 * @return Mission[]
	 */
	public function available_missions( array $missions, CartContext $context ) {
		$identity = $this->context_identity( $context );

		$limited = array_filter( $missions, function ( Mission $mission ) {
			return null !== $mission->max_completions_per_user();
		} );

		// No mission carries a limit: everything stays, zero queries.
		if ( empty( $limited ) ) {
			return array_values( $missions );
		}

		$counts = $this->counts_for(
			array_map( function ( Mission $mission ) {
				return $mission->id();
			}, $limited ),
			$identity['user_id'],
			$identity['session_id']
		);

		return array_values(
			array_filter( $missions, function ( Mission $mission ) use ( $counts ) {
				$limit = $mission->max_completions_per_user();

				if ( null === $limit ) {
					return true;
				}

				$count = isset( $counts[ $mission->id() ] ) ? $counts[ $mission->id() ] : 0;

				return $count < $limit;
			} )
		);
	}

	/**
	 * Record one successful completion cycle (transactional, race-safe).
	 *
	 * For a limited mission the count + insert run inside a transaction with
	 * a row lock on the mission (`SELECT ... FOR UPDATE`), so two concurrent
	 * requests both see the pre-insert count and exactly one of them can
	 * cross the limit — the invariant
	 * `successful completions for one identity + one mission <= limit`
	 * always holds. The `order_mission` unique key additionally makes the
	 * same (order, mission) exactly-once regardless of which hook path or
	 * webhook replay reached here.
	 *
	 * @param Mission       $mission        Mission.
	 * @param int        $user_id     Logged-in user id (0 = guest).
	 * @param string     $session_id  Anonymous session id (guests).
	 * @param int        $order_id    Order id (0 = not order-bound).
	 * @param string|null $reward_type The mission's reward type at completion.
	 * @return bool True when a completion row was written; false when the
	 *              limit is reached, the row already exists, or the write
	 *              failed (no reward may be granted on a blocked write).
	 */
	public function record_completion( Mission $mission, $user_id, $session_id, $order_id = 0, $reward_type = null ) {
		$user_id    = (int) $user_id;
		$order_id   = (int) $order_id;
		$session_id = Session::is_valid( $session_id ) ? $session_id : '';
		$identity_key = ( $user_id > 0 ? 'u' . $user_id : 's' . $session_id );

		if ( $mission->id() < 1 || '' === $identity_key || ! $this->table_ready() ) {
			return false;
		}

		$limit = $mission->max_completions_per_user();

		// Unlimited missions have no cap to enforce; the order_mission unique
		// key still dedups double-processing of the same order.
		if ( null === $limit ) {
			$inserted = $this->insert_completion( $mission, $user_id, $session_id, $order_id, $reward_type );

			if ( $inserted ) {
				$this->refresh_count_cache( $mission->id(), $user_id, $session_id, $identity_key );
			}

			return $inserted;
		}

		global $wpdb;

		$missions_table = Schema::table( 'missions' );

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {
			// Lock the mission row so concurrent attempts serialize on it.
			$locked = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$missions_table} WHERE id = %d FOR UPDATE", $mission->id() )
			);

			if ( null === $locked ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				return false;
			}

			// Fresh count inside the lock (bypasses the request cache).
			if ( $this->count_for( $mission->id(), $user_id, $session_id, true ) >= $limit ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				return false;
			}

			$inserted = $this->insert_completion( $mission, $user_id, $session_id, $order_id, $reward_type );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// A successful write must refresh the per-request count cache
			// (Phase 21: after completion, update cached eligibility) so a
			// second check later in the same request — storefront re-read,
			// double-submit, reward reconcile — sees the new count instead
			// of the stale pre-write value.
			if ( $inserted ) {
				$this->refresh_count_cache( $mission->id(), $user_id, $session_id, $identity_key );
			}

			return $inserted;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return false;
		}
	}

	/**
	 * Refresh the per-request count cache for one mission after a successful
	 * write (Phase 21 cache rule: eligibility is identity-keyed and must
	 * be updated the moment a completion is recorded).
	 *
	 * @param int    $mission_id      Mission id.
	 * @param int    $user_id      Logged-in user id.
	 * @param string $session_id   Anonymous session id.
	 * @param string $identity_key Cache identity key ('uN' / 's<session>').
	 * @return void
	 */
	protected function refresh_count_cache( $mission_id, $user_id, $session_id, $identity_key ) {
		$this->count_cache_identity = $identity_key;
		$this->count_cache[ (int) $mission_id ] = $this->count_for( $mission_id, $user_id, $session_id, true );
	}

	/**
	 * Insert one completion history row.
	 *
	 * @param Mission        $mission        Mission.
	 * @param int         $user_id     Logged-in user id.
	 * @param string      $session_id  Anonymous session id.
	 * @param int         $order_id    Order id.
	 * @param string|null $reward_type Reward type.
	 * @return bool
	 */
	protected function insert_completion( Mission $mission, $user_id, $session_id, $order_id, $reward_type ) {
		global $wpdb;

		$table = Schema::table( 'mission_completions' );

		$data = array(
			'mission_id'     => $mission->id(),
			'user_id'     => $user_id > 0 ? $user_id : null,
			'session_id'  => Session::is_valid( $session_id ) ? $session_id : null,
			'order_id'    => $order_id > 0 ? $order_id : null,
			'reward_type' => null !== $reward_type && '' !== $reward_type ? sanitize_key( (string) $reward_type ) : null,
			'created_at'  => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert( $table, $data, array( '%d', '%d', '%s', '%d', '%s', '%s' ) );

		// A unique-key violation (same order + mission already recorded) is an
		// idempotent no-op, not an error.
		return (bool) $inserted;
	}

	/**
	 * Order-lifecycle handler: record completions for a revenue order.
	 *
	 * Hooked to `woocommerce_payment_complete` + `woocommerce_order_status_completed`.
	 * For every active mission the order met, records a completion cycle for
	 * the order's identity — honoring the per-user limit (an exhausted
	 * mission records nothing and grants nothing).
	 *
	 * @param int $order_id Order id.
	 * @return int Number of completion rows recorded (0 = none/gated/blocked).
	 */
	public function handle_order_completed( $order_id ) {
		return $this->record_order_completions( (int) $order_id );
	}

	/**
	 * Record the completion cycles of an order.
	 *
	 * Accepts a WC_Order object or a plain data array so headless/tests
	 * can drive the same code path without WooCommerce (mirrors
	 * AttributionEngine::attribute_order()).
	 *
	 * @param int                  $order_id Order id.
	 * @param \WC_Order|array|null $order    Order object or data array.
	 * @return int Number of completion rows recorded.
	 */
	public function record_order_completions( $order_id, $order = null ) {
		$order_id = (int) $order_id;

		if ( $order_id < 1 || ! $this->enabled() ) {
			return 0;
		}

		if ( null === $order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$data = $this->order_data( $order );

		if ( empty( $data ) ) {
			return 0;
		}

		$user_id    = (int) $data['user_id'];
		$session_id = isset( $data['session_id'] ) && Session::is_valid( $data['session_id'] )
			? $data['session_id']
			: $this->order_session_id( $order, $user_id );

		// No countable identity (guest order with an unresolvable session):
		// nothing to enforce or measure — skip (documented limitation).
		if ( $user_id < 1 && ! Session::is_valid( $session_id ) ) {
			return 0;
		}

		if ( null === $this->repository || null === $this->engine ) {
			try {
				$this->repository = \FaraCart\Plugin::instance()->container()->get( MissionRepository::class );
				$this->engine     = \FaraCart\Plugin::instance()->container()->get( MissionEngine::class );
			} catch ( \Throwable $e ) {
				return 0;
			}
		}

		$context = $this->order_context( $order );
		$missions   = $this->repository->active_missions();

		$recorded = 0;

		foreach ( $missions as $mission ) {
			$result = $this->engine->evaluate( $mission, $context );

			if ( ! $result->eligible() || ! $result->completed() ) {
				continue;
			}

			if ( $this->record_completion( $mission, $user_id, $session_id, $order_id, $mission->reward_type() ) ) {
				$recorded++;
			}
		}

		return $recorded;
	}

	/**
	 * Stamp the anonymous session id onto an order at checkout.
	 *
	 * This is the reliable guest-identity anchor for order-time
	 * completion recording: the shopper's browser holds the Session
	 * cookie during checkout, so `woocommerce_checkout_create_order` can
	 * persist it before the redirect-based gateways and webhooks lose it.
	 * Reuses the existing anonymous Session — no new tracking system, no
	 * PII (a random 32-hex token, never an IP/email).
	 *
	 * @param \WC_Order $order Order being created.
	 * @param mixed     $data  Checkout data (unused).
	 * @return void
	 */
	public function stamp_order_session( $order, $data ) {
		if ( ! $this->enabled() || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$session_id = $this->session_id();

		if ( ! Session::is_valid( $session_id ) ) {
			return;
		}

		// Never overwrite an existing stamp (idempotent on replays).
		if ( method_exists( $order, 'get_meta' ) && $order->get_meta( self::ORDER_SESSION_META ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_SESSION_META, $session_id );
	}

	/**
	 * Resolve the anonymous session id for an order.
	 *
	 * Priority: the session stamped at checkout (`_faracart_session`) →
	 * the order_paid revenue event (when the analytics pipeline ran) →
	 * the live cookie (payment-complete often fires on the checkout
	 * request itself). Logged-in orders don't need it (user_id is the
	 * count key).
	 *
	 * @param \WC_Order|array|null $order   Order object or data array.
	 * @param int                  $user_id Order customer id.
	 * @return string Session id ('' when unresolvable).
	 */
	protected function order_session_id( $order, $user_id ) {
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			$stamped = $order->get_meta( self::ORDER_SESSION_META );

			if ( Session::is_valid( $stamped ) ) {
				return (string) $stamped;
			}
		}

		global $wpdb;

		if ( $this->table_ready() ) {
			$events = Schema::table( 'revenue_events' );
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;

			if ( $order_id > 0 ) {
				$row = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT session_id FROM {$events} WHERE event_type = %s AND order_id = %d ORDER BY id DESC LIMIT 1",
						'order_paid',
						$order_id
					)
				);

				if ( Session::is_valid( $row ) ) {
					return (string) $row;
				}
			}
		}

		$cookie = $this->session_id();

		if ( Session::is_valid( $cookie ) ) {
			return $cookie;
		}

		return '';
	}

	/**
	 * Normalize an order into the plain data array the recording path
	 * reads (mirrors AttributionEngine::order_data()).
	 *
	 * @param \WC_Order|array|null $order Order object or data array.
	 * @return array<string, mixed> Empty when unrecognized.
	 */
	protected function order_data( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_total' ) ) {
			return array(
				'user_id' => (int) $order->get_user_id(),
				'status'  => (string) $order->get_status(),
			);
		}

		if ( is_array( $order ) ) {
			return array(
				'user_id'    => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
				'status'     => isset( $order['status'] ) ? (string) $order['status'] : 'completed',
				'session_id' => isset( $order['session_id'] ) ? (string) $order['session_id'] : '',
			);
		}

		return array();
	}

	/**
	 * Build a CartContext from an order's line items.
	 *
	 * The mission engine evaluates against the same normalized snapshot it
	 * uses for carts, so order-time completion determination reuses the
	 * engine verbatim — no duplicated matching logic. Categories are
	 * resolved from the product ids (variations resolve via their parent,
	 * the WooCommerce convention).
	 *
	 * @param \WC_Order|array|null $order Order object or data array.
	 * @return CartContext
	 */
	protected function order_context( $order ) {
		$items = array();

		if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
			foreach ( $order->get_items() as $line ) {
				$product_id = (int) $line->get_product_id();
				$quantity   = max( 0.0, (float) $line->get_quantity() );

				$items[] = array(
					'product_id'    => $product_id,
					'variation_id'  => (int) $line->get_variation_id(),
					'quantity'      => $quantity,
					'line_subtotal' => (float) $line->get_subtotal(),
					'line_total'    => (float) $line->get_total(),
					'price'         => $quantity > 0 ? (float) $line->get_subtotal() / $quantity : 0.0,
					'categories'    => $this->product_categories( $product_id ),
				);
			}

			return new CartContext(
				array(
					'subtotal'       => (float) $order->get_subtotal(),
					'total'          => (float) $order->get_total(),
					'discount_total' => (float) $order->get_discount_total(),
					'taxes_total'    => (float) $order->get_total_tax(),
					'shipping_total' => (float) $order->get_shipping_total(),
					'currency'       => method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '',
					'user_id'        => (int) $order->get_user_id(),
					'is_guest'       => 0 === (int) $order->get_user_id(),
					'items'          => $items,
				)
			);
		}

		// Headless/test path: a plain data array with an explicit total.
		$total = isset( $order['total'] ) ? (float) $order['total'] : 0.0;

		return new CartContext(
			array(
				'subtotal' => $total,
				'total'    => $total,
				'user_id'  => isset( $order['user_id'] ) ? (int) $order['user_id'] : 0,
				'is_guest' => empty( $order['user_id'] ),
				'items'    => $items,
			)
		);
	}

	/**
	 * The category term ids of a product (empty when unavailable).
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	protected function product_categories( $product_id ) {
		if ( $product_id < 1 || ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		return is_wp_error( $terms ) ? array() : array_map( 'intval', (array) $terms );
	}

	/**
	 * Whether the mission_completions table exists (checked once per request).
	 *
	 * Guards pre-migration environments (tests, mid-upgrade requests) so a
	 * missing table degrades to "no history / unlimited" instead of a DB
	 * error.
	 *
	 * @return bool
	 */
	protected function table_ready() {
		if ( null !== $this->table_ready ) {
			return $this->table_ready;
		}

		global $wpdb;

		$this->table_ready = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				Schema::table( 'mission_completions' )
			)
		);

		return $this->table_ready;
	}
}
