<?php
/**
 * Product recommendation + goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template4
 *
 * The product-recommendation goal card (Concept 07 — Product
 * Recommendation + Goal): a gradient progress header with the remaining
 * amount, followed by the goal's own recommended products (the existing
 * Goal Cart / WooCommerce recommendation data) with add-to-cart buttons.
 * The default appearance follows the reference design (blue/indigo
 * header).
 */
class Template4 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-4';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 4', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Progress header plus recommended products with add-to-cart buttons.', 'goalcart' );
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
				'default' => '#2563eb',
			),
			'headerBg'      => array(
				'type'    => 'color',
				'label'   => __( 'Header color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#2563eb',
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
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 5,
			),
			'barHeight'     => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 8,
				'min'     => 4,
				'max'     => 48,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#2563eb',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#ffffff',
			),
			'buttonRadius'  => array(
				'type'    => 'number',
				'label'   => __( 'Button radius (px)', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => 8,
				'min'     => 0,
				'max'     => 24,
			),
			'productImageSize' => array(
				'type'    => 'number',
				'label'   => __( 'Product image size (px)', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => 40,
				'min'     => 24,
				'max'     => 80,
			),
			'showHeading'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the recommendation heading', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showRemaining' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the remaining amount', 'goalcart' ),
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
