<?php
/**
 * Anonymous session management for FaraCart analytics.
 *
 * @package FaraCart
 */

namespace FaraCart\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class Session
 *
 * Anonymous, cookie-based visitor sessions (P16 — Analytics Foundation,
 * Privacy task). A session is a 32-char random hex ID stored in an
 * HttpOnly, SameSite=Lax cookie. The same ID is reused across page views
 * so every mission impression, completion and suggestion event from one
 * visitor groups into a single session, and the cookie expiry slides
 * forward on each frontend request.
 *
 * Privacy (P16-T04): the session ID is a random anonymous token — it is
 * never derived from, and never stores, an IP address, user agent,
 * email or any other personally identifiable information. The analytics
 * event rows group by this ID only.
 *
 * Mirrors the reference plugin (WooInsights\Tracker\Session) — the
 * faracart variant keeps the same cookie mechanics without the
 * search-specific conversion markers.
 */
class Session {

	/**
	 * Cookie name holding the anonymous session ID.
	 *
	 * @var string
	 */
	const COOKIE = 'faracart_session';

	/**
	 * Accepted session ID format (32 lowercase hex chars).
	 *
	 * @var string
	 */
	const ID_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * Cookie lifetime (sliding renewal on each request).
	 *
	 * @var int
	 */
	const LIFETIME = 30 * DAY_IN_SECONDS;

	/**
	 * Session ID resolved for the current request.
	 *
	 * @var string|null
	 */
	protected $id;

	/**
	 * Get the current session ID, creating or renewing it as needed.
	 *
	 * A valid stored cookie is reused and its expiry slides forward;
	 * otherwise a fresh session ID is generated and persisted.
	 *
	 * @return string
	 */
	public function id() {
		if ( null !== $this->id ) {
			return $this->id;
		}

		$existing = $this->read_cookie();

		if ( '' !== $existing ) {
			$this->id = $existing;
			$this->renew();
		} else {
			$this->id = $this->create();
		}

		return $this->id;
	}

	/**
	 * Discard the current session and start a fresh one.
	 *
	 * @return string New session ID.
	 */
	public function rotate() {
		$this->id = $this->create();

		return $this->id;
	}

	/**
	 * Whether a candidate session ID is well-formed.
	 *
	 * @param mixed $session_id Candidate ID.
	 * @return bool
	 */
	public static function is_valid( $session_id ) {
		return is_string( $session_id ) && preg_match( self::ID_PATTERN, $session_id );
	}

	/**
	 * Read the session ID from the cookie, if present and well-formed.
	 *
	 * @return string
	 */
	protected function read_cookie() {
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$session = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );

			if ( preg_match( self::ID_PATTERN, $session ) ) {
				return $session;
			}
		}

		return '';
	}

	/**
	 * Generate and persist a brand new session ID.
	 *
	 * @return string
	 */
	protected function create() {
		$id = bin2hex( random_bytes( 16 ) );

		$this->set_cookie( $id );

		return $id;
	}

	/**
	 * Slide the cookie expiry forward (session renewal).
	 *
	 * @return void
	 */
	protected function renew() {
		if ( null !== $this->id ) {
			$this->set_cookie( $this->id );
		}
	}

	/**
	 * Write the session cookie.
	 *
	 * @param string $session_id Session ID.
	 * @return void
	 */
	protected function set_cookie( $session_id ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$session_id,
			array(
				'expires'  => time() + self::LIFETIME,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
