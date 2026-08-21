<?php
/**
 * Debug log utility for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 *
 * A small file logger for the plugin's operational messages. There is no
 * admin UI for it — logging is a developer feature, controlled by code:
 *
 *  - Master switch (off means no file is ever touched): the
 *    `FARACART_LOGGING` constant or the `faracart_logging_enabled` filter.
 *  - Debug level (only `error`-level entries are written when off): the
 *    `FARACART_DEBUG` constant or the `faracart_debug_mode` filter.
 *
 * A defined constant always wins over the filter, and both default to off.
 * Example:
 *
 *     define( 'FARACART_LOGGING', true );  // errors land in the log.
 *     define( 'FARACART_DEBUG', true );    // debug entries too.
 *
 * Entries are appended to a single `faracart-debug.log` file in
 * WP_CONTENT_DIR, one line per entry, UTC-timestamped. See Logger::path().
 *
 * Mirrors the reference plugin's includes/Utils helper-folder convention.
 */
class Logger {

	/**
	 * Filter that gates the master logging switch (default off).
	 *
	 * @var string
	 */
	const LOGGING_ENABLED_FILTER = 'faracart_logging_enabled';

	/**
	 * Filter that gates debug-level entries (default off).
	 *
	 * @var string
	 */
	const DEBUG_MODE_FILTER = 'faracart_debug_mode';

	/**
	 * Debug log file name (inside WP_CONTENT_DIR).
	 *
	 * @var string
	 */
	const LOG_FILE = 'faracart-debug.log';

	/**
	 * Absolute path of the debug log file.
	 *
	 * @return string
	 */
	public static function path() {
		return trailingslashit( WP_CONTENT_DIR ) . self::LOG_FILE;
	}

	/**
	 * Whether a message of the given level should be written.
	 *
	 * @param string $level debug|error.
	 * @return bool
	 */
	public static function enabled( $level ) {
		if ( ! self::logging_enabled() ) {
			return false;
		}

		// Errors always land in the log; debug lines need debug mode on.
		if ( 'error' !== $level && ! self::debug_mode() ) {
			return false;
		}

		return true;
	}

	/**
	 * Master logging switch (developer-controlled).
	 *
	 * The `FARACART_LOGGING` constant wins when defined; otherwise the
	 * `faracart_logging_enabled` filter decides. Both default to off.
	 *
	 * @return bool
	 */
	public static function logging_enabled() {
		if ( defined( 'FARACART_LOGGING' ) ) {
			return (bool) FARACART_LOGGING;
		}

		return (bool) apply_filters( self::LOGGING_ENABLED_FILTER, false );
	}

	/**
	 * Debug-mode switch (developer-controlled): writes debug-level entries.
	 *
	 * The `FARACART_DEBUG` constant wins when defined; otherwise the
	 * `faracart_debug_mode` filter decides. Both default to off.
	 *
	 * @return bool
	 */
	public static function debug_mode() {
		if ( defined( 'FARACART_DEBUG' ) ) {
			return (bool) FARACART_DEBUG;
		}

		return (bool) apply_filters( self::DEBUG_MODE_FILTER, false );
	}

	/**
	 * Append one log line.
	 *
	 * No-op (and never touches the file) when the level is gated off.
	 * Best-effort: a failed write (permissions, missing uploads dir) is
	 * silent — logging must never break the storefront or the admin.
	 *
	 * @param mixed  $message Message to log (scalars and arrays are fine).
	 * @param string $level   debug|error.
	 * @return bool Whether a line was written.
	 */
	public static function write( $message, $level = 'debug' ) {
		if ( ! self::enabled( $level ) ) {
			return false;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			// @codingStandardsIgnoreLine -- wp_json_encode output is safe for a log file.
			$message = wp_json_encode( $message );
		}

		$line = '[' . gmdate( 'c' ) . '] [' . sanitize_key( $level ) . '] ' . (string) $message . "\n";

		// @phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- write
		// failure is handled by the boolean return; silence keeps the log clean.
		return (bool) @file_put_contents( self::path(), $line, FILE_APPEND );
	}

	/**
	 * Write a debug-level entry (requires debug_mode + logging_enabled).
	 *
	 * @param mixed $message Message.
	 * @return bool
	 */
	public static function debug( $message ) {
		return self::write( $message, 'debug' );
	}

	/**
	 * Write an error-level entry (requires logging_enabled).
	 *
	 * @param mixed $message Message.
	 * @return bool
	 */
	public static function error( $message ) {
		return self::write( $message, 'error' );
	}
}
