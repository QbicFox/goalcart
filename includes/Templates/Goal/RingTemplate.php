<?php
/**
 * Ring progress template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class RingTemplate
 *
 * A circular gauge: the target drawn as an SVG ring with the percentage
 * readout centered inside. Structurally different from the bar-based
 * templates (basic / percentage / milestone / card) — it renders an SVG
 * circle, not a fill bar — and its schema exposes ring-specific fields
 * (`ringSize`, `strokeWidth`, `trackColor`, `showPercent`) alongside the
 * shared legacy appearance surface (accent/bg/border/text/radius), so an
 * unconfigured ring still inherits the store's Appearance tokens.
 */
class RingTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'ring';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Ring', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Circular gauge — the target as a filled ring with the percentage centered.', 'goalcart' );
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
			// Shared legacy surface — the engine merges the store's
			// Appearance tokens (frontend_*) onto these keys when the ring
			// has not been customized per-template yet.
			'accent'      => array(
				'type'    => 'color',
				'label'   => __( 'Ring color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#2271b1',
			),
			'bg'          => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ffffff',
			),
			'border'      => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'text'        => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#1d2327',
			),
			'trackColor'  => array(
				'type'    => 'color',
				'label'   => __( 'Track color', 'goalcart' ),
				'group'   => __( 'Ring', 'goalcart' ),
				'default' => '#f0f0f1',
			),
			'ringSize'    => array(
				'type'    => 'number',
				'label'   => __( 'Ring size (px)', 'goalcart' ),
				'group'   => __( 'Ring', 'goalcart' ),
				'default' => 120,
				'min'     => 60,
				'max'     => 240,
			),
			'strokeWidth' => array(
				'type'    => 'number',
				'label'   => __( 'Stroke width (px)', 'goalcart' ),
				'group'   => __( 'Ring', 'goalcart' ),
				'default' => 12,
				'min'     => 4,
				'max'     => 24,
			),
			'showPercent' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the percentage in the center', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'animation'   => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'    => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'   => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
