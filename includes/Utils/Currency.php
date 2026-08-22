<?php

/**
 * Centralized WooCommerce currency configuration for FaraCart.
 *
 * FaraCart never maintains its own currency unit, symbol, position or
 * formatting — this class is a thin, read-only facade over WooCommerce's
 * currency settings, which are the single source of truth for every
 * FaraCart-rendered amount (admin dashboard, storefront widgets, previews
 * and server-rendered messages).
 *
 * Every function degrades gracefully when WooCommerce is absent so the
 * plugin's bare constructions and tests keep working.
 *
 * @package FaraCart
 */

namespace FaraCart\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Class Currency
 *
 * Static helpers resolving WooCommerce's currency configuration:
 *
 *  - `code()`     — get_woocommerce_currency()
 *  - `symbol()`   — get_woocommerce_currency_symbol()
 *  - `position()` — get_option( 'woocommerce_currency_pos' )
 *  - `decimals()` / `decimal_separator()` / `thousand_separator()` —
 *    wc_get_price_decimals() / wc_get_price_decimal_separator() /
 *    wc_get_price_thousand_separator()
 *  - `price()`    — wc_price() output, stripped to plain text
 *  - `config()`   — the full config array exposed to the JS/React layers
 */
class Currency {

	/**
	 * The active currency code (uppercase ISO-4217).
	 *
	 * @return string
	 */
	public static function code() {
		return function_exists( 'get_woocommerce_currency' )
			? (string) get_woocommerce_currency()
			: '';
	}

	/**
	 * The currency symbol localized by WooCommerce (for example, a symbol or currency name).
	 *
	 * @return string
	 */
	public static function symbol() {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? (string) get_woocommerce_currency_symbol()
			: '';

		// WooCommerce integrations may return a localized symbol as an
		// HTML entity. Currency values are inserted with textContent in the
		// JS layers, so normalize the entity before exposing the symbol.
		return html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The currency position: left | right | left_space | right_space.
	 *
	 * @return string
	 */
	public static function position() {
		$position = function_exists( 'get_option' )
			? (string) get_option( 'woocommerce_currency_pos', 'left' )
			: 'left';

		return in_array( $position, array( 'left', 'right', 'left_space', 'right_space' ), true )
			? $position
			: 'left';
	}

	/**
	 * The number of decimals WooCommerce displays for prices.
	 *
	 * @return int
	 */
	public static function decimals() {
		return function_exists( 'wc_get_price_decimals' )
			? (int) wc_get_price_decimals()
			: 2;
	}

	/**
	 * The decimal separator.
	 *
	 * @return string
	 */
	public static function decimal_separator() {
		return function_exists( 'wc_get_price_decimal_separator' )
			? (string) wc_get_price_decimal_separator()
			: '.';
	}

	/**
	 * The thousand separator.
	 *
	 * @return string
	 */
	public static function thousand_separator() {
		return function_exists( 'wc_get_price_thousand_separator' )
			? (string) wc_get_price_thousand_separator()
			: ',';
	}

	/**
	 * Format an amount as WooCommerce does, reduced to plain text.
	 *
	 * `wc_price()` is the authoritative formatter (symbol, position,
	 * separators, decimals). Its markup is stripped and its HTML entities
	 * decoded so the result is safe for insertion via `textContent` in the
	 * storefront and React layers (WooCommerce ships localized symbols as
	 * HTML entities).
	 *
	 * @param float $value Amount.
	 * @return string
	 */
	public static function price( $value ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode(
				wp_strip_all_tags( wc_price( (float) $value ) ),
				ENT_QUOTES,
				'UTF-8'
			);
		}

		// WooCommerce absent: reproduce the configured format directly.
		return number_format(
			(float) $value,
			self::decimals(),
			self::decimal_separator(),
			self::thousand_separator()
		);
	}

	/**
	 * The currency configuration exposed to PHP/API consumers.
	 *
	 * @return array<string, mixed>
	 */
	public static function config() {
		return array(
			'currency'           => self::code(),
			'symbol'             => self::symbol(),
			'position'           => self::position(),
			'decimals'           => self::decimals(),
			'decimal_separator'  => self::decimal_separator(),
			'thousand_separator' => self::thousand_separator(),
		);
	}

	/**
	 * The flat configuration used by the storefront and admin boot payloads.
	 *
	 * The values originate from config(); this method only adapts the keys to
	 * the existing JavaScript payload convention.
	 *
	 * @return array<string, mixed>
	 */
	public static function frontend_config() {
		$config = self::config();

		return array(
			'currency'                  => $config['currency'],
			'currencySymbol'            => $config['symbol'],
			'currencyPosition'          => $config['position'],
			'currencyDecimals'          => $config['decimals'],
			'currencyDecimalSeparator'  => $config['decimal_separator'],
			'currencyThousandSeparator' => $config['thousand_separator'],
		);
	}
}
