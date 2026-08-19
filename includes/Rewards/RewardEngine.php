<?php
/**
 * Reward engine facade for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Rewards;

use FaraCart\Cart\CartIntegration;
use FaraCart\Missions\CartContext;
use FaraCart\Missions\CompletionService;
use FaraCart\Missions\ConflictResolver;
use FaraCart\Missions\Mission;
use FaraCart\Missions\MissionEngine;
use FaraCart\Missions\MissionRepository;
use FaraCart\Missions\MissionResult;
use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class RewardEngine
 *
 * Decouples rewards from mission calculation (P05-T01): the MissionEngine
 * computes a MissionResult and this engine turns it into a RewardResult using
 * the mission's Reward configuration. Pure evaluation (evaluate()) never
 * touches the database or the cart; the WooCommerce integration
 * (sync_cart and the fee/shipping callbacks) grants and reverts rewards on
 * the live cart.
 *
 * WooCommerce integration points (all public hooks):
 *  - 'woocommerce_before_calculate_totals'  clamp gift lines to qty 1
 *                                           (authoritative server-side)
 *  - 'woocommerce_before_calculate_totals'  evaluate missions + reconcile
 *                                           coupons/automatic gifts
 *  - 'woocommerce_before_calculate_totals'  zero automatic-gift prices
 *  - 'woocommerce_cart_calculate_fees'      apply percentage/fixed fees
 *  - 'woocommerce_package_rates'            apply free shipping	 *  - 'woocommerce_cart_item_remove_link'    hide the remove link only of
	 *                                           mandatory (automatic-mode)
	 *                                           gift lines; selectable
	 *                                           (choose-mode) gifts keep their
	 *                                           remove control
	 *  - 'woocommerce_cart_item_quantity'       lock gift lines to quantity 1
	 *                                           (classic cart page display;
	 *                                           enforcement is server-side via
	 *                                           clamp_gift_quantities)
	 *  - 'woocommerce_cart_item_removed'        re-add a mandatory gift line a
	 *                                           shopper removed while its mission
	 *                                           still grants it (Blocks carts
	 *                                           always render a remove control;
	 *                                           the re-add there is the
	 *                                           server-side rejection, which
	 *                                           snaps the line back in place)
 *
 * Reward safety (P05-T03) is enforced here and in RewardSafety:
 *  - duplicates / stacking   -> first reward of each type wins unless the
 *                               reward opts into stacking
 *  - reward loops            -> own-fee exclusion in CartContext plus
 *                               idempotent reconciliation mean reward
 *                               mutations never re-trigger evaluation
 *  - stale rewards           -> every totals pass re-evaluates and
 *                               reconciles; coupons and gifts granted by
 *                               this engine are removed the moment a mission
 *                               becomes incomplete, even without a cart
 *                               change (schedule expiry, admin
 *                               deactivation). Gift removal scans the
 *                               live cart (mission-marked lines), not just
 *                               the session record, so a stale gift can
 *                               never outlive its granting mission.
 *  - invalid coupons         -> validated before application
 *  - excluded products       -> discount bases exclude them (applicators)
 *
 * Conflict resolution (Phase 26) is enforced here and in ConflictResolver:
 *  - deterministic order     -> active_missions() sorts by campaign
 *                               priority, then mission priority, then id
 *  - cumulative mode         -> every completed mission grants (default)
 *  - best / first modes      -> only the best reward / the first matching
 *                               mission grants; losers are blocked with a
 *                               resolution reason
 *  - mutually exclusive missions-> a completed exclusive mission suppresses
 *                               every lower-priority mission
 */
final class RewardEngine {

	/**
	 * Session keys owned by the engine.
	 */
	const SESSION_COUPONS = 'faracart_applied_coupons';
	const SESSION_GIFTS   = 'faracart_gift_missions';

	/**
	 * Mission engine used to evaluate missions against the cart.
	 *
	 * @var MissionEngine
	 */
	protected $engine;

	/**
	 * Mission repository (active missions). Null disables the WC sync.
	 *
	 * @var MissionRepository|null
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
	 * multiple missions complete — cumulative / best / first modes plus
	 * mutually exclusive missions.
	 *
	 * @var ConflictResolver
	 */
	protected $resolver;

