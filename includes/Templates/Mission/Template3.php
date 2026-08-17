<?php
/**
 * Circular progress mission template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Mission;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template3
 *
 * The circular progress gauge (Concept 03 — Circular Progress): a ring
 * with the percentage centered inside, next to the mission icon, title,
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
		return __( 'Template 3', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Circular gauge — percentage ring with the mission details beside it.', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function scope() {
		return 'mission';
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
				'label'   => __( 'Ring color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#6366f1',
			),
			'trackColor'    => array(
				'type'    => 'color',
				'label'   => __( 'Track color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#e5e7eb',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#ffffff',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#e5e7eb',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#1f2937',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#6b7280',
			),
			'ringSize'      => array(
				'type'    => 'number',
				'label'   => __( 'Ring size (px)', 'faracart' ),
				'group'   => __( 'Ring', 'faracart' ),
				'default' => 100,
				'min'     => 60,
				'max'     => 200,
			),
			'strokeWidth'   => array(
				'type'    => 'number',
				'label'   => __( 'Ring thickness (px)', 'faracart' ),
				'group'   => __( 'Ring', 'faracart' ),
				'default' => 8,
				'min'     => 4,
				'max'     => 20,
			),
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 5,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#6366f1',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#6366f1',
			),
			'buttonStyle'   => array(
				'type'    => 'select',
				'label'   => __( 'Button style', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => 'outline',
				'options' => array(
					'solid'   => __( 'Solid', 'faracart' ),
					'outline' => __( 'Outline', 'faracart' ),
				),
			),
			'showPercent'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the percentage in the center', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showDescription' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the mission description', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showAmounts'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the amounts', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showCta'       => array(
				'type'    => 'bool',
				'label'   => __( 'Show the call-to-action', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'animation'     => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'faracart' ),
				'group'   => __( 'Behavior', 'faracart' ),
				'default' => true,
			),
			'cssClass'      => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'faracart' ),
				'group'   => __( 'Advanced', 'faracart' ),
				'default' => '',
			),
			'customCss'     => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'faracart' ),
				'group'   => __( 'Advanced', 'faracart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'faracart' ),
			),
		);
	}
}
