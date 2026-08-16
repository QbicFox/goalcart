<?php
/**
 * Product recommendation + goal template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Goal;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template4
 *
 * The product-recommendation goal card (Concept 07 — Product
 * Recommendation + Goal): a gradient progress header with the remaining
 * amount, followed by the goal's own recommended products (the existing
 * FaraCart / WooCommerce recommendation data) with add-to-cart buttons.
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
		return __( 'Template 4', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Progress header plus recommended products with add-to-cart buttons.', 'faracart' );
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
				'label'   => __( 'Accent color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#2563eb',
			),
			'headerBg'      => array(
				'type'    => 'color',
				'label'   => __( 'Header color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#2563eb',
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
				'default' => 8,
				'min'     => 4,
				'max'     => 48,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#2563eb',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#ffffff',
			),
			'buttonRadius'  => array(
				'type'    => 'number',
				'label'   => __( 'Button radius (px)', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => 8,
				'min'     => 0,
				'max'     => 24,
			),
			'productImageSize' => array(
				'type'    => 'number',
				'label'   => __( 'Product image size (px)', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => 40,
				'min'     => 24,
				'max'     => 80,
			),
			'showHeading'   => array(
				'type'    => 'bool',
				'label'   => __( 'Show the recommendation heading', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showRemaining' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the remaining amount', 'faracart' ),
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