	/**
	 * Per-user completion limit service (Phase 36). When injected, an
	 * exhausted mission (completion count >= its per-user limit) drops out of
	 * evaluation entirely — it can never grant a reward, and any reward it
	 * previously granted is revoked by the normal reconcile pass. Null in
	 * bare/test constructions keeps the pre-limit behavior.
	 *
	 * @var CompletionService|null
	 */
	protected $completions;

	/**
	 * Reward results for the current request: mission_id => RewardResult.
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
	 * @param MissionEngine|null        $engine           Mission engine.
	 * @param MissionRepository|null    $repository       Mission repository.
	 * @param Settings|null          $settings         Plugin settings.
	 * @param CartIntegration|null   $cart_integration Cart integration service.
	 * @param ConflictResolver|null  $resolver         Conflict resolver (Phase 26).
	 * @param CompletionService|null $completions      Per-user completion limit
	 *                                                  service (Phase 36); null
	 *                                                  disables the limit gate
	 *                                                  (bare/test constructions).
	 */
	public function __construct( ?MissionEngine $engine = null, ?MissionRepository $repository = null, ?Settings $settings = null, ?CartIntegration $cart_integration = null, ?ConflictResolver $resolver = null, ?CompletionService $completions = null ) {
		$this->engine           = null !== $engine ? $engine : new MissionEngine();
		$this->repository       = $repository;
		$this->settings         = $settings;
		$this->registry         = new RewardApplicatorRegistry();
		$this->cart_integration = $cart_integration;
		$this->resolver         = null !== $resolver ? $resolver : new ConflictResolver();
		$this->completions      = $completions;
	}

	/**
	 * Register the WooCommerce hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// The quantity clamp runs first (priority 5) so every totals pass
		// sees gift lines at quantity 1 — classic cart updates, AJAX and
		// the Store API (Blocks cart) all funnel through here.
		$hooks->add_action( 'woocommerce_before_calculate_totals', array( $this, 'clamp_gift_quantities' ), 5 );
		$hooks->add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart' ), 100 );
		$hooks->add_action( 'woocommerce_before_calculate_totals', array( $this, 'zero_gift_prices' ), 10 );
		$hooks->add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_discount_fees' ), 20 );
		$hooks->add_filter( 'woocommerce_package_rates', array( $this, 'apply_free_shipping' ), 100, 2 );

		// Blocks/Store API quantity lock: gift lines are quantity-fixed
		// (no editable stepper in the Blocks cart, and the Store API
		// rejects quantity updates on them). Classic cart page display is
		// covered by lock_gift_quantity(); clamp_gift_quantities() is the
		// authoritative backstop for every path.
		$hooks->add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'store_api_gift_quantity_editable' ), 10, 3 );

		// Mandatory (automatic-mode) gifts are shopper-proof: the remove
		// link is hidden, the quantity is locked to 1, and a removed gift
		// line is restored on the spot while its mission still grants it.
		// Selectable (choose-mode) gifts keep their remove control (their
		// removal is respected server-side) but still cannot change
		// quantity. The quantity lock is display-only on the classic cart
		// page — enforcement is authoritative via clamp_gift_quantities().
		$hooks->add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'hide_gift_remove_link' ), 10, 2 );
		$hooks->add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_gift_quantity' ), 10, 3 );
		$hooks->add_action( 'woocommerce_cart_item_removed', array( $this, 'restore_removed_gift' ), 10, 2 );
	}

	/**
	 * Evaluate the reward for a mission result.
	 *
	 * Pure: no database access, no cart mutation. `opts` may carry:
	 *  - 'already_applied': string[] of reward types already granted in this
	 *    pass (drives stacking/duplicate safety)
	 *  - 'cart': CartContext snapshot (lets discount applicators compute
	 *    concrete amounts)
	 *
	 * @param MissionResult        $result Mission evaluation result.
	 * @param array<string, mixed> $opts   Evaluation options.
	 * @return RewardResult
	 */
	public function evaluate( MissionResult $result, array $opts = array() ) {
		$mission   = $result->mission();
		$reward = Reward::from_mission( $mission );
		$mission_id = $mission->id();

		if ( ! $result->eligible() || MissionResult::REWARD_NOT_APPLICABLE === $result->reward_state() ) {
			return RewardResult::not_applicable( $reward, $mission_id );
		}

		if ( ! $reward->has_config() ) {
			return RewardResult::not_applicable( $reward, $mission_id, RewardResult::REASON_NO_REWARD );
		}

		if ( ! $this->registry->supports( $reward->type() ) ) {
			return RewardResult::not_applicable( $reward, $mission_id, RewardResult::REASON_UNKNOWN_TYPE );
		}

		if ( MissionResult::REWARD_LOCKED === $result->reward_state() ) {
			return RewardResult::locked( $reward, $mission_id );
		}

		$already = isset( $opts['already_applied'] ) && is_array( $opts['already_applied'] ) ? $opts['already_applied'] : array();

		if ( ! RewardSafety::stacking_allows( $reward, $already ) ) {
			return RewardResult::blocked( $reward, $mission_id, RewardResult::REASON_STACKING );
		}

		$applicator = $this->registry->applicator( $reward->type() );
		$context    = isset( $opts['cart'] ) && $opts['cart'] instanceof CartContext ? $opts['cart'] : null;

		return $applicator->evaluate( $reward, $result, $context );
	}

