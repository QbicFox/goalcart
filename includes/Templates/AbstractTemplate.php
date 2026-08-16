<?php
/**
 * Base class for FaraCart progress templates.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Class AbstractTemplate
 *
 * Shared plumbing for every built-in template: `default_settings()` is
 * derived from the settings schema, so a template only declares its
 * schema and the defaults come along automatically.
 */
abstract class AbstractTemplate implements Template {

	/**
	 * The template's default settings (one entry per schema field).
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings() {
		$defaults = array();

		foreach ( $this->schema() as $key => $field ) {
			$defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
		}

		return $defaults;
	}

	/**
	 * Whether this template inherits the legacy store-wide `frontend_*`
	 * appearance tokens when it has not been configured yet.
	 *
	 * The six design templates ship their own reference defaults (their
	 * schema defaults match the HTML design), so they opt out — an
	 * unconfigured template must look exactly like its default design, not
	 * like the store's legacy appearance. Templates without a strong
	 * identity of their own (e.g. the campaign templates that predate the
	 * design system) may opt in to keep the pre-engine behavior.
	 *
	 * @return bool
	 */
	public function inherits_legacy() {
		return false;
	}
}
