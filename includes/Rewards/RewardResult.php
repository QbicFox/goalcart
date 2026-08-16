<?php
/**
 * Reward evaluation result for the FaraCart engine.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardResult
 *
 * The consistent output of every reward evaluation (P05-T02). Produced by
 * the RewardEngine from a GoalResult plus the goal's Reward configuration.
 * Immutable once built.
 *
 * State semantics:
 *  - not_applicable  no reward applies (goal ineligible, no reward configured,
 *                    or unknown reward type)
 *  - locked          goal eligible but target not reached — reward stays locked
 *  - available       target reached and the reward may be granted
 *  - applied         the reward has actually been applied to the live cart
 *                    (used by the WooCommerce sync; reserved for analytics)
 *  - blocked         target reached but a safety rule prevents granting
 *                    (stacking/duplicate, invalid coupon, unavailable gift)
 */
final class RewardResult {

	/**
	 * Reward states.
	 */
	const STATE_NOT_APPLICABLE = 'not_applicable';
	const STATE_LOCKED         = 'locked';
	const STATE_AVAILABLE      = 'available';
	const STATE_APPLIED        = 'applied';
	const STATE_BLOCKED        = 'blocked';

	/**
	 * Blocking / ineligibility reasons.
	 */
	const REASON_NONE             = '';
	const REASON_GOAL_INELIGIBLE  = 'goal_ineligible';
	const REASON_NO_REWARD        = 'no_reward';
	const REASON_UNKNOWN_TYPE     = 'unknown_type';
	const REASON_STACKING         = 'stacking';
	const REASON_DUPLICATE        = 'duplicate';
	const REASON_INVALID_COUPON   = 'invalid_coupon';
	const REASON_GIFT_UNAVAILABLE = 'gift_unavailable';

	/**
	 * The reward that was evaluated.
	 *
	 * @var Reward
	 */
	protected $reward;

	/**
	 * One of the STATE_* constants.
	 *
	 * @var string
	 */
	protected $state;

	/**
	 * Goal id the reward belongs to (0 for anonymous goals).
	 *
	 * @var int
	 */
	protected $goal_id;

	/**
	 * REASON_* constant when not available.
	 *
	 * @var string
	 */
	protected $reason;

	/**
	 * Computed reward value (discount amount; 0 for shipping/gift/coupon).
	 *
	 * @var float
	 */
	protected $amount;

	/**
	 * Type-specific payload for the UI/API (gift product, coupon code, …).
	 *
	 * @var array<string, mixed>
	 */
	protected $meta;

	/**
	 * Build a result.
	 *
	 * @param Reward              $reward Reward.
	 * @param string              $state  STATE_* constant.
	 * @param int                 $goal_id Goal id.
	 * @param string              $reason REASON_* constant.
	 * @param float               $amount Computed reward value.
	 * @param array<string, mixed> $meta   Extra payload.
	 */
	protected function __construct( Reward $reward, $state, $goal_id, $reason = self::REASON_NONE, $amount = 0.0, array $meta = array() ) {
		$this->reward  = $reward;
		$this->state   = (string) $state;
		$this->goal_id = (int) $goal_id;
		$this->reason  = (string) $reason;
		$this->amount  = (float) $amount;
		$this->meta    = $meta;
	}

	/**
	 * No reward applies.
	 *
	 * @param Reward $reward  Reward.
	 * @param int    $goal_id Goal id.
	 * @param string $reason  REASON_* constant.
	 * @return RewardResult
	 */
	public static function not_applicable( Reward $reward, $goal_id, $reason = self::REASON_GOAL_INELIGIBLE ) {
		return new self( $reward, self::STATE_NOT_APPLICABLE, $goal_id, $reason );
	}

	/**
	 * Goal eligible but target not reached.
	 *
	 * @param Reward $reward  Reward.
	 * @param int    $goal_id Goal id.
	 * @return RewardResult
	 */
	public static function locked( Reward $reward, $goal_id ) {
		return new self( $reward, self::STATE_LOCKED, $goal_id );
	}

	/**
	 * Target reached; the reward can be granted.
	 *
	 * @param Reward              $reward  Reward.
	 * @param int                 $goal_id Goal id.
	 * @param float               $amount  Computed reward value.
	 * @param array<string, mixed> $meta    Extra payload.
	 * @return RewardResult
	 */
	public static function available( Reward $reward, $goal_id, $amount = 0.0, array $meta = array() ) {
		return new self( $reward, self::STATE_AVAILABLE, $goal_id, self::REASON_NONE, $amount, $meta );
	}

	/**
	 * The reward has been applied to the live cart.
	 *
	 * @param Reward              $reward  Reward.
	 * @param int                 $goal_id Goal id.
	 * @param float               $amount  Computed reward value.
	 * @param array<string, mixed> $meta    Extra payload.
	 * @return RewardResult
	 */
	public static function applied( Reward $reward, $goal_id, $amount = 0.0, array $meta = array() ) {
		return new self( $reward, self::STATE_APPLIED, $goal_id, self::REASON_NONE, $amount, $meta );
	}

	/**
	 * Target reached but a safety rule prevents granting.
	 *
	 * @param Reward $reward  Reward.
	 * @param int    $goal_id Goal id.
	 * @param string $reason  REASON_* constant.
	 * @return RewardResult
	 */
	public static function blocked( Reward $reward, $goal_id, $reason ) {
		return new self( $reward, self::STATE_BLOCKED, $goal_id, $reason );
	}

	/**
	 * @return Reward
	 */
	public function reward() {
		return $this->reward;
	}

	/**
	 * @return string
	 */
	public function type() {
		return $this->reward->type();
	}

	/**
	 * @return string
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * @return int
	 */
	public function goal_id() {
		return $this->goal_id;
	}

	/**
	 * @return string
	 */
	public function reason() {
		return $this->reason;
	}

	/**
	 * @return float
	 */
	public function amount() {
		return $this->amount;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function meta() {
		return $this->meta;
	}

	/**
	 * Whether the reward is currently granted (available or applied).
	 *
	 * @return bool
	 */
	public function is_active() {
		return self::STATE_AVAILABLE === $this->state || self::STATE_APPLIED === $this->state;
	}

	/**
	 * Serializable array form (used by the REST/JS layer in later phases).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'type'    => $this->type(),
			'state'   => $this->state,
			'goal_id' => $this->goal_id,
			'reason'  => $this->reason,
			'amount'  => $this->amount,
			'meta'    => $this->meta,
			'reward'  => $this->reward->to_array(),
		);
	}
}
