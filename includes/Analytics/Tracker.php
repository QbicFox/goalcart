<?php
/**
 * Analytics event tracker for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Analytics;

use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tracker
 *
 * Phase 16 (Analytics Foundation) — records the seven goal-cart events
 * into the `analytics_events` table:
 *
 *  - goal_impression          a goal widget was shown to a shopper
 *  - goal_progress            the widget reported a progress percentage
 *  - goal_completed           a goal's target was reached (no reward)
 *  - reward_activated         a goal's target was reached (with reward)
 *  - suggestion_impression    a suggested product was shown
 *  - suggestion_clicked       a shopper clicked a suggested product
 *  - suggested_product_added  a suggested product was added to the cart
 *
 * Six events are reported by the storefront JS through the public REST
 * `POST /goalcart/v1/track` route (TrackController). The seventh —
 * `suggested_product_added` — is attributed server-side on
 * `woocommerce_add_to_cart`: a cart addition is recorded as a suggestion
 * conversion when the session saw a `suggestion_impression` for that
 * product within the attribution window, mirroring the reference
 * plugin's add-to-cart funnel attribution.
 *
 * Sessions are anonymous (Session cookie) and every event row stores
 * only aggregate numbers (cart value, percentage) plus product/goal ids
 * — never IPs, user agents, emails or other PII (P16-T04).
 *
 * The REST controller checks tracking_enabled() (master toggle + filter);
 * frontend hooks use the fuller is_tracking_allowed() chain (admin/REST/
 * cron excluded), mirroring the reference Tracker.
 */
final class Tracker {

	/**
	 * Nonce action for client-side event reporting (REST track route).
	 *
	 * @var string
	 */
	const TRACK_NONCE_ACTION = 'goalcart_track';

	/**
	 * Attribution window for suggestion → add-to-cart conversions.
	 *
	 * Only suggestion impressions within the last 24 hours are eligible.
	 *
	 * @var int
	 */
	const CONVERSION_WINDOW = DAY_IN_SECONDS;

	/**
	 * Event types.
	 */
	const EVENT_GOAL_IMPRESSION         = 'goal_impression';
	const EVENT_GOAL_PROGRESS           = 'goal_progress';
	const EVENT_GOAL_COMPLETED          = 'goal_completed';
	const EVENT_REWARD_ACTIVATED        = 'reward_activated';
	const EVENT_SUGGESTION_IMPRESSION   = 'suggestion_impression';
	const EVENT_SUGGESTION_CLICKED      = 'suggestion_clicked';
	const EVENT_SUGGESTED_PRODUCT_ADDED = 'suggested_product_added';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Session manager.
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 * @param Session  $session  Session manager.
	 */
	public function __construct( Settings $settings, Session $session ) {
		$this->settings = $settings;
		$this->session  = $session;
	}

	/**
	 * Register tracking hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// Ensure the session cookie exists before any output so the JS
		// tracker and the add-to-cart attribution share one session id.
		$hooks->add_action( 'wp', array( $this, 'maybe_ensure_session' ) );

		// Front-end config (endpoint, nonce, session id) for the vanilla JS
		// tracker. Printed early in wp_footer (priority 4, before the
		// ProgressUI config at 5) so frontend.js finds it ready.
		$hooks->add_action( 'wp_footer', array( $this, 'output_frontend_config' ), 4 );

		// Suggested-product attribution: fires for both native (?add-to-cart)
		// and AJAX adds, since both flow through WC_Cart::add_to_cart().
		$hooks->add_action( 'woocommerce_add_to_cart', array( $this, 'handle_add_to_cart' ), 10, 2 );
	}

	/**
	 * The whitelist of recordable event types.
	 *
	 * @return string[]
	 */
	public static function event_types() {
		return array(
			self::EVENT_GOAL_IMPRESSION,
			self::EVENT_GOAL_PROGRESS,
			self::EVENT_GOAL_COMPLETED,
			self::EVENT_REWARD_ACTIVATED,
			self::EVENT_SUGGESTION_IMPRESSION,
			self::EVENT_SUGGESTION_CLICKED,
			self::EVENT_SUGGESTED_PRODUCT_ADDED,
		);
	}

