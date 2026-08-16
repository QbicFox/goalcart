<?php
/**
 * REST controller for the smart upsell ranking + historical tracking.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\Session;
use FaraCart\Analytics\UpsellRanker;
use FaraCart\Cart\CartIntegration;
use FaraCart\Goals\Goal;
use FaraCart\Goals\GoalEngine;
use FaraCart\Goals\GoalRepository;
use FaraCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class UpsellController
 *
 * Phase 33.5 (Smart Upsell) — the endpoints behind the ranking engine and
 * its historical learning loop:
 *
 *  - `POST /faracart/v1/upsell/track` — public, nonce-guarded (the same
 *    tracking nonce the storefront already holds): the frontend reports
 *    upsell_impression / upsell_clicked / upsell_added events into the
 *    Phase 33.1 upsell_events log. Server-side attribution of upsell_order
 *    events happens on order payment (UpsellRanker hooks), so a client
 *    can never self-report a purchase. Rate limited per IP.
 *
 *  - `GET /faracart/v1/upsell/rank` — public, per-IP rate limited (Phase
 *    33.7 Frontend Upsell Integration): ranked products that help the
 *    shopper close the live goal gap. The server resolves the goal
 *    (explicit goal_id, or the featured active money goal), computes the
 *    remaining gap from the LIVE cart through the same GoalEngine the
 *    progress widgets use (goal target − current cart value) and runs the
 *    deterministic UpsellRanker. The storefront sends only goal_id +
 *    limit, so the gap is always computed server-side — never trusted
 *    from the client. The payload carries catalog data only (name, price,
 *    image, reasons) with no PII or secrets — the same privacy posture
 *    as the public /progress endpoint.
 *
 *  - `GET /faracart/v1/revenue/upsells` — admin-only. Two modes:
 *      - context mode (default): ranked upsell products for a simulated
 *        cart + goal (goal_id, cart_value, remaining, cart, limit) — the
 *        engine's full payload with per-product score breakdowns,
 *        historical conversion stats and plain-English reasons.
 *      - analytics mode (`analytics=1`): the top-products upsell analytics
 *        table (impressions / clicks / adds / orders / conversion /
 *        revenue / estimated profit / upsell score) over the requested
 *        window — the Phase 33.6 Upsell Analytics page's data source.
 *
 *  - `GET /faracart/v1/revenue/upsells/{product_id}` — admin-only: one
 *    product's upsell score breakdown + historical stats, scored in the
 *    given context (or standalone when no context args are passed).
 *
 * Admin routes are manage_options-gated and rate limited per user (P07-T04);
 * the public track route is protected by the tracking nonce + per-IP rate
 * limiting, mirroring TrackController. All values are validated through
 * the REST arg schemas before they reach the repository/engine.
 */
class UpsellController extends BaseController {

	/**
	 * Rate-limit budget for the upsell track route (requests per window,
	 * per IP) — impressions fire on every widget render, so the budget is
	 * generous like the Phase 16 track route.
	 *
	 * @var int
	 */
	const TRACK_RATE_LIMIT_COUNT = 300;

	/**
	 * Cached revenue repository (serves admin rankings + analytics).
	 *
	 * @var RevenueRepository
	 */
	protected $repository;

	/**
	 * Deterministic ranking engine (Phase 33.5). The public storefront
	 * rank route calls it directly (no transient churn per cart state);
	 * admin reads go through the repository's cached layer.
	 *
	 * @var UpsellRanker|null
	 */
	protected $ranker;

	/**
	 * Live-cart snapshot service (Phase 33.7 goal gap calculation).
	 *
	 * @var CartIntegration|null
	 */
	protected $cart_integration;

	/**
	 * Goal engine (evaluates the goal on the live cart).
	 *
	 * @var GoalEngine|null
	 */
	protected $engine;

	/**
	 * Goal repository (featured active goal lookup).
	 *
	 * @var GoalRepository|null
	 */
	protected $goals;

