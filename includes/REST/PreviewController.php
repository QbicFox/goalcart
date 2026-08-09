<?php
/**
 * REST controller for the admin preview system.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Campaigns\CampaignRepository;
use GoalCart\Goals\CartContext;
use GoalCart\Goals\CartItem;
use GoalCart\Goals\Goal;
use GoalCart\Goals\GoalEngine;
use GoalCart\Goals\GoalRepository;
use GoalCart\Goals\GoalResult;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class PreviewController
 *
 * Phase 15 (Admin Preview System): lets administrators see the customer
 * experience before publishing.
 *
 *  - `POST /goalcart/v1/preview` — evaluates a goal (or a campaign's
 *    milestone goals) against a SIMULATED cart and returns the exact same
 *    per-goal payload shape as the public `GET /progress` endpoint, so the
 *    admin React preview renders the real storefront widget (templates,
 *    messages, rewards, suggestions) without publishing anything.
 *
 * Preview never affects the real WooCommerce cart: a synthetic CartContext
 * is built purely from the request's `simulated` values (cart amount and
 * item quantity), and the engine / message / suggestion services are all
 * pure — no cart is loaded, no session is touched, no fees or coupons are
 * applied. Publish gating is ignored on purpose (the goal is previewed as
 * active and in-schedule) so drafts, inactive goals and scheduled
 * campaigns can be seen before they go live.
 *
 * Simulation fidelity (documented trade-off): a single synthetic cart line
 * carries the simulated amount, quantity, weight, categories and product
 * id, so amount/quantity/distinct-quantity/category/weight goals evaluate
 * honestly; product goals use the first configured product id; composite
 * goals union their children's constraints (a product child beyond the
 * first cannot be represented by one line and is approximated).
 *
 * Security (P07-T04, mirrored): admin-only — `manage_options` capability
 * plus per-user rate limiting, every input validated through the route arg
 * schema, and the payload carries only aggregate numbers (plus the admin's
 * own simulated values).
 */
class PreviewController extends BaseController {

	/**
	 * Rate-limit budget for the preview route (requests per window).
	 *
	 * Higher than the default 60/min: the preview dialog fires a debounced
	 * request per control change (simulated amount/quantity typing, preset
	 * clicks), so generous headroom avoids tripping admins mid-interaction.
	 *
	 * @var int
	 */
	const PREVIEW_RATE_LIMIT_COUNT = 300;

	/**
	 * Goal engine instance.
	 *
	 * @var GoalEngine
	 */
	protected $engine;

	/**
	 * Goal repository instance (stored rows, for preview goal loading).
	 *
	 * @var GoalRepository
	 */
	protected $goals;

	/**
	 * Campaign repository instance (milestone loading).
	 *
	 * @var CampaignRepository
	 */
	protected $campaigns;

	/**
	 * Frontend controller (shared payload shaping — message, suggestions,
	 * reward, icon, template all flow through shape_goal()).
	 *
	 * @var FrontendController
	 */
	protected $frontend;

