<?php
/**
 * Template contract for the Goal Cart template engine.
 *
 * @package GoalCart
 */

namespace GoalCart\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Template
 *
 * Every progress template — Goal-scoped or Campaign-scoped — implements
 * this contract and registers against the TemplateRegistry. A template is
 * a fully self-describing rendering unit:
 *
 *  - a stable `id` (never renamed/reused — it is persisted in goal
 *    display_settings / campaign display_rules and in plugin settings),
 *  - human-readable label + description,
 *  - the scope it applies to (goal | campaign | both),
 *  - a `settings schema` describing exactly which appearance fields this
 *    template accepts — the schema drives the dynamic admin settings
 *    form, the REST payload and the server-side validation, so a new
 *    template automatically gets a working settings UI,
 *  - a `version` so a template's settings shape can migrate safely later.
 *
 * Built-in templates live in `includes/Templates/Goal/` and
 * `includes/Templates/Campaign/` and are registered on plugin bootstrap
 * through the filterable `goalcart_template_classes` class map (the same
 * lazy registry convention as GoalEvaluatorRegistry / RewardApplicatorRegistry).
 *
 * @see TemplateRegistry
 * @see TemplateEngine
 */
interface Template {

	/**
	 * The stable template id (stored in goal/campaign config and settings).
	 *
	 * @return string
	 */
	public function id();

	/**
	 * The human-readable template name (translated).
	 *
	 * @return string
	 */
	public function label();

	/**
	 * A short description of the template's layout (translated).
	 *
	 * @return string
	 */
	public function description();

	/**
	 * The scope this template can be used in: goal, campaign or both.
	 *
	 * @return string One of TemplateEngine::SCOPE_GOAL / SCOPE_CAMPAIGN / 'both'.
	 */
	public function scope();

	/**
	 * The template's schema version (bump when the settings shape changes).
	 *
	 * @return int
	 */
	public function version();

	/**
	 * The settings schema this template accepts.
	 *
	 * Shape: field key => array(
	 *   'type'    => 'color'|'text'|'textarea'|'number'|'bool'|'select'|'css',
	 *   'label'   => translated label,
	 *   'group'   => optional group heading for the settings form,
	 *   'default' => mixed,
	 *   'help'    => optional translated helper text,
	 *   'min'     => int (number fields),
	 *   'max'     => int (number fields),
	 *   'options' => array( value => label ) (select fields),
	 * ).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schema();

	/**
	 * The template's default settings (schema defaults, keyed by field).
	 *
	 * @return array<string, mixed>
	 */
	public function default_settings();
}