	/**
	 * Constructor.
	 *
	 * @param RevenueRepository  $repository       Revenue repository.
	 * @param UpsellRanker|null  $ranker           Ranking engine (Phase
	 *                                             33.7 direct storefront
	 *                                             reads; falls back to the
	 *                                             cached repository read).
	 * @param CartIntegration|null $cart_integration Live-cart snapshot.
	 * @param GoalEngine|null    $engine           Goal engine (gap calc).
	 * @param GoalRepository|null $goals           Goal repository.
	 */
	public function __construct( RevenueRepository $repository, ?UpsellRanker $ranker = null, ?CartIntegration $cart_integration = null, ?GoalEngine $engine = null, ?GoalRepository $goals = null ) {
		$this->repository       = $repository;
		$this->ranker           = $ranker;
		$this->cart_integration = $cart_integration;
		$this->engine           = $engine;
		$this->goals            = $goals;
	}

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the upsell routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/upsell/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_track' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $this->track_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upsell/rank',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_rank' ),
				// Public by design (Phase 33.7): guests read their own
				// goal-gap recommendations, so no capability — but the
				// route is rate limited per IP exactly like /progress and
				// serves catalog data only.
				'permission_callback' => $this->get_public_permission_callback(),
				'args'                => $this->rank_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/upsells',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->index_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/upsells/(?P<product_id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->get_args(),
			)
		);
	}

	/**
	 * Verify the tracking nonce before the public route runs.
	 *
	 * Mirrors TrackController: the plugin's own tracking nonce (created in
	 * the frontend config printed by the Phase 16 Tracker) replaces the
	 * admin capability check, then the per-IP rate limit applies.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function permission_callback( $request ) {
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), \FaraCart\Analytics\Tracker::TRACK_NONCE_ACTION ) ) {
			return $this->error(
				'faracart_invalid_nonce',
				__( 'Invalid tracking nonce.', 'faracart' ),
				403
			);
		}

		$limited = $this->rate_limit_ip( $request, self::TRACK_RATE_LIMIT_COUNT );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return true;
	}

	/**
	 * Record an upsell interaction event reported by the storefront.
	 *
	 * Only the three client-reportable upsell events are accepted here
	 * (impression / clicked / added); upsell_order is attributed
	 * server-side on order payment (UpsellRanker::attribute_order), so a
	 * client can never self-report a conversion.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_track( $request ) {
		if ( ! class_exists( RevenueTracker::class ) ) {
			return $this->error(
				'faracart_tracking_disabled',
				__( 'Tracking is disabled.', 'faracart' ),
				403
			);
		}

		$event_type = (string) $request->get_param( 'event_type' );

		if ( ! in_array( $event_type, array( RevenueTracker::EVENT_UPSELL_IMPRESSION, RevenueTracker::EVENT_UPSELL_CLICKED, RevenueTracker::EVENT_UPSELL_ADDED ), true ) ) {
			return $this->error(
				'faracart_invalid_event_type',
				__( 'Unknown upsell event type.', 'faracart' ),
				400
			);
		}

		$session_id = (string) $request->get_param( 'session_id' );

		$tracker = \FaraCart\Plugin::instance()->container()->get( RevenueTracker::class );

		if ( ! $tracker->tracking_enabled() ) {
			return $this->error(
				'faracart_tracking_disabled',
				__( 'Tracking is disabled.', 'faracart' ),
				403
			);
		}

		// A well-formed client session id wins; otherwise the request's own
		// cookie (set on the page view) identifies the session.
		if ( ! Session::is_valid( $session_id ) ) {
			$session_id = $tracker->get_session_id();
		}

		$id = $tracker->record_upsell(
			$event_type,
			array(
				'goal_id'    => (int) $request->get_param( 'goal_id' ),
				'product_id' => (int) $request->get_param( 'product_id' ),
				'cart_value' => max( 0.0, (float) $request->get_param( 'cart_value' ) ),
				'session_id' => $session_id,
				'meta'       => array(
					'source' => (string) $request->get_param( 'source' ),
				),
			)
		);

		if ( $id < 1 ) {
			// Deduped events (repeat impression within 24h, same session +
			// goal + product) are not errors — they are idempotency in
			// action. Only gate/whitelist failures are surfaced.
			if ( ! $tracker->tracking_enabled() ) {
				return $this->error(
					'faracart_tracking_disabled',
					__( 'Tracking is disabled.', 'faracart' ),
					403
				);
			}
		}

		return $this->success( array( 'id' => max( 0, $id ) ) );
	}

	/**
	 * Rank products that help close the live goal gap (Phase 33.7).
	 *
	 * The storefront's upsell panel calls this with just goal_id + limit;
	 * the server resolves the goal (explicit id, else the featured active
	 * money goal), computes the remaining gap from the live cart via the
	 * same GoalEngine the progress widgets use, and returns the
	 * deterministic ranker payload (available / context / recommendations
	 * with score breakdowns + reasons). Graceful degradation is the
	 * ranker's own: no goal, closed gap, disabled or no candidates all
	 * return an unavailable payload with a reason — never a fabricated
	 * list. Catalog data only, no PII.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_rank( $request ) {
		$args = array(
			'goal_id' => (int) $request->get_param( 'goal_id' ),
			'limit'   => (int) $request->get_param( 'limit' ),
		);

		// Explicit cart override (tests / embedded consumers) — the live
		// storefront path leaves it null and the server reads WC()->cart.
		$cart = $request->get_param( 'cart' );

		if ( is_array( $cart ) ) {
			$args['cart'] = array_values( array_filter( array_map( 'intval', $cart ), function ( $id ) {
				return $id > 0;
			} ) );
		}

		// Explicit money overrides beat the live-cart computation.
		if ( null !== $request->get_param( 'cart_value' ) ) {
			$args['cart_value'] = (float) $request->get_param( 'cart_value' );
		}

		if ( null !== $request->get_param( 'remaining' ) ) {
			$args['remaining'] = (float) $request->get_param( 'remaining' );
		}

		// Resolve the goal and compute the live gap when the client did
		// not pin the money context (the normal storefront path).
		if ( empty( $args['goal_id'] ) && empty( $args['remaining'] ) ) {
			$goal = $this->rank_goal();

			if ( null !== $goal ) {
				$args['goal_id'] = $goal->id();
			}
		}

		if ( empty( $args['cart_value'] ) && ! isset( $args['remaining'] ) ) {
			$live = $this->live_rank_context( isset( $args['goal_id'] ) ? (int) $args['goal_id'] : 0 );

			if ( null !== $live ) {
				$args['cart_value'] = $live['cart_value'];

				if ( empty( $args['cart'] ) ) {
					$args['cart'] = $live['cart'];
				}
			}
		}

		// The ranker itself degrades gracefully (no goal → "a goal target
		// or an explicit remaining amount is required", closed gap →
		// unavailable, disabled → unavailable, no candidates →
		// unavailable), so the endpoint never invents a list.
		$payload = null !== $this->ranker
			? $this->ranker->rank( $args )
			: $this->repository->upsell_ranking( $args );

		$response = $this->success(
			$this->public_rank_payload( $payload ),
			array( 'applied' => $args )
		);

		// The ranking is cart-dependent, so it must never be served from
		// a browser or shared cache — the same posture as /progress.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Redact the store's margin/profit data from the public payload.
	 *
	 * P22-style hardening: the storefront panel only renders product
	 * image/name/price/permalink — it has no use for the scoring
	 * transparency beyond that. Per-unit estimated profit, the
	 * profit-availability flag, the raw margin percentage and the
	 * margin reason bullets are therefore stripped so an anonymous
	 * caller can never harvest the store's cost-derived margin data from
	 * this public route (the admin-only analytics surface keeps them
	 * behind manage_options).
	 *
	 * @param array<string, mixed> $payload Ranker payload.
	 * @return array<string, mixed>
	 */
	protected function public_rank_payload( array $payload ) {
		if ( empty( $payload['recommendations'] ) || ! is_array( $payload['recommendations'] ) ) {
			return $payload;
		}

		foreach ( $payload['recommendations'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item['estimated_profit'] = null;
			$item['profit_available'] = false;

			if ( isset( $item['factors']['margin_pct'] ) ) {
				$item['factors']['margin_pct'] = null;
			}

			// Drop the margin explanation bullets too — a store that
			// stores costs would otherwise leak "Estimated margin 25%…"
			// through the reasons list.
			if ( ! empty( $item['reasons'] ) && is_array( $item['reasons'] ) ) {
				$item['reasons'] = array_values(
					array_filter( $item['reasons'], function ( $reason ) {
						return false === stripos( (string) $reason, 'margin' );
					} )
				);
			}

			$payload['recommendations'][ $i ] = $item;
		}

		return $payload;
	}

	/**
	 * The featured active money goal for storefront ranking.
	 *
	 * When no explicit goal_id is sent, the first active money goal in
	 * repository order is the upsell target — the same goal the progress
	 * widgets feature. Non-money goals have no money gap to close, so
	 * they are skipped.
	 *
	 * @return Goal|null
	 */
	protected function rank_goal() {
		if ( null === $this->goals ) {
			return null;
		}

		foreach ( $this->goals->active_goals() as $goal ) {
			if ( $goal instanceof Goal && $goal->is_money_goal() ) {
				return $goal;
			}
		}

		return null;
	}

	/**
	 * The live cart's money context for a goal (Phase 33.7 gap calc).
	 *
	 * Builds the same CartContext the progress widgets evaluate goals
	 * on, evaluates the goal, and returns its current money value + the
	 * cart's product ids. The ranker then derives the remaining gap as
	 * goal target − current, matching exactly what the widget displays.
	 *
	 * @param int $goal_id Resolved goal id (0 = none yet).
	 * @return array{cart_value: float, cart: int[]}|null
	 */
	protected function live_rank_context( $goal_id ) {
		if ( null === $this->cart_integration ) {
			return null;
		}

		$context = $this->cart_integration->context();

		$cart = array();

		foreach ( $context->items() as $item ) {
			$id = (int) $item->product_id();

			if ( $id > 0 && ! in_array( $id, $cart, true ) ) {
				$cart[] = $id;
			}
		}

		$cart_value = 0.0;

		if ( $goal_id > 0 && null !== $this->engine ) {
			$goal = $this->goals ? $this->goals->find( $goal_id ) : null;

			if ( $goal instanceof Goal ) {
				$result = $this->engine->evaluate( $goal, $context );
				$cart_value = (float) $result->current();
			}
		}

		return array(
			'cart_value' => max( 0.0, $cart_value ),
			'cart'       => $cart,
		);
	}

	/**
	 * Ranked upsells (context mode) or the top-products analytics table.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		$args = array(
			'goal_id'    => (int) $request->get_param( 'goal_id' ),
			'cart_value' => (float) $request->get_param( 'cart_value' ),
			'remaining'  => $request->get_param( 'remaining' ),
			'cart'       => $request->get_param( 'cart' ),
			'limit'      => (int) $request->get_param( 'limit' ),
			'from'       => (string) $request->get_param( 'from' ),
			'to'         => (string) $request->get_param( 'to' ),
		);

		if ( $request->get_param( 'analytics' ) ) {
			return $this->success(
				$this->repository->upsell_analytics( $args ),
				array( 'applied' => $args )
			);
		}

		return $this->success(
			$this->repository->upsell_ranking( $args ),
			array( 'applied' => $args )
		);
	}

	/**
	 * One product's upsell score breakdown + historical stats.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );

		if ( $product_id < 1 ) {
			return $this->error(
				'faracart_invalid_product',
				__( 'Invalid product id.', 'faracart' ),
				400
			);
		}

		$args = array(
			'goal_id'    => (int) $request->get_param( 'goal_id' ),
			'cart_value' => (float) $request->get_param( 'cart_value' ),
			'remaining'  => $request->get_param( 'remaining' ),
			'cart'       => $request->get_param( 'cart' ),
		);

		$detail = $this->repository->upsell_product_detail( $product_id, $args );

		if ( null === $detail ) {
			return $this->error(
				'faracart_product_not_found',
				__( 'The product could not be found.', 'faracart' ),
				404
			);
		}

		return $this->success( $detail, array( 'applied' => $args ) );
	}

	/**
	 * Arg schema for the public storefront rank route (Phase 33.7).
	 *
	 * goal_id + limit are all the storefront sends; cart / cart_value /
	 * remaining exist for tests and embedded consumers. When they are
	 * absent the server computes the gap from the live cart — the client
	 * can never pin the gap on the public route (well, it can, but the
	 * storefront never does; the numbers are catalog-only either way).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function rank_args() {
		return array(
			'goal_id'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'limit'      => array(
				'type'    => 'integer',
				'default' => 3,
				'minimum' => 1,
				'maximum' => 10,
			),
			'cart'       => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'cart_value' => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
			'remaining'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
		);
	}

	/**
	 * Arg schema for the public track route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function track_args() {
		return array(
			'event_type' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $value ) {
					return in_array(
						(string) $value,
						array(
							RevenueTracker::EVENT_UPSELL_IMPRESSION,
							RevenueTracker::EVENT_UPSELL_CLICKED,
							RevenueTracker::EVENT_UPSELL_ADDED,
						),
						true
					);
				},
			),
			'goal_id'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'product_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'source'     => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'session_id' => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => function ( $value ) {
					if ( '' === (string) $value ) {
						return true;
					}

					return Session::is_valid( $value );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'nonce'      => array(
				'required' => true,
				'type'     => 'string',
			),
		);
	}

	/**
	 * Arg schema for the ranking/analytics list route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index_args() {
		return array(
			'goal_id'    => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'remaining'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
			'cart'       => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'limit'      => array(
				'type'    => 'integer',
				'default' => 4,
				'minimum' => 1,
				// Matches the repository clamp (1–100). In analytics mode the
				// limit is the top-products table size; in context mode the
				// ranker clamps to its own 10-result cap regardless.
				'maximum' => 100,
			),
			'analytics'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'from'       => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'to'         => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
		);
	}

	/**
	 * Arg schema for the per-product route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_args() {
		$args = array(
			'product_id' => array(
				'type'              => 'integer',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'goal_id'    => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'remaining'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
			'cart'       => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);

		return $args;
	}
}
