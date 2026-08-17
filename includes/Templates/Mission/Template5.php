<?php
/**
 * Compact floating / sticky mission template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Mission;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template5
 *
 * The compact sticky/floating mission strip (Concept 08 — Compact Floating
 * / Sticky Mission): a dark bar with the mission icon, a slim progress bar, the
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
		return __( 'Template 5', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Compact dark bar — icon, progress, remaining amount and a small CTA.', 'faracart' );
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
				'default' => '#4ade80',
			),
			'bg'            => array(
				'type'    => 'color',
				'label'   => __( 'Bar background', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#1e293b',
			),
			'border'        => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#334155',
			),
			'text'          => array(
				'type'    => 'color',
				'label'   => __( 'Text color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#f1f5f9',
			),
			'secondaryText' => array(
				'type'    => 'color',
				'label'   => __( 'Secondary text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#cbd5e1',
			),
			'trackColor'    => array(
				'type'    => 'color',
				'label'   => __( 'Progress track color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#475569',
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
				'default' => '#4ade80',
			),
			'buttonTextColor' => array(
				'type'    => 'color',
				'label'   => __( 'Button text color', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => '#0f172a',
			),
			'buttonRadius'  => array(
				'type'    => 'number',
				'label'   => __( 'Button radius (px)', 'faracart' ),
				'group'   => __( 'Button', 'faracart' ),
				'default' => 8,
				'min'     => 0,
				'max'     => 24,
			),
			'shadow'        => array(
				'type'    => 'number',
				'label'   => __( 'Shadow intensity', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => 16,
				'min'     => 0,
				'max'     => 32,
			),
			'showIcon'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show the icon', 'faracart' ),
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
