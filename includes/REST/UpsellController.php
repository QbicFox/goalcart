<?php
/**
 * REST controller for the smart upsell ranking + historical tracking.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Analytics\RevenueRepository;
use GoalCart\Analytics\RevenueTracker;
use GoalCart\Analytics\Session;
use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class UpsellController
 *
 * Phase 33.5 (Smart Upsell) — the endpoints behind the ranking engine and
 * its historical learning loop:
 *
 *  - `POST /goalcart/v1/upsell/track` — public, nonce-guarded (the same
 *    tracking nonce the storefront already holds): the frontend reports
 *    upsell_impression / upsell_clicked / upsell_added events into the
 *    Phase 33.1 upsell_events log. Server-side attribution of upsell_order
 *    events happens on order payment (UpsellRanker hooks), so a client
 *    can never self-report a purchase. Rate limited per IP.
 *
 *  - `GET /goalcart/v1/revenue/upsells` — admin-only. Two modes:
 *      - context mode (default): ranked upsell products for a simulated
 *        cart + goal (goal_id, cart_value, remaining, cart, limit) — the
 *        engine's full payload with per-product score breakdowns,
 *        historical conversion stats and plain-English reasons.
 *      - analytics mode (`analytics=1`): the top-products upsell analytics
 *        table (impressions / clicks / adds / orders / conversion /
 *        revenue / estimated profit / upsell score) over the requested
 *        window — the Phase 33.6 Upsell Analytics page's data source.
 *
 *  - `GET /goalcart/v1/revenue/upsells/{product_id}` — admin-only: one
 *    product's upsell score breakdown + historical stats, scored in the
 *    given context (or standalone when no context args are passed).
 *
 * Admin routes are manage_options-gated and rate limited per user (P07-T04);
 * the public track route is protected by the tracking nonce + per-IP rate
 * limiting, mirroring TrackController. All values are validated through
 * the REST arg schemas before they reach the repository/engine.
 */
class UpsellController extends BaseController {

	/**
	 * Rate-limit budget for the upsell track route (requests per window,
	 * per IP) — impressions fire on every widget render, so the budget is
	 * generous like the Phase 16 track route.
	 *
	 * @var int
	 */
	const TRACK_RATE_LIMIT_COUNT = 300;

	/**
	 * Cached revenue repository (serves rankings + analytics).
	 *
	 * @var RevenueRepository
	 */
	protected $repository;

