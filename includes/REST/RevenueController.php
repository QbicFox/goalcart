<?php
/**
 * REST controller for the revenue optimization admin pages.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Analytics\RevenueRepository;
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
