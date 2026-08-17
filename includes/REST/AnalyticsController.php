<?php
/**
 * REST controller for the analytics dashboard.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Analytics\AnalyticsRepository;
use FaraCart\Analytics\RewardCostEstimator;
use FaraCart\Analytics\RevenueRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\Rewards\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Class AnalyticsController
 *
 * Phase 17 (Analytics Dashboard) — the single read-only endpoint powering
 * the admin dashboard page:
 *
 *  - `GET /faracart/v1/analytics` — summary KPIs, daily trend, and the
 *    top campaigns / top missions / top suggested products lists, all
 *    sliced by the same filter set (date range, campaign, mission, mission
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
 *  - data.top_missions            — same shape, ranked per mission
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
	 * Cached revenue repository — purchase/profit metrics for the extended
	 * summary (Phase 2).
	 *
	 * @var RevenueRepository
	 */
	protected $revenue;

	/**
	 * Constructor.
	 *
	 * @param AnalyticsRepository $analytics Metrics repository.
	 * @param RevenueRepository   $revenue   Cached revenue repository.
	 */
	public function __construct( AnalyticsRepository $analytics, RevenueRepository $revenue ) {
		$this->analytics = $analytics;
		$this->revenue   = $revenue;
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
			'mission_id'     => (int) $request->get_param( 'mission_id' ),
			'mission_ids'    => $request->get_param( 'mission_ids' ),
			'product_id'  => (int) $request->get_param( 'product_id' ),
			'reward_type' => (string) $request->get_param( 'reward' ),
		);

		// Defaults are applied here (not only by the REST server) so direct
		// handler calls behave like dispatched requests (same pattern as
		// MissionsController::handle_index).
		$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ?: 5 ) );

		$summary = array(
			'impressions'                 => $this->analytics->impressions( $filters ),
			'completions'                 => $this->analytics->completions( $filters ),
			'completion_rate'             => $this->analytics->completion_rate( $filters ),
			'average_cart_value'          => $this->analytics->average_cart_value( $filters ),
			'revenue_influenced'          => $this->analytics->revenue_associated_with_completed_missions( $filters ),
			'suggestion_ctr'              => $this->analytics->suggestion_ctr( $filters ),
			'suggestion_add_to_cart_rate' => $this->analytics->suggestion_add_to_cart_rate( $filters ),
		);

		// Phase 2 (Backend/Data Layer — Improvement.md §37/§38): the same
		// payload now also carries the purchase/profit metrics derived from
		// the existing attribution layer (same date range + mission filters,
		// cached through the revenue repository). Null when the active
		// filter cannot be expressed in attribution (product_id) — never a
		// fabricated number. Existing fields are untouched (API
		// compatibility).
		$summary = array_merge( $summary, $this->purchase_summary_fields( $this->revenue->purchase_summary( $filters ) ) );

		return $this->success(
			array(
				'summary'              => $summary,
				'trend'                => $this->analytics->trend( $filters ),
				'top_campaigns'        => $this->analytics->top_campaigns( $filters, $limit ),
				'top_missions'            => $this->analytics->top_missions( $filters, $limit ),
				'top_suggested_products' => $this->analytics->top_suggested_products( $filters, $limit ),
				// Phase 6 — per-mission purchase comparison rows (§27), same
				// shape as /revenue/missions, sliced by the same filters; null
				// when the active filter cannot be expressed in attribution.
				'mission_comparison'      => $this->revenue->mission_comparison( $filters ),
			),
			array(
				'applied' => array(
					'from'        => $filters['from'],
					'to'          => $filters['to'],
					'campaign_id' => $filters['campaign_id'],
					'mission_id'     => $filters['mission_id'],
					'mission_ids'    => $filters['mission_ids'],
					'product_id'  => $filters['product_id'],
					'reward'      => $filters['reward_type'],
					'limit'       => $limit,
				),
			)
		);
	}

	/**
	 * Map the attribution summary onto the extended analytics summary keys.
	 *
	 * @param array<string, mixed>|null $purchase Attribution summary (null
	 *                                             when the filter is
	 *                                             unsupported in attribution).
	 * @return array<string, mixed>
	 */
	protected function purchase_summary_fields( $purchase ) {
		if ( null === $purchase ) {
			return array(
				'progressed'         => null,
				'purchased_orders'   => null,
				'purchase_rate'      => null,
				'attributed_sales'   => null,
				'estimated_profit'   => null,
				'profit_available'   => false,
				'profit_reason'      => null,
				'profit_reason_code' => null,
				'cost_coverage'      => array(
					'attributed_orders'     => 0,
					'orders_with_cost_data' => 0,
					'coverage_pct'          => null,
					'available'             => false,
				),
				// Phase 3: the cost sources are a constant; the store-wide
				// signal is unknown for a filter attribution cannot express
				// (null — the UI must not claim "no cost data" here).
				'cost_sources'       => RewardCostEstimator::COST_SOURCES,
				'store_has_cost_data'=> null,
				'profit_details'     => null,
				// Phase 6 — the attribution funnel + assisted/influenced
				// revenue are also unexpressible for such filters: null.
				'funnel'             => null,
				'assisted_sales'     => null,
				'influenced_sales'   => null,
			);
		}

		return array(
			'progressed'         => (int) $purchase['funnel']['progressed'],
			'purchased_orders'   => (int) $purchase['orders'],
			'purchase_rate'      => $purchase['funnel']['conversion_rate'],
			'attributed_sales'   => round( (float) $purchase['mission_driven_revenue'], 4 ),
			'estimated_profit'   => $purchase['profit_impact'],
			'profit_available'   => (bool) $purchase['profit_available'],
			'profit_reason'      => $purchase['profit_reason'],
			'profit_reason_code' => $purchase['profit_reason_code'],
			'cost_coverage'      => $purchase['cost_coverage'],
			'cost_sources'       => $purchase['cost_sources'],
			'store_has_cost_data'=> (bool) $purchase['store_has_cost_data'],
			'profit_details'     => $purchase['profit_details'],
			// Phase 6 — the full attribution funnel (views → progressed →
			// completed → purchased) plus the assisted/influenced revenue
			// splits, so the analytics page renders the funnel (§23), the
			// completion-vs-purchase comparison (§25) and the advanced
			// attribution section (§30) from one self-consistent pipeline.
			'funnel'             => $purchase['funnel'],
			'assisted_sales'     => round( (float) $purchase['mission_assisted_revenue'], 4 ),
			'influenced_sales'   => round( (float) $purchase['mission_influenced_revenue'], 4 ),
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
			'mission_id'     => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'mission_ids'    => array(
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
