<?php
/**
 * Composite goal evaluator.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals\Evaluators;

use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalEvaluator;
use GoalCart\Goals\GoalEvaluatorRegistry;
use GoalCart\Goals\GoalResult;

defined( 'ABSPATH' ) || exit;

/**
 * Class CompositeEvaluator
 *
 * Evaluates composite goals: AND/OR combinations of child goals, each child
 * defined as a Goal::from_array() payload and evaluated against the same
 * CartContext through the registry.
 *
 * Combination semantics (documented in docs/goal-engine.md):
 *  - AND: progress is the weakest child (min percentage); completed only
 *         when every child is complete. current/target are the sums of the
 *         children's values. An ineligible child keeps the goal incomplete.
 *  - OR:  progress is the best child (max percentage); completed as soon as
 *         any child completes. current/target mirror the best child.
 */
class CompositeEvaluator implements GoalEvaluator {

	/**
	 * Evaluator registry for resolving child goals.
	 *
	 * @var GoalEvaluatorRegistry
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * @param GoalEvaluatorRegistry $registry Registry used for children.
	 */
	public function __construct( GoalEvaluatorRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports( $type ) {
		return Goal::TYPE_COMPOSITE === $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function evaluate( Goal $goal, CartContext $context ) {
		$children = $goal->children();

		if ( empty( $children ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_NO_MATCHING_ITEMS );
		}

		$results   = array();
		$reasons   = array();

		foreach ( $children as $child_data ) {
			if ( ! is_array( $child_data ) ) {
				continue;
			}

			// Children inherit the parent's status unless they override it.
			if ( ! isset( $child_data['status'] ) ) {
				$child_data['status'] = $goal->status();
			}

			$child = new Goal( $child_data );

			// Children are held to the same eligibility rules as top-level
			// goals (status, schedule, target validity).
			$child_reason = GoalEngine::eligibility_reason( $child );

			if ( GoalResult::REASON_NONE !== $child_reason ) {
				$reasons[] = $child_reason;
				continue;
			}

			// Unsupported child types are treated as ineligible rather than
			// throwing, mirroring GoalEngine's own unknown-type handling.
			if ( ! $this->registry->supports( $child->type() ) ) {
				$reasons[] = GoalResult::REASON_UNKNOWN_TYPE;
				continue;
			}

			$result = $this->registry->evaluator( $child->type() )->evaluate( $child, $context );

			if ( $result->eligible() ) {
				$results[] = $result;
			} else {
				$reasons[] = $result->reason();
			}
		}

		if ( empty( $results ) ) {
			return GoalResult::ineligible(
				$goal,
				! empty( $reasons ) ? $reasons[0] : GoalResult::REASON_NO_MATCHING_ITEMS
			);
		}

		if ( $goal->is_operator_or() ) {
			// OR: the best child drives progress and completion.
			$best = $results[0];
			foreach ( $results as $result ) {
				if ( $result->percentage() > $best->percentage() ) {
					$best = $result;
				}
			}

			return GoalResult::build( $goal, $best->current(), $best->target(), $best->percentage(), $best->completed() );
		}

		// AND: weakest child drives progress; every child must complete.
		// An ineligible child keeps the goal incomplete (per the docs).
		$weakest     = $results[0];
		$current_sum = 0.0;
		$target_sum  = 0.0;
		$all_done    = empty( $reasons );

		foreach ( $results as $result ) {
			$current_sum += $result->current();
			$target_sum  += $result->target();

			if ( $result->percentage() < $weakest->percentage() ) {
				$weakest = $result;
			}

			if ( ! $result->completed() ) {
				$all_done = false;
			}
		}

		return GoalResult::build( $goal, $current_sum, $target_sum, $weakest->percentage(), $all_done );
	}
}
