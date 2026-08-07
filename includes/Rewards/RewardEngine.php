<?php
/**
 * Reward engine facade for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Rewards;

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardEngine
 *
 * Decouples rewards from goal calculation (P05-T01): the GoalEngine
 * computes a GoalResult and this engine turns it into a RewardResult using
 * the goal's Reward configuration. Pure evaluation (evaluate()) never
 * touches the database or the cart; the WooCommerce integration
 * (sync_cart and the fee/shipping callbacks) grants and reverts rewards on
 * the live cart.
 *
 * WooCommerce integration points (all public hooks):
 *  - 'woocommerce_before_calculate_totals'  evaluate goals + reconcile
 *                                           coupons/automatic gifts
 *  - 'woocommerce_before_calculate_totals'  zero automatic-gift prices
 *  - 'woocommerce_cart_calculate_fees'      apply percentage/fixed fees
 *  - 'woocommerce_package_rates'            apply free shipping
 *	 * Reward safety (P05-T03) is enforced here and in RewardSafety:
	 *  - duplicates / stacking   -> first reward of each type wins unless the
	 *                               reward opts into stacking
	 *  - reward loops            -> own-fee exclusion in CartContext plus
	 *                               idempotent reconciliation mean reward
	 *                               mutations never re-trigger evaluation
	 *  - stale rewards           -> every totals pass re-evaluates and
	 *                               reconciles; coupons and gifts granted by
	 *                               this engine are removed the moment a goal
	 *                               becomes incomplete, even without a cart
	 *                               change (schedule expiry, admin
	 *                               deactivation)
	 *  - invalid coupons         -> validated before application
	 *  - excluded products       -> discount bases exclude them (applicators)
 */
final class RewardEngine {

	/**
	 * Session keys owned by the engine.
	 */
	const SESSION_COUPONS = 'goalcart_applied_coupons';
	const SESSION_GIFTS   = 'goalcart_gift_goals';

	/**
	 * Goal engine used to evaluate goals against the cart.
	 *
	 * @var GoalEngine
	 */
	protected $engine;

	/**
	 * Goal repository (active goals). Null disables the WC sync.
	 *
	 * @var GoalRepository|null
	 */
	protected $repository;

	/**
	 * Plugin settings (master toggle).
	 *
	 * @var Settings|null
	 */
	protected $settings;

	/**
	 * Reward applicator registry.
	 *
	 * @var RewardApplicatorRegistry
	 */
	protected $registry;

	/**
	 * Cart integration service (single source of the cart snapshot, Phase 6).
	 *
	 * @var CartIntegration|null
	 */
	protected $cart_integration;

	/**
	 * Reward results for the current request: goal_id => RewardResult.
	 *
	 * @var array<int, RewardResult>|null
	 */
	protected $results_cache;

	/**
	 * Re-entrancy guard for the WooCommerce totals pipeline.
	 *
	 * @var bool
	 */
	protected $syncing = false;

	/**
	 * Constructor.
	 *
	 * @param GoalEngine|null        $engine           Goal engine.
	 * @param GoalRepository|null    $repository       Goal repository.
	 * @param Settings|null          $settings         Plugin settings.
	 * @param CartIntegration|null   $cart_integration Cart integration service.
	 */
	public function __construct( ?GoalEngine $engine = null, ?GoalRepository $repository = null, ?Settings $settings = null, ?CartIntegration $cart_integration = null ) {
		$this->engine           = null !== $engine ? $engine : new GoalEngine();
		$this->repository       = $repository;
		$this->settings         = $settings;
		$this->registry         = new RewardApplicatorRegistry();
		$this->cart_integration = $cart_integration;
	}

