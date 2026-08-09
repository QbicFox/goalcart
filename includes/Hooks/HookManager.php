<?php
/**
 * Action and filter hook management for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Hooks;

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
	 * A reference list of the documented goalcart_* actions and filters
	 * surfaced in the admin Settings page (and served in the settings REST
	 * meta) so theme/plugin developers can see the extension surface
	 * without digging through the source.
	 *
	 * @return array<int, array{type: string, hook: string, description: string}>
	 */
	public static function documented_hooks() {
		return array(
			array( 'type' => 'action', 'hook' => 'goalcart_loaded', 'description' => 'Fires after the plugin has fully bootstrapped.' ),
			array( 'type' => 'action', 'hook' => 'goalcart_settings_saved', 'description' => 'Fires after settings are persisted through the REST API.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_rest_capability', 'description' => 'Capability required for the admin REST endpoints.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_admin_capability', 'description' => 'Capability required for the admin menu page.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_enabled', 'description' => 'Master storefront widget toggle.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_locations', 'description' => 'Enabled widget display locations.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_template', 'description' => 'Store-wide widget template variant.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_animation', 'description' => 'Storefront progress-bar animation flag.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_mobile', 'description' => 'Storefront mobile behavior (show|hide).' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_currency_display', 'description' => 'Storefront currency display style (symbol|code|name).' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_frontend_refresh_interval', 'description' => 'Widget poll interval in seconds.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_tracking_enabled', 'description' => 'Analytics tracking consent for the current request.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_suggestions_enabled', 'description' => 'Whether product suggestions render on the storefront.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_default_calculation_mode', 'description' => 'Store-wide default money calculation basis.' ),
			array( 'type' => 'filter', 'hook' => 'goalcart_suggestions', 'description' => 'The shaped suggestion items for a goal.' ),
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
