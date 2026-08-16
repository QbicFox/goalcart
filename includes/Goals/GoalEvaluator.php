<?php
/**
 * Goal evaluator interface.
 *
 * @package FaraCart
 */

namespace FaraCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Interface GoalEvaluator
 *
 * Every goal type is evaluated by a dedicated, stateless evaluator that
 * reads a CartContext and produces a GoalResult (P04-T02 pipeline step 2).
 * Evaluators are resolved through the GoalEvaluatorRegistry, which is
 * filterable so stores can add custom goal types.
 */
interface GoalEvaluator {

	/**
	 * Whether this evaluator handles the given goal type.
	 *
	 * @param string $type Goal type (Goal::TYPE_*).
	 * @return bool
	 */
	public function supports( $type );

	/**
	 * Evaluate a goal against a cart context.
	 *
	 * @param Goal        $goal    Goal to evaluate.
	 * @param CartContext $context Cart snapshot.
	 * @return GoalResult
	 */
	public function evaluate( Goal $goal, CartContext $context );
}
