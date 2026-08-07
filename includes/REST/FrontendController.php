<?php
/**
 * REST controller for the public frontend progress API.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Hooks\HookManager;

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
 * Security (P07-T04): public by design — guests must be able to read their
 * own cart progress — so it requires no capability, returns only aggregate
 * numbers (no PII), and is rate limited per IP. The message field is a
 * minimal built-in placeholder; the template engine (Phase 13) owns
 * message copy. Suggestions are always empty until Phase 14.
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
	 * Constructor.
	 *
	 * @param GoalEngine       $engine          Goal engine.
	 * @param GoalRepository   $goals           Goal repository.
	 * @param CartIntegration  $cart_integration Cart snapshot service.
	 */
	public function __construct( GoalEngine $engine, GoalRepository $goals, CartIntegration $cart_integration ) {
		$this->engine          = $engine;
		$this->goals           = $goals;
		$this->cart_integration = $cart_integration;
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

		foreach ( $goals as $goal ) {
			$result = $this->engine->evaluate( $goal, $context );

			$items[] = array(
				'goal_id'      => $goal->id(),
				'goal_name'    => $goal->name(),
				'goal_type'    => $goal->type(),
				'current'      => $result->current(),
				'target'       => $result->target(),
				'remaining'    => $result->remaining(),
				'percentage'   => $result->percentage(),
				'completed'    => $result->completed(),
				'message'      => $this->message( $result ),
				'reward'       => $this->reward( $goal ),
				'suggestions'  => array(),
				'reward_state' => $result->reward_state(),
				'eligible'     => $result->eligible(),
				'reason'       => $result->reason(),
			);
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
	 * Minimal built-in progress message.
	 *
	 * Placeholder copy until the message template engine (Phase 13) ships;
	 * money-based goals get a currency-formatted remaining amount, other
	 * goals get a plain number.
	 *
	 * @param GoalResult $result Evaluation result.
	 * @return string
	 */
	protected function message( GoalResult $result ) {
		if ( $result->completed() ) {
			return __( 'You reached your goal!', 'goalcart' );
		}

		$remaining = $this->is_money_goal( $result->goal() )
			? $this->format_money( $result->remaining() )
			: (string) number_format_i18n( $result->remaining() );

		return sprintf(
			/* translators: %s: remaining amount to reach the goal. */
			__( 'Only %s left to reach your goal', 'goalcart' ),
			$remaining
		);
	}

	/**
	 * Whether a goal's progress is measured in money.
	 *
	 * Quantity-mode and weight goals are not; every other calculation basis
	 * is a money amount.
	 *
	 * @param Goal $goal Goal.
	 * @return bool
	 */
	protected function is_money_goal( Goal $goal ) {
		return Goal::TYPE_WEIGHT !== $goal->type() && Goal::MODE_QUANTITY !== $goal->calculation_mode();
	}

	/**
	 * Format a money amount with the store currency.
	 *
	 * @param float $value Amount.
	 * @return string
	 */
	protected function format_money( $value ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( (float) $value ) );
		}

		return (string) number_format_i18n( (float) $value, 2 );
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
