<?php
/**
 * Classic progress card goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template1
 *
 * The most general-purpose goal template (Concept 01 — Classic Progress
 * Card): an icon badge, goal label + title, a percentage chip, a
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
		return __( 'Template 1', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Progress card with icon, title, percentage, amounts and a call-to-action.', 'goalcart' );
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
				'label'   => __( 'Accent color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#f97316',
			),
			'iconBg'        => array(
				'type'    => 'color',
				'label'   => __( 'Icon background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ffedd5',
			),
			'iconColor'     => array(
				'type'    => 'color',
				'label'   => __( 'Icon color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#f97316',
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
				'default' => '#f3f4f6',
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
				'default' => '#9ca3af',
			),
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 16,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'     => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#f97316',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#ffffff',
			),
			'buttonStyle'   => array(
				'type'    => 'select',
				'label'   => __( 'Button style', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => 'solid',
				'options' => array(
					'solid'  => __( 'Solid', 'goalcart' ),
					'outline' => __( 'Outline', 'goalcart' ),
				),
			),
			'density'       => array(
				'type'    => 'select',
				'label'   => __( 'Card density', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => 'comfortable',
				'options' => array(
					'comfortable' => __( 'Comfortable', 'goalcart' ),
					'compact'     => __( 'Compact', 'goalcart' ),
				),
			),
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showPercent'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the percentage', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showAmounts'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the amounts', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showRemaining' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the remaining amount', 'goalcart' ),
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