	/**
	 * Evaluate a mission's reward against a cart snapshot (convenience wrapper).
	 *
	 * @param Mission        $mission    Mission.
	 * @param CartContext $context Cart snapshot.
	 * @param string|null $now     Reference time for schedule checks.
	 * @return RewardResult
	 */
	public function evaluate_mission( Mission $mission, CartContext $context, $now = null ) {
		return $this->evaluate( $this->engine->evaluate( $mission, $context, $now ) );
	}

	/**
	 * WooCommerce cart sync: evaluate active missions and reconcile rewards.
	 *
	 * Hooked to 'woocommerce_before_calculate_totals' at priority 100. Runs
	 * at most once per totals pass (re-entrancy guard). Evaluation uses the
	 * line-item bases in CartContext, which stay valid while WC has reset
	 * the cart aggregates; reconciliation runs on every pass but is
	 * idempotent (it only touches rewards this engine applied), so a mission
	 * that becomes incomplete without any cart change — schedule expiry,
	 * admin deactivation — has its coupon/gift revoked immediately.
	 *
	 * @param \WC_Cart|null $cart Live cart.
	 * @return array<int, RewardResult> Reward results (mission_id => result).
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

			// Evaluation context: FaraCart's own fees and shipping are
			// excluded so a reward can never un-grant itself. The cart
			// snapshot comes from the CartIntegration service (Phase 6) —
			// the single source of truth — falling back to a direct build
			// when the engine is constructed without it (tests/headless).
			$context = null !== $this->cart_integration
				? $this->cart_integration->context( $cart, array( 'exclude_shipping' => true ) )
				: CartContext::from_cart( $cart, array( 'exclude_shipping' => true ) );

			$missions = $this->repository->active_missions();

			// Phase 36 (per-user completion limit): an exhausted mission (this
			// identity already completed it the configured maximum times) is
			// dropped before evaluation, so it can never grant its reward.
			// Unlimited missions (the default for every existing mission) pass
			// through without a single count query.
			if ( null !== $this->completions ) {
				$missions = $this->completions->available_missions( $missions, $context );
			}

			// Pass 1 — evaluate every completed mission's reward WITHOUT the
			// stacking guard: the conflict-resolution pass decides the
			// winners first, then stacking applies at grant time in
			// priority order (unchanged for cumulative mode). The computed
			// reward amount (scores) lets 'best' compare real discount
			// values on the current cart.
			$mission_results   = array();
			$reward_results = array();
			$scores         = array();

			foreach ( $missions as $mission ) {
				$result = $this->engine->evaluate( $mission, $context );

				if ( ! $result->eligible() || MissionResult::REWARD_UNLOCKED !== $result->reward_state() ) {
					continue;
				}

				// Only missions with a reward configured compete for grants;
				// reward-less missions grant nothing in any mode.
				if ( empty( $mission->reward_type() ) ) {
					continue;
				}

				$reward_result = $this->evaluate(
					$result,
					array(
						'already_applied' => array(),
						'cart'            => $context,
					)
				);

				$mission_results[ $mission->id() ]   = $result;
				$reward_results[ $mission->id() ] = $reward_result;

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$scores[ $mission->id() ] = $reward_result->amount();
				}
			}

			$resolution = $this->resolver->resolve(
				$missions,
				$mission_results,
				$this->conflict_mode(),
				$scores
			);

			// Pass 2 — grant in priority order: winners apply subject to
			// stacking; suppressed missions are blocked with their resolution
			// reason so the payload communicates exactly why.
			$results         = array();
			$already_applied = array();

			foreach ( $missions as $mission ) {
				$mission_id = (int) $mission->id();

				if ( ! isset( $reward_results[ $mission_id ] ) ) {
					continue;
				}

				$reason = isset( $resolution[ $mission_id ] ) ? $resolution[ $mission_id ] : ConflictResolver::REASON_NONE;

				if ( ConflictResolver::REASON_NONE !== $reason ) {
					$results[ $mission_id ] = RewardResult::blocked( $reward_results[ $mission_id ]->reward(), $mission_id, $reason );
					continue;
				}

				$reward_result = $reward_results[ $mission_id ];

				if ( RewardResult::STATE_NOT_APPLICABLE === $reward_result->state() ) {
					$results[ $mission_id ] = $reward_result;
					continue;
				}

				if ( ! RewardSafety::stacking_allows( $reward_result->reward(), $already_applied ) ) {
					$results[ $mission_id ] = RewardResult::blocked( $reward_result->reward(), $mission_id, RewardResult::REASON_STACKING );
					continue;
				}

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$already_applied[] = $reward_result->type();
				}

				$results[ $mission_id ] = $reward_result;
			}

			$this->results_cache = $results;

			// Reconcile on every pass (not only when the cart changed):
			// both methods are idempotent — they compare the desired reward
			// set against what this engine previously applied and only
			// mutate when they differ — so the stale-reward guarantee holds
			// even for missions that stop qualifying without a cart mutation.
			$this->reconcile_coupons( $cart, $results );
			$this->reconcile_gifts( $cart, $results, $missions );

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
	 * Lock gift-line quantities in the Store API / Blocks cart.
	 *
	 * Hooked to 'woocommerce_store_api_product_quantity_editable' (WC 7.9+;
	 * on older WooCommerce versions the filter never fires and the hook is
	 * inert). Returning false marks engine-added gift lines as
	 * quantity-fixed: the Blocks cart renders a fixed “1” instead of an
	 * editable stepper. The Store API validation only errors outright when
	 * a quantity exceeds the product maximum (default ~9999); a direct
	 * update-item with a normal quantity passes validation and is then
	 * reset to 1 by clamp_gift_quantities() on the ensuing totals pass, so
	 * the Store API response reflects the locked quantity either way. The
	 * classic cart page is covered by lock_gift_quantity();
	 * clamp_gift_quantities() remains the authoritative backstop for every
	 * path.
	 *
	 * @param bool                   $editable  Whether the quantity is editable.
	 * @param \WC_Product            $product   Line product.
	 * @param array<string, mixed>   $cart_item Cart item array.
	 * @return bool
	 */
	public function store_api_gift_quantity_editable( $editable, $product, $cart_item ) {
		return ( is_array( $cart_item ) && ! empty( $cart_item['faracart_gift'] ) ) ? false : $editable;
	}

