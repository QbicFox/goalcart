<?php
/**
 * REST controller for the public frontend progress API.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Goals\MessageEngine;
use GoalCart\Hooks\HookManager;
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
	 * Constructor.
	 *
	 * @param GoalEngine        $engine          Goal engine.
	 * @param GoalRepository    $goals           Goal repository.
	 * @param CartIntegration   $cart_integration Cart snapshot service.
	 * @param MessageEngine     $messages        Message template engine.
	 * @param SuggestionEngine  $suggestions     Suggestion engine.
	 */
	public function __construct( GoalEngine $engine, GoalRepository $goals, CartIntegration $cart_integration, MessageEngine $messages, SuggestionEngine $suggestions ) {
		$this->engine           = $engine;
		$this->goals            = $goals;
		$this->cart_integration = $cart_integration;
		$this->messages         = $messages;
		$this->suggestions      = $suggestions;
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
		$context = $this->cart_integration->context( $cart );
		$goals   = $this->goals->active_goals();

		$items = array();

		$extra = array(
			'quantity' => $context->total_quantity(),
		);

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			$items[] = $this->shape_goal( $goal, $result, $context, $extra );
		}

		return $this->success(
			array(
				'goals'    => $items,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			),
			array(
				'total_goals' => count( $items ),
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
	 * @return array<string, mixed>
	 */
	public function shape_goal( Goal $goal, GoalResult $result, CartContext $context, array $extra = array() ) {
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
			'reward'       => $this->reward( $goal ),
			'suggestions'  => $this->suggestions->suggest( $goal, $result, $context ),
			'reward_state' => $result->reward_state(),
			'eligible'     => $result->eligible(),
			'reason'       => $result->reason(),
		);
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
	 * Null when the goal has no reward configured; otherwise the flat
	 * reward fields (the frontend mirrors the reward offer regardless of
	 * lock state).
	 *
	 * @param Goal $goal Goal.
	 * @return array<string, mixed>|null
	 */
	protected function reward( Goal $goal ) {
		if ( empty( $goal->reward_type() ) ) {
			return null;
		}

		return array(
			'type'      => $goal->reward_type(),
			'value'     => $goal->reward_value(),
			'max_value' => $goal->reward_max_value(),
			'meta'      => $goal->reward_meta(),
		);
	}
}
