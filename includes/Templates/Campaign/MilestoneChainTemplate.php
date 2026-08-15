<?php
/**
 * Milestone chain campaign template.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates\Campaign;

use GoalCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class MilestoneChainTemplate
 *
 * The first Campaign-scoped template: the multi-milestone chain from the
 * Phase 10 example — the campaign's milestones render as one connected
 * ladder (dots + labels + rewards) with a progress bar underneath,
 * instead of each goal rendering its own standalone card.
 *
 * Campaign templates live in their own scope on purpose: a milestone
 * chain needs the *whole campaign's* milestones, which a single Goal
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
		return __( 'Milestone chain', 'goalcart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'A connected ladder of the campaign milestones — dots, targets and rewards — with an overall progress bar.', 'goalcart' );
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
				'label'   => __( 'Show milestone names', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => true,
			),
			'showTargets'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone targets', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => true,
			),
			'showRewards'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone rewards', 'goalcart' ),
				'group'   => __( 'Layout', 'goalcart' ),
				'default' => true,
			),
			'dotColor'        => array(
				'type'    => 'color',
				'label'   => __( 'Pending dot color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'doneColor'       => array(
				'type'    => 'color',
				'label'   => __( 'Reached dot color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#00a32a',
			),
			'connectorColor'  => array(
				'type'    => 'color',
				'label'   => __( 'Connector line color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'accent'          => array(
				'type'    => 'color',
				'label'   => __( 'Accent color', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#2271b1',
			),
			'bg'              => array(
				'type'    => 'color',
				'label'   => __( 'Background', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#ffffff',
			),
			'border'          => array(
				'type'    => 'color',
				'label'   => __( 'Border', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#dcdcde',
			),
			'text'            => array(
				'type'    => 'color',
				'label'   => __( 'Text', 'goalcart' ),
				'group'   => __( 'Colors', 'goalcart' ),
				'default' => '#1d2327',
			),
			'radius'          => array(
				'type'    => 'number',
				'label'   => __( 'Corner radius (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'       => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'goalcart' ),
				'group'   => __( 'Shape', 'goalcart' ),
				'default' => 10,
				'min'     => 4,
				'max'     => 48,
			),
			'animation'       => array(
				'type'    => 'bool',
				'label'   => __( 'Animate progress updates', 'goalcart' ),
				'group'   => __( 'Behavior', 'goalcart' ),
				'default' => true,
			),
			'cssClass'        => array(
				'type'    => 'text',
				'label'   => __( 'Extra CSS class', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
			),
			'customCss'       => array(
				'type'    => 'css',
				'label'   => __( 'Custom CSS', 'goalcart' ),
				'group'   => __( 'Advanced', 'goalcart' ),
				'default' => '',
				'help'    => __( 'Appended to the widget styles. Applied only while this template renders.', 'goalcart' ),
			),
		);
	}
}
