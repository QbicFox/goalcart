<?php
/**
 * Premium / elegant e-commerce goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template6
 *
 * The premium/elegant goal card (Concept 09 — Premium / Elegant
 * E-commerce Style): a gold-accented header, a thin elegant progress bar
 * with a marker dot, the current/remaining amounts and a refined CTA,
 * plus a highlighted "almost completed" callout. The default appearance
 * follows the reference design (gold on a soft neutral surface).
 */
class Template6 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-6';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 6', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Premium card — gold accents, elegant progress and a refined call-to-action.', 'goalcart' );
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
				'label'   => __( 'Gold accent color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#d4af37',
			),
			'progressColor' => array(
				'type'    => 'color',
				'label'   => __( 'Progress color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#d4af37',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#fafafa',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ece5d0',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#111827',
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
				'default' => 6,
				'min'     => 2,
				'max'     => 16,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#b8922a',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#b8922a',
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
