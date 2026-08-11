<?php
/**
 * REST controller for the smart goal recommendations.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Analytics\RevenueRepository;
use GoalCart\Hooks\HookManager;
use GoalCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class RecommendationsController
 *
 * Phase 33.4 (Smart Goal Recommendation) — the read-only endpoint serving
 * the deterministic goal-threshold recommendation to the admin UI:
 *
 *  - `GET /goalcart/v1/revenue/goal-recommendations` — the full
 *    recommendation payload: analyzed store data (AOV, median, order
 *    distribution, shipping, margin, current goal history), every ranked
 *    candidate threshold with its score, confidence, expected impact and
 *    plain-English reasons, plus the top recommendation.
 *
 * Optional args: goal_id (recommend for an existing goal — its reward type
 * and historical performance feed the scoring), reward_type (one of the
 * Reward::types() whitelist), reward_value / reward_max_value / reward_meta
 * (reward config for recommendations without an existing goal), window_days
 * (7–180, default 90), from / to (explicit date range).
 *
 * Admin-only (manage_options) and rate limited per user like every other
 * admin endpoint; the payload is served through the Phase 33.3 cached read
 * layer, so repeated admin renders never recompute the analysis. The engine
 * never modifies a goal — applying a recommendation is an explicit admin
 * action handled by the existing GoalsController.
 */
class RecommendationsController extends BaseController {

	/**
	 * Cached revenue repository (serves the recommendation payload).
	 *
	 * @var RevenueRepository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @param RevenueRepository $repository Revenue repository.
	 */
	public function __construct( RevenueRepository $repository ) {
		$this->repository = $repository;
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
	}

	/**
	 * Build the recommendation payload over the requested filters.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get( $request ) {
		$args = array(
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
	 * @return array<string, array<string, mixed>>
	 */
	public function recommendation_args() {
		return array(
			'goal_id'          => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
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
