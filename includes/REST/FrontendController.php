<?php
/**
 * REST controller for the public frontend progress API.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\ConflictResolver;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Goals\MessageEngine;
use GoalCart\Hooks\HookManager;
use GoalCart\Rewards\RewardEngine;
use GoalCart\Rewards\RewardResult;
use GoalCart\Settings\Settings;
use GoalCart\Suggestions\SuggestionEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class FrontendController
 *
 * Phase 7 (REST API / AJAX Layer) frontend endpoint:
 *
 *  - `GET /goalcart/v1/progress` — the current cart's goal progress,
 *    exposing only the minimum necessary data (P07-T03):
 *
 *    ```text
 *    current, target, remaining, percentage, completed,
 *    message, reward, suggestions
 *    ```
 *
 *    one entry per active goal, plus cart/currency metadata. The progress
 *    widgets (Phase 11) poll this endpoint and re-render.
 *
 *  - `shape_goal()` — the shared per-goal payload shaper, the single
 *    source of truth for the item shape above. It is consumed by this
 *    endpoint and by the Phase 15 PreviewController, so the admin preview
 *    and the storefront payload can never drift.
 *	 * Security (P07-T04): public by design — guests must be able to read their
	 * own cart progress — so it requires no capability, returns only aggregate
	 * numbers (no PII), and is rate limited per IP. Message copy is rendered
	 * by the Phase 13 MessageEngine (state-aware, display-settings
	 * overridable); suggestions come from the Phase 14 SuggestionEngine
	 * (published, in-stock products only).
	 */
class FrontendController extends BaseController {

	/**
	 * Goal engine instance.
	 *
	 * @var GoalEngine
	 */
	protected $engine;

	/**
	 * Goal repository instance.
	 *
	 * @var GoalRepository
	 */
	protected $goals;

	/**
	 * Cart integration instance.
	 *
	 * @var CartIntegration
	 */
	protected $cart_integration;

	/**
	 * Message engine instance (Phase 13: dynamic messaging).
	 *
	 * @var MessageEngine
	 */
	protected $messages;

	/**
	 * Suggestion engine instance (Phase 14: smart product suggestions).
	 *
	 * @var SuggestionEngine
	 */
	protected $suggestions;

	/**
	 * Settings instance (Phase 18: goal behavior, suggestions, caching).
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Reward engine (Phase 26 display/grant parity): evaluates each
	 * completed goal's reward against the same cart snapshot the engine
	 * grants with, so 'best' mode compares real computed amounts and the
	 * payload reflects stacking suppression exactly like the live cart.
	 * Null when unavailable (bare constructions) — falls back to the
	 * deterministic static scoring and skips the stacking mirror.
	 *
	 * @var RewardEngine|null
	 */
	protected $reward_engine;

	/**
	 * Progress payload transient TTL in seconds (performance_caching).
	 *
	 * @var int
	 */
	const PROGRESS_CACHE_TTL = 10;

