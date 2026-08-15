<?php
/**
 * Minimal inline cart goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template2
 *
 * The compact inline cart-goal strip (Concept 02 — Minimal Inline Cart
 * Goal): a small icon, the goal title, the remaining amount, a slim
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
		return __( 'Template 2', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Minimal inline strip — icon, title, remaining amount and a slim bar.', 'goalcart' );
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
				'default' => '#6366f1',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#eef2ff',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#e0e7ff',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#312e81',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#6366f1',
			),
			'radius'        => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 12,
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
				'default' => '#6366f1',
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
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showTitle'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show the goal title', 'goalcart' ),
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
