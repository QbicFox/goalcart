<?php
/**
 * Base class for Goal Cart progress templates.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates;

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
}
