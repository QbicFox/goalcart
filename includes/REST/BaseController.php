<?php
/**
 * Base REST controller for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;
use GoalCart\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class BaseController
 *
 * Foundation for every Goal Cart REST endpoint (Phase 7: REST API / AJAX
 * Layer). Provides:
 *
 *  - the `goalcart/v1` namespace (created implicitly by the first
 *    register_rest_route() call, per the WP REST API)
 *  - a permission callback for admin endpoints combining a capability
 *    check with per-user rate limiting
 *  - a permission callback for the public frontend endpoint (no
 *    capability — guests must be able to read their own cart progress —
 *    rate limited per IP instead)
 *  - the standard `{ data, meta, pagination }` response envelope
 *    (mirroring the reference plugin and the ApiEnvelope type in the
 *    React admin app)
 *
 * Security (P07-T04): every admin route enforces the manage_options
 * capability (nonce validation is handled by WP core cookie auth for
 * logged-in admin requests), every input is validated/sanitized through
 * the route arg schemas, and every failure returns a structured
 * WP_Error with a machine-readable code and HTTP status.
 *
 * Mirrors the reference plugin (WooInsights\REST\BaseController).
 */
abstract class BaseController {

	/**
	 * REST namespace for all plugin endpoints.
	 *
	 * @var string
	 */
	const NAMESPACE = 'goalcart/v1';

	/**
	 * Capability required to access admin endpoints.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Default admin-endpoint rate limit (requests per window).
	 *
	 * @var int
	 */
	const RATE_LIMIT_COUNT = 60;

	/**
	 * Default rate-limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = MINUTE_IN_SECONDS;

	/**
	 * Public-endpoint rate limit (requests per window, per IP).
	 *
	 * @var int
	 */
	const PUBLIC_RATE_LIMIT_COUNT = 120;

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	abstract public function register( HookManager $hooks );

