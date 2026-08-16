<?php
/**
 * Campaign progress template.
 *
 * @package FaraCart
 */

namespace FaraCart\Templates\Campaign;

use FaraCart\Templates\AbstractTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Class CampaignProgressTemplate
 *
 * The second Campaign-scoped template (Phase 32, campaign templates): a
 * single overall progress readout for the whole campaign — the campaign
 * name, a "2 of 4 milestones" counter and one bar driven by the top
 * milestone — instead of the per-goal cards or the milestone ladder. The
 * template's schema lives here; the React admin renderer and the
 * storefront JS render by the same id, so adding templates stays a
 * registration-only change (see TemplateRegistry).
 */
class CampaignProgressTemplate extends AbstractTemplate {

	/**
	 * Stable template id (persisted — never rename).
	 *
	 * @return string
	 */
	public function id() {
		return 'campaign_progress';
	}

	/**
	 * @return string
	 */
	public function label() {
		return __( 'Campaign progress', 'faracart' );
	}

	/**
	 * @return string
	 */
	public function description() {
		return __( 'One overall progress bar for the whole campaign, with a milestone counter and the rewards still on display.', 'faracart' );
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
	 * identity of their own, so an unconfigured campaign keeps tracking
	 * the legacy store-wide Appearance tokens (pre-engine behavior).
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
			'showTitle'       => array(
				'type'    => 'bool',
				'label'   => __( 'Show campaign name', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
			),
			'showCounter'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone counter', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
			),
			'showRewards'     => array(
				'type'    => 'bool',
				'label'   => __( 'Show milestone rewards', 'faracart' ),
				'group'   => __( 'Layout', 'faracart' ),
				'default' => true,
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
				'default' => 10,
				'min'     => 0,
				'max'     => 40,
			),
			'barHeight'       => array(
				'type'    => 'number',
				'label'   => __( 'Bar height (px)', 'faracart' ),
				'group'   => __( 'Shape', 'faracart' ),
				'default' => 12,
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