	/**
	 * Constructor.
	 *
	 * @param GoalEngine        $engine          Goal engine.
	 * @param GoalRepository    $goals           Goal repository.
	 * @param CartIntegration   $cart_integration Cart snapshot service.
	 * @param MessageEngine     $messages        Message template engine.
	 * @param SuggestionEngine  $suggestions     Suggestion engine.
	 * @param Settings          $settings        Settings service.
	 * @param RewardEngine|null $reward_engine   Reward engine (Phase 26
	 *                                           display/grant parity).
	 */
	public function __construct( GoalEngine $engine, GoalRepository $goals, CartIntegration $cart_integration, MessageEngine $messages, SuggestionEngine $suggestions, Settings $settings, ?RewardEngine $reward_engine = null ) {
		$this->engine           = $engine;
		$this->goals            = $goals;
		$this->cart_integration = $cart_integration;
		$this->messages         = $messages;
		$this->suggestions      = $suggestions;
		$this->settings         = $settings;
		$this->reward_engine    = $reward_engine;
	}

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the progress route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/progress',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_progress' ),
				'permission_callback' => $this->get_public_permission_callback(),
				'args'                => array(),
			)
		);
	}

	/**
	 * Handle a cart-progress read.
	 *
	 * Evaluates every active goal against the current cart snapshot and
	 * returns the minimal per-goal progress payload. Accepts an optional
	 * cart for testability/embedding; null uses the live cart via
	 * CartIntegration.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param \WC_Cart|null    $cart    Cart to evaluate (null = live cart).
	 * @return \WP_REST_Response
	 */
	public function handle_progress( $request, ?\WC_Cart $cart = null ) {
		$caching = (bool) $this->settings->get( 'performance_caching', false );

		$context = $this->cart_integration->context( $cart );
		$goals   = $this->active_goals_for( $this->goals->active_goals(), $context );

		// Phase 18 (Performance → caching): a short-lived transient keyed
		// by the cart snapshot + goals + behavior settings serves repeat
		// widget polls without re-evaluating every goal. The key embeds the
		// cart state, so any cart change produces a fresh payload within
		// one TTL; admin-disabled by default.
		$cache_key = $caching ? $this->progress_cache_key( $context, $goals ) : '';

		if ( $caching ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				$cached_response = rest_ensure_response( $cached );
				$this->prevent_progress_caching( $cached_response );

				return $cached_response;
			}
		}

		$items = array();

		$extra = array(
			'quantity' => $context->total_quantity(),
		);

		// Phase 26 (Conflict & Priority Engine): the same deterministic
		// resolution the reward engine grants with is reflected here, so
		// every goal's payload says whether it won or was suppressed (and
		// why). The storefront widgets keep rendering every goal's
		// progress; the conflict flag lets them show the honest reward
		// state (a suppressed reward never renders as unlocked).
		$resolved = $this->resolve_conflicts( $goals, $context );

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			$items[] = $this->shape_goal( $goal, $result, $context, $extra, false, $this->conflict_for( $resolved, $goal ) );
		}

		$response = $this->success(
			array(
				'goals'    => $items,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			),
			array(
				'total_goals' => count( $items ),
			)
		);

		if ( $caching ) {
			set_transient( $cache_key, $response->get_data(), self::PROGRESS_CACHE_TTL );
		}

		$this->prevent_progress_caching( $response );

		return $response;
	}

	/**
	 * Mark the public progress payload as non-cacheable.
	 *
	 * The guest `/progress` response carries no Cache-Control by default
	 * (WP core only sends nocache headers for cookie-authenticated
	 * requests), so browsers may heuristically cache the first payload
	 * and keep serving it after the shopper's cart changed — the widget
	 * would render stale progress. `no-store` forbids both browser and
	 * shared caches from ever reusing the response; the storefront JS
	 * additionally cache-busts its request with a timestamp parameter.
	 *
	 * @param \WP_REST_Response $response Response to annotate.
	 * @return void
	 */
	protected function prevent_progress_caching( \WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	}

	/**
	 * Narrow the active goals per the default goal behavior setting.
	 *
	 * Phase 18 (Settings → General):
	 *
	 *  - all     — every active goal (default)
	 *  - first   — only the first active goal (repository order)
	 *  - closest — only the eligible goal closest to completion
	 *
	 * The storefront still picks an eligible featured goal per render, so
	 * a narrowed set changes how many goals are advertised at once.
	 *
	 * @param Goal[]      $goals   Active goals.
	 * @param CartContext $context Cart snapshot.
	 * @return Goal[]
	 */
	protected function active_goals_for( array $goals, CartContext $context ) {
		$behavior = $this->settings->get( 'default_goal_behavior', 'all' );

		if ( 'all' === $behavior || count( $goals ) < 2 ) {
			return $goals;
		}

		if ( 'first' === $behavior ) {
			return array( $goals[0] );
		}

		// 'closest': the eligible goal with the highest percentage.
		$best      = null;
		$best_perc = -1.0;

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			if ( $result->eligible() && $result->percentage() > $best_perc ) {
				$best      = $goal;
				$best_perc = $result->percentage();
			}
		}

		return $best ? array( $best ) : array( $goals[0] );
	}

	/**
	 * Resolve conflict winners among the given goals for the payload.
	 *
	 * Mirrors the RewardEngine pass (Phase 26) so the payload is always
	 * what the live cart grants: completed goals that carry a reward
	 * compete under the configured mode ('best' compares the real computed
	 * reward amounts on the same cart snapshot), and the per-reward
	 * stacking safety applies to the winners in priority order — a
	 * same-type non-stacking loser is blocked with the 'stacking' reason.
	 * Display narrowing (default_goal_behavior) happens before this, so a
	 * narrowed set resolves within itself.
	 *
	 * @param Goal[]      $goals   Goals being served.
	 * @param CartContext $context Cart snapshot.
	 * @return array<int, string> goal_id => ConflictResolver::REASON_*.
	 */
	protected function resolve_conflicts( array $goals, CartContext $context ) {
		$mode    = (string) $this->settings->get( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE );
		$results = array();
		$scores  = array();
		$rewards = array();

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			if ( ! $result->eligible() || GoalResult::REWARD_UNLOCKED !== $result->reward_state() ) {
				continue;
			}

			if ( empty( $goal->reward_type() ) ) {
				continue;
			}

			$results[ $goal->id() ] = $result;

			// The reward is evaluated WITHOUT the stacking guard (the empty
			// already_applied pass), exactly like RewardEngine::sync_cart()
			// pass 1 — the amount drives 'best', the state drives the
			// stacking mirror below.
			if ( null !== $this->reward_engine ) {
				$reward_result = $this->reward_engine->evaluate(
					$result,
					array( 'cart' => $context )
				);

				$rewards[ $goal->id() ] = $reward_result;

				if ( RewardResult::STATE_AVAILABLE === $reward_result->state() ) {
					$scores[ $goal->id() ] = $reward_result->amount();
				}
			}
		}

		$resolver  = new ConflictResolver();
		$resolution = $resolver->resolve( $goals, $results, $mode, $scores );

		if ( null !== $this->reward_engine ) {
			$resolution = $resolver->apply_stacking( $goals, $resolution, $rewards );
		}

		return $resolution;
	}

	/**
	 * The per-goal conflict payload fragment.
	 *
	 * Goals that did not participate in resolution (not completed, no
	 * reward) are reported as resolved — they are never suppressed.
	 *
	 * @param array<int, string> $resolution goal_id => reason.
	 * @param Goal               $goal       Goal.
	 * @return array<string, mixed>
	 */
	protected function conflict_for( array $resolution, Goal $goal ) {
		$reason = isset( $resolution[ $goal->id() ] ) ? $resolution[ $goal->id() ] : ConflictResolver::REASON_NONE;

		return array(
			'resolved' => ConflictResolver::REASON_NONE === $reason,
			'reason'   => $reason,
		);
	}

	/**
	 * The progress cache key for a cart snapshot + goal set.
	 *
	 * @param CartContext $context Cart snapshot.
	 * @param Goal[]      $goals   Selected goals.
	 * @return string
	 */
	protected function progress_cache_key( CartContext $context, array $goals ) {
		$goal_ids = array();

		foreach ( $goals as $goal ) {
			$goal_ids[] = $goal->id();
		}

		return 'goalcart_progress_' . md5(
			wp_json_encode(
				array(
					'ctx'         => array(
						$context->subtotal(),
						$context->total(),
						$context->total_quantity(),
						$context->distinct_product_count(),
						$context->total_weight(),
					),
					'goals'       => $goal_ids,
					'behavior'    => $this->settings->get( 'default_goal_behavior', 'all' ),
					'conflict'    => $this->settings->get( 'conflict_resolution', ConflictResolver::MODE_CUMULATIVE ),
					'suggestions' => (bool) $this->settings->get( 'performance_suggestions', true ),
				)
			)
		);
	}

	/**
	 * Shape one goal evaluation into the shared progress payload item.
	 *
	 * Single source of truth for the per-goal payload shape, used by both
	 * the public `GET /progress` endpoint (live cart) and the admin
	 * PreviewController (Phase 15, simulated cart) so the two can never
	 * drift apart. Mirrors the documented payload in docs/api.md.
	 *
	 * @param Goal        $goal    Goal.
	 * @param GoalResult  $result  Evaluation result.
	 * @param CartContext $context Cart snapshot the goal was evaluated on
	 *                             (drives suggestions).
	 * @param array<string, mixed> $extra Extra message variables (quantity,
	 *                             remaining_quantity, campaign_name).
	 * @param bool         $full_reward_meta Whether to expose the full reward
	 *                             meta (admin preview only — P22 redaction).
	 * @param array<string, mixed>|null $conflict Conflict payload fragment
	 *                             (resolved/reason, Phase 26); null = goal
	 *                             was not suppressed.
	 * @return array<string, mixed>
	 */
	public function shape_goal( Goal $goal, GoalResult $result, CartContext $context, array $extra = array(), $full_reward_meta = false, $conflict = null ) {
		if ( ! is_array( $conflict ) ) {
			$conflict = array(
				'resolved' => true,
				'reason'   => ConflictResolver::REASON_NONE,
			);
		}

		return array(
			'goal_id'      => $goal->id(),
			'campaign_id'  => $goal->campaign_id(),
			'goal_name'    => $goal->name(),
			'goal_type'    => $goal->type(),
			'is_money'     => $this->is_money_goal( $goal ),
			'icon'         => $this->goal_icon( $goal ),
			'template'     => $this->goal_template( $goal ),
			'current'      => $result->current(),
			'target'       => $result->target(),
			'remaining'    => $result->remaining(),
			'percentage'   => $result->percentage(),
			'completed'    => $result->completed(),
			'state'        => $this->messages->state( $goal, $result ),
			'message'      => $this->messages->message( $goal, $result, $extra ),
			'reward'       => $this->reward( $goal, ! $full_reward_meta ),
			'suggestions'  => $this->suggestions_on() ? $this->suggestions->suggest( $goal, $result, $context ) : array(),
			'reward_state' => $result->reward_state(),
			'eligible'     => $result->eligible(),
			'reason'       => $result->reason(),
			'conflict'     => $conflict,
		);
	}

	/**
	 * Whether the storefront payload carries product suggestions.
	 *
	 * Phase 18 (Settings → Performance → suggestions): an opt-out for
	 * stores that want the goals without the upsell list. Filterable via
	 * goalcart_suggestions_enabled (the Phase 28 developer API hook).
	 *
	 * @return bool
	 */
	protected function suggestions_on() {
		$on = (bool) $this->settings->get( 'performance_suggestions', true );

		return (bool) apply_filters( 'goalcart_suggestions_enabled', $on );
	}

	/**
	 * Whether a goal's progress is measured in money.
	 *
	 * Quantity/distinct-quantity/weight goals count items, not money, and
	 * quantity-mode category/product goals do too. Quantity goals default
	 * to the subtotal calculation mode (Goal::default_calculation_mode),
	 * so the type is checked in addition to the mode — keeps the widget
	 * milestone labels and the Phase 13 message numbers consistent.
	 *
	 * @param Goal $goal Goal.
	 * @return bool
	 */
	protected function is_money_goal( Goal $goal ) {
		return $goal->is_money_goal();
	}

	/**
	 * The goal's display icon for the card template (Phase 12).
	 *
	 * Comes from the goal builder's Display section (`display_settings.icon`);
	 * empty when none was configured — the widget falls back to its own
	 * default icon.
	 *
	 * @param Goal $goal Goal.
	 * @return string
	 */
	protected function goal_icon( Goal $goal ) {
		$display = $goal->display_settings();

		return isset( $display['icon'] ) && is_string( $display['icon'] ) ? trim( $display['icon'] ) : '';
	}

	/**
	 * The goal's display template for the progress widget (Phase 12).
	 *
	 * Comes from the goal builder's Display section
	 * (`display_settings.template`) and is normalized to the template enum
	 * so a bad stored value never reaches the widget. Empty when none was
	 * configured — the widget falls back to the store-wide Appearance
	 * template.
	 *
	 * @param Goal $goal Goal.
	 * @return string
	 */
	protected function goal_template( Goal $goal ) {
		$display   = $goal->display_settings();
		$template  = isset( $display['template'] ) ? (string) $display['template'] : '';
		$templates = array( 'basic', 'percentage', 'milestone', 'card' );

		return in_array( $template, $templates, true ) ? $template : '';
	}

	/**
	 * The goal's reward summary for the frontend.
	 *
	 * P22 hardening: the PUBLIC `/goalcart/v1/progress` payload carries only
	 * the reward offer the widget needs to render (type, value, max_value).
	 * The full configuration — including configured coupon codes, the
	 * deterministic generated-coupon code, gift product ids, eligible /
	 * excluded product and category lists, and shipping restrictions — is
	 * deliberately NOT exposed: any visitor could otherwise harvest those
	 * secrets at `wp-json/goalcart/v1/progress` without authentication and
	 * redeem a coupon reward before its goal is completed.
	 *
	 * The admin PreviewController shapes the same goal but may pass
	 * `$private_meta = true` (it is manage_options-gated), so the admin
	 * preview can still reflect the configured reward meta.
	 *
	 * @param Goal $goal Goal.
	 * @param bool $redact_meta Whether the meta must be stripped. Default
	 *                          true (public endpoint contract).
	 * @return array<string, mixed>|null
	 */
	protected function reward( Goal $goal, $redact_meta = true ) {
		if ( empty( $goal->reward_type() ) ) {
			return null;
		}

		$reward = array(
			'type'      => $goal->reward_type(),
			'value'     => $goal->reward_value(),
			'max_value' => $goal->reward_max_value(),
		);

		if ( ! $redact_meta ) {
			$reward['meta'] = $goal->reward_meta();
		}

		return $reward;
	}
}