	/**
	 * Register this controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Permission callback for admin-only endpoints: capability check plus
	 * per-user rate limiting.
	 *
	 * @return callable
	 */
	public function get_permission_callback() {
		return function ( $request ) {
			$capability = apply_filters( 'goalcart_rest_capability', self::CAPABILITY );

			if ( ! current_user_can( $capability ) ) {
				return $this->error(
					'goalcart_forbidden',
					__( 'You are not allowed to access this endpoint.', 'goalcart' ),
					403
				);
			}

			$limited = $this->rate_limit( $request );

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}

			return true;
		};
	}

	/**
	 * Permission callback for the public frontend endpoint.
	 *
	 * The cart-progress payload contains only aggregate numbers for the
	 * current shopper's cart (no PII), so guests may read it without a
	 * capability — but it is still rate limited per IP to prevent abuse.
	 *
	 * @return callable
	 */
	public function get_public_permission_callback() {
		return function ( $request ) {
			$limited = $this->rate_limit_ip( $request );

			if ( is_wp_error( $limited ) ) {
				return $limited;
			}

			return true;
		};
	}

	/**
	 * Build a standard success response envelope.
	 *
	 * @param mixed                $data Payload.
	 * @param array<string, mixed> $meta Optional metadata.
	 * @return \WP_REST_Response
	 */
	protected function success( $data, array $meta = array() ) {
		$response = array( 'data' => $data );

		if ( ! empty( $meta ) ) {
			$response['meta'] = $meta;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Build a paginated success response envelope.
	 *
	 * @param mixed                $data     Payload.
	 * @param int                  $total    Total matching records.
	 * @param int                  $page     Current page.
	 * @param int                  $per_page Records per page.
	 * @param array<string, mixed> $meta     Optional metadata.
	 * @return \WP_REST_Response
	 */
	protected function paginated( $data, $total, $page, $per_page, array $meta = array() ) {
		$per_page = max( 1, (int) $per_page );

		return rest_ensure_response(
			array(
				'data'       => $data,
				'meta'       => $meta,
				'pagination' => array(
					'page'        => (int) $page,
					'per_page'    => $per_page,
					'total'       => (int) $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				),
			)
		);
	}

	/**
	 * Build a standard error response.
	 *
	 * @param string               $code    Machine-readable error code.
	 * @param string               $message Human-readable message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra error data.
	 * @return \WP_Error
	 */
	protected function error( $code, $message, $status = 400, array $data = array() ) {
		// Phase 18 (Advanced → logging): REST failures land in the debug
		// log when logging is enabled (error level always writes).
		Logger::error( $code . ' — ' . $message . ' (HTTP ' . (int) $status . ')' );

		$data['status'] = $status;

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Fixed-window rate limiter keyed by user + route.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param int|null         $limit   Max requests per window.
	 * @param int|null         $window  Window length in seconds.
	 * @return true|\WP_Error
	 */
	protected function rate_limit( $request, $limit = null, $window = null ) {
		$limit  = $limit ?: self::RATE_LIMIT_COUNT;
		$window = $window ?: self::RATE_LIMIT_WINDOW;

		$user_id = get_current_user_id();
		$key     = 'goalcart_rl_' . md5( $user_id . '|' . $request->get_route() );
		$slot    = (int) floor( time() / $window );

		$counts = get_transient( $key );

		if ( ! is_array( $counts ) ) {
			$counts = array();
		}

		// Drop slots outside the current window.
		foreach ( array_keys( $counts ) as $old_slot ) {
			if ( $old_slot < $slot ) {
				unset( $counts[ $old_slot ] );
			}
		}

		$current = isset( $counts[ $slot ] ) ? $counts[ $slot ] : 0;

		if ( $current >= $limit ) {
			return $this->error(
				'goalcart_rate_limited',
				__( 'Too many requests. Please try again shortly.', 'goalcart' ),
				429,
				array( 'retry_after' => $window )
			);
		}

		$counts[ $slot ] = $current + 1;

		set_transient( $key, $counts, $window * 2 );

		return true;
	}

	/**
	 * Rate limiter for public endpoints, keyed by IP + route.
	 *
	 * Trade-offs (documented): each call writes a short-lived transient,
	 * which is a DB write on stores without an object cache — acceptable
	 * for a lightweight cart-progress poll, revisited by Phase 23
	 * (Performance Optimization). The key uses REMOTE_ADDR deliberately:
	 * trusting X-Forwarded-For would let clients spoof past the limit, and
	 * behind a proxy all visitors share one bucket (a generous default
	 * limit keeps that from tripping).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param int|null         $limit   Max requests per window.
	 * @param int|null         $window  Window length in seconds.
	 * @return true|\WP_Error
	 */
	protected function rate_limit_ip( $request, $limit = null, $window = null ) {
		$limit  = $limit ?: self::PUBLIC_RATE_LIMIT_COUNT;
		$window = $window ?: self::RATE_LIMIT_WINDOW;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$key     = 'goalcart_rl_ip_' . md5( $ip . '|' . $request->get_route() );
		$slot    = (int) floor( time() / $window );

		$counts = get_transient( $key );

		if ( ! is_array( $counts ) ) {
			$counts = array();
		}

		foreach ( array_keys( $counts ) as $old_slot ) {
			if ( $old_slot < $slot ) {
				unset( $counts[ $old_slot ] );
			}
		}

		$current = isset( $counts[ $slot ] ) ? $counts[ $slot ] : 0;

		if ( $current >= $limit ) {
			return $this->error(
				'goalcart_rate_limited',
				__( 'Too many requests. Please try again shortly.', 'goalcart' ),
				429,
				array( 'retry_after' => $window )
			);
		}

		$counts[ $slot ] = $current + 1;

		set_transient( $key, $counts, $window * 2 );

		return true;
	}

	/**
	 * Validate a Y-m-d or Y-m-d H:i:s datetime parameter.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	public function validate_datetime_param( $value ) {
		if ( null === $value || '' === $value ) {
			return true;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value ) ) {
			return false;
		}

		$ts = strtotime( $value );

		return false !== $ts;
	}
}
