<?php
/**
 * Percentage progress template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class PercentageTemplate
 *
 * The "big percent readout" variant — a large percentage above the bar.
 * Its schema is genuinely different from Basic: a dedicated percent color
 * and size, and a bar toggle, because the percent is the hero element.
 */
class PercentageTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'percentage';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Percentage', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'A big percent readout above the bar.', 'goalcart' );
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
			'percentColor' => array(
				'type'    => 'color',
				'label'   => __( 'Percent color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#2271b1',
			),
			'percentSize'  => array(
				'type'    => 'number',
				'label'   => __( 'Percent size (px)', 'goalcart' ),
				'group'   => __( 'Typography', 'goalcart' ),
				'default' => 28,
				'min'     => 16,
				'max'     => 56,
			),
			'showBar'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the progress bar', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => true,
			),
			'radius'       => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'    => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'animation'    => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'     => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'    => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
