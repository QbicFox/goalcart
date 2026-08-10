<?php
/**
 * Basic progress template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class BasicTemplate
 *
 * The classic goal strip: a single progress bar + message. This is the
 * original Phase 12 "basic" variant, re-implemented as the first
 * registered Goal template of the pluggable engine — its schema exposes
 * the full legacy appearance surface (colors, radius, bar height,
 * animation, CSS class, custom CSS) plus a message toggle.
 */
class BasicTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'basic';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Basic', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Progress bar + message — the classic goal strip.', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function scope() {
		return 'goal';
	}

	/**
	 * @return int
	 */
	public function version() {
		return 1;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function schema() {
		return array(
			'accent'      => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#2271b1',
			),
			'bg'          => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ffffff',
			),
			'border'      => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'text'        => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#1d2327',
			),
			'radius'      => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'   => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'showMessage' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the progress message', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'animation'   => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'    => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'   => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