	/**
	 * Register the WooCommerce hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart' ), 100 );
		$hooks->add_action( 'woocommerce_before_calculate_totals', array( $this, 'zero_gift_prices' ), 10 );
		$hooks->add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount_fees' ), 20 );
		$hooks->add_filter( 'woocommerce_package_rates', array( $this, 'apply_free_shipping' ), 100, 2 );
	}

	/**
	 * Evaluate the reward for a goal result.
	 *
	 * Pure: no database access, no cart mutation. `opts` may carry:
	 *  - 'already_applied': string[] of reward types already granted in this
	 *    pass (drives stacking/duplicate safety)
	 *  - 'cart': CartContext snapshot (lets discount applicators compute
	 *    concrete amounts)
	 *
	 * @param GoalResult        $result Goal evaluation result.
	 * @param array<string, mixed> $opts   Evaluation options.
	 * @return RewardResult
	 */
	public function evaluate( GoalResult $result, array $opts = array() ) {
		$goal   = $result->goal();
		$reward = Reward::from_goal( $goal );
		$goal_id = $goal->id();

		if ( ! $result->eligible() || GoalResult::REWARD_NOT_APPLICABLE === $result->reward_state() ) {
			return RewardResult::not_applicable( $reward, $goal_id );
		}

		if ( ! $reward->has_config() ) {
			return RewardResult::not_applicable( $reward, $goal_id, RewardResult::REASON_NO_REWARD );
		}

		if ( ! $this->registry->supports( $reward->type() ) ) {
			return RewardResult::not_applicable( $reward, $goal_id, RewardResult::REASON_UNKNOWN_TYPE );
		}

		if ( GoalResult::REWARD_LOCKED === $result->reward_state() ) {
			return RewardResult::locked( $reward, $goal_id );
		}

		$already = isset( $opts['already_applied'] ) && is_array( $opts['already_applied'] ) ? $opts['already_applied'] : array();

		if ( ! RewardSafety::stacking_allows( $reward, $already ) ) {
			return RewardResult::blocked( $reward, $goal_id, RewardResult::REASON_STACKING );
		}

		$applicator = $this->registry->applicator( $reward->type() );
		$context    = isset( $opts['cart'] ) && $opts['cart'] instanceof CartContext ? $opts['cart'] : null;

		return $applicator->evaluate( $reward, $result, $context );
	}

	/**
	 * Evaluate a goal's reward against a cart snapshot (convenience wrapper).
	 *
	 * @param Goal        $goal    Goal.
	 * @param CartContext $context Cart snapshot.
	 * @param string|null $now     Reference time for schedule checks.
	 * @return RewardResult
	 */
	public function evaluate_goal( Goal $goal, CartContext $context, $now = null ) {
		return $this->evaluate( $this->engine->evaluate( $goal, $context, $now ) );
	}

	/**
	 * WooCommerce cart sync: evaluate active goals and reconcile rewards.
	 *
	 * Hooked to 'woocommerce_before_calculate_totals' at priority 100. Runs
	 * at most once per totals pass (re-entrancy guard). Evaluation uses the
	 * line-item bases in CartContext, which stay valid while WC has reset
	 * the cart aggregates; reconciliation runs on every pass but is
	 * idempotent (it only touches rewards this engine applied), so a goal
	 * that becomes incomplete without any cart change — schedule expiry,
	 * admin deactivation — has its coupon/gift revoked immediately.
	 *
	 * @param \WC_Cart|null $cart Live cart.
	 * @return array<int, RewardResult> Reward results (goal_id => result).
	 */
	public function sync_cart( $cart = null ) {
		if ( $this->syncing ) {
			return $this->cached_results();
		}

		if ( is_admin() && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			return array();
		}

		if ( null === $cart ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return array();
			}

			$cart = WC()->cart;
		}

		$this->syncing = true;

		try {
			$this->results_cache = null;

			if ( $this->settings && ! $this->settings->get( 'enabled', true ) ) {
				$this->revert_all_applied( $cart );

				return array();
			}

			if ( empty( $cart->get_cart() ) ) {
				$this->revert_all_applied( $cart );

				return array();
			}

			if ( null === $this->repository ) {
				return array();
			}

			// Evaluation context: Goal Cart's own fees and shipping are
			// excluded so a reward can never un-grant itself. The cart
			// snapshot comes from the CartIntegration service (Phase 6) —
			// the single source of truth — falling back to a direct build
			// when the engine is constructed without it (tests/headless).
			$context = null !== $this->cart_integration
				? $this->cart_integration->context( $cart, array( 'exclude_shipping' => true ) )
				: CartContext::from_cart( $cart, array( 'exclude_shipping' => true ) );

			$results         = array();
			$already_applied = array();

			foreach ( $this->repository->active_goals() as $goal ) {
				$result = $this->engine->evaluate( $goal, $context );

				if ( ! $result->eligible() || GoalResult::REWARD_UNLOCKED !== $result->reward_state() ) {
					continue;
				}

				$reward_result = $this->evaluate(
					$result,
					array(
						'already_applied' => $already_applied,
						'cart'            => $context,
					)
				);

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$already_applied[] = $reward_result->type();
				}

				$results[ $goal->id() ] = $reward_result;
			}

