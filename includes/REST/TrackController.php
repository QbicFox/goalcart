<?php
/**
 * REST controller for frontend analytics event tracking.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Analytics\RevenueTracker;
use FaraCart\Analytics\Session;
use FaraCart\Analytics\Tracker;
use FaraCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class TrackController
 *
 * Analytics Foundation — Events: registers and handles
 * `POST /faracart/v1/track`, the public endpoint the storefront JS uses
 * to report analytics events (mission_impression, mission_progress,
 * mission_completed, reward_activated, suggestion_impression,
 * suggestion_clicked).
 *
 * The endpoint is public — guests are the analytics population — so
 * instead of REST cookie auth it is protected by the plugin's own
 * tracking nonce (created in the frontend config printed by the
 * Tracker) verified in permission_callback(), plus per-IP rate
 * limiting. The event type is validated against the whitelist and every
 * other field is typed in the arg schema, so a forged payload can only
 * ever record a well-formed aggregate row.
 *	 * `suggested_product_added` is intentionally NOT accepted here: it is
	 * attributed server-side on woocommerce_add_to_cart (see Tracker), so a
	 * client can never self-report a conversion.
	 *
	 * Known limitation (documented): the remaining six events — including
	 * mission_completed / reward_activated — are client-reported, so a
	 * determined visitor holding the page nonce could inflate completion
	 * metrics. This matches the reference plugin's client-side analytics
	 * trust boundary (and the JS dedupes per page session); the dashboard
	 * treats these as directional signals, not audited conversion
	 * counters. Server-side verification of completions/rewards is a
	 * possible refinement.
	 *
	 * Mirrors the reference plugin (WooInsights\REST\ClickController).
	 */
class TrackController extends BaseController {

	/**
	 * Rate-limit budget for the track route (requests per window, per IP).
	 *
	 * Higher than the default public 120/min: the widget fires
	 * impression/progress events on every cart refresh, so a busy
	 * shopper can legitimately exceed the generic budget.
	 *
	 * @var int
	 */
	const TRACK_RATE_LIMIT_COUNT = 300;

	/**
	 * Tracker instance (analytics_events).
	 *
	 * @var Tracker
	 */
	protected $tracker;

	/**
	 * RevenueTracker instance (revenue_events).
	 *
	 * @var RevenueTracker
	 */
	protected $revenue_tracker;

	/**
	 * Mapping from frontend analytics event types to revenue event types.
	 *
	 * When the frontend reports an impression/progress/completion, we also
	 * record the corresponding revenue event so the mission funnel stats
	 * (views, completed, ordered) have data.
	 *
	 * @var array<string, string>
	 */
	const ANALYTICS_TO_REVENUE_MAP = array(
		'mission_impression' => RevenueTracker::EVENT_MISSION_VIEW,
		'mission_progress'   => RevenueTracker::EVENT_MISSION_PROGRESS,
		'mission_completed'  => RevenueTracker::EVENT_MISSION_COMPLETED,
		'reward_activated'   => RevenueTracker::EVENT_MISSION_COMPLETED,
	);

	/**
	 * Constructor.
	 *
	 * @param Tracker          $tracker          Tracker instance.
	 * @param RevenueTracker   $revenue_tracker  RevenueTracker instance.
	 */
	public function __construct( Tracker $tracker, RevenueTracker $revenue_tracker ) {
		$this->tracker          = $tracker;
		$this->revenue_tracker  = $revenue_tracker;
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
	 * Register the track route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'event_type' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => function ( $value ) {
							return Tracker::is_event_type( $value );
						},
					),
					'mission_id'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
					),
					'campaign_id' => array(
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
					'percentage' => array(
						'type'    => 'number',
						'default' => 0,
						'minimum' => 0,
						'maximum' => 100,
					),
					'session_id' => array(
					'type'              => 'string',
					'default'           => '',
					// Optional: empty means "no session"; when provided it
					// must match the anonymous 32-hex session format
					// (defense-in-depth on top of the handler check).
					'validate_callback' => function ( $value ) {
						if ( '' === (string) $value ) {
							return true;
						}

						return \FaraCart\Analytics\Session::is_valid( $value );
					},
					'sanitize_callback' => 'sanitize_text_field',
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
	 * Verify the tracking nonce before the route runs.
	 *
	 * Public endpoint: this replaces the base controller's admin
	 * capability check with the plugin's own tracking nonce, then applies
	 * the per-IP rate limit.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function permission_callback( $request ) {
		if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), Tracker::TRACK_NONCE_ACTION ) ) {
			return $this->error(
				'faracart_invalid_nonce',
				__( 'Invalid tracking nonce.', 'faracart' ),
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
	 * Handle a tracking request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( $request ) {
		if ( ! $this->tracker->tracking_enabled() ) {
			return $this->error(
				'faracart_tracking_disabled',
				__( 'Tracking is disabled.', 'faracart' ),
				403
			);
		}

		$event_type = (string) $request->get_param( 'event_type' );

		// event_type is whitelist-validated by the arg schema, but the
		// whitelist check is repeated here for direct-handler callers.
		if ( ! Tracker::is_event_type( $event_type ) ) {
			return $this->error(
				'faracart_invalid_event_type',
				__( 'Unknown event type.', 'faracart' ),
				400
			);
		}

		$session_id = (string) $request->get_param( 'session_id' );

		// A well-formed client session id wins; otherwise the request's
		// own cookie (set on the page view) identifies the session.
		if ( ! Session::is_valid( $session_id ) ) {
			$session_id = $this->tracker->get_session_id();
		}

		// P22 hardening: the numeric event fields are clamped here in the
		// handler (in addition to the arg-schema ranges) so direct-handler
		// callers and any future dispatch path can never persist
		// out-of-range values — percentage is a 0–100 progress readout and
		// cart_value a non-negative money amount.
		$percentage = round( min( 100.0, max( 0.0, (float) $request->get_param( 'percentage' ) ) ), 2 );

		// percentage is only meaningful on mission_progress; keep the meta JSON
		// clean (no spurious keys) for every other event type.
		$meta = array();

		if ( $percentage > 0 ) {
			$meta['percentage'] = $percentage;
		}

		$mission_id = (int) $request->get_param( 'mission_id' );

		$id = $this->tracker->record(
			$event_type,
			array(
				'mission_id'     => $mission_id,
				'campaign_id' => (int) $request->get_param( 'campaign_id' ),
				'product_id'  => (int) $request->get_param( 'product_id' ),
				'cart_value'  => max( 0.0, (float) $request->get_param( 'cart_value' ) ),
				'session_id'  => $session_id,
				'meta'        => $meta,
			)
		);

		if ( $id < 1 ) {
			return $this->error(
				'faracart_track_failed',
				__( 'Could not record the event.', 'faracart' ),
				500
			);
		}

		// Bridge frontend analytics events into the revenue event pipeline
		// so the mission funnel stats (views, completed, ordered) read
		// real data. Without this, frontend impressions/completions only
		// land in analytics_events and the revenue funnel stays empty.
		if ( isset( self::ANALYTICS_TO_REVENUE_MAP[ $event_type ] ) ) {
			$this->revenue_tracker->record(
				self::ANALYTICS_TO_REVENUE_MAP[ $event_type ],
				array(
					'mission_id'     => $mission_id,
					'campaign_id' => (int) $request->get_param( 'campaign_id' ),
					'cart_value'  => max( 0.0, (float) $request->get_param( 'cart_value' ) ),
					'session_id'  => $session_id,
				)
			);
		}

		return $this->success( array( 'id' => $id ) );
	}
}