	/**
	 * Clamp engine-added gift lines to quantity 1 (authoritative).
	 *
	 * Hooked to 'woocommerce_before_calculate_totals' at priority 5 (before
	 * zero_gift_prices and sync_cart). Every cart-mutating path — the
	 * classic cart update form, AJAX add/remove, and the Store API behind
	 * the Blocks cart — ends in a totals pass, so clamping here makes the
	 * quantity lock hold even when a direct request bypasses the
	 * display-layer filter ('woocommerce_cart_item_quantity' only affects
	 * the classic cart page). set_quantity() with $check_qty=false skips
	 * validation/stock checks so a locked gift can never raise a cart
	 * error; the change is reflected in the Store API response because the
	 * response is built after calculate_totals().
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return void
	 */
	public function clamp_gift_quantities( \WC_Cart $cart ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['faracart_gift'] ) ) {
				continue;
			}

			if ( 1 !== (int) $item['quantity'] ) {
				$cart->set_quantity( (string) $key, 1, false );
			}
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
			if ( ! empty( $item['faracart_gift'] ) && isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
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
	 * Apply percentage/fixed discount fees for completed missions.
	 *
	 * Hooked to 'woocommerce_cart_calculate_fees'. Fees are rebuilt from the
	 * per-request evaluation cache on every totals pass and drop out
	 * automatically when a mission stops being available.
	 *
	 * @param \WC_Cart $cart Live cart.
	 * @return void
	 */
	public function apply_discount_fees( \WC_Cart $cart ) {
		foreach ( $this->cached_results() as $mission_id => $reward_result ) {
			if ( RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			if ( Reward::TYPE_PERCENT_DISCOUNT !== $reward_result->type() && Reward::TYPE_FIXED_DISCOUNT !== $reward_result->type() ) {
				continue;
			}

			$this->registry->applicator( $reward_result->type() )->apply( $reward_result->reward(), $reward_result, $cart, $mission_id );
		}
	}

	/**
	 * Apply free shipping to the package rates for completed missions.
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

		foreach ( $results as $mission_id => $reward_result ) {
			if ( Reward::TYPE_COUPON !== $reward_result->type() || RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			/** @var CouponApplicator $applicator */
			$applicator = $this->registry->applicator( Reward::TYPE_COUPON );
			$code       = $applicator->resolve_coupon_code( $reward_result->reward(), $mission_id );

			if ( '' === $code ) {
				continue;
			}

			if ( $applicator->apply( $reward_result->reward(), $reward_result, $cart, $mission_id ) ) {
				$desired[ (int) $mission_id ] = $code;
			}
		}

		// Remove exactly the coupons this engine applied whose missions are no
		// longer complete — the shopper's own coupons are never touched.
		foreach ( $applied as $mission_id => $code ) {
			if ( isset( $desired[ (int) $mission_id ] ) ) {
				continue;
			}

			if ( $cart->has_discount( (string) $code ) ) {
				$cart->remove_coupon( (string) $code );
			}
		}

		$this->session_set( self::SESSION_COUPONS, $desired );
	}

	/**
	 * Add automatic gifts of completed missions; remove stale gift lines;
	 * keep shopper-chosen gifts while their mission still grants them.
	 *
	 * Automatic gifts are added for the mission's configured product.
	 * Choose-mode gifts are left to the shopper — but a gift the shopper
	 * already chose (via the public gift endpoint) is kept as long as the
	 * mission still grants it AND the chosen product is still in the gift
	 * list; a re-configured reward revokes the stale line.
	 *
	 * Stale gift lines are removed by scanning the live cart for
	 * mission-marked lines, not just the session record: a gift whose
	 * granting mission is no longer in the desired set (or whose product is
	 * no longer the desired one) is revoked even when the session and the
	 * persisted cart diverge (session expiry, restored persistent cart,
	 * direct Store API tampering). Only engine-marked lines are ever
	 * touched, so a customer-added line of the same product — which
	 * carries no mission marker — always survives, and each line is keyed to
	 * exactly one mission so a gift still granted by a different, still-met
	 * mission is left alone.
	 *
	 * The session payload is a map mission_id => product_id (older installs
	 * stored a plain mission-id list; those entries are treated as
	 * mission_id => null and reconciled normally).
	 *
	 * @param \WC_Cart                 $cart    Live cart.
	 * @param array<int, RewardResult> $results Reward results.
	 * @param Mission[]                   $missions   Missions evaluated this pass
	 *                                          (the authoritative set the
	 *                                          cart-scan is scoped to).
	 * @return void
	 */
	protected function reconcile_gifts( \WC_Cart $cart, array $results, array $missions ) {
		$applied = $this->session_get( self::SESSION_GIFTS );
		$applied = is_array( $applied ) ? $applied : array();

		// Normalize the legacy list form to the map form.
		$applied_map = array();

		foreach ( $applied as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$applied_map[ (int) $value ] = null;
			} else {
				$applied_map[ (int) $key ] = (int) $value;
			}
		}

		$desired = array();

		foreach ( $results as $mission_id => $reward_result ) {
			if ( Reward::TYPE_FREE_GIFT !== $reward_result->type() || RewardResult::STATE_AVAILABLE !== $reward_result->state() ) {
				continue;
			}

			$reward = $reward_result->reward();

			/** @var FreeGiftApplicator $applicator */
			$applicator = $this->registry->applicator( Reward::TYPE_FREE_GIFT );

			if ( $reward->is_gift_automatic() ) {
				if ( $applicator->apply( $reward, $reward_result, $cart, $mission_id ) ) {
					$desired[ (int) $mission_id ] = (int) $reward->gift_product_id();
				}

				continue;
			}

			// Choose mode: keep a previously chosen gift while it is still
			// allowed by the current reward configuration. If the session
			// record was lost (session expiry, restored persistent cart)
			// the choice is recovered from the mission-marked line already in
			// the cart, so a validly chosen gift is never swept just
			// because the session is empty.
			$chosen = isset( $applied_map[ (int) $mission_id ] ) ? (int) $applied_map[ (int) $mission_id ] : 0;

			if ( $chosen <= 0 ) {
				foreach ( $cart->get_cart() as $item ) {
					if ( ! empty( $item['faracart_gift_mission'] ) && (int) $item['faracart_gift_mission'] === (int) $mission_id ) {
						$chosen = isset( $item['faracart_gift_product'] ) ? (int) $item['faracart_gift_product'] : 0;
						break;
					}
				}
			}

			if ( $chosen > 0 && $reward->is_gift_allowed( $chosen ) && RewardSafety::gift_product_available( $chosen ) ) {
				$desired[ (int) $mission_id ] = $chosen;
			}
		}

		// Self-heal legacy lines: a kept gift line that predates the mode
		// marker (added before this fix) has its add-mode stamped now, so
		// the per-mode remove-link policy applies without a repository
		// lookup and the line no longer looks mandatory by default.
		foreach ( $desired as $mission_id => $product_id ) {
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( ! empty( $item['faracart_gift_mission'] ) && (int) $item['faracart_gift_mission'] === (int) $mission_id && ! isset( $item['faracart_gift_mode'] ) ) {
					$mode = Reward::GIFT_AUTOMATIC;

					if ( isset( $results[ (int) $mission_id ] ) && $results[ (int) $mission_id ]->reward() instanceof Reward ) {
						$mode = $results[ (int) $mission_id ]->reward()->gift_add_mode();
					}

					$cart->cart_contents[ $key ]['faracart_gift_mode'] = $mode;
					break;
				}
			}
		}

		// Path 1 — session-driven removal: revoke gifts this engine
		// previously granted whose missions are no longer desired. Covers
		// missions that vanished from active_missions() entirely (admin
		// deactivation, schedule expiry) where no evaluation happened
		// this pass.
		foreach ( $applied_map as $mission_id => $product_id ) {
			if ( isset( $desired[ (int) $mission_id ] ) && ( null === $product_id || (int) $desired[ (int) $mission_id ] === (int) $product_id ) ) {
				continue;
			}

			$this->remove_gift_line( $cart, (int) $mission_id );
		}

		// Path 2 — cart-scan, scoped to the missions evaluated this pass: a
		// mission-marked line is revoked only when the engine actually saw
		// its granting mission this pass and no longer wants it (the mission
		// stopped qualifying, or the reward was re-configured to a
		// different product). Lines whose mission was not evaluated this
		// pass are out of scope — the engine never removes a gift it
		// cannot vouch for (stale caches and nested totals passes
		// triggered by add_to_cart mid-reconcile are harmless).
		$desired_by_mission = array();

		foreach ( $desired as $mission_id => $product_id ) {
			$desired_by_mission[ (int) $mission_id ] = (int) $product_id;
		}

		$considered = array();

		foreach ( $missions as $mission ) {
			$considered[ (int) $mission->id() ] = true;
		}

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['faracart_gift_mission'] ) || ! isset( $considered[ (int) $item['faracart_gift_mission'] ] ) ) {
				continue;
			}

			$gift_mission    = (int) $item['faracart_gift_mission'];
			$gift_product = isset( $item['faracart_gift_product'] ) ? (int) $item['faracart_gift_product'] : 0;

			if ( ! isset( $desired_by_mission[ $gift_mission ] ) || $desired_by_mission[ $gift_mission ] !== $gift_product ) {
				$this->remove_gift_line( $cart, $gift_mission );
			}
		}

		$this->session_set( self::SESSION_GIFTS, $desired );
	}

	/**
	 * Add the shopper's chosen gift for a completed mission (Phase 32).
	 *
	 * Called by the public gift endpoint. The mission must currently be
	 * completed AND grant a free-gift reward whose gift list allows the
	 * chosen product; the gift is added free (faracart markers) and
	 * session-tracked so a mission that stops qualifying revokes it.
	 *
	 * @param int       $mission_id    Mission id.
	 * @param int       $product_id Chosen gift product id.
	 * @param \WC_Cart  $cart       Live cart.
	 * @return bool
	 */
	public function add_chosen_gift( $mission_id, $product_id, \WC_Cart $cart ) {
		$mission_id    = (int) $mission_id;
		$product_id = (int) $product_id;

		if ( null === $this->repository ) {
			return false;
		}

		$mission = $this->repository->find( $mission_id );

		if ( ! $mission ) {
			return false;
		}

		$context = null !== $this->cart_integration
			? $this->cart_integration->context( $cart )
			: CartContext::from_cart( $cart );

		$result = $this->engine->evaluate( $mission, $context );

		if ( ! $result->eligible() || MissionResult::REWARD_UNLOCKED !== $result->reward_state() ) {
			return false;
		}

		// Phase 36 (per-user completion limit): an exhausted mission must not
		// grant its gift — the same authoritative gate the cart sync
		// applies (an identity that already completed the mission the
		// configured maximum times cannot claim another reward).
		if ( null !== $this->completions && ! $this->completions->context_allows( $mission, $context ) ) {
			return false;
		}

		$reward = Reward::from_mission( $mission );

		if ( Reward::TYPE_FREE_GIFT !== $reward->type()
			|| $reward->is_gift_automatic()
			|| ! $reward->is_gift_allowed( $product_id )
			|| ! RewardSafety::gift_product_available( $product_id ) ) {
			return false;
		}

		// Replace a previous selection: a mission may only ever carry ONE
		// gift line, so re-choosing a different candidate swaps it instead
		// of stacking a second gift. The engine-removal flag suppresses the
		// restore handler so the stale line stays gone.
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['faracart_gift_mission'] ) || (int) $item['faracart_gift_mission'] !== $mission_id ) {
				continue;
			}

			$current = isset( $item['faracart_gift_product'] ) ? (int) $item['faracart_gift_product'] : 0;

			if ( $current !== $product_id ) {
				$this->remove_gift_line( $cart, $mission_id );
			}

			break;
		}

		/** @var FreeGiftApplicator $applicator */
		$applicator = $this->registry->applicator( Reward::TYPE_FREE_GIFT );

		if ( ! $applicator->apply( $reward, RewardResult::available( $reward, $mission_id ), $cart, $mission_id, $product_id ) ) {
			return false;
		}

		$applied = $this->session_get( self::SESSION_GIFTS );
		$applied = is_array( $applied ) ? $applied : array();

		// Normalize the legacy list form before writing the map form.
		$applied_map = array();

		foreach ( $applied as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$applied_map[ (int) $value ] = null;
			} else {
				$applied_map[ (int) $key ] = (int) $value;
			}
		}

		$applied_map[ $mission_id ] = $product_id;

		$this->session_set( self::SESSION_GIFTS, $applied_map );

		return true;
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
			foreach ( $gifts as $key => $value ) {
				// Legacy list form: values are mission ids; map form: keys are.
				$this->remove_gift_line( $cart, is_numeric( $key ) ? $value : $key );
			}
		}

		$this->session_set( self::SESSION_COUPONS, array() );
		$this->session_set( self::SESSION_GIFTS, array() );
	}

	/**
	 * Remove the automatic gift line for a mission from the cart.
	 *
	 * The engine-removal flag is set around the mutation so the
	 * 'woocommerce_cart_item_removed' handler (restore_removed_gift) never
	 * re-adds a gift the engine deliberately revoked.
	 *
	 * @param \WC_Cart $cart    Live cart.
	 * @param int      $mission_id Mission id.
	 * @return void
	 */
	protected function remove_gift_line( \WC_Cart $cart, $mission_id ) {
		$this->removing_gift = true;

		try {
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( ! empty( $item['faracart_gift_mission'] ) && (int) $item['faracart_gift_mission'] === (int) $mission_id ) {
					$cart->remove_cart_item( $key );
				}
			}
		} finally {
			$this->removing_gift = false;
		}
	}

	/**
	 * Hide the remove link of MANDATORY gift lines only.
	 *
	 * Hooked to 'woocommerce_cart_item_remove_link'. Returning '' removes
	 * the “Remove” affordance from the cart table. Mandatory
	 * (automatic-mode) gifts cannot be removed by the shopper; selectable
	 * (choose-mode) gifts keep their remove control, and the removal is
	 * respected server-side (restore_removed_gift only re-adds mandatory
	 * gifts).
	 *
	 * @param string $link          Remove-link HTML.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function hide_gift_remove_link( $link, $cart_item_key ) {
		return $this->gift_line_is_mandatory( $cart_item_key ) ? '' : $link;
	}

	/**
	 * Whether a gift line is mandatory (automatic gift-add mode).
	 *
	 * The add mode is stamped on the line at add time
	 * ('faracart_gift_mode'); legacy lines without the stamp fall back to
	 * a repository lookup of the granting mission, then to the conservative
	 * automatic default (an unrecognised gift line stays shopper-proof
	 * until the engine re-adds it with a stamped mode).
	 *
	 * @param mixed $cart_item_key Cart item key.
	 * @return bool
	 */
	protected function gift_line_is_mandatory( $cart_item_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$item = WC()->cart->get_cart_item( (string) $cart_item_key );

		if ( ! is_array( $item ) || empty( $item['faracart_gift'] ) ) {
			return false;
		}

		if ( isset( $item['faracart_gift_mode'] ) && '' !== $item['faracart_gift_mode'] ) {
			return Reward::GIFT_AUTOMATIC === $item['faracart_gift_mode'];
		}

		if ( ! empty( $item['faracart_gift_mission'] ) && null !== $this->repository ) {
			$mission = $this->repository->find( (int) $item['faracart_gift_mission'] );

			if ( $mission ) {
				return Reward::from_mission( $mission )->is_gift_automatic();
			}
		}

		return true;
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
		if ( is_array( $cart_item ) && ! empty( $cart_item['faracart_gift'] ) ) {
			return sprintf(
				'<div class="quantity faracart-gift-quantity"><input type="hidden" name="cart[%1$s][qty]" value="1" />1</div>',
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
	 * mission is still active and still grants an automatic free-gift reward
	 * with an available product, the line is restored immediately. apply()
	 * is idempotent (the mission marker is re-checked on the line), and the
	 * next totals pass reconciles if the mission stops qualifying. The engine's
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

		if ( ! is_array( $removed ) || empty( $removed['faracart_gift'] ) || empty( $removed['faracart_gift_mission'] ) ) {
			return;
		}

		if ( null === $this->repository ) {
			return;
		}

		$mission_id = (int) $removed['faracart_gift_mission'];
		$mission    = $this->repository->find( $mission_id );

		if ( ! $mission || ! $mission->is_active() ) {
			return;
		}

		// The mission must be currently met — a removed gift line for a
		// still-active mission whose cart no longer qualifies must NOT be
		// re-added. Re-evaluate the mission against the live cart (the same
		// path sync_cart uses) to confirm the shopper still qualifies.
		$context = null !== $this->cart_integration
			? $this->cart_integration->context( $cart )
			: \FaraCart\Missions\CartContext::from_cart( $cart );
		$result  = $this->engine->evaluate( $mission, $context );

		if ( ! $result->eligible() || \FaraCart\Missions\MissionResult::REWARD_UNLOCKED !== $result->reward_state() ) {
			return;
		}

		$reward = Reward::from_mission( $mission );

		if ( Reward::TYPE_FREE_GIFT !== $reward->type()
			|| ! $reward->is_gift_automatic()
			|| $reward->gift_product_id() <= 0
			|| ! RewardSafety::gift_product_available( $reward->gift_product_id() ) ) {
			return;
		}

		$this->registry->applicator( Reward::TYPE_FREE_GIFT )->apply(
			$reward,
			RewardResult::available( $reward, $mission_id ),
			$cart,
			$mission_id
		);

		// The restored line is re-added at the product price; zero it right
		// away (idempotent) so the gift is free even before the next totals
		// pass runs the priority-10 zeroing hook.
		$this->zero_gift_prices( $cart );
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
