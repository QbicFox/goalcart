<?php
/**
 * Mission evaluation result for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Missions;

defined( 'ABSPATH' ) || exit;

/**
 * Class MissionResult
 *
 * The consistent output of every evaluator (P04-T04). Always contains:
 * current, target, remaining, percentage, completed, reward_state,
 * eligible, and a reason when not eligible. Immutable once built.
 *
 * reward_state semantics (Phase 5 activates the reward):
 *  - not_applicable  mission is not eligible for this cart/shopper
 *  - locked          eligible, target not reached yet
 *  - unlocked        eligible and target reached — the reward engine may
 *                    now activate the reward
 */
final class MissionResult {

	/**
	 * Reward states.
	 */
	const REWARD_NOT_APPLICABLE = 'not_applicable';
	const REWARD_LOCKED         = 'locked';
	const REWARD_UNLOCKED       = 'unlocked';

	/**
	 * Eligibility reasons.
	 *
	 * Phase 32 (Advanced V2) adds the customer/order/cart/shipping
	 * condition reasons: customer_conditions (roles / customer state),
	 * first_order_only, vip_only, shipping_zone and cart_conditions
	 * (required coupons / minimum items).
	 */
	const REASON_NONE              = '';
	const REASON_MISSION_INACTIVE     = 'mission_inactive';
	const REASON_OUT_OF_SCHEDULE   = 'out_of_schedule';
	const REASON_INVALID_TARGET    = 'invalid_target';
	const REASON_NO_MATCHING_ITEMS = 'no_matching_items';
	const REASON_UNKNOWN_TYPE      = 'unknown_type';
	const REASON_CUSTOMER_CONDITIONS = 'customer_conditions';
	const REASON_FIRST_ORDER_ONLY    = 'first_order_only';
	const REASON_VIP_ONLY            = 'vip_only';
	const REASON_SHIPPING_ZONE       = 'shipping_zone';
	const REASON_CART_CONDITIONS     = 'cart_conditions';

	/**
	 * The mission that was evaluated.
	 *
	 * @var Mission
	 */
	protected $mission;

	/**
	 * Current value for the mission's basis.
	 *
	 * @var float
	 */
	protected $current;

	/**
	 * The mission threshold.
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
	 * @param Mission  $mission    Mission.
	 * @param float $current Current value.
	 * @param float $target  Target value.
	 */
	public function __construct( Mission $mission, $current, $target ) {
		$this->mission    = $mission;
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
	 * child missions rather than the ratio of the sums (AND = weakest child,
	 * OR = best child).
	 *
	 * @param Mission   $mission       Mission.
	 * @param float  $current    Current value.
	 * @param float  $target     Target value.
	 * @param float  $percentage Overridden percentage (0-100).
	 * @param bool   $completed  Overridden completion flag.
	 * @return MissionResult
	 */
	public static function build( Mission $mission, $current, $target, $percentage, $completed ) {
		$result = new self( $mission, $current, $target );

		$result->percentage = round( max( 0.0, min( 100.0, (float) $percentage ) ), 2 );
		$result->completed  = (bool) $completed;
		$result->reward_state = $result->completed ? self::REWARD_UNLOCKED : self::REWARD_LOCKED;

		return $result;
	}

	/**
	 * Build an ineligible result (mission does not apply to this cart/shopper).
	 *
	 * @param Mission   $mission   Mission.
	 * @param string $reason REASON_* constant.
	 * @return MissionResult
	 */
	public static function ineligible( Mission $mission, $reason = self::REASON_NONE ) {
		$result              = new self( $mission, 0, $mission->target() );
		$result->eligible    = false;
		$result->reason      = (string) $reason;
		$result->completed   = false;
		$result->remaining   = max( 0.0, $result->target );
		$result->percentage  = 0.0;
		$result->reward_state = self::REWARD_NOT_APPLICABLE;

		return $result;
	}

	/**
	 * @return Mission
	 */
	public function mission() {
		return $this->mission;
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
			'mission_id'      => $this->mission->id(),
			'mission_type'    => $this->mission->type(),
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