			$this->results_cache = $results;

			// Reconcile on every pass (not only when the cart changed):
			// both methods are idempotent — they compare the desired reward
			// set against what this engine previously applied and only
			// mutate when they differ — so the stale-reward guarantee holds
			// even for goals that stop qualifying without a cart mutation.
			$this->reconcile_coupons( $cart, $results );
			$this->reconcile_gifts( $cart, $results );

			return $results;
		} finally {
			$this->syncing = false;
		}
	}

	/**
	 * Zero the price of automatic gift lines.
	 *
	 * Hooked to 'woocommerce_before_calculate_totals' at priority 10 (before
	 * sync) so gift lines contribute 0 to every totals pass. Mutates the
	 * per-line product clones only — the underlying product is untouched.
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return void
	 */
	public function zero_gift_prices( \WC_Cart $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['goalcart_gift'] ) && isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Apply percentage/fixed discount fees for completed goals.
	 *
	 * Hooked to 'woocommerce_cart_calculate_fees'. Fees are rebuilt from the
	 * per-request evaluation cache on every totals pass and drop out
	 * automatically when a goal stops being available.
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return void
	 */
	public function apply_discount_fees( \WC_Cart $cart ) {
		foreach ( $this->cached_results() as $goal_id => $reward_result ) {
			if ( RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			if ( Reward::TYPE_PERCENT_DISCOUNT !== $reward_result->type() && Reward::TYPE_FIXED_DISCOUNT !== $reward_result->type() ) {
				continue;
			}

			$this->registry->applicator( $reward_result->type() )->apply( $reward_result->reward(), $reward_result, $cart, $goal_id );
		}
	}

	/**
	 * Apply free shipping to the package rates for completed goals.
	 *
	 * Hooked to 'woocommerce_package_rates'. Stateless: when no free-shipping
	 * reward is active every rate passes through untouched.
	 *
	 * @param array<string, mixed> $rates   Shipping rates.
	 * @param array<string, mixed> $package Shipping package.
	 * @return array<string, mixed>
	 */
	public function apply_free_shipping( $rates, $package ) {
		$rewards = array();

		foreach ( $this->cached_results() as $reward_result ) {
			if ( Reward::TYPE_FREE_SHIPPING === $reward_result->type() && RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
				$rewards[] = $reward_result->reward();
			}
		}

		if ( empty( $rewards ) ) {
			return $rates;
		}

		/** @var FreeShippingApplicator $applicator */
		$applicator = $this->registry->applicator( Reward::TYPE_FREE_SHIPPING );

		return $applicator->apply_to_rates( $rates, is_array( $package ) ? $package : array(), $rewards );
	}

	/**
	 * Apply the coupons of completed coupon rewards; remove stale ones.
	 *
	 * @param \WC_Cart                 $cart    Live cart.
	 * @param array<int, RewardResult> $results Reward results.
	 * @return void
	 */
	protected function reconcile_coupons( \WC_Cart $cart, array $results ) {
		$applied = $this->session_get( self::SESSION_COUPONS );
		$applied = is_array( $applied ) ? $applied : array();

		$desired = array();

		foreach ( $results as $goal_id => $reward_result ) {
			if ( Reward::TYPE_COUPON !== $reward_result->type() || RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			/** @var CouponApplicator $applicator */
			$applicator = $this->registry->applicator( Reward::TYPE_COUPON );
			$code       = $applicator->resolve_coupon_code( $reward_result->reward(), $goal_id );

			if ( '' === $code ) {
				continue;
			}

			if ( $applicator->apply( $reward_result->reward(), $reward_result, $cart, $goal_id ) ) {
				$desired[ (int) $goal_id ] = $code;
			}
		}

		// Remove exactly the coupons this engine applied whose goals are no
		// longer complete — the shopper's own coupons are never touched.
		foreach ( $applied as $goal_id => $code ) {
			if ( isset( $desired[ (int) $goal_id ] ) ) {
				continue;
			}

			if ( $cart->has_discount( (string) $code ) ) {
				$cart->remove_coupon( (string) $code );
			}
		}

		$this->session_set( self::SESSION_COUPONS, $desired );
	}

	/**
	 * Add automatic gifts of completed goals; remove stale gift lines.
	 *
	 * Optional-mode gifts are left to the shopper (and a later-phase
	 * frontend action); they are never added automatically.
	 *
	 * @param \WC_Cart                 $cart    Live cart.
	 * @param array<int, RewardResult> $results Reward results.
	 * @return void
	 */
	protected function reconcile_gifts( \WC_Cart $cart, array $results ) {
		$applied = $this->session_get( self::SESSION_GIFTS );
		$applied = is_array( $applied ) ? $applied : array();

		$desired = array();

		foreach ( $results as $goal_id => $reward_result ) {
			if ( Reward::TYPE_FREE_GIFT !== $reward_result->type() || RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			if ( ! $reward_result->reward()->is_gift_automatic() ) {
				continue;
			}

			/** @var FreeGiftApplicator $applicator */
			$applicator = $this->registry->applicator( Reward::TYPE_FREE_GIFT );

			if ( $applicator->apply( $reward_result->reward(), $reward_result, $cart, $goal_id ) ) {
				$desired[] = (int) $goal_id;
			}
		}

		foreach ( $applied as $goal_id ) {
			if ( ! in_array( (int) $goal_id, $desired, true ) ) {
				$this->remove_gift_line( $cart, $goal_id );
			}
		}

		$this->session_set( self::SESSION_GIFTS, $desired );
	}

	/**
	 * Remove every reward this engine previously granted (empty cart,
	 * disabled plugin).
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return void
	 */
	protected function revert_all_applied( \WC_Cart $cart ) {
		$coupons = $this->session_get( self::SESSION_COUPONS );

		if ( is_array( $coupons ) ) {
			foreach ( $coupons as $code ) {
				if ( $cart->has_discount( (string) $code ) ) {
					$cart->remove_coupon( (string) $code );
				}
			}
		}

		$gifts = $this->session_get( self::SESSION_GIFTS );

		if ( is_array( $gifts ) ) {
			foreach ( $gifts as $goal_id ) {
				$this->remove_gift_line( $cart, $goal_id );
			}
		}

		$this->session_set( self::SESSION_COUPONS, array() );
		$this->session_set( self::SESSION_GIFTS, array() );
	}

	/**
	 * Remove the automatic gift line for a goal from the cart.
	 *
	 * @param \WC_Cart $cart    Live cart.
	 * @param int      $goal_id Goal id.
	 * @return void
	 */
	protected function remove_gift_line( \WC_Cart $cart, $goal_id ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['goalcart_gift_goal'] ) && (int) $item['goalcart_gift_goal'] === (int) $goal_id ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Reward results cached for the current request.
	 *
	 * @return array<int, RewardResult>
	 */
	protected function cached_results() {
		return null !== $this->results_cache ? $this->results_cache : array();
	}

	/**
	 * Read a value from the WooCommerce session.
	 *
	 * @param string $key Session key.
	 * @return mixed
	 */
	protected function session_get( $key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		return WC()->session->get( $key );
	}

	/**
	 * Write a value to the WooCommerce session (only when it changed, so
	 * unchanged passes never dirty the session storage).
	 *
	 * @param string $key   Session key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	protected function session_set( $key, $value ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( WC()->session->get( $key ) === $value ) {
			return;
		}

		WC()->session->set( $key, $value );
	}

	/**
	 * The applicator registry (exposed for extension).
	 *
	 * @return RewardApplicatorRegistry
	 */
	public function registry() {
		return $this->registry;
	}
}