	/**
	 * Constructor.
	 *
	 * @param GoalEngine         $engine     Goal engine.
	 * @param GoalRepository     $goals      Goal repository.
	 * @param CampaignRepository $campaigns  Campaign repository.
	 * @param FrontendController $frontend   Frontend controller (shape_goal).
	 */
	public function __construct( GoalEngine $engine, GoalRepository $goals, CampaignRepository $campaigns, FrontendController $frontend ) {
		$this->engine    = $engine;
		$this->goals     = $goals;
		$this->campaigns = $campaigns;
		$this->frontend  = $frontend;
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
	 * Register the preview route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_preview' ),
				'permission_callback' => $this->get_preview_permission_callback(),
				'args'                => $this->preview_args(),
			)
		);
	}

	/**
	 * Permission callback for the preview route.
	 *
	 * Admin-only (manage_options + rate limit) like every admin endpoint,
	 * but with a larger per-window budget so the dialog's debounced
	 * preview requests cannot trip the default 60/min limiter.
	 *
	 * @return callable
	 */
	public function get_preview_permission_callback() {
		return function ( $request ) {
			$capability = apply_filters( 'goalcart_rest_capability', self::CAPABILITY );

			if ( ! current_user_can( $capability ) ) {
				return $this->error(
					'goalcart_forbidden',
					__( 'You are not allowed to access this endpoint.', 'goalcart' ),
					403
				);
			}

			$limited = $this->rate_limit( $request, self::PREVIEW_RATE_LIMIT_COUNT );

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}

			return true;
		};
	}

	/**
	 * Arg schema for the preview route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function preview_args() {
		return array(
			'goal_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'campaign_id' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'simulated' => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'amount'   => array( 'type' => 'number', 'minimum' => 0 ),
					'quantity' => array( 'type' => 'number', 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Handle a preview read.
	 *
	 * Exactly one of goal_id / campaign_id is required. Evaluates the
	 * target goal(s) against the simulated cart and returns the shared
	 * progress payload shape (`goals`, `currency`, plus the `simulated`
	 * values echoed back so the UI can label the frame).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_preview( $request ) {
		$goal_id     = (int) $request->get_param( 'goal_id' );
		$campaign_id = (int) $request->get_param( 'campaign_id' );

		// XOR: exactly one target (0 and 0, or both set, are invalid).
		if ( ( $goal_id > 0 ) === ( $campaign_id > 0 ) ) {
			return $this->error(
				'goalcart_preview_target_required',
				__( 'Provide exactly one of goal_id or campaign_id to preview.', 'goalcart' ),
				400
			);
		}

		$simulated = $request->get_param( 'simulated' );

		if ( ! is_array( $simulated ) ) {
			$simulated = array();
		}

		$amount   = isset( $simulated['amount'] ) ? (float) $simulated['amount'] : 0.0;
		$quantity = isset( $simulated['quantity'] ) ? (float) $simulated['quantity'] : 0.0;

		$goals = $this->resolve_goals( $goal_id, $campaign_id );

		if ( empty( $goals ) ) {
			return $this->error(
				'goalcart_preview_not_found',
				__( 'The goal or campaign could not be found.', 'goalcart' ),
				404
			);
		}

		$items = array();

		foreach ( $goals as $goal ) {
			$context = $this->simulated_context( $goal, $amount, $quantity );
			$result  = $this->engine->evaluate( $goal, $context );

			$items[] = $this->frontend->shape_goal(
				$goal,
				$result,
				$context,
				array( 'quantity' => $context->total_quantity() ),
				true // Admin preview: expose the full reward meta (manage_options-gated).
			);
		}

		return $this->success(
			array(
				'goals'    => $items,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'simulated' => array(
					'amount'   => $amount,
					'quantity' => $quantity,
				),
			),
			array(
				'mode' => $campaign_id > 0 ? 'campaign' : 'goal',
			)
		);
	}

	/**
	 * Load the preview goals, forced into their "published" state.
	 *
	 * Single-goal mode returns one Goal; campaign mode returns every
	 * milestone goal in menu order (with the campaign name folded in for
	 * the {campaign_name} message variable). Empty array when the target
	 * does not exist or has no goals.
	 *
	 * @param int $goal_id     Goal id (0 when previewing a campaign).
	 * @param int $campaign_id Campaign id (0 when previewing a goal).
	 * @return Goal[]
	 */
	protected function resolve_goals( $goal_id, $campaign_id ) {
		$goals = array();

		if ( $campaign_id > 0 ) {
			$campaign = $this->campaigns->get( $campaign_id );

			if ( null === $campaign ) {
				return $goals;
			}

			foreach ( $campaign['goals'] as $milestone ) {
				$goal = $this->preview_goal(
					$this->goals->get( (int) $milestone['id'] ),
					(string) $campaign['name']
				);

				if ( $goal ) {
					$goals[] = $goal;
				}
			}

			return $goals;
		}

		$goal = $this->preview_goal( $this->goals->get( $goal_id ) );

		return $goal ? array( $goal ) : array();
	}

	/**
	 * Build a preview Goal from a stored repository row.
	 *
	 * Publish gating is intentionally ignored (Phase 15 objective: see the
	 * customer experience BEFORE publishing): the goal is forced active and
	 * its schedule cleared, so drafts, inactive goals and scheduled
	 * campaigns evaluate as they will once live.
	 *
	 * @param mixed  $row           Stored goal row (array) or null.
	 * @param string $campaign_name Campaign name to fold in ('' for singles).
	 * @return Goal|null Null when the row does not exist.
	 */
	protected function preview_goal( $row, $campaign_name = '' ) {
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}

		$row['status']     = Goal::STATUS_ACTIVE;
		$row['starts_at']  = null;
		$row['ends_at']    = null;

		if ( '' !== $campaign_name ) {
			$row['campaign_name'] = $campaign_name;
		}

		return new Goal( $row );
	}

	/**
	 * Build the synthetic CartContext that yields the simulated values.
	 *
	 * One (or, for distinct-quantity, several) synthetic cart line carries
	 * the simulated amount / quantity / weight / categories / product id,
	 * so each evaluator reads exactly what the admin dialed in. The engine
	 * is exercised end-to-end — eligibility, progress math, message engine,
	 * suggestions — against a context that never came from a real cart.
	 *
	 * @param Goal  $goal     Goal being previewed.
	 * @param float $amount   Simulated cart amount (money basis).
	 * @param float $quantity Simulated item quantity (count basis).
	 * @return CartContext
	 */
	protected function simulated_context( Goal $goal, $amount, $quantity ) {
		$amount   = max( 0.0, (float) $amount );
		$quantity = max( 0.0, (float) $quantity );
		$items    = array();

		switch ( $goal->type() ) {
			case Goal::TYPE_QUANTITY:
				$items[] = $this->simulated_item(
					array(
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Goal::TYPE_DISTINCT_QUANTITY:
				// One unique product per simulated unit (capped so a huge
				// simulated quantity cannot balloon the payload).
				$count = (int) ceil( $quantity );
				for ( $i = 1; $i <= $count && $i <= 50; $i++ ) {
					$items[] = $this->simulated_item( array( 'product_id' => $i ) );
				}
				break;

			case Goal::TYPE_CATEGORY:
				$items[] = $this->simulated_item(
					array(
						'categories'    => $goal->categories(),
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Goal::TYPE_PRODUCT:
				$products = $goal->products();
				$items[] = $this->simulated_item(
					array(
						'product_id'    => ! empty( $products ) ? (int) $products[0] : 0,
						'quantity'      => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;

			case Goal::TYPE_WEIGHT:
				$items[] = $this->simulated_item(
					array(
						'weight'        => $quantity,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
					)
				);
				break;

			case Goal::TYPE_COMPOSITE:
				$items[] = $this->composite_item( $goal, $amount, $quantity );
				break;

			default:
				// Amount (and any unknown type — the engine rejects unknown
				// types as ineligible anyway): a single line worth $amount.
				$items[] = $this->simulated_item(
					array(
						'quantity'      => 1.0,
						'line_subtotal' => $amount,
						'line_total'    => $amount,
						'price'         => $amount,
					)
				);
				break;
		}

		return new CartContext(
			array(
				'subtotal' => $amount,
				'total'    => $amount,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'items'    => $items,
			)
		);
	}

	/**
	 * The synthetic line for a composite goal.
	 *
	 * Unions the children's constraints onto a single line: categories of
	 * every category child, the first product child's id, the simulated
	 * quantity for count children, and weight for a weight child — so
	 * amount/quantity/category/weight children evaluate honestly. Product
	 * children beyond the first cannot share one line and are approximated
	 * (documented trade-off).
	 *
	 * @param Goal  $goal     Composite goal.
	 * @param float $amount   Simulated amount.
	 * @param float $quantity Simulated quantity.
	 * @return CartItem
	 */
	protected function composite_item( Goal $goal, $amount, $quantity ) {
		$categories = array();
		$products   = array();
		$weight     = 0.0;

		foreach ( $goal->children() as $child_data ) {
			if ( ! is_array( $child_data ) ) {
				continue;
			}

			$child = new Goal( $child_data );

			foreach ( $child->categories() as $id ) {
				$categories[ (int) $id ] = true;
			}

			foreach ( $child->products() as $id ) {
				$products[ (int) $id ] = true;
			}

			if ( Goal::TYPE_WEIGHT === $child->type() ) {
				$weight = (float) $quantity;
			}
		}

		$product_ids = array_keys( $products );

		return $this->simulated_item(
			array(
				'product_id'    => ! empty( $product_ids ) ? (int) $product_ids[0] : 0,
				'categories'    => array_keys( $categories ),
				'quantity'      => $quantity,
				'weight'        => $weight,
				'line_subtotal' => $amount,
				'line_total'    => $amount,
				'price'         => $amount,
			)
		);
	}

	/**
	 * Build a synthetic cart line from overrides + safe defaults.
	 *
	 * @param array<string, mixed> $overrides CartItem payload overrides.
	 * @return CartItem
	 */
	protected function simulated_item( array $overrides = array() ) {
		$data = array_merge(
			array(
				'product_id'    => 0,
				'variation_id'  => 0,
				'name'          => __( 'Preview item', 'goalcart' ),
				'quantity'      => 1.0,
				'line_subtotal' => 0.0,
				'line_total'    => 0.0,
				'price'         => 0.0,
				'weight'        => 0.0,
				'categories'    => array(),
				'virtual'       => false,
				'downloadable'  => false,
			),
			$overrides
		);

		return new CartItem( $data );
	}
}
