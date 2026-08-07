<?php
/**
 * Goal evaluation result for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Goals;

defined( 'ABSPATH' ) || exit;

/**
 * Class GoalResult
 *
 * The consistent output of every evaluator (P04-T04). Always contains:
 * current, target, remaining, percentage, completed, reward_state,
 * eligible, and a reason when not eligible. Immutable once built.
 *
 * reward_state semantics (Phase 5 activates the reward):
 *  - not_applicable  goal is not eligible for this cart/shopper
 *  - locked          eligible, target not reached yet
 *  - unlocked        eligible and target reached — the reward engine may
 *                    now activate the reward
 */
final class GoalResult {

	/**
	 * Reward states.
	 */
	const REWARD_NOT_APPLICABLE = 'not_applicable';
	const REWARD_LOCKED         = 'locked';
	const REWARD_UNLOCKED       = 'unlocked';

	/**
	 * Eligibility reasons.
	 */
	const REASON_NONE              = '';
	const REASON_GOAL_INACTIVE     = 'goal_inactive';
	const REASON_OUT_OF_SCHEDULE   = 'out_of_schedule';
	const REASON_INVALID_TARGET    = 'invalid_target';
	const REASON_NO_MATCHING_ITEMS = 'no_matching_items';
	const REASON_UNKNOWN_TYPE      = 'unknown_type';

	/**
	 * The goal that was evaluated.
	 *
	 * @var Goal
	 */
	protected $goal;

	/**
	 * Current value for the goal's basis.
	 *
	 * @var float
	 */
	protected $current;

	/**
	 * The goal threshold.
	 *
	 * @var float
	 */
	protected $target;

	/**
	 * target - current, never negative.
	 *
	 * @var float
	 */
	protected $remaining;

	/**
	 * 0-100, capped at 100.
	 *
	 * @var float
	 */
	protected $percentage;

	/**
	 * @var bool
	 */
	protected $completed;

	/**
	 * One of the REWARD_* constants.
	 *
	 * @var string
	 */
	protected $reward_state;

	/**
	 * @var bool
	 */
	protected $eligible;

	/**
	 * REASON_* constant when not eligible.
	 *
	 * @var string
	 */
	protected $reason;

	/**
	 * Build a completed evaluation.
	 *
	 * @param Goal  $goal    Goal.
	 * @param float $current Current value.
	 * @param float $target  Target value.
	 */
	public function __construct( Goal $goal, $current, $target ) {
		$this->goal    = $goal;
		$this->target  = max( 0.0, (float) $target );
		$this->current = max( 0.0, (float) $current );

		$this->remaining  = ProgressCalculator::remaining( $this->current, $this->target );
		$this->percentage = ProgressCalculator::percentage( $this->current, $this->target );
		$this->completed  = ProgressCalculator::completed( $this->current, $this->target );

		$this->eligible    = true;
		$this->reason      = self::REASON_NONE;
		$this->reward_state = $this->completed ? self::REWARD_UNLOCKED : self::REWARD_LOCKED;
	}

	/**
	 * Build a result with explicit percentage/completion overrides.
	 *
	 * Used by the composite evaluator, where progress is derived from
	 * child goals rather than the ratio of the sums (AND = weakest child,
	 * OR = best child).
	 *
	 * @param Goal   $goal       Goal.
	 * @param float  $current    Current value.
	 * @param float  $target     Target value.
	 * @param float  $percentage Overridden percentage (0-100).
	 * @param bool   $completed  Overridden completion flag.
	 * @return GoalResult
	 */
	public static function build( Goal $goal, $current, $target, $percentage, $completed ) {
		$result = new self( $goal, $current, $target );

		$result->percentage = round( max( 0.0, min( 100.0, (float) $percentage ) ), 2 );
		$result->completed  = (bool) $completed;
		$result->reward_state = $result->completed ? self::REWARD_UNLOCKED : self::REWARD_LOCKED;

		return $result;
	}

	/**
	 * Build an ineligible result (goal does not apply to this cart/shopper).
	 *
	 * @param Goal   $goal   Goal.
	 * @param string $reason REASON_* constant.
	 * @return GoalResult
	 */
	public static function ineligible( Goal $goal, $reason = self::REASON_NONE ) {
		$result              = new self( $goal, 0, $goal->target() );
		$result->eligible    = false;
		$result->reason      = (string) $reason;
		$result->completed   = false;
		$result->remaining   = max( 0.0, $result->target );
		$result->percentage  = 0.0;
		$result->reward_state = self::REWARD_NOT_APPLICABLE;

		return $result;
	}

	/**
	 * @return Goal
	 */
	public function goal() {
		return $this->goal;
	}

	/**
	 * @return float
	 */
	public function current() {
		return $this->current;
	}

	/**
	 * @return float
	 */
	public function target() {
		return $this->target;
	}

	/**
	 * @return float
	 */
	public function remaining() {
		return $this->remaining;
	}

	/**
	 * @return float
	 */
	public function percentage() {
		return $this->percentage;
	}

	/**
	 * @return bool
	 */
	public function completed() {
		return $this->completed;
	}

	/**
	 * @return string
	 */
	public function reward_state() {
		return $this->reward_state;
	}

	/**
	 * @return bool
	 */
	public function eligible() {
		return $this->eligible;
	}

	/**
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}

	/**
	 * Serializable array form (used by the REST/JS layer in later phases).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'goal_id'      => $this->goal->id(),
			'goal_type'    => $this->goal->type(),
			'current'      => $this->current,
			'target'       => $this->target,
			'remaining'    => $this->remaining,
			'percentage'   => $this->percentage,
			'completed'    => $this->completed,
			'reward_state' => $this->reward_state,
			'eligible'     => $this->eligible,
			'reason'       => $this->reason,
		);
	}
}
