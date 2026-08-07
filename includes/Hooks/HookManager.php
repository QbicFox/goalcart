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
