<?php
/**
 * Milestone chain campaign template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Campaign;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class MilestoneChainTemplate
 *
 * The first Campaign-scoped template: the multi-milestone chain from the
 * example — the campaign's milestones render as one connected
 * ladder (dots + labels + rewards) with a progress bar underneath,
 * instead of each mission rendering its own standalone card.
 *
 * Campaign templates live in their own scope on purpose: a milestone
 * chain needs the *whole campaign's* milestones, which a single Mission
 * template can never provide, so the two registries stay independent.
 */
class MilestoneChainTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'milestone_chain';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Milestone chain', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'A connected ladder of the campaign milestones — dots, targets and rewards — with an overall progress bar.', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function scope() {
		return 'campaign';
	}

	/**
	 * @return int
	 */
	public function version() {
		return 1;
	}

	/**
	 * The campaign templates predate the design system and have no strong
	 * identity of their own, so an unconfigured chain keeps tracking the
	 * legacy store-wide Appearance tokens (pre-engine behavior).
	 *
	 * @return bool
	 */
	public function inherits_legacy() {
		return true;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function schema() {
		return array(
			'showLabels'      => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone names', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
			),
			'showTargets'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone targets', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
			),
			'showRewards'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone rewards', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
			),
			'dotColor'        => array(
				'type'    => 'color',
				'label'   => __( 'Pending dot color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#dcdcde',
			),
			'doneColor'       => array(
				'type'    => 'color',
				'label'   => __( 'Reached dot color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#00a32a',
			),
			'connectorColor'  => array(
				'type'    => 'color',
				'label'   => __( 'Connector line color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#dcdcde',
			),
			'accent'          => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#2271b1',
			),
			'bg'              => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#ffffff',
			),
			'border'          => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#dcdcde',
			),
			'text'            => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'faracart' ),
				'group'   => __( 'Colors', 'faracart' ),
				'default' => '#1d2327',
			),
			'radius'          => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 2,
				'min'     => 0,
				'max'     => 5,
			),
			'barHeight'       => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'animation'       => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'faracart' ),
				'group'   => __( 'Behavior', 'faracart' ),
				'default' => true,
			),
			'cssClass'        => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'faracart' ),
				'group'   => __( 'Advanced', 'faracart' ),
				'default' => '',
			),
			'customCss'       => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'faracart' ),
				'group'   => __( 'Advanced', 'faracart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'faracart' ),
			),
		);
	}
}
