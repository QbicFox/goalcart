<?php
/**
 * Mission evaluator registry.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

use FaraCart\Missions\Evaluators\AmountEvaluator;
use FaraCart\Missions\Evaluators\AttributeEvaluator;
use FaraCart\Missions\Evaluators\CategoryEvaluator;
use FaraCart\Missions\Evaluators\CompositeEvaluator;
use FaraCart\Missions\Evaluators\DistinctQuantityEvaluator;
use FaraCart\Missions\Evaluators\ProductEvaluator;
use FaraCart\Missions\Evaluators\QuantityEvaluator;
use FaraCart\Missions\Evaluators\TagEvaluator;
use FaraCart\Missions\Evaluators\WeightEvaluator;

defined( 'ABSPATH' ) || exit;

/**
 * Class MissionEvaluatorRegistry
 *
 * Maps mission types to evaluator classes and resolves them lazily. The class
 * map is filterable through 'faracart_mission_evaluator_classes' so stores can
 * register custom mission types without touching the core.
 */
class MissionEvaluatorRegistry {

	/**
	 * Default type => evaluator class map.
	 *
	 * @return array<string, string>
	 */
	protected function default_classes() {
		return array(
			Mission::TYPE_AMOUNT            => AmountEvaluator::class,
			Mission::TYPE_QUANTITY          => QuantityEvaluator::class,
			Mission::TYPE_DISTINCT_QUANTITY => DistinctQuantityEvaluator::class,
			Mission::TYPE_CATEGORY          => CategoryEvaluator::class,
			Mission::TYPE_PRODUCT           => ProductEvaluator::class,
			Mission::TYPE_WEIGHT            => WeightEvaluator::class,
			Mission::TYPE_COMPOSITE         => CompositeEvaluator::class,
			// Phase 32 (tag/attribute conditions) — the category family
			// extended to tags and attribute taxonomies.
			Mission::TYPE_TAG               => TagEvaluator::class,
			Mission::TYPE_ATTRIBUTE         => AttributeEvaluator::class,
		);
	}

	/**
	 * Resolved evaluator instances, cached per type.
	 *
	 * @var array<string, MissionEvaluator>
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
			 * @param array<string, string> $classes Mission type => evaluator class.
			 */
			$classes = apply_filters( 'faracart_mission_evaluator_classes', $classes );
		}

		foreach ( $classes as $type => $class ) {
			if ( is_string( $class ) && class_exists( $class ) ) {
				$this->cache[ $type ] = $this->instantiate( $class );
			}
		}
	}

	/**
	 * Instantiate an evaluator, injecting the registry into composite
	 * evaluators so they can resolve child missions.
	 *
	 * @param string $class Evaluator class name.
	 * @return MissionEvaluator
	 */
	protected function instantiate( $class ) {
		if ( CompositeEvaluator::class === $class || is_subclass_of( $class, CompositeEvaluator::class ) ) {
			return new $class( $this );
		}

		return new $class();
	}

	/**
	 * Resolve the evaluator for a mission type.
	 *
	 * @param string $type Mission type.
	 * @return MissionEvaluator
	 * @throws \InvalidArgumentException When no evaluator is registered.
	 */
	public function evaluator( $type ) {
		$type = (string) $type;

		if ( ! isset( $this->cache[ $type ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'No mission evaluator registered for type "%s".', $type )
			);
		}

		return $this->cache[ $type ];
	}

	/**
	 * Whether an evaluator is registered for the given type.
	 *
	 * @param string $type Mission type.
	 * @return bool
	 */
	public function supports( $type ) {
		return isset( $this->cache[ (string) $type ] );
	}

	/**
	 * All registered mission types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array_keys( $this->cache );
	}
}
