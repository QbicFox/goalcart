<?php
/**
 * Goal engine facade.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class GoalEngine
 *
 * The central calculation engine (P04-T02 pipeline entry point). Runs the
 * goal through its evaluator and returns a GoalResult. The engine is
 * stateless and UI-independent — it never renders anything, never touches
 * the database, and never reads request state; callers supply the Goal and
 * CartContext.
 *
 * Pre-evaluation eligibility:
 *  - goal status must be 'active'
 *  - the current time must be inside the goal's schedule window (if any)
 *  - the target must not be negative (zero is a valid, trivially completed
 *    goal; a negative target is a configuration error)
 */
final class GoalEngine {

	/**
	 * Evaluator registry.
	 *
	 * @var GoalEvaluatorRegistry
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * @param GoalEvaluatorRegistry|null $registry Evaluator registry.
	 */
	public function __construct( ?GoalEvaluatorRegistry $registry = null ) {
		$this->registry = null !== $registry ? $registry : new GoalEvaluatorRegistry();
	}

	/**
	 * Evaluate a goal against a cart context.
	 *
	 * @param Goal        $goal    Goal to evaluate.
	 * @param CartContext $context Cart snapshot.
	 * @param string|null $now     Current time 'Y-m-d H:i:s' (site tz) for
	 *                             schedule checks; defaults to current_time().
	 * @return GoalResult
	 * @throws \InvalidArgumentException When the goal type has no evaluator.
	 */
	public function evaluate( Goal $goal, CartContext $context, $now = null ) {
		$reason = self::eligibility_reason( $goal, $now );

		if ( GoalResult::REASON_NONE !== $reason ) {
			return GoalResult::ineligible( $goal, $reason );
		}

		if ( ! $this->registry->supports( $goal->type() ) ) {
			return GoalResult::ineligible( $goal, GoalResult::REASON_UNKNOWN_TYPE );
		}

		return $this->registry->evaluator( $goal->type() )->evaluate( $goal, $context );
	}

	/**
	 * Shared eligibility pre-checks (status, schedule, target validity).
	 *
	 * Exposed statically so composite children are held to the same rules
	 * as top-level goals without routing them through a GoalEngine
	 * instance. Returns REASON_NONE when the goal is eligible.
	 *
	 * @param Goal        $goal Goal.
	 * @param string|null $now  Reference time, defaults to current_time( 'mysql' ).
	 * @return string GoalResult::REASON_* constant.
	 */
	public static function eligibility_reason( Goal $goal, $now = null ) {
		if ( ! $goal->is_active() ) {
			return GoalResult::REASON_GOAL_INACTIVE;
		}

		if ( ! self::is_in_schedule( $goal, $now ) ) {
			return GoalResult::REASON_OUT_OF_SCHEDULE;
		}

		if ( $goal->target() < 0 ) {
			return GoalResult::REASON_INVALID_TARGET;
		}

		return GoalResult::REASON_NONE;
	}

	/**
	 * Whether the goal's schedule window contains the given time.
	 *
	 * @param Goal        $goal Goal.
	 * @param string|null $now  Reference time, defaults to current_time( 'mysql' ).
	 * @return bool
	 */
	protected static function is_in_schedule( Goal $goal, $now = null ) {
		$starts_at = $goal->starts_at();
		$ends_at   = $goal->ends_at();

		if ( empty( $starts_at ) && empty( $ends_at ) ) {
			return true;
		}

		$now = null === $now ? current_time( 'mysql' ) : (string) $now;

		if ( ! empty( $starts_at ) && $now < $starts_at ) {
			return false;
		}

		if ( ! empty( $ends_at ) && $now > $ends_at ) {
			return false;
		}

		return true;
	}

	/**
	 * The evaluator registry (exposed for extension).
	 *
	 * @return GoalEvaluatorRegistry
	 */
	public function registry() {
		return $this->registry;
	}
}
