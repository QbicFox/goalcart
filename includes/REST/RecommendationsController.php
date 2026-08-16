<?php
/**
 * REST controller for the smart goal recommendations.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\RevenueTracker;
use FaraCart\Goals\GoalRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class RecommendationsController
 *
 * Phase 33.4 (Smart Goal Recommendation) — the endpoints behind the
 * admin-facing Goal Optimization surface:
 *
 *  - `GET /faracart/v1/revenue/goal-recommendations` — the full
 *    recommendation payload: analyzed store data (AOV, median, order
 *    distribution, shipping, margin, current goal history), every ranked
 *    candidate threshold with its score, confidence, expected impact and
 *    plain-English reasons, plus the top recommendation.
 *
 *  - `POST /faracart/v1/revenue/goal-recommendations/apply` — the only
 *    write path (UPSELL_REFACTOR §10/§41): applies a chosen threshold to
 *    an existing goal with explicit confirmation from the admin UI,
 *    records the recommendation_applied feedback-loop event (goal changed
 *    → performance can later be correlated), and invalidates the revenue
 *    caches. It never touches unrelated Goal settings.
 *
 * GET args: goal_id is REQUIRED (the admin Recommendations page always
 * analyzes exactly one selected goal — the goal's reward type and
 * historical performance feed the scoring, and a goal_id that no longer
 * resolves is rejected instead of silently recommending for a deleted
 * goal). Optional: reward_type (one of the Reward::types() whitelist),
 * reward_value / reward_max_value / reward_meta (reward config override
 * for advanced callers — the goal's own reward config is used when
 * omitted), window_days (7–180, default 90), from / to (explicit date
 * range).
 *
 * Admin-only (manage_options) and rate limited per user like every other
 * admin endpoint; the payload is served through the Phase 33.3 cached read
 * layer, so repeated admin renders never recompute the analysis. The
 * recommendation engine itself never modifies a goal — applying is an
 * explicit, permission-checked admin action through the apply endpoint.
 */
class RecommendationsController extends BaseController {

	/**
	 * Cached revenue repository (serves the recommendation payload).
	 *
	 * @var RevenueRepository
	 */
	protected $repository;

	/**
	 * Goal repository (the apply write path).
	 *
	 * @var GoalRepository
	 */
	protected $goals;

	/**
	 * Revenue event tracker (feedback-loop event recording).
	 *
	 * @var RevenueTracker
	 */
	protected $tracker;

	/**
	 * Constructor.
	 *
	 * @param RevenueRepository $repository Revenue repository.
	 * @param GoalRepository    $goals      Goal repository.
	 * @param RevenueTracker    $tracker    Revenue event tracker.
	 */
	public function __construct( RevenueRepository $repository, GoalRepository $goals, RevenueTracker $tracker ) {
		$this->repository = $repository;
		$this->goals      = $goals;
		$this->tracker    = $tracker;
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
	 * Register the recommendation routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/revenue/goal-recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->recommendation_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/goal-recommendations/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_apply' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->apply_args(),
			)
		);
	}

	/**
	 * Apply a recommended threshold to an existing goal.
	 *
	 * The admin UI always confirms first (current target vs new target);
	 * this endpoint only ever changes the goal's target — no other Goal
	 * settings are touched. Records the recommendation_applied event for
	 * the feedback loop and invalidates the revenue caches so every
	 * dashboard reflects the change immediately.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_apply( $request ) {
		$goal_id   = (int) $request->get_param( 'goal_id' );
		$threshold = (float) $request->get_param( 'threshold' );

		$goal = $this->goals->find( $goal_id );

		if ( null === $goal ) {
			return $this->error(
				'faracart_goal_not_found',
				__( 'The goal could not be found.', 'faracart' ),
				404
			);
		}

		if ( $threshold <= 0 ) {
			return $this->error(
				'faracart_invalid_threshold',
				__( 'The recommended target must be greater than zero.', 'faracart' ),
				400
			);
		}

		$previous = (float) $goal->target();

		// Feedback loop (UPSELL_REFACTOR §41): record the apply before the
		// update so the event always captures the old target.
		$this->tracker->record(
			RevenueTracker::EVENT_RECOMMENDATION_APPLIED,
			array(
				'goal_id' => $goal_id,
				'meta'    => array(
					'threshold'        => $threshold,
					'previous_target'  => $previous,
					'changed'          => abs( $previous - $threshold ) > 0.0001 ? 1 : 0,
				),
			)
		);

		$this->goals->update( $goal_id, array( 'target' => $threshold ) );

		// The goal update fires faracart_goals_changed → revenue cache
		// invalidation; bump explicitly too in case that wiring is filtered
		// away on a store.
		$this->repository->invalidate();

		return $this->success(
			array(
				'goal_id' => $goal_id,
				'name'    => $goal->name(),
				'target'  => $threshold,
				'previous_target' => $previous,
			)
		);
	}

	/**
	 * Arg schema for the apply route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function apply_args() {
		return array(
			'goal_id'   => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'threshold' => array(
				'required'         => true,
				'type'             => 'number',
				'minimum'          => 0,
				'exclusiveMinimum' => true,
			),
		);
	}

	/**
	 * Build the recommendation payload over the requested filters.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get( $request ) {
		$args = array(
			// Required by the route schema; always ≥ 1 on this page.
			'goal_id'          => (int) $request->get_param( 'goal_id' ),
			'reward_type'      => (string) $request->get_param( 'reward_type' ),
			'reward_value'     => $request->get_param( 'reward_value' ),
			'reward_max_value' => $request->get_param( 'reward_max_value' ),
			'reward_meta'      => $request->get_param( 'reward_meta' ),
			'window_days'      => (int) $request->get_param( 'window_days' ),
			'from'             => (string) $request->get_param( 'from' ),
			'to'               => (string) $request->get_param( 'to' ),
		);

		return $this->success(
			$this->repository->goal_recommendations( $args ),
			array(
				'applied' => array(
					'goal_id'      => $args['goal_id'],
					'reward_type'  => $args['reward_type'],
					'window_days'  => $args['window_days'],
					'from'         => $args['from'],
					'to'           => $args['to'],
				),
			)
		);
	}

	/**
	 * Arg schema for the recommendation route.
	 *
	 * goal_id is required: recommendations are always computed for exactly
	 * one selected goal (the admin page never requests an "all goals"
	 * context).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function recommendation_args() {
		return array(
			'goal_id'          => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'reward_type'      => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array_merge( array( '' ), Reward::types() ),
			),
			'reward_value'     => array(
				'type'    => 'number',
				'default' => null,
				'minimum' => 0,
			),
			'reward_max_value' => array(
				'type'    => 'number',
				'default' => null,
				'minimum' => 0,
			),
			'reward_meta'      => array(
				'type'  => 'object',
				'default' => array(),
			),
			'window_days'      => array(
				'type'    => 'integer',
				'default' => 90,
				'minimum' => 7,
				'maximum' => 180,
			),
			'from'             => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'to'               => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
		);
	}
}
