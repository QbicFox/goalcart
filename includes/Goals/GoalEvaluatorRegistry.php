<?php
/**
 * Goal evaluator registry.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

use GoalCart\Goals\Evaluators\AmountEvaluator;
use GoalCart\Goals\Evaluators\CategoryEvaluator;
use GoalCart\Goals\Evaluators\CompositeEvaluator;
use GoalCart\Goals\Evaluators\DistinctQuantityEvaluator;
use GoalCart\Goals\Evaluators\ProductEvaluator;
use GoalCart\Goals\Evaluators\QuantityEvaluator;
use GoalCart\Goals\Evaluators\WeightEvaluator;

defined( 'ABSPATH' ) || exit;

/**
 * Class GoalEvaluatorRegistry
 *
 * Maps goal types to evaluator classes and resolves them lazily. The class
 * map is filterable through 'goalcart_goal_evaluator_classes' so stores can
 * register custom goal types without touching the core.
 */
class GoalEvaluatorRegistry {

	/**
	 * Default type => evaluator class map.
	 *
	 * @return array<string, string>
	 */
	protected function default_classes() {
		return array(
			Goal::TYPE_AMOUNT            => AmountEvaluator::class,
			Goal::TYPE_QUANTITY          => QuantityEvaluator::class,
			Goal::TYPE_DISTINCT_QUANTITY => DistinctQuantityEvaluator::class,
			Goal::TYPE_CATEGORY          => CategoryEvaluator::class,
			Goal::TYPE_PRODUCT           => ProductEvaluator::class,
			Goal::TYPE_WEIGHT            => WeightEvaluator::class,
			Goal::TYPE_COMPOSITE         => CompositeEvaluator::class,
		);
	}

	/**
	 * Resolved evaluator instances, cached per type.
	 *
	 * @var array<string, GoalEvaluator>
	 */
	protected $cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_defaults();
	}

	/**
	 * Register the built-in evaluators, honoring the extension filter.
	 *
	 * @return void
	 */
	protected function register_defaults() {
		$classes = $this->default_classes();

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the evaluator class map.
			 *
			 * @param array<string, string> $classes Goal type => evaluator class.
			 */
			$classes = apply_filters( 'goalcart_goal_evaluator_classes', $classes );
		}

		foreach ( $classes as $type => $class ) {
			if ( is_string( $class ) && class_exists( $class ) ) {
				$this->cache[ $type ] = $this->instantiate( $class );
			}
		}
	}

	/**
	 * Instantiate an evaluator, injecting the registry into composite
	 * evaluators so they can resolve child goals.
	 *
	 * @param string $class Evaluator class name.
	 * @return GoalEvaluator
	 */
	protected function instantiate( $class ) {
		if ( CompositeEvaluator::class === $class || is_subclass_of( $class, CompositeEvaluator::class ) ) {
			return new $class( $this );
		}

		return new $class();
	}

	/**
	 * Resolve the evaluator for a goal type.
	 *
	 * @param string $type Goal type.
	 * @return GoalEvaluator
	 * @throws \InvalidArgumentException When no evaluator is registered.
	 */
	public function evaluator( $type ) {
		$type = (string) $type;

		if ( ! isset( $this->cache[ $type ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'No goal evaluator registered for type "%s".', $type )
			);
		}

		return $this->cache[ $type ];
	}

	/**
	 * Whether an evaluator is registered for the given type.
	 *
	 * @param string $type Goal type.
	 * @return bool
	 */
	public function supports( $type ) {
		return isset( $this->cache[ (string) $type ] );
	}

	/**
	 * All registered goal types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array_keys( $this->cache );
	}
}