	/**
	 * Whether an event type is whitelisted.
	 *
	 * @param mixed $event_type Candidate event type.
	 * @return bool
	 */
	public static function is_event_type( $event_type ) {
		return in_array( $event_type, self::event_types(), true );
	}

	/**
	 * The master tracking toggle: enabled setting + consent filter.
	 *
	 * Used by the REST handler and the add-to-cart hook (both can run
	 * outside plain frontend page views), mirroring the reference which
	 * checks only the settings toggle for AJAX/add-to-cart paths.
	 *
	 * @return bool
	 */
	public function tracking_enabled() {
		if ( ! $this->settings->get( 'enabled', true ) ) {
			return false;
		}

		/**
		 * Filter whether event tracking is enabled for the current request.
		 *
		 * @param bool    $enabled Whether tracking is enabled.
		 * @param Tracker $tracker Tracker instance.
		 */
		return (bool) apply_filters( 'goalcart_tracking_enabled', true, $this );
	}

	/**
	 * Whether tracking is allowed for the current frontend request.
	 *
	 * Adds the request-context guards on top of tracking_enabled():
	 * admin screens, REST requests and cron runs never emit events.
	 *
	 * @return bool
	 */
	public function is_tracking_allowed() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return false;
		}

		return $this->tracking_enabled();
	}

	/**
	 * Ensure the anonymous session cookie exists on frontend page views.
	 *
	 * @return void
	 */
	public function maybe_ensure_session() {
		if ( $this->is_tracking_allowed() ) {
			$this->session->id();
		}
	}

	/**
	 * Print a small front-end config object for the JS tracker.
	 *
	 * Mirrors the reference plugin: an inline `window.goalcartTracking`
	 * object (endpoint, nonce, session id) consumed by the vanilla JS
	 * tracker in frontend.js with a must-never-throw contract.
	 *
	 * @return void
	 */
	public function output_frontend_config() {
		if ( is_admin() || ! $this->is_tracking_allowed() ) {
			return;
		}

		$config = array(
			'endpoint'  => esc_url_raw( rest_url( 'goalcart/v1/track' ) ),
			'nonce'     => wp_create_nonce( self::TRACK_NONCE_ACTION ),
			'sessionId' => $this->session->id(),
		);

		wp_print_inline_script_tag(
			'window.goalcartTracking = ' . wp_json_encode( $config ) . ';',
			array( 'id' => 'goalcart-tracking-config', 'type' => 'text/javascript' )
		);
	}

	/**
	 * Get the current session ID.
	 *
	 * @return string
	 */
	public function get_session_id() {
		return $this->session->id();
	}

	/**
	 * Record an analytics event.
	 *
	 * The event type must be whitelisted and the master toggle on. The
	 * row stores only aggregate data: goal/campaign/product/order ids,
	 * the cart value at event time, and a scalar-only meta JSON (e.g.
	 * percentage, quantity) — never IPs or other PII.
	 *
	 * @param string               $event_type One of the EVENT_* constants.
	 * @param array<string, mixed> $context    Optional context: goal_id,
	 *                                         campaign_id, product_id,
	 *                                         order_id, cart_value, meta,
	 *                                         session_id.
	 * @return int Event row id, or 0 on failure / when gated off.
	 */
	public function record( $event_type, array $context = array() ) {
		if ( ! self::is_event_type( $event_type ) || ! $this->tracking_enabled() ) {
			return 0;
		}

		$session_id = isset( $context['session_id'] ) && Session::is_valid( $context['session_id'] )
			? $context['session_id']
			: $this->session->id();

		$goal_id     = isset( $context['goal_id'] ) ? absint( $context['goal_id'] ) : 0;
		$campaign_id = isset( $context['campaign_id'] ) ? absint( $context['campaign_id'] ) : 0;
		$product_id  = isset( $context['product_id'] ) ? absint( $context['product_id'] ) : 0;
		$order_id    = isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0;

		$cart_value = isset( $context['cart_value'] ) ? round( (float) $context['cart_value'], 4 ) : null;
		$meta       = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $this->sanitize_meta( $context['meta'] ) : array();

		// user_id is recorded only when the shopper is logged in (an id,
		// not personal data); guests stay fully anonymous.
		$user_id = get_current_user_id();

		global $wpdb;

		$table = \GoalCart\Database\Schema::table( 'analytics_events' );

		$data = array(
			'goal_id'     => $goal_id > 0 ? $goal_id : null,
			'campaign_id' => $campaign_id > 0 ? $campaign_id : null,
			'event_type'  => $event_type,
			'session_id'  => $session_id,
			'user_id'     => $user_id > 0 ? $user_id : null,
			'product_id'  => $product_id > 0 ? $product_id : null,
			'order_id'    => $order_id > 0 ? $order_id : null,
			'cart_value'  => $cart_value,
			'meta'        => empty( $meta ) ? null : wp_json_encode( $meta ),
			'created_at'  => current_time( 'mysql' ),
		);

		$formats = array( '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s' );

		$inserted = $wpdb->insert( $table, $data, $formats );

		// FK resilience: analytics_events references goals/campaigns with
		// ON DELETE SET NULL, but a goal deleted between the impression and
		// this event report would make the INSERT fail and silently drop
		// the event. Retry once without the FK ids so the event survives
		// (the same outcome the FK's SET NULL implies for deletion). The
		// retry only fires on an actual foreign-key failure — a genuine DB
		// error is reported, not masked. If only one id is stale, both are
		// dropped (simpler than resolving which failed; the event survives,
		// which is the priority).
		if (
			! $inserted
			&& ( $goal_id > 0 || $campaign_id > 0 )
			&& false !== stripos( (string) $wpdb->last_error, 'foreign key' )
		) {
			$data['goal_id']     = null;
			$data['campaign_id'] = null;
			$inserted = $wpdb->insert( $table, $data, $formats );
		}

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Sanitize event meta to scalar values only.
	 *
	 * Nested arrays/objects and non-scalars are dropped so the meta JSON
	 * column can never carry unexpected structures or PII-shaped data.
	 *
	 * @param array<string, mixed> $meta Raw meta.
	 * @return array<string, scalar>
	 */
	protected function sanitize_meta( array $meta ) {
		$clean = array();

		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ sanitize_key( $key ) ] = is_bool( $value ) ? (int) $value : $value;
		}

		return $clean;
	}

	/**
	 * Handle an add-to-cart event: attribute suggested-product conversions.
	 *
	 * Hooked to 'woocommerce_add_to_cart'. Only the master toggle is
	 * checked here (not the full is_tracking_allowed() chain) so adds via
	 * admin-ajax.php are attributed too — the session-based attribution
	 * below already ties the event to a real suggestion impression, which
	 * keeps noise low.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product ID.
	 * @return int Event row id when attributed, 0 otherwise (also when
	 *             the master toggle is off).
	 */
	public function handle_add_to_cart( $cart_item_key, $product_id ) {
		if ( ! $this->tracking_enabled() ) {
			return 0;
		}

		return $this->track_suggested_product_added( (int) $product_id );
	}

	/**
	 * Record a suggested_product_added event when attributable.
	 *
	 * The product counts as a suggestion conversion when the session saw
	 * a suggestion_impression for it within the attribution window. The
	 * goal/campaign from that impression carry over, so the analytics
	 * dashboard can measure suggestion add-to-cart rate per goal.
	 *
	 * @param int $product_id Product ID.
	 * @return int Event row id, or 0 when not attributable.
	 */
	protected function track_suggested_product_added( $product_id ) {
		if ( $product_id < 1 || ! $this->tracking_enabled() ) {
			return 0;
		}

		$session_id = $this->session->id();

		if ( ! Session::is_valid( $session_id ) ) {
			return 0;
		}

		global $wpdb;

		$events = \GoalCart\Database\Schema::table( 'analytics_events' );
		$since  = date( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d H:i:s' ) ) - self::CONVERSION_WINDOW );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT goal_id, campaign_id FROM {$events}
				 WHERE session_id = %s AND event_type = %s AND product_id = %d AND created_at >= %s
				 ORDER BY created_at DESC, id DESC
				 LIMIT 1",
				$session_id,
				self::EVENT_SUGGESTION_IMPRESSION,
				$product_id,
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return 0;
		}

		return $this->record(
			self::EVENT_SUGGESTED_PRODUCT_ADDED,
			array(
				'product_id'  => $product_id,
				'goal_id'     => (int) $row['goal_id'],
				'campaign_id' => (int) $row['campaign_id'],
				'session_id'  => $session_id,
			)
		);
	}
}
