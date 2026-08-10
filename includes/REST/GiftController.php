<?php
/**
 * REST controller for the shopper's free-gift selection.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;
use GoalCart\Rewards\RewardEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Class GiftController
 *
 * Phase 32 (free gift selection): `POST /goalcart/v1/gift` — the storefront
 * gift picker calls this when the shopper picks one of the candidate gifts
 * of a completed goal configured in "choose" mode. The reward engine
 * validates the goal is currently completed and the product is in the
 * goal's gift list, then adds it free (session-tracked so a goal that
 * stops qualifying revokes it).
 *
 * The endpoint is public (guests must be able to claim their gift), so it
 * is guarded by the plugin's own gift nonce (minted into the frontend
 * config, adopted from every progress poll) plus per-IP rate limiting —
 * the same trust boundary as the track endpoint.
 */
class GiftController extends BaseController {

	/**
	 * The nonce action for the gift selection endpoint.
	 *
	 * @var string
	 */
	const GIFT_NONCE_ACTION = 'goalcart_gift_nonce';

	/**
	 * Reward engine (validates the goal + adds the chosen gift).
	 *
	 * @var RewardEngine
	 */
	protected $rewards;

	/**
	 * Constructor.
	 *
	 * @param RewardEngine $rewards Reward engine.
	 */
	public function __construct( RewardEngine $rewards ) {
		$this->rewards = $rewards;
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
	 * Register the gift route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/gift',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'goal_id'    => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'product_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'nonce'      => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Verify the gift nonce and rate-limit the route.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function permission_callback( $request ) {
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), self::GIFT_NONCE_ACTION ) ) {
			return $this->error(
				'goalcart_invalid_nonce',
				__( 'Invalid gift nonce.', 'goalcart' ),
				403
			);
		}

		$limited = $this->rate_limit_ip( $request );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return true;
	}

	/**
	 * Handle a gift-selection request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( $request ) {
		$cart = null;

		if ( function_exists( 'WC' ) && WC() && WC()->cart instanceof \WC_Cart ) {
			$cart = WC()->cart;
		}

		if ( null === $cart || empty( $cart->get_cart() ) ) {
			return $this->error(
				'goalcart_gift_empty_cart',
				__( 'Your cart is empty.', 'goalcart' ),
				400
			);
		}

		$goal_id    = (int) $request->get_param( 'goal_id' );
		$product_id = (int) $request->get_param( 'product_id' );

		if ( ! $this->rewards->add_chosen_gift( $goal_id, $product_id, $cart ) ) {
			return $this->error(
				'goalcart_gift_unavailable',
				__( 'This gift is no longer available for the goal.', 'goalcart' ),
				400
			);
		}

		// Cart contents changed; make sure totals reflect the free gift.
		$cart->calculate_totals();

		return $this->success(
			array(
				'added'   => true,
				'goal_id' => $goal_id,
			)
		);
	}
}
