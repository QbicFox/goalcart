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
	 * The plugin's public developer hooks (Phase 18 → Advanced → developer
	 * hooks).
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
			array( 'type' => 'action', 'hook' => 'faracart_loaded', 'description' => 'Fires after the plugin has fully bootstrapped.' ),
			array( 'type' => 'action', 'hook' => 'faracart_settings_saved', 'description' => 'Fires after settings are persisted through the REST API.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_rest_capability', 'description' => 'Capability required for the admin REST endpoints.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_admin_capability', 'description' => 'Capability required for the admin menu page.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_enabled', 'description' => 'Master storefront widget toggle.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_locations', 'description' => 'Enabled widget display locations.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_position', 'description' => 'Page widget position (top|bottom).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_template', 'description' => 'Store-wide widget template variant.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_animation', 'description' => 'Storefront progress-bar animation flag.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_mobile', 'description' => 'Storefront mobile behavior (show|hide).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_currency', 'description' => 'Resolved display currency unit (uppercase ISO-4217 code) — the currency every FaraCart amount is labelled with.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_currency_display', 'description' => 'Storefront currency display style (symbol|code|name).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_frontend_refresh_interval', 'description' => 'Widget poll interval in seconds.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_tracking_enabled', 'description' => 'Analytics tracking consent for the current request.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_suggestions_enabled', 'description' => 'Whether product suggestions render on the storefront.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_default_calculation_mode', 'description' => 'Store-wide default money calculation basis.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_suggestions', 'description' => 'The shaped suggestion items for a mission.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_template_classes', 'description' => 'The progress template class map (id => Template class). Register a new Mission or Campaign template here.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_tracking_enabled', 'description' => 'Whether the Phase 33 revenue event pipeline records events.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_retention_days', 'description' => 'Retention window (days) for the revenue/upsell event logs before the weekly cleanup purges them.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_enabled', 'description' => 'Whether Phase 33.2 revenue attribution (order association) is on.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_product_cost', 'description' => 'Product cost used by the Phase 33.2 reward-cost / profit estimation (null = no cost data).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_order_cost_snapshot', 'description' => 'The order-item unit-cost snapshot written at checkout (UPSELL_REFACTOR §21) — return a float to stamp it, null to leave the line without a snapshot.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_metric_rows', 'description' => 'Row cap for the bounded Phase 33.2 metric reads.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_attribution_order_scan_pages', 'description' => 'Page cap for the Phase 33.2 store-wide order scans (AOV, shipping).' ),
			array( 'type' => 'action', 'hook' => 'faracart_missions_changed', 'description' => 'Fires after a mission is created, updated or deleted (cache invalidation).' ),
			array( 'type' => 'action', 'hook' => 'faracart_revenue_aggregated', 'description' => 'Fires after the Phase 33.3 daily aggregation run completes.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_aggregate_max_days', 'description' => 'Max days the Phase 33.3 aggregation job processes per run.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_aggregate_lookback_days', 'description' => 'Lookback floor for the Phase 33.3 aggregation catch-up window.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_cache_enabled', 'description' => 'Whether the Phase 33.3 revenue summary cache is on.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_revenue_cache_ttl', 'description' => 'TTL (seconds) for the Phase 33.3 revenue summary transients.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendations_enabled', 'description' => 'Whether Phase 33.4 smart mission recommendations are on.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_min_orders', 'description' => 'Minimum order count before Phase 33.4 recommendations are generated.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_candidates', 'description' => 'The candidate thresholds generated for Phase 33.4 scoring.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_weights', 'description' => 'The Phase 33.4 composite scoring weights (reachability/distance/economics/history).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_margin_products', 'description' => 'Product sample size for the Phase 33.4 margin analyzer.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendations', 'description' => 'The full Phase 33.4 recommendation payload before it is served.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_recommendation_cache_ttl', 'description' => 'TTL (seconds) for the Phase 33.4 recommendation cache.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsells_enabled', 'description' => 'Whether Phase 33.5 smart upsell ranking is on.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsell_candidates', 'description' => 'The candidate product ids collected for Phase 33.5 upsell scoring.' ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsell_weights', 'description' => 'The Phase 33.5 composite scoring weights (price_gap/relevance/popularity/inventory/margin/conversion).' ),
			array( 'type' => 'filter', 'hook' => 'faracart_upsells', 'description' => 'The full Phase 33.5 upsell ranking payload before it is served.' ),
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
