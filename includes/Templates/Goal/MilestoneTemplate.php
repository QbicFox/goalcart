<?php
/**
 * Milestone progress template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class MilestoneTemplate
 *
 * The goal ladder variant — a threshold rung (dot + target) above the
 * bar. Its schema exposes the rung-specific controls (labels, dot and
 * done colors) that Basic / Percentage do not share.
 */
class MilestoneTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'milestone';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Milestone', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'A goal ladder of dots and targets, bar underneath.', 'goalcart' );
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
			'showLabels' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the target labels', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => true,
			),
			'dotColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Pending dot color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'doneColor'  => array(
				'type'    => 'color',
				'label'   => __( 'Reached dot color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#00a32a',
			),
			'radius'     => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'  => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'animation'  => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'   => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'  => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
