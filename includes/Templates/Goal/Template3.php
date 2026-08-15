<?php
/**
 * Circular progress goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template3
 *
 * The circular progress gauge (Concept 03 — Circular Progress): a ring
 * with the percentage centered inside, next to the goal icon, title,
 * description and the current/remaining amounts, plus a CTA. The
 * completed state draws a full green ring with a check. The default
 * appearance follows the reference design (indigo ring).
 */
class Template3 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-3';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 3', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Circular gauge — percentage ring with the goal details beside it.', 'goalcart' );
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
			'accent'        => array(
				'type'    => 'color',
				'label'   => __( 'Ring color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#6366f1',
			),
			'trackColor'    => array(
				'type'    => 'color',
				'label'   => __( 'Track color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#e5e7eb',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ffffff',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#e5e7eb',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#1f2937',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#6b7280',
			),
			'ringSize'      => array(
				'type'    => 'number',
				'label'   => __( 'Ring size (px)', 'goalcart' ),
				'group'   => __( 'Ring', 'goalcart' ),
				'default' => 100,
				'min'     => 60,
				'max'     => 200,
			),
			'strokeWidth'   => array(
				'type'    => 'number',
				'label'   => __( 'Ring thickness (px)', 'goalcart' ),
				'group'   => __( 'Ring', 'goalcart' ),
				'default' => 8,
				'min'     => 4,
				'max'     => 20,
			),
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 16,
				'min'     => 0,
				'max'     => 40,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#6366f1',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#6366f1',
			),
			'buttonStyle'   => array(
				'type'    => 'select',
				'label'   => __( 'Button style', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => 'outline',
				'options' => array(
					'solid'   => __( 'Solid', 'goalcart' ),
					'outline' => __( 'Outline', 'goalcart' ),
				),
			),
			'showPercent'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the percentage in the center', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showDescription' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the goal description', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showAmounts'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the amounts', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showCta'       => array(
				'type'    => 'bool',
				'label'   => __( 'Show the call-to-action', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'animation'     => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'      => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'     => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
