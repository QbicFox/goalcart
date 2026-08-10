<?php
/**
 * Card progress template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Goal;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class CardTemplate
 *
 * The card variant — icon + title header above the bar, reward chip and
 * message toggles. Its schema carries the icon and content toggles that
 * the strip templates do not share.
 */
class CardTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'card';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Card', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'Icon, title and reward bundled in a card.', 'goalcart' );
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
			'icon'        => array(
				'type'    => 'text',
				'label'   => __( 'Fallback icon', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => '🎯',
				'help'    => __( 'Shown when the goal has no icon of its own.', 'goalcart' ),
			),
			'showReward'  => array(
				'type'    => 'bool',
				'label'   => __( 'Show the reward chip', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'showMessage' => array(
				'type'    => 'bool',
				'label'   => __( 'Show the progress message', 'goalcart' ),
				'group'   => __( 'Content', 'goalcart' ),
				'default' => true,
			),
			'accent'      => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'goalcart' ),
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
			'radius'      => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
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