	/**
	 * Constructor.
	 *
	 * @param RevenueRepository $repository Revenue repository.
	 */
	public function __construct( RevenueRepository $repository ) {
		$this->repository = $repository;
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
	 * Register the upsell routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/upsell/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_track' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $this->track_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/upsells',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_index' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->index_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/revenue/upsells/(?P<product_id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->get_args(),
			)
		);
	}

	/**
	 * Verify the tracking nonce before the public route runs.
	 *
	 * Mirrors TrackController: the plugin's own tracking nonce (created in
	 * the frontend config printed by the Phase 16 Tracker) replaces the
	 * admin capability check, then the per-IP rate limit applies.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function permission_callback( $request ) {
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), \GoalCart\Analytics\Tracker::TRACK_NONCE_ACTION ) ) {
			return $this->error(
				'goalcart_invalid_nonce',
				__( 'Invalid tracking nonce.', 'goalcart' ),
				403
			);
		}

		$limited = $this->rate_limit_ip( $request, self::TRACK_RATE_LIMIT_COUNT );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return true;
	}

	/**
	 * Record an upsell interaction event reported by the storefront.
	 *
	 * Only the three client-reportable upsell events are accepted here
	 * (impression / clicked / added); upsell_order is attributed
	 * server-side on order payment (UpsellRanker::attribute_order), so a
	 * client can never self-report a conversion.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_track( $request ) {
		if ( ! class_exists( RevenueTracker::class ) ) {
			return $this->error(
				'goalcart_tracking_disabled',
				__( 'Tracking is disabled.', 'goalcart' ),
				403
			);
		}

		$event_type = (string) $request->get_param( 'event_type' );

		if ( ! in_array( $event_type, array( RevenueTracker::EVENT_UPSELL_IMPRESSION, RevenueTracker::EVENT_UPSELL_CLICKED, RevenueTracker::EVENT_UPSELL_ADDED ), true ) ) {
			return $this->error(
				'goalcart_invalid_event_type',
				__( 'Unknown upsell event type.', 'goalcart' ),
				400
			);
		}

		$session_id = (string) $request->get_param( 'session_id' );

		$tracker = \GoalCart\Plugin::instance()->container()->get( RevenueTracker::class );

		if ( ! $tracker->tracking_enabled() ) {
			return $this->error(
				'goalcart_tracking_disabled',
				__( 'Tracking is disabled.', 'goalcart' ),
				403
			);
		}

		// A well-formed client session id wins; otherwise the request's own
		// cookie (set on the page view) identifies the session.
		if ( ! Session::is_valid( $session_id ) ) {
			$session_id = $tracker->get_session_id();
		}

		$id = $tracker->record_upsell(
			$event_type,
			array(
				'goal_id'    => (int) $request->get_param( 'goal_id' ),
				'product_id' => (int) $request->get_param( 'product_id' ),
				'cart_value' => max( 0.0, (float) $request->get_param( 'cart_value' ) ),
				'session_id' => $session_id,
				'meta'       => array(
					'source' => (string) $request->get_param( 'source' ),
				),
			)
		);

		if ( $id < 1 ) {
			// Deduped events (repeat impression within 24h, same session +
			// goal + product) are not errors — they are idempotency in
			// action. Only gate/whitelist failures are surfaced.
			if ( ! $tracker->tracking_enabled() ) {
				return $this->error(
					'goalcart_tracking_disabled',
					__( 'Tracking is disabled.', 'goalcart' ),
					403
				);
			}
		}

		return $this->success( array( 'id' => max( 0, $id ) ) );
	}

	/**
	 * Ranked upsells (context mode) or the top-products analytics table.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_index( $request ) {
		$args = array(
			'goal_id'    => (int) $request->get_param( 'goal_id' ),
			'cart_value' => (float) $request->get_param( 'cart_value' ),
			'remaining'  => $request->get_param( 'remaining' ),
			'cart'       => $request->get_param( 'cart' ),
			'limit'      => (int) $request->get_param( 'limit' ),
			'from'       => (string) $request->get_param( 'from' ),
			'to'         => (string) $request->get_param( 'to' ),
		);

		if ( $request->get_param( 'analytics' ) ) {
			return $this->success(
				$this->repository->upsell_analytics( $args ),
				array( 'applied' => $args )
			);
		}

		return $this->success(
			$this->repository->upsell_ranking( $args ),
			array( 'applied' => $args )
		);
	}

	/**
	 * One product's upsell score breakdown + historical stats.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_get( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );

		if ( $product_id < 1 ) {
			return $this->error(
				'goalcart_invalid_product',
				__( 'Invalid product id.', 'goalcart' ),
				400
			);
		}

		$args = array(
			'goal_id'    => (int) $request->get_param( 'goal_id' ),
			'cart_value' => (float) $request->get_param( 'cart_value' ),
			'remaining'  => $request->get_param( 'remaining' ),
			'cart'       => $request->get_param( 'cart' ),
		);

		$detail = $this->repository->upsell_product_detail( $product_id, $args );

		if ( null === $detail ) {
			return $this->error(
				'goalcart_product_not_found',
				__( 'The product could not be found.', 'goalcart' ),
				404
			);
		}

		return $this->success( $detail, array( 'applied' => $args ) );
	}

	/**
	 * Arg schema for the public track route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function track_args() {
		return array(
			'event_type' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $value ) {
					return in_array(
						(string) $value,
						array(
							RevenueTracker::EVENT_UPSELL_IMPRESSION,
							RevenueTracker::EVENT_UPSELL_CLICKED,
							RevenueTracker::EVENT_UPSELL_ADDED,
						),
						true
					);
				},
			),
			'goal_id'    => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'product_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'source'     => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			'session_id' => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => function ( $value ) {
					if ( '' === (string) $value ) {
						return true;
					}

					return Session::is_valid( $value );
				},
				'sanitize_callback' => 'sanitize_text_field',
			),
			'nonce'      => array(
				'required' => true,
				'type'     => 'string',
			),
		);
	}

	/**
	 * Arg schema for the ranking/analytics list route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index_args() {
		return array(
			'goal_id'    => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'remaining'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
			'cart'       => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'limit'      => array(
				'type'    => 'integer',
				'default' => 4,
				'minimum' => 1,
				'maximum' => 10,
			),
			'analytics'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'from'       => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
			'to'         => array(
				'type'              => 'string',
				'default'           => '',
				'validate_callback' => array( $this, 'validate_datetime_param' ),
			),
		);
	}

	/**
	 * Arg schema for the per-product route.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_args() {
		$args = array(
			'product_id' => array(
				'type'              => 'integer',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'goal_id'    => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'cart_value' => array(
				'type'    => 'number',
				'default' => 0,
				'minimum' => 0,
			),
			'remaining'  => array(
				'type'    => array( 'number', 'null' ),
				'default' => null,
				'minimum' => 0,
			),
			'cart'       => array(
				'type'  => 'array',
				'items' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);

		return $args;
	}
}
