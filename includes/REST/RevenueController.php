<?php
/**
 * REST controller for the revenue optimization admin pages.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RewardCostEstimator;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class RevenueController
 *
 * Phase 33.6 (React Admin) — the read-only endpoints behind the Revenue
 * Optimization admin section. All payloads are served through the Phase
 * 33.3 cached repository layer, so repeated admin renders never recompute
 * the summaries:
 *
 *  - `GET /goalcart/v1/revenue/overview` — the Revenue Overview page's
 *    data source: the attribution summary (goal-driven / assisted /
 *    influenced revenue, orders, reward cost, profit impact, funnel),
 *    incremental cart value, AOV analysis, shipping stats and the daily
 *    trend series (revenue_daily + today's live bucket) over the window.
 *  - `GET /goalcart/v1/revenue/attribution` — the Attribution Dashboard
 *    page's data source: the same overview payload (funnel + direct vs
 *    assisted models + incremental cart value + profit), without the
 *    trend series.
 *  - `GET /goalcart/v1/revenue/goals` — the Goal Performance page's data
 *    source: per-goal metrics rows (funnel counts, completion/conversion
 *    rates, average + incremental cart value, attributed + assisted
 *    revenue, reward cost, profit impact) for every goal or one goal.
 *  - `GET /goalcart/v1/revenue/cost-coverage` — Product Cost coverage
 *    (UPSELL_REFACTOR §25/§46): how much of the catalog carries cost
 *    data, so the Goal Optimization UI can show "842 / 1,000 products"
 *    and explain why profit estimates may be unavailable.
 *
 * Optional args on every route: from / to (validated Y-m-d bounds),
 * goal_id (filter the metrics to one goal). All routes are admin-only
 * (manage_options, per-user rate limited — P07-T04) and every value is
 * validated through the REST arg schema before reaching the repository.
 */
class RevenueController extends BaseController {

	/**
	 * Cached revenue repository (serves every payload).
	 *
	 * @var RevenueRepository
	 */
	protected $repository;

	/**
	 * Reward cost / product cost estimator (cost coverage reads).
	 *
	 * @var RewardCostEstimator
	 */
	protected $costs;

	/**
	 * Constructor.
	 *
	 * @param RevenueRepository  $repository Revenue repository.
	 * @param RewardCostEstimator $costs     Product cost estimator.
	 */
	public function __construct( RevenueRepository $repository, RewardCostEstimator $costs ) {
		$this->repository = $repository;
		$this->costs      = $costs;
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
	 * Register the revenue routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/revenue/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_overview' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->window_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/attribution',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_attribution' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->window_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/goals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_goals' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->window_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/cost-coverage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_cost_coverage' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(),
			)
		);
	}

	/**
	 * Product Cost coverage payload (UPSELL_REFACTOR §25/§46).
	 *
	 * Exposes only the minimal admin surface: the product-level coverage
	 * counts, the store-wide availability flag and the cost sources — no
	 * internal cost values, and never to the storefront.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_cost_coverage( $request ) {
		return $this->success(
			array(
				'product_coverage'    => $this->costs->cost_coverage(),
				'store_has_cost_data' => $this->costs->store_has_cost_data(),
				'cost_sources'        => RewardCostEstimator::COST_SOURCES,
			)
		);
	}

	/**
	 * Revenue Overview payload: overview + daily trend.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_overview( $request ) {
		$args = $this->window_filters( $request );

		$payload = $this->repository->overview( $args );

		// The trend series is part of the overview page (one fetch), read
		// through the same cached layer with the same filters.
		$payload['trend'] = $this->repository->daily_trend( $args );

		return $this->success( $payload, array( 'applied' => $args ) );
	}

	/**
	 * Attribution Dashboard payload: the overview minus the trend series.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_attribution( $request ) {
		$args = $this->window_filters( $request );

		return $this->success(
			$this->repository->overview( $args ),
			array( 'applied' => $args )
		);
	}

	/**
	 * Goal Performance payload: per-goal metrics rows.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_goals( $request ) {
		$args = $this->window_filters( $request );

		return $this->success(
			array(
				'items' => $this->repository->goal_performance( $args ),
			),
			array( 'applied' => $args )
		);
	}

	/**
	 * Normalize the shared window filters from a request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array<string, mixed>
	 */
	protected function window_filters( $request ) {
		return array(
			'from'    => (string) $request->get_param( 'from' ),
			'to'      => (string) $request->get_param( 'to' ),
			'goal_id' => (int) $request->get_param( 'goal_id' ),
		);
	}

	/**
	 * Arg schema shared by the revenue routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function window_args() {
		return array(
			'from'    => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'to'      => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'goal_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
		);
	}
}
