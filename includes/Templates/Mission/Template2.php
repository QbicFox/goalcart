<?php
/**
 * Minimal inline cart mission template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Mission;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template2
 *
 * The compact inline cart-mission strip (Concept 02 — Minimal Inline Cart
 * Mission): a small icon, the mission title, the remaining amount, a slim
 * progress bar and a compact CTA. Intended to fit naturally inside the
 * WooCommerce cart between the cart content and the totals, so its
 * vertical height stays small. The default appearance follows the
 * reference design (indigo accent on a soft indigo surface).
 */
class Template2 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-2';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 2', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Minimal inline strip — icon, title, remaining amount and a slim bar.', 'faracart' );
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
				'default' => '#6366f1',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#eef2ff',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#e0e7ff',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#312e81',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#6366f1',
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
				'default' => 6,
				'min'     => 2,
				'max'     => 16,
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
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'faracart' ),
				'group'   => __( 'Content', 'faracart' ),
				'default' => true,
			),
			'showTitle'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show the mission title', 'faracart' ),
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
