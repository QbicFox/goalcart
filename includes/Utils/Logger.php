<?php
/**
 * Debug log utility for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Utils;

use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 *
 * Phase 18 (Settings → Advanced → logging / debug mode) — a small file
 * logger for the plugin's operational messages.
 *
 * Gating (both read straight from the settings option so any service can
 * log without holding the Settings instance):
 *
 *  - `logging_enabled` — master switch; off means no file is ever touched.
 *  - `debug_mode`      — when off, only `error`-level entries are written;
 *                        when on, `debug`-level entries are written too.
 *
 * Entries are appended to a single `goalcart-debug.log` file in
 * WP_CONTENT_DIR, one line per entry, UTC-timestamped. The path is
 * surfaced in the admin Settings page when logging is enabled.
 *
 * Mirrors the reference plugin's includes/Utils helper-folder convention.
 */
class Logger {

	/**
	 * Debug log file name (inside WP_CONTENT_DIR).
	 *
	 * @var string
	 */
	const LOG_FILE = 'goalcart-debug.log';

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
		$settings = get_option( Settings::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( empty( $settings['logging_enabled'] ) ) {
			return false;
		}

		// Errors always land in the log; debug lines need debug_mode on.
		if ( 'error' !== $level && empty( $settings['debug_mode'] ) ) {
			return false;
		}

		return true;
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
