<?php
/**
 * Reward engine facade for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Rewards;

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\ConflictResolver;
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
 *  - 'woocommerce_cart_item_remove_link'    hide the remove link of gift
 *                                           lines (shoppers cannot remove
 *                                           an earned gift)
 *  - 'woocommerce_cart_item_quantity'       lock gift lines to quantity 1
 *  - 'woocommerce_cart_item_removed'        re-add a gift line a shopper
 *                                           removed while its goal still
 *                                           grants it
 *
 * Reward safety (P05-T03) is enforced here and in RewardSafety:
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
 *
 * Conflict resolution (Phase 26) is enforced here and in ConflictResolver:
 *  - deterministic order     -> active_goals() sorts by campaign
 *                               priority, then goal priority, then id
 *  - cumulative mode         -> every completed goal grants (default)
 *  - best / first modes      -> only the best reward / the first matching
 *                               goal grants; losers are blocked with a
 *                               resolution reason
 *  - mutually exclusive goals-> a completed exclusive goal suppresses
 *                               every lower-priority goal
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
	 * Conflict resolver (Phase 26): deterministic winner selection when
	 * multiple goals complete — cumulative / best / first modes plus
	 * mutually exclusive goals.
	 *
	 * @var ConflictResolver
	 */
	protected $resolver;

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
	 * True while the engine itself removes a gift line (stale reward,
	 * empty cart, disabled plugin). 'woocommerce_cart_item_removed' must
	 * not re-add a gift the engine deliberately revoked.
	 *
	 * @var bool
	 */
	protected $removing_gift = false;

	/**
	 * Constructor.
	 *
	 * @param GoalEngine|null        $engine           Goal engine.
	 * @param GoalRepository|null    $repository       Goal repository.
	 * @param Settings|null          $settings         Plugin settings.
	 * @param CartIntegration|null   $cart_integration Cart integration service.
	 * @param ConflictResolver|null  $resolver         Conflict resolver (Phase 26).
	 */
	public function __construct( ?GoalEngine $engine = null, ?GoalRepository $repository = null, ?Settings $settings = null, ?CartIntegration $cart_integration = null, ?ConflictResolver $resolver = null ) {
		$this->engine           = null !== $engine ? $engine : new GoalEngine();
		$this->repository       = $repository;
		$this->settings         = $settings;
		$this->registry         = new RewardApplicatorRegistry();
		$this->cart_integration = $cart_integration;
		$this->resolver         = null !== $resolver ? $resolver : new ConflictResolver();
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

		// Automatic gifts are shopper-proof: the remove link is hidden, the
		// quantity is locked to 1, and a removed gift line is restored on
		// the spot while its goal still grants it.
		$hooks->add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'hide_gift_remove_link' ), 10, 2 );
		$hooks->add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_gift_quantity' ), 10, 3 );
		$hooks->add_action( 'woocommerce_cart_item_removed', array( $this, 'restore_removed_gift' ), 10, 2 );
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

			$goals = $this->repository->active_goals();

			// Pass 1 — evaluate every completed goal's reward WITHOUT the
			// stacking guard: the conflict-resolution pass decides the
			// winners first, then stacking applies at grant time in
			// priority order (unchanged for cumulative mode). The computed
			// reward amount (scores) lets 'best' compare real discount
			// values on the current cart.
			$goal_results   = array();
			$reward_results = array();
			$scores         = array();

			foreach ( $goals as $goal ) {
				$result = $this->engine->evaluate( $goal, $context );

				if ( ! $result->eligible() || GoalResult::REWARD_UNLOCKED !== $result->reward_state() ) {
					continue;
				}

				// Only goals with a reward configured compete for grants;
				// reward-less goals grant nothing in any mode.
				if ( empty( $goal->reward_type() ) ) {
					continue;
				}

				$reward_result = $this->evaluate(
					$result,
					array(
						'already_applied' => array(),
						'cart'            => $context,
					)
				);

				$goal_results[ $goal->id() ]   = $result;
				$reward_results[ $goal->id() ] = $reward_result;

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$scores[ $goal->id() ] = $reward_result->amount();
				}
			}

			$resolution = $this->resolver->resolve(
				$goals,
				$goal_results,
				$this->conflict_mode(),
				$scores
			);

			// Pass 2 — grant in priority order: winners apply subject to
			// stacking; suppressed goals are blocked with their resolution
			// reason so the payload communicates exactly why.
			$results         = array();
			$already_applied = array();

			foreach ( $goals as $goal ) {
				$goal_id = (int) $goal->id();

				if ( ! isset( $reward_results[ $goal_id ] ) ) {
					continue;
				}

				$reason = isset( $resolution[ $goal_id ] ) ? $resolution[ $goal_id ] : ConflictResolver::REASON_NONE;

				if ( ConflictResolver::REASON_NONE !== $reason ) {
					$results[ $goal_id ] = RewardResult::blocked( $reward_results[ $goal_id ]->reward(), $goal_id, $reason );
					continue;
				}

				$reward_result = $reward_results[ $goal_id ];

				if ( RewardResult::STATE_NOT_APPLICABLE === $reward_result->state() ) {
					$results[ $goal_id ] = $reward_result;
					continue;
				}

				if ( ! RewardSafety::stacking_allows( $reward_result->reward(), $already_applied ) ) {
					$results[ $goal_id ] = RewardResult::blocked( $reward_result->reward(), $goal_id, RewardResult::REASON_STACKING );
					continue;
				}

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$already_applied[] = $reward_result->type();
				}

				$results[ $goal_id ] = $reward_result;
			}

			$this->results_cache = $results;

			// Reconcile on every pass (not only when the cart changed):
			// both methods are idempotent — they compare the desired reward
			// set against what this engine previously applied and only
			// mutate when they differ — so the stale-reward guarantee holds
			// even for goals that stop qualifying without a cart mutation.
			$this->reconcile_coupons( $cart, $results );
			$this->reconcile_gifts( $cart, $results );

			// A gift added by reconcile_gifts in THIS pass must already be
			// free — the priority-10 zeroing hook ran before the gift
			// existed. Re-zeroing here (idempotent) covers the adding pass;
			// the earlier hook covers every later pass.
			$this->zero_gift_prices( $cart );

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
	 * The active conflict-resolution mode from the settings.
	 *
	 * Falls back to cumulative (the pre-Phase-26 behavior) when the
	 * setting is missing or invalid.
	 *
	 * @return string ConflictResolver::MODE_* constant.
	 */
	protected function conflict_mode() {
		$mode = null !== $this->settings
			? (string) $this->settings->get( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE )
			: ConflictResolver::MODE_CUMULATIVE;

		return in_array( $mode, ConflictResolver::modes(), true ) ? $mode : ConflictResolver::MODE_CUMULATIVE;
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
	 * The engine-removal flag is set around the mutation so the
	 * 'woocommerce_cart_item_removed' handler (restore_removed_gift) never
	 * re-adds a gift the engine deliberately revoked.
	 *
	 * @param \WC_Cart $cart    Live cart.
	 * @param int      $goal_id Goal id.
	 * @return void
	 */
	protected function remove_gift_line( \WC_Cart $cart, $goal_id ) {
		$this->removing_gift = true;

		try {
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( ! empty( $item['goalcart_gift_goal'] ) && (int) $item['goalcart_gift_goal'] === (int) $goal_id ) {
					$cart->remove_cart_item( $key );
				}
			}
		} finally {
			$this->removing_gift = false;
		}
	}

	/**
	 * Hide the remove link of automatic gift lines.
	 *
	 * Hooked to 'woocommerce_cart_item_remove_link'. Returning '' removes
	 * the “Remove” affordance from the cart table for gift lines.
	 *
	 * @param string $link          Remove-link HTML.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function hide_gift_remove_link( $link, $cart_item_key ) {
		return $this->is_gift_cart_item( $cart_item_key ) ? '' : $link;
	}

	/**
	 * Lock automatic gift lines to quantity 1.
	 *
	 * Hooked to 'woocommerce_cart_item_quantity'. Gift lines get a hidden
	 * qty input (so the “Update cart” button keeps them at 1) instead of an
	 * editable stepper — the shopper can neither change the gift quantity
	 * nor remove it by zeroing it. Non-gift lines pass through untouched.
	 *
	 * @param mixed  $quantity      Quantity field HTML.
	 * @param string $cart_item_key Cart item key.
	 * @param array<string, mixed> $cart_item    Cart item array.
	 * @return mixed
	 */
	public function lock_gift_quantity( $quantity, $cart_item_key, $cart_item ) {
		if ( is_array( $cart_item ) && ! empty( $cart_item['goalcart_gift'] ) ) {
			return sprintf(
				'<div class="quantity goalcart-gift-quantity"><input type="hidden" name="cart[%1$s][qty]" value="1" />1</div>',
				esc_attr( (string) $cart_item_key )
			);
		}

		return $quantity;
	}

	/**
	 * Re-add an automatic gift line a shopper removed.
	 *
	 * Hooked to 'woocommerce_cart_item_removed'. Shoppers cannot keep an
	 * earned gift out of the cart: when a gift line is removed while its
	 * goal is still active and still grants an automatic free-gift reward
	 * with an available product, the line is restored immediately. apply()
	 * is idempotent (the goal marker is re-checked on the line), and the
	 * next totals pass reconciles if the goal stops qualifying. The engine's
	 * own revocations (stale rewards, empty cart, disabled plugin) are
	 * skipped via the removing_gift flag.
	 *
	 * @param string   $cart_item_key Removed cart item key.
	 * @param \WC_Cart $cart          Live cart (removal already applied).
	 * @return void
	 */
	public function restore_removed_gift( $cart_item_key, $cart ) {
		if ( $this->removing_gift || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$removed = isset( $cart->removed_cart_contents[ $cart_item_key ] )
			? $cart->removed_cart_contents[ $cart_item_key ]
			: null;

		if ( ! is_array( $removed ) || empty( $removed['goalcart_gift'] ) || empty( $removed['goalcart_gift_goal'] ) ) {
			return;
		}

		if ( null === $this->repository ) {
			return;
		}

		$goal_id = (int) $removed['goalcart_gift_goal'];
		$goal    = $this->repository->find( $goal_id );

		if ( ! $goal || ! $goal->is_active() ) {
			return;
		}

		$reward = Reward::from_goal( $goal );

		if ( Reward::TYPE_FREE_GIFT !== $reward->type()
			|| ! $reward->is_gift_automatic()
			|| $reward->gift_product_id() <= 0
			|| ! RewardSafety::gift_product_available( $reward->gift_product_id() ) ) {
			return;
		}

		$this->registry->applicator( Reward::TYPE_FREE_GIFT )->apply(
			$reward,
			RewardResult::available( $reward, $goal_id ),
			$cart,
			$goal_id
		);

		// The restored line is re-added at the product price; zero it right
		// away (idempotent) so the gift is free even before the next totals
		// pass runs the priority-10 zeroing hook.
		$this->zero_gift_prices( $cart );
	}

	/**
	 * Whether a cart item key refers to an engine-added gift line.
	 *
	 * @param mixed $cart_item_key Cart item key.
	 * @return bool
	 */
	protected function is_gift_cart_item( $cart_item_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$item = WC()->cart->get_cart_item( (string) $cart_item_key );

		return is_array( $item ) && ! empty( $item['goalcart_gift'] );
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
