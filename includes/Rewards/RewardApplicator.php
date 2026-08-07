<?php
/**
 * Reward applicator contract for the Goal Cart engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Rewards;

use GoalCart\Goals\CartContext;
use GoalCart\Goals\GoalResult;

defined( 'ABSPATH' ) || exit;

/**
 * Interface RewardApplicator
 *
 * One stateless applicator per reward type (P05-T02). Each applicator:
 *
 *  - declares which reward type it handles (supports()),
 *  - evaluates a GoalResult against the reward config and returns a
 *    RewardResult — this is the pure, WooCommerce-independent step that
 *    tests and headless callers use (evaluate()),
 *  - applies the reward to the live WC cart idempotently (apply()).
 *
 * Reversal (coupons, automatic gifts) is session-tracked by the
 * RewardEngine rather than part of this contract: the engine knows which
 * rewards it previously granted and removes exactly those.
 */
interface RewardApplicator {

	/**
	 * Whether this applicator handles the given reward type.
	 *
	 * @param string $type Reward::TYPE_* constant.
	 * @return bool
	 */
	public function supports( $type );

	/**
	 * Evaluate the reward for a completed (or in-progress) goal.
	 *
	 * Pure: no database access, no cart mutation. When a CartContext is
	 * supplied, computed values (e.g. discount amounts) are attached to the
	 * RewardResult.
	 *
	 * @param Reward           $reward  Reward configuration.
	 * @param GoalResult       $result  Goal evaluation result.
	 * @param CartContext|null $context Optional cart snapshot.
	 * @return RewardResult
	 */
	public function evaluate( Reward $reward, GoalResult $result, ?CartContext $context = null );

	/**
	 * Apply the reward to the live cart (idempotent).
	 *
	 * Called only for available rewards, inside the WooCommerce totals
	 * pipeline. Must be safe to call repeatedly and must never throw.
	 *
	 * @param Reward       $reward     Reward configuration.
	 * @param RewardResult $evaluation Pre-computed evaluation (amount, meta).
	 * @param \WC_Cart     $cart       Live cart.
	 * @param int          $goal_id    Goal id the reward belongs to.
	 * @return bool Whether the reward was (or already is) applied.
	 */
	public function apply( Reward $reward, RewardResult $evaluation, \WC_Cart $cart, $goal_id );
}
