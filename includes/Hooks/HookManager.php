<?php
/**
 * Action and filter hook management for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Class HookManager
 *
 * Components declare their WordPress hooks by implementing a register()
 * method that receives this manager. The manager buffers the declarations
 * and applies them to WordPress with a single run() call, keeping every
 * hook registration in one place.
 *
 * Mirrors the reference plugin (WooInsights\Hooks\HookManager) exactly.
 */
class HookManager {

	/**
	 * Buffered action registrations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $actions = array();

	/**
	 * Buffered filter registrations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $filters = array();

	/**
	 * Register an action.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return $this
	 */
	public function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return $this;
	}

	/**
	 * Register a filter.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return $this
	 */
	public function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = array(
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return $this;
	}

	/**
	 * The plugin's public developer hooks (Advanced → developer hooks).
	 *
	 * A reference list of the documented faracart_* actions and filters
	 * surfaced in the admin Settings page (and served in the settings REST
	 * meta) so theme/plugin developers can see the extension surface
	 * without digging through the source.
	 *
	 * @return array<int, array{type: string, hook: string, description: string}>
	 */
	public static function documented_hooks() {
		return array(
			array( 'type' => 'action', 'hook' => 'faracart_loaded', 'description' => __( 'Fires after the plugin has fully bootstrapped.', 'faracart' ) ),
			array( 'type' => 'action', 'hook' => 'faracart_settings_saved', 'description' => __( 'Fires after settings are persisted through the REST API.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_rest_capability', 'description' => __( 'Capability required for the admin REST endpoints.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_admin_capability', 'description' => __( 'Capability required for the admin menu page.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_enabled', 'description' => __( 'Master storefront widget toggle.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_locations', 'description' => __( 'Enabled widget display locations.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_position', 'description' => __( 'Page widget position (top|bottom).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_template', 'description' => __( 'Store-wide widget template variant.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_animation', 'description' => __( 'Storefront progress-bar animation flag.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_mobile', 'description' => __( 'Storefront mobile behavior (show|hide).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_refresh_interval', 'description' => __( 'Widget poll interval in seconds.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_tracking_enabled', 'description' => __( 'Analytics tracking consent for the current request.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_suggestions_enabled', 'description' => __( 'Whether product suggestions render on the storefront.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_default_calculation_mode', 'description' => __( 'Store-wide default money calculation basis.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_suggestions', 'description' => __( 'The shaped suggestion items for a mission.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_template_classes', 'description' => __( 'The progress template class map (id => Template class). Register a new Mission or Campaign template here.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_tracking_enabled', 'description' => __( 'Whether the Phase 33 revenue event pipeline records events.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_retention_days', 'description' => __( 'Retention window (days) for the revenue/upsell event logs before the weekly cleanup purges them.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_enabled', 'description' => __( 'Whether Phase 33.2 revenue attribution (order association) is on.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_product_cost', 'description' => __( 'Product cost used by the Phase 33.2 reward-cost / profit estimation (null = no cost data).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_order_cost_snapshot', 'description' => __( 'The order-item unit-cost snapshot written at checkout (UPSELL_REFACTOR §21) — return a float to stamp it, null to leave the line without a snapshot.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_metric_rows', 'description' => __( 'Row cap for the bounded Phase 33.2 metric reads.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_order_scan_pages', 'description' => __( 'Page cap for the Phase 33.2 store-wide order scans (AOV, shipping).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_logging_enabled', 'description' => __( 'Master switch for the debug log file (default off; FARACART_LOGGING constant wins).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_debug_mode', 'description' => __( 'Whether debug-level entries are written to the debug log (default off; FARACART_DEBUG constant wins).', 'faracart' ) ),
			array( 'type' => 'action', 'hook' => 'faracart_missions_changed', 'description' => __( 'Fires after a mission is created, updated or deleted (cache invalidation).', 'faracart' ) ),
			array( 'type' => 'action', 'hook' => 'faracart_revenue_aggregated', 'description' => __( 'Fires after the Phase 33.3 daily aggregation run completes.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_aggregate_max_days', 'description' => __( 'Max days the Phase 33.3 aggregation job processes per run.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_aggregate_lookback_days', 'description' => __( 'Lookback floor for the Phase 33.3 aggregation catch-up window.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_cache_enabled', 'description' => __( 'Whether the Phase 33.3 revenue summary cache is on.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_cache_ttl', 'description' => __( 'TTL (seconds) for the Phase 33.3 revenue summary transients.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendations_enabled', 'description' => __( 'Whether Phase 33.4 smart mission recommendations are on.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_min_orders', 'description' => __( 'Minimum order count before Phase 33.4 recommendations are generated.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_candidates', 'description' => __( 'The candidate thresholds generated for Phase 33.4 scoring.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_weights', 'description' => __( 'The Phase 33.4 composite scoring weights (reachability/distance/economics/history).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_margin_products', 'description' => __( 'Product sample size for the Phase 33.4 margin analyzer.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendations', 'description' => __( 'The full Phase 33.4 recommendation payload before it is served.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_cache_ttl', 'description' => __( 'TTL (seconds) for the Phase 33.4 recommendation cache.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsells_enabled', 'description' => __( 'Whether Phase 33.5 smart upsell ranking is on.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsell_candidates', 'description' => __( 'The candidate product ids collected for Phase 33.5 upsell scoring.', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsell_weights', 'description' => __( 'The Phase 33.5 composite scoring weights (price_gap/relevance/popularity/inventory/margin/conversion).', 'faracart' ) ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsells', 'description' => __( 'The full Phase 33.5 upsell ranking payload before it is served.', 'faracart' ) ),
		);
	}

	/**
	 * Let a component register its own hooks.
	 *
	 * Calls $component->register( $this ) when the method exists.
	 *
	 * @param object $component Component instance.
	 * @return $this
	 */
	public function register( $component ) {
		if ( is_object( $component ) && method_exists( $component, 'register' ) ) {
			$component->register( $this );
		}

		return $this;
	}

	/**
	 * Apply all buffered hooks to WordPress and clear the buffer.
	 *
	 * @return $this
	 */
	public function run() {
		foreach ( $this->actions as $action ) {
			add_action(
				$action['hook'],
				$action['callback'],
				$action['priority'],
				$action['accepted_args']
			);
		}

		foreach ( $this->filters as $filter ) {
			add_filter(
				$filter['hook'],
				$filter['callback'],
				$filter['priority'],
				$filter['accepted_args']
			);
		}

		$this->actions = array();
		$this->filters = array();

		return $this;
	}
}
