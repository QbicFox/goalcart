<?php
/**
 * Classic progress card mission template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Mission;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template1
 *
 * The most general-purpose mission template (Concept 01 — Classic Progress
 * Card): an icon badge, mission label + title, a percentage chip, a
 * horizontal progress bar, the current/remaining amounts and a CTA. It
 * also renders the completed and expired states. The default appearance
 * follows the reference design (orange accent).
 */
class Template1 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-1';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 1', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Progress card with icon, title, percentage, amounts and a call-to-action.', 'faracart' );
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
				'label'   => __( 'Accent color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#f97316',
			),
			'iconBg'        => array(
				'type'    => 'color',
				'label'   => __( 'Icon background', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#ffedd5',
			),
			'iconColor'     => array(
				'type'    => 'color',
				'label'   => __( 'Icon color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#f97316',
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
				'default' => '#f3f4f6',
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
				'default' => '#9ca3af',
			),
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 5,
			),
			'barHeight'     => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#f97316',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#ffffff',
			),
			'buttonStyle'   => array(
				'type'    => 'select',
				'label'   => __( 'Button style', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => 'solid',
				'options' => array(
					'solid'  => __( 'Solid', 'faracart' ),
					'outline' => __( 'Outline', 'faracart' ),
				),
			),
			'density'       => array(
				'type'    => 'select',
				'label'   => __( 'Card density', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => 'comfortable',
				'options' => array(
					'comfortable' => __( 'Comfortable', 'faracart' ),
					'compact'     => __( 'Compact', 'faracart' ),
				),
			),
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showPercent'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the percentage', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showAmounts'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the amounts', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showRemaining' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the remaining amount', 'faracart' ),
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
