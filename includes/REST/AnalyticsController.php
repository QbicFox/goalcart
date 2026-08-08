<?php
/**
 * REST controller for the analytics dashboard.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Analytics\AnalyticsRepository;
use GoalCart\Hooks\HookManager;
use GoalCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnalyticsController
 *
 * Phase 17 (Analytics Dashboard) — the single read-only endpoint powering
 * the admin dashboard page:
 *
 *  - `GET /goalcart/v1/analytics` — summary KPIs, daily trend, and the
 *    top campaigns / top goals / top suggested products lists, all
 *    sliced by the same filter set (date range, campaign, goal, goal
 *    ids, reward type, product).
 *
 * The response envelope is:
 *
 *  - data.summary              — the seven Phase 16 metrics over the
 *    filtered window (impressions, completions, completion rate,
 *    average cart value, revenue influenced, suggestion CTR,
 *    suggestion add-to-cart rate)
 *  - data.trend                — one daily point per day of the window
 *    (impressions, completions, revenue) with zero-filled gaps
 *  - data.top_campaigns        — per-campaign impressions/completions/
 *    revenue/completion rate, ranked by completions
 *  - data.top_goals            — same shape, ranked per goal
 *  - data.top_suggested_products — per-product suggestion impressions/
 *    clicks/conversions plus CTR and add-to-cart rate
 *  - meta.applied              — the exact filters that produced the
 *    payload (the UI echoes them on the page)
 *
 * Admin-only (manage_options) and rate limited per user like every other
 * admin endpoint (P07-T04). All filters are validated through the REST
 * arg schema — dates through validate_datetime_param, the reward type
 * against the Reward::types() whitelist — before they reach the
 * repository, which binds every value with $wpdb->prepare.
 */
class AnalyticsController extends BaseController {

	/**
	 * Analytics repository instance.
	 *
	 * @var AnalyticsRepository
	 */
	protected $analytics;

	/**
	 * Constructor.
	 *
	 * @param AnalyticsRepository $analytics Metrics repository.
	 */
	public function __construct( AnalyticsRepository $analytics ) {
		$this->analytics = $analytics;
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
	 * Register the analytics routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/analytics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->analytics_args(),
			)
		);
	}

	/**
	 * Build the dashboard payload over the requested filters.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get( $request ) {
		$filters = array(
			'from'        => (string) $request->get_param( 'from' ),
			'to'          => (string) $request->get_param( 'to' ),
			'campaign_id' => (int) $request->get_param( 'campaign_id' ),
			'goal_id'     => (int) $request->get_param( 'goal_id' ),
			'goal_ids'    => $request->get_param( 'goal_ids' ),
			'product_id'  => (int) $request->get_param( 'product_id' ),
			'reward_type' => (string) $request->get_param( 'reward' ),
		);

		// Defaults are applied here (not only by the REST server) so direct
		// handler calls behave like dispatched requests (same pattern as
		// GoalsController::handle_index).
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ?: 5 ) );

		$summary = array(
			'impressions'                 => $this->analytics->impressions( $filters ),
			'completions'                 => $this->analytics->completions( $filters ),
			'completion_rate'             => $this->analytics->completion_rate( $filters ),
			'average_cart_value'          => $this->analytics->average_cart_value( $filters ),
			'revenue_influenced'          => $this->analytics->revenue_associated_with_completed_goals( $filters ),
			'suggestion_ctr'              => $this->analytics->suggestion_ctr( $filters ),
			'suggestion_add_to_cart_rate' => $this->analytics->suggestion_add_to_cart_rate( $filters ),
		);

		return $this->success(
			array(
				'summary'              => $summary,
				'trend'                => $this->analytics->trend( $filters ),
				'top_campaigns'        => $this->analytics->top_campaigns( $filters, $limit ),
				'top_goals'            => $this->analytics->top_goals( $filters, $limit ),
				'top_suggested_products' => $this->analytics->top_suggested_products( $filters, $limit ),
			),
			array(
				'applied' => array(
					'from'        => $filters['from'],
					'to'          => $filters['to'],
					'campaign_id' => $filters['campaign_id'],
					'goal_id'     => $filters['goal_id'],
					'goal_ids'    => $filters['goal_ids'],
					'product_id'  => $filters['product_id'],
					'reward'      => $filters['reward_type'],
					'limit'       => $limit,
				),
			)
		);
	}

	/**
	 * Arg schema for the analytics route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function analytics_args() {
		return array(
			'from'        => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'to'          => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'campaign_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'goal_id'     => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'goal_ids'    => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'reward'      => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array_merge( array( '' ), Reward::types() ),
			),
			'product_id'  => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'limit'       => array(
				'type'    => 'integer',
				'default' => 5,
				'minimum' => 1,
				'maximum' => 20,
			),
		);
	}
}
