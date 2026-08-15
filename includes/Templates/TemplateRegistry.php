<?php
/**
 * Progress template registry.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates;

use GoalCart\Templates\Campaign\CampaignProgressTemplate;
use GoalCart\Templates\Campaign\MilestoneChainTemplate;
use GoalCart\Templates\Goal\Template1;
use GoalCart\Templates\Goal\Template2;
use GoalCart\Templates\Goal\Template3;
use GoalCart\Templates\Goal\Template4;
use GoalCart\Templates\Goal\Template5;
use GoalCart\Templates\Goal\Template6;

defined( 'ABSPATH' ) || exit;

/**
 * Class TemplateRegistry
 *
 * Maps template ids to template classes and resolves them lazily — the
 * same convention as GoalEvaluatorRegistry (Phase 4) and
 * RewardApplicatorRegistry (Phase 5). The class map is filterable through
 * `goalcart_template_classes`, so a future template (including third-party
 * templates from the Phase 28 developer API) registers by adding an entry
 * to the map — no changes to the registry core, the Settings UI, the
 * builders, the REST layer or the preview system.
 *
 * The backend is the source of truth for *which* templates exist and what
 * their settings schemas are (exposed via the TemplatesController); the
 * React admin app only supplies the matching rendering components through
 * its own registry, keyed by the same template ids.
 */
class TemplateRegistry {

	/**
	 * Default template id => class map.
	 *
	 * The six design templates (template-1 … template-6 — the Classic
	 * Progress Card, Minimal Inline Cart Goal, Circular Progress, Product
	 * Recommendation + Goal, Compact Floating / Sticky Goal and Premium /
	 * Elegant E-commerce Style) replace the original Phase 12 Goal
	 * templates (basic, percentage, milestone, card) and the Ring gauge.
	 * The old ids are no longer registered and are never mapped — a
	 * persisted old id falls back to the scope default / store-wide
	 * template instead of resolving to a different template. The two
	 * Campaign templates (milestone_chain, campaign_progress) keep their
	 * separate campaign scope.
	 *
	 * @return array<string, string>
	 */
	protected function default_classes() {
		return array(
			'template-1'         => Template1::class,
			'template-2'         => Template2::class,
			'template-3'         => Template3::class,
			'template-4'         => Template4::class,
			'template-5'         => Template5::class,
			'template-6'         => Template6::class,
			'milestone_chain'    => MilestoneChainTemplate::class,
			'campaign_progress'  => CampaignProgressTemplate::class,
		);
	}

	/**
	 * Resolved template instances, cached per id.
	 *
	 * @var array<string, Template>
	 */
	protected $cache = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_defaults();
	}

	/**
	 * Register the built-in templates, honoring the extension filter.
	 *
	 * @return void
	 */
	protected function register_defaults() {
		$classes = $this->default_classes();

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the template class map.
			 *
			 * Add a template by mapping its stable id to a Template class:
			 *
			 * add_filter( 'goalcart_template_classes', function ( $classes ) {
			 *     $classes['countdown'] = My_Countdown_Template::class;
			 *     return $classes;
			 * } );
			 *
			 * @param array<string, string> $classes Template id => Template class.
			 */
			$classes = apply_filters( 'goalcart_template_classes', $classes );
		}

		foreach ( $classes as $id => $class ) {
			if ( is_string( $class ) && class_exists( $class ) ) {
				$instance = new $class();

				if ( $instance instanceof Template ) {
					$this->cache[ $id ] = $instance;
				}
			}
		}
	}

	/**
	 * Resolve the template instance for an id.
	 *
	 * @param string $id Template id.
	 * @return Template
	 * @throws \InvalidArgumentException When no template is registered.
	 */
	public function get( $id ) {
		$id = (string) $id;

		if ( ! isset( $this->cache[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'No progress template registered for id "%s".', $id )
			);
		}

		return $this->cache[ $id ];
	}

	/**
	 * Whether a template is registered for the given id.
	 *
	 * @param string $id Template id.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->cache[ (string) $id ] );
	}

	/**
	 * All registered templates.
	 *
	 * @return Template[]
	 */
	public function all() {
		return array_values( $this->cache );
	}

	/**
	 * All templates usable in a scope (goal | campaign | 'both' included).
	 *
	 * @param string $scope TemplateEngine::SCOPE_GOAL | SCOPE_CAMPAIGN.
	 * @return Template[]
	 */
	public function for_scope( $scope ) {
		$templates = array();

		foreach ( $this->cache as $template ) {
			if ( $scope === $template->scope() || 'both' === $template->scope() ) {
				$templates[] = $template;
			}
		}

		return $templates;
	}

	/**
	 * All registered template ids.
	 *
	 * @return string[]
	 */
	public function ids() {
		return array_keys( $this->cache );
	}
}
