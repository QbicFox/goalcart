<?php
/**
 * REST controller for the public frontend progress API.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Analytics\Tracker;
use FaraCart\Cart\CartIntegration;
use FaraCart\REST\GiftController;
use FaraCart\Goals\CartContext;
use FaraCart\Goals\CompletionService;
use FaraCart\Goals\ConflictResolver;
use FaraCart\Goals\Goal;
use FaraCart\Goals\GoalEngine;
use FaraCart\Goals\GoalRepository;
use FaraCart\Goals\GoalResult;
use FaraCart\Goals\MessageEngine;
use FaraCart\Hooks\HookManager;
use FaraCart\Rewards\Reward;
use FaraCart\Rewards\RewardEngine;
use FaraCart\Recommendations\ProductRecommendationEngine;
use FaraCart\Rewards\RewardResult;
use FaraCart\Settings\Settings;
use FaraCart\Templates\TemplateEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class FrontendController
 *
 * Phase 7 (REST API / AJAX Layer) frontend endpoint:
 *
 *  - `GET /faracart/v1/progress` — the current cart's goal progress,
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
 * Security (P07-T04): public by design — guests must be able to read
 * their own cart progress — so it requires no capability, returns only
 * aggregate numbers (no PII), and is rate limited per IP. Message copy
 * is rendered by the Phase 13 MessageEngine (state-aware,
 * display-settings overridable); suggestions come from the unified
 * product recommendation engine (Phase 14 SuggestionEngine + Phase
 * 33.5 UpsellRanker merged into ONE ranked, deduplicated list —
 * published, in-stock products only).
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
	 * Unified product recommendation engine (Phase 14 + Phase 33.5):
	 * merges the suggestion and upsell strategies into ONE ranked,
	 * deduplicated customer-facing list.
	 *
	 * @var ProductRecommendationEngine
	 */
	protected $recommendations;

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
	 * Template engine (pluggable templates): resolves each goal's
	 * effective template + settings (item override → scope default →
	 * legacy → fallback) and each campaign's group template. Null when not
	 * injected — resolved lazily from the plugin container.
	 *
	 * @var TemplateEngine|null
	 */
	protected $templates;

	/**
	 * Per-user completion limit service (Phase 36): the payload carries
	 * each goal's completion status (limit / count / remaining /
	 * can_complete) for the current shopper, and a goal the shopper has
	 * already completed the maximum number of times renders the
	 * limit-reached state with its reward shown locked — the storefront
	 * only ever reflects the authoritative server state. Null when not
	 * injected (bare constructions skip the completion block).
	 *
	 * @var CompletionService|null
	 */
	protected $completions;

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
	 * @param ProductRecommendationEngine $recommendations Unified recommendation
	 *                                           engine (Phase 14 + Phase 33.5).
	 * @param Settings          $settings        Settings service.
	 * @param RewardEngine|null $reward_engine   Reward engine (Phase 26
	 *                                           display/grant parity).
	 * @param TemplateEngine|null $templates     Template engine (Phase 32).
	 * @param CompletionService|null $completions Per-user completion limit
	 *                                           service (Phase 36).
	 */
	public function __construct( GoalEngine $engine, GoalRepository $goals, CartIntegration $cart_integration, MessageEngine $messages, ProductRecommendationEngine $recommendations, Settings $settings, ?RewardEngine $reward_engine = null, ?TemplateEngine $templates = null, ?CompletionService $completions = null ) {
		$this->engine           = $engine;
		$this->goals            = $goals;
		$this->cart_integration = $cart_integration;
		$this->messages         = $messages;
		$this->recommendations  = $recommendations;
		$this->settings         = $settings;
		$this->reward_engine    = $reward_engine;
		$this->templates        = $templates;
		$this->completions      = $completions;
	}

	/**
	 * The template engine, resolved lazily when not injected.
	 *
	 * @return TemplateEngine
	 */
	protected function templates() {
		if ( null === $this->templates ) {
			$this->templates = \FaraCart\Plugin::instance()->container()->get( TemplateEngine::class );
		}

		return $this->templates;
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
				// The cached payload never stores the session-bound nonces
				// (the write path below strips them), so re-inject fresh
				// ones here — a cached payload can never serve a stale or
				// another user's nonce.
				if ( isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
					$cached['data']['tracking_nonce'] = $this->tracking_nonce();
					$cached['data']['gift_nonce']     = $this->gift_nonce();
				}

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

		// Phase 36 (per-user completion limit): one batched completion-count
		// query primes the per-request cache so the per-goal shape below
		// never runs N individual counts on the storefront. The completion
		// status is user-specific, so the optional progress transient is
		// keyed by identity too (see progress_cache_key).
		if ( null !== $this->completions ) {
			$this->completions->context_statuses( $goals, $context );
		}

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			$items[] = $this->shape_goal( $goal, $result, $context, $extra, false, $this->conflict_for( $resolved, $goal ) );
		}

		$response = $this->success(
			array(
				'goals'    => $items,
				// Campaign template groups (pluggable engine): only campaigns
				// with a configured campaign-scoped template are listed — the
				// storefront renders those milestone groups through the
				// campaign template (e.g. the milestone chain) instead of
				// per-goal cards.
				'campaigns' => $this->campaign_groups( $goals ),
				'currency' => $this->settings->currency(),
				// Self-healing tracking nonce: a freshly minted faracart_track
				// nonce rides on every progress response so the storefront JS
				// can adopt it before reporting events — a cached page serving
				// an expired or another user's nonce self-heals within one
				// poll instead of producing a stream of 403s. See
				// tracking_nonce().
				'tracking_nonce' => $this->tracking_nonce(),
				// Phase 32 (free gift selection): a freshly minted gift nonce
				// rides on every progress response so the storefront JS can
				// adopt it before claiming a gift — a long-lived cart page
				// never outlives its gift nonce window.
				'gift_nonce'     => $this->gift_nonce(),
			),
			array(
				'total_goals' => count( $items ),
			)
		);

		if ( $caching ) {
			// Cache the envelope WITHOUT the session-bound nonces (tracking
			// + gift): they are regenerated fresh on every read (see the
			// cache-hit branch above), so a cached payload can never serve a
			// stale or another user's nonce.
			$cache_payload = $response->get_data();
			unset( $cache_payload['data']['tracking_nonce'], $cache_payload['data']['gift_nonce'] );
			set_transient( $cache_key, $cache_payload, self::PROGRESS_CACHE_TTL );
		}

		$this->prevent_progress_caching( $response );

		return $response;
	}

	/**
	 * A fresh tracking nonce for the storefront analytics endpoint.
	 *
	 * The tracking nonce baked into a cached page expires after its
	 * 12-hour tick and can be bound to another user's session, which turns
	 * every subsequent `/track` report into `faracart_invalid_nonce` (403).
	 * The progress payload therefore mints a fresh nonce on every poll and
	 * frontend.js adopts it before the next event report.
	 *
	 * The gate mirrors the master toggles of Tracker::tracking_enabled()
	 * (which additionally applies the faracart_tracking_enabled filter —
	 * not re-applied here; the /track handler enforces it anyway, so a
	 * disabled-by-filter store simply drops events). Keep the two gates in
	 * sync if a third toggle is ever added.
	 *
	 * @return string
	 */
	protected function tracking_nonce() {
		// Mirrors the master toggles of Tracker::tracking_enabled() — keep
		// the two in sync if a third toggle is ever added.
		if ( ! $this->settings->get( 'enabled', true ) || ! $this->settings->get( 'analytics_enabled', true ) ) {
			return '';
		}

		if ( ! class_exists( Tracker::class ) ) {
			return '';
		}

		return wp_create_nonce( Tracker::TRACK_NONCE_ACTION );
	}

	/**
	 * A fresh gift nonce for the storefront gift endpoint.
	 *
	 * Mirrors the tracking-nonce self-healing pattern: the gift nonce
	 * baked into a cached page expires after its 12-hour tick, so every
	 * progress poll mints a fresh one and frontend.js adopts it before
	 * the shopper claims a gift. Gated on the master `enabled` toggle
	 * (matching ProgressUI::frontend_config()).
	 *
	 * @return string
	 */
	protected function gift_nonce() {
		if ( ! $this->settings->get( 'enabled', true ) ) {
			return '';
		}

		if ( ! class_exists( GiftController::class ) ) {
			return '';
		}

		return wp_create_nonce( GiftController::GIFT_NONCE_ACTION );
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

		// Phase 36: the payload now carries per-user completion status, so
		// the cache key MUST include the identity — a cached payload can
		// never serve another shopper's completion counts (Phase 21 rule:
		// identity is part of the cache key).
		$identity = null !== $this->completions ? $this->completions->context_identity( $context ) : array();

		return 'faracart_progress_' . md5(
			wp_json_encode(
				array(
					'ctx'         => array(
						$context->subtotal(),
						$context->total(),
						$context->total_quantity(),
						$context->distinct_product_count(),
						$context->total_weight(),
					),
					'identity'    => $identity,
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

		$resolved = $this->templates()->resolve_goal( $goal );

		// Phase 36 (per-user completion limit): the shopper's completion
		// status for this goal (completion_limit / completion_count /
		// remaining_completions / can_complete). Null when the service is
		// absent (bare constructions). When the shopper can no longer
		// complete the goal, the reward renders locked (the conflict
		// fragment is overridden — a limit-reached reward must never look
		// claimable) and the message switches to the limit copy.
		$completion    = $this->completion_status( $goal, $context );
		$limit_reached = is_array( $completion ) && empty( $completion['can_complete'] );

		if ( $limit_reached ) {
			$conflict = array(
				'resolved' => false,
				'reason'   => 'completion_limit',
			);
		}

		return array(
			'goal_id'      => $goal->id(),
			'campaign_id'  => $goal->campaign_id(),
			'goal_name'    => $goal->name(),
			'goal_type'    => $goal->type(),
			'is_money'     => $this->is_money_goal( $goal ),
			'icon'         => $this->goal_icon( $goal ),
			// The effective template + settings (item override → scope
			// default → legacy → fallback) — the storefront renders exactly
			// what the template engine resolved.
			'template'         => $resolved['template_id'],
			'template_settings' => $resolved['settings'],
			'current'      => $result->current(),
			'target'       => $result->target(),
			'remaining'    => $result->remaining(),
			'percentage'   => $result->percentage(),
			'completed'    => $result->completed(),
			'state'        => $this->messages->state( $goal, $result, $completion ),
			'message'      => $this->messages->message( $goal, $result, $extra, $completion ),
			'reward'       => $this->reward( $goal, ! $full_reward_meta ),
			'suggestions'  => $this->suggestions_on() ? $this->recommendations->recommend( $goal, $result, $context ) : array(),
			'reward_state' => $result->reward_state(),
			'eligible'     => $result->eligible(),
			'reason'       => $result->reason(),
			'conflict'     => $conflict,
			// Phase 36 (per-user completion limit): the shopper's own
			// completion status — the storefront reflects it, the server
			// enforces it.
			'completion'   => $completion,
			// Phase 32 (countdown): the goal's deadline as a local-time ISO
			// string ('' when the goal has no end time). The storefront JS
			// renders a live countdown chip from it.
			'countdown_end' => $this->countdown_end( $goal ),
		);
	}

	/**
	 * The shopper's completion status for a goal (Phase 36).
	 *
	 * Null when the completion service is absent (bare constructions). The
	 * per-request count cache is primed in handle_progress() with one
	 * batched query, so the per-goal reads here are cache hits on the
	 * storefront; the admin preview path pays one COUNT per goal (admin
	 * only, acceptable).
	 *
	 * @param Goal        $goal    Goal.
	 * @param CartContext $context Cart snapshot.
	 * @return array<string, mixed>|null
	 */
	protected function completion_status( Goal $goal, CartContext $context ) {
		if ( null === $this->completions ) {
			return null;
		}

		return $this->completions->context_status( $goal, $context );
	}

	/**
	 * The goal's deadline for the storefront countdown (Phase 32).
	 *
	 * Only an end time in the future is worth counting down to; past and
	 * empty values render no chip. The stored site-local datetime is
	 * emitted as a local-time ISO string ('2026-08-07T14:30:00') so the
	 * JS Date parsing matches the site clock.
	 *
	 * @param Goal $goal Goal.
	 * @return string
	 */
	protected function countdown_end( Goal $goal ) {
		$ends_at = $goal->ends_at();

		if ( empty( $ends_at ) || ! (bool) $this->settings->get( 'frontend_countdown', true ) ) {
			return '';
		}

		if ( $ends_at < current_time( 'mysql' ) ) {
			return '';
		}

		return str_replace( ' ', 'T', (string) $ends_at );
	}

	/**
	 * Whether the storefront payload carries product suggestions.
	 *
	 * Phase 18 (Settings → Performance → suggestions): an opt-out for
	 * stores that want the goals without the upsell list. Filterable via
	 * faracart_suggestions_enabled (the Phase 28 developer API hook).
	 *
	 * @return bool
	 */
	protected function suggestions_on() {
		$on = (bool) $this->settings->get( 'performance_suggestions', true );

		return (bool) apply_filters( 'faracart_suggestions_enabled', $on );
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
	 * The campaign template groups for the progress payload.
	 *
	 * Groups the served goals by campaign and resolves each campaign's
	 * template + settings through the engine. Only campaigns with a
	 * configured campaign-scoped template are listed — a campaign without
	 * one keeps the pre-engine per-goal card rendering.
	 *
	 * @param Goal[] $goals Goals being served.
	 * @return array<int, array<string, mixed>>
	 */
	protected function campaign_groups( array $goals ) {
		$groups = array();

		foreach ( $goals as $goal ) {
			if ( ! $goal->campaign_id() ) {
				continue;
			}

			if ( isset( $groups[ $goal->campaign_id() ] ) ) {
				continue;
			}

			$resolved = $this->templates()->resolve_campaign( $goal->campaign_display_rules() );

			if ( '' === $resolved['template_id'] ) {
				continue; // No campaign template configured → per-goal cards.
			}

			$groups[ $goal->campaign_id() ] = array(
				'campaign_id' => (int) $goal->campaign_id(),
				'name'        => $goal->campaign_name(),
				'template'    => $resolved['template_id'],
				'settings'    => $resolved['settings'],
			);
		}

		// Phase 32 (countdown): a campaign group exposes the latest of its
		// milestones' end times so the storefront can render one countdown
		// per campaign.
		foreach ( $goals as $goal ) {
			if ( ! $goal->campaign_id() || ! isset( $groups[ $goal->campaign_id() ] ) ) {
				continue;
			}

			$ends = $this->countdown_end( $goal );

			if ( '' !== $ends ) {
				$current = isset( $groups[ $goal->campaign_id() ]['countdown_end'] )
					? (string) $groups[ $goal->campaign_id() ]['countdown_end']
					: '';

				if ( '' === $current || $ends > $current ) {
					$groups[ $goal->campaign_id() ]['countdown_end'] = $ends;
				}
			}
		}

		return array_values( $groups );
	}

	/**
	 * The goal's reward summary for the frontend.
	 *
	 * P22 hardening: the PUBLIC `/faracart/v1/progress` payload carries only
	 * the reward offer the widget needs to render (type, value, max_value).
	 * The full configuration — including configured coupon codes, the
	 * deterministic generated-coupon code, gift product ids, eligible /
	 * excluded product and category lists, and shipping restrictions — is
	 * deliberately NOT exposed: any visitor could otherwise harvest those
	 * secrets at `wp-json/faracart/v1/progress` without authentication and
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

		// Phase 32 (free gift selection): a choose-mode gift goal exposes
		// the candidate gifts as catalog data (id/name/image/price — store
		// products, no secrets) so the storefront picker can render; the
		// gift_chosen flag reflects the live cart. Only rendered for the
		// public payload — the admin preview gets the full meta anyway.
		if ( $redact_meta && Reward::TYPE_FREE_GIFT === $goal->reward_type() ) {
			$meta = $goal->reward_meta();

			if ( isset( $meta['gift_add_mode'] ) && 'choose' === $meta['gift_add_mode'] ) {
				$reward['gift']        = $this->gift_catalog( $meta );
				$reward['gift_chosen'] = $this->gift_chosen_in_cart( $goal->id() );
			}
		}

		if ( ! $redact_meta ) {
			$reward['meta'] = $goal->reward_meta();
		}

		return $reward;
	}

	/**
	 * The catalog-safe gift list for the storefront picker.
	 *
	 * @param array<string, mixed> $meta Reward meta.
	 * @return array<int, array<string, mixed>>
	 */
	protected function gift_catalog( array $meta ) {
		$ids = isset( $meta['gift_products'] ) && is_array( $meta['gift_products'] ) ? $meta['gift_products'] : array();
		$ids = array_values( array_filter( array_map( 'intval', $ids ), function ( $id ) {
			return $id > 0;
		} ) );

		$items = array();

		if ( empty( $ids ) || ! function_exists( 'wc_get_product' ) ) {
			return $items;
		}

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product || 'publish' !== $product->get_status() ) {
				continue;
			}

			$image_id = $product->get_image_id();
			$price    = $product->get_price();

			$items[] = array(
				'id'         => (int) $product->get_id(),
				'name'       => $product->get_name(),
				'image'      => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '',
				'price'      => '' !== $price ? (float) $price : null,
				'price_html' => '' !== $price && function_exists( 'wc_price' )
					? html_entity_decode( wp_strip_all_tags( wc_price( (float) $price, array( 'currency' => $this->settings->currency() ) ) ), ENT_QUOTES, 'UTF-8' )
					: '',
			);
		}

		return $items;
	}

	/**
	 * Whether the live cart already carries a chosen gift for the goal.
	 *
	 * @param int $goal_id Goal id.
	 * @return bool
	 */
	protected function gift_chosen_in_cart( $goal_id ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['faracart_gift_goal'] ) && (int) $item['faracart_gift_goal'] === (int) $goal_id ) {
				return true;
			}
		}

		return false;
	}
}
