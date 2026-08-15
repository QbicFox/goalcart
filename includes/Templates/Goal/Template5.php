<?php
/**
 * Compact floating / sticky goal template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template5
 *
 * The compact sticky/floating goal strip (Concept 08 — Compact Floating
 * / Sticky Goal): a dark bar with the goal icon, a slim progress bar, the
 * remaining amount and a compact CTA. Kept intentionally compact — it
 * must not behave like a normal large card. The default appearance
 * follows the reference design (dark slate surface with a green accent).
 */
class Template5 extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'template-5';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Template 5', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Compact dark bar — icon, progress, remaining amount and a small CTA.', 'goalcart' );
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
				'default' => '#4ade80',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Bar background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#1e293b',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#334155',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#f1f5f9',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#cbd5e1',
			),
			'trackColor'    => array(
				'type'    => 'color',
				'label'   => __( 'Progress track color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#475569',
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
				'default' => 6,
				'min'     => 2,
				'max'     => 16,
			),
			'buttonColor'   => array(
				'type'    => 'color',
				'label'   => __( 'Button color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#4ade80',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => '#0f172a',
			),
			'buttonRadius'  => array(
				'type'    => 'number',
				'label'   => __( 'Button radius (px)', 'goalcart' ),
				'group'   => __( 'Button', 'goalcart' ),
				'default' => 8,
				'min'     => 0,
				'max'     => 24,
			),
			'shadow'        => array(
				'type'    => 'number',
				'label'   => __( 'Shadow intensity', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => 16,
				'min'     => 0,
				'max'     => 32,
			),
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'goalcart' ),
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
