<?php
/**
 * FaraCart pluggable template engine tests (Phase 12 → engine).
 *
 * Boots WordPress and exercises the template engine end to end:
 *
 *  - the registry contract: every built-in template implements Template,
 *    the six design Goal templates (template-1 … template-6) plus the
 *    two Campaign templates (milestone_chain and campaign_progress) are
 *    registered, each with a stable id, label, description, scope,
 *    version and a settings schema whose defaults drive
 *    `default_settings()`
 *  - schema validation: sanitize_settings() clamps numbers, validates
 *    colors/enums/booleans, strips tags from CSS, drops unknown keys, and
 *    scope checks reject the wrong template in the wrong scope
 *  - resolution order: item override → scope default → store-wide
 *    frontend_template → hardcoded 'template-1' fallback; retired
 *    pre-design ids (basic / percentage / milestone / card / ring) are
 *    never mapped — they resolve to '' and fall back through the chain,
 *    and the pre-engine `display_settings.template` alias is no longer
 *    read
 *  - campaign resolution: campaign display_rules drive a campaign-scoped
 *    template; none configured means per-goal cards ('' template id)
 *  - extensibility: a custom template registers through the
 *    faracart_template_classes filter and resolves through the engine
 *  - settings REST: the save schema carries template_defaults /
 *    template_settings with server-side validation, the sanitizer drops
 *    unknown scopes/templates and cleans values against each schema, and
 *    frontend_template ↔ template_defaults.goal stay in sync
 *  - the admin TemplatesController payload: scopes, defaults, per-scope
 *    definitions with schema + effective settings, versions
 *  - the frontend payload integration: shape_goal() carries the resolved
 *    template + settings, and /progress builds campaign template groups
 *
 * Settings flips are in-memory and restored; DB writes run inside
 * transactions and are rolled back, with residue asserted.
 *
 * Run: php tests/template-test.php   (from the plugin directory)
 */

// Locate wp-load.php by walking up from this file (tests -> plugin -> plugins -> wp-content -> root).
$dir = __DIR__;
while ( ! file_exists( $dir . '/wp-load.php' ) ) {
	$parent = dirname( $dir );
	if ( $parent === $dir ) {
		fwrite( STDERR, "Could not locate wp-load.php.\n" );
		exit( 2 );
	}
	$dir = $parent;
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';

require $dir . '/wp-load.php';
require dirname( __DIR__ ) . '/ravis-faracart.php';

use FaraCart\REST\FrontendController;
use FaraCart\REST\SettingsController;
use FaraCart\REST\TemplatesController;
use FaraCart\Settings\Settings;
use FaraCart\Templates\AbstractTemplate;
use FaraCart\Templates\Template;
use FaraCart\Templates\TemplateEngine;
use FaraCart\Templates\TemplateRegistry;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;

$failures = 0;
$checks   = 0;

function check( $label, $cond ) {
	global $failures, $checks;
	$checks++;
	if ( $cond ) {
		echo "OK   {$label}\n";
	} else {
		$failures++;
		echo "FAIL {$label}\n";
	}
}

function goal_display( array $display ) {
	return new \FaraCart\Goals\Goal( array(
		'id'               => 1,
		'name'             => 'Template Test Goal',
		'status'           => 'active',
		'type'             => 'amount',
		'target'           => 100,
		'calculation_mode' => 'subtotal',
		'display_settings' => $display,
	) );
}

$container = \FaraCart\Plugin::instance()->container();

$engine        = $container->get( TemplateEngine::class );
$registry      = $container->get( TemplateRegistry::class );
$settings      = $container->get( Settings::class );
$settings_ctrl = $container->get( SettingsController::class );
$templates_ctrl = $container->get( TemplatesController::class );
$frontend      = $container->get( FrontendController::class );

// Snapshot the in-memory settings so every section can restore them.
$all_before = $settings->all();

// Deterministic baseline: clear the per-scope defaults so resolution runs
// against the store-wide surface only until a section sets them
// explicitly.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'template-1' );

// ---------------------------------------------------------------------------
// 1. Registry & contract
// ---------------------------------------------------------------------------
echo "\n== 1. Registry & contract ==\n";

check( 'registry resolves from container', $registry instanceof TemplateRegistry );
check( 'engine resolves from container', $engine instanceof TemplateEngine );

$goal_ids = array_map(
	function ( $template ) {
		return $template->id();
	},
	$registry->for_scope( TemplateEngine::SCOPE_GOAL )
);
sort( $goal_ids );

check( 'goal scope has the six design templates', array( 'template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6' ) === $goal_ids );

$campaign_ids = array_map(
	function ( $template ) {
		return $template->id();
	},
	$registry->for_scope( TemplateEngine::SCOPE_CAMPAIGN )
);
sort( $campaign_ids );

check( 'campaign scope has both campaign templates', array( 'campaign_progress', 'milestone_chain' ) === $campaign_ids );
check( 'all() exposes eight templates', 8 === count( $registry->all() ) );

foreach ( $registry->all() as $template ) {
	check( 'template implements the contract', $template instanceof Template );
	check( 'template id is a non-empty string', is_string( $template->id() ) && '' !== $template->id() );
	check( 'template has a label', is_string( $template->label() ) && '' !== $template->label() );
	check( 'template has a version', is_int( $template->version() ) && $template->version() >= 1 );
}

// Schema contract: every field declares type + default, and
// default_settings() derives from the schema.
$basic = $registry->get( 'template-1' );
$schema = $basic->schema();

check( 'template-1 schema exposes the shared surface', array_key_exists( 'accent', $schema ) && array_key_exists( 'barHeight', $schema ) && array_key_exists( 'animation', $schema ) && array_key_exists( 'customCss', $schema ) );

$schema_ok = true;
foreach ( $basic->default_settings() as $key => $value ) {
	if ( ! isset( $schema[ $key ]['type'], $schema[ $key ]['default'] ) ) {
		$schema_ok = false;
		break;
	}
	if ( $schema[ $key ]['default'] !== $value ) {
		$schema_ok = false;
		break;
	}
}
check( 'default_settings derives from the schema', $schema_ok );

$chain = $registry->get( 'milestone_chain' );
check( 'chain schema has campaign-specific fields', array_key_exists( 'showLabels', $chain->schema() ) && array_key_exists( 'dotColor', $chain->schema() ) && array_key_exists( 'showRewards', $chain->schema() ) );

// The template-3 (circular gauge) schema is genuinely its own — ring-only
// fields that no other built-in exposes, alongside the shared surface.
$ring = $registry->get( 'template-3' );
check( 'template-3 schema has ring-specific fields', array_key_exists( 'ringSize', $ring->schema() ) && array_key_exists( 'strokeWidth', $ring->schema() ) && array_key_exists( 'trackColor', $ring->schema() ) && array_key_exists( 'showPercent', $ring->schema() ) );
check( 'template-3 shares the shared accent key', array_key_exists( 'accent', $ring->schema() ) );

// ---------------------------------------------------------------------------
// 2. Schema validation (engine-level)
// ---------------------------------------------------------------------------
echo "\n== 2. Schema validation ==\n";

check( 'template-1 is registered for the goal scope', $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'template-1' ) );
check( 'chain is registered for the campaign scope', $engine->is_registered( TemplateEngine::SCOPE_CAMPAIGN, 'milestone_chain' ) );
check( 'chain rejected for the goal scope', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'milestone_chain' ) );
check( 'unknown id rejected', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'countdown' ) );
check( 'empty id rejected', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, '' ) );
check( 'normalize rejects unknown ids', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'countdown' ) );
check( 'normalize keeps known ids', 'template-4' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'template-4' ) );
check( 'retired id is rejected (no mapping)', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'card' ) );
check( 'retired ring id is rejected (no mapping)', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'ring' ) );

// Colors, numbers, booleans, enums, CSS and unknown keys.
$cleaned = $engine->sanitize_settings(
	$registry->get( 'template-1' ),
	array(
		'accent'     => 'not-a-color',
		'bg'         => '#ff0000',
		'radius'     => 999,
		'barHeight'  => -5,
		'animation'  => 1,
		'showPercent' => 'yes',
		'cssClass'   => '  my-class  ',
		'customCss'  => '<script>alert(1)</script>.gc { color: red; }',
		'bogus_key'  => 'dropped',
	)
);

check( 'invalid color falls back to the schema default', '#f97316' === $cleaned['accent'] );
check( 'valid color kept', '#ff0000' === $cleaned['bg'] );
check( 'radius clamped to max', 5 === $cleaned['radius'] );
check( 'barHeight clamped to min', 4 === $cleaned['barHeight'] );
check( 'bool normalized', true === $cleaned['animation'] && true === $cleaned['showPercent'] );
check( 'css class trimmed', 'my-class' === $cleaned['cssClass'] );
check( 'css stripped of tags', false !== strpos( $cleaned['customCss'], '.gc' ) && false === strpos( $cleaned['customCss'], '<script' ) );
check( 'unknown keys dropped', ! array_key_exists( 'bogus_key', $cleaned ) );
check( 'sanitized settings are complete', count( $cleaned ) === count( $schema ) );

// The chain template's select/bool/number fields.
$cleaned_chain = $engine->sanitize_settings(
	$registry->get( 'milestone_chain' ),
	array(
		'showLabels' => 0,
		'dotColor'   => '#123456',
	)
);
check( 'chain booleans normalized', false === $cleaned_chain['showLabels'] );
check( 'chain colors validated', '#123456' === $cleaned_chain['dotColor'] );

// The template-3 gauge's number/color/bool fields.
$cleaned_ring = $engine->sanitize_settings(
	$registry->get( 'template-3' ),
	array(
		'ringSize'    => 999,
		'strokeWidth' => 0,
		'trackColor'  => 'not-a-color',
		'showPercent' => 0,
	)
);
check( 'ring size clamped to max', 200 === $cleaned_ring['ringSize'] );
check( 'ring stroke clamped to min', 4 === $cleaned_ring['strokeWidth'] );
check( 'ring track color falls back on invalid', '#e5e7eb' === $cleaned_ring['trackColor'] );
check( 'ring bool normalized', false === $cleaned_ring['showPercent'] );
check( 'template-3 rejected for the campaign scope', ! $engine->is_registered( TemplateEngine::SCOPE_CAMPAIGN, 'template-3' ) );

// ---------------------------------------------------------------------------
// 3. Goal resolution (override → scope default → legacy → fallback)
// ---------------------------------------------------------------------------
echo "\n== 3. Goal resolution ==\n";

// 3.1 Hardcoded fallback: nothing configured anywhere.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'template-1' );

$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'fallback resolves to template-1', 'template-1' === $resolved['template_id'] );
check( 'fallback settings are complete', count( $resolved['settings'] ) === count( $schema ) );
check( 'fallback settings carry the schema defaults', '#f97316' === $resolved['settings']['accent'] );

// 3.2 Retired store-wide template is never mapped: 'card' is not
// registered, so resolution falls through the scope default ('' here)
// and the hardcoded fallback still yields template-1.
$settings->set( 'frontend_template', 'card' );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'retired frontend_template falls back to template-1 (never mapped)', 'template-1' === $resolved['template_id'] );

// 3.3 Scope default beats the legacy surface.
$settings->set( 'template_defaults', array( 'goal' => 'template-2', 'campaign' => '' ) );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'scope default wins over legacy template', 'template-2' === $resolved['template_id'] );

// 3.4 Item override beats the scope default.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'template-4' ) ) );
check( 'item template_id wins', 'template-4' === $resolved['template_id'] );

// 3.5 The pre-engine display_settings.template alias is no longer read:
// a goal storing only `template` resolves through the scope default
// (template-2, set above) instead of through any old-id mapping.
$resolved = $engine->resolve_goal( goal_display( array( 'template' => 'card' ) ) );
check( 'pre-engine template alias is ignored', 'template-2' === $resolved['template_id'] );

// 3.6 Explicit template_id beats any stale `template` value.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'template-2', 'template' => 'card' ) ) );
check( 'template_id beats a stale template value', 'template-2' === $resolved['template_id'] );

// 3.7 Removed template: falls back to the scope default, never fails.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'countdown' ) ) );
check( 'removed template falls back to the scope default', 'template-2' === $resolved['template_id'] );

// 3.8 The design templates ship their own reference defaults and never
// inherit the legacy frontend_* tokens — an unconfigured template-1
// renders exactly like its default design, not like the store's legacy
// appearance.
$settings->set( 'template_defaults', array( 'goal' => 'template-1', 'campaign' => '' ) );
$settings->set( 'frontend_accent', '#ff0000' );
$settings->set( 'frontend_bg', '#f0f0f0' );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'design template keeps its own accent default', '#f97316' === $resolved['settings']['accent'] );
check( 'design template ignores the legacy bg token', '#ffffff' === $resolved['settings']['bg'] );

// 3.9 Stored per-template appearance overrides the design defaults.
$settings->set(
	'template_settings',
	array(
		'goal'     => array( 'template-1' => array( 'accent' => '#00aa00', 'barHeight' => 22 ) ),
		'campaign' => array(),
	)
);
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'stored template appearance wins over the defaults', '#00aa00' === $resolved['settings']['accent'] && 22 === $resolved['settings']['barHeight'] );

// 3.10 Per-goal override on top of the stored appearance.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'template-1', 'template_settings' => array( 'accent' => '#0000ff' ) ) ) );
check( 'per-goal settings override the stored appearance', '#0000ff' === $resolved['settings']['accent'] );

// 3.11 Template-specific schema keys stay out of the legacy mapping —
// template-3's gauge fields keep their schema defaults.
$settings->set( 'template_defaults', array( 'goal' => 'template-3', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'template-3 keeps its own gauge defaults', 100 === $resolved['settings']['ringSize'] && '#e5e7eb' === $resolved['settings']['trackColor'] );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 4. Campaign resolution
// ---------------------------------------------------------------------------
echo "\n== 4. Campaign resolution ==\n";

$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'template-1' );

// 4.1 Nothing configured → per-goal cards ('' template).
$resolved = $engine->resolve_campaign( array() );
check( 'no campaign template resolves to empty', '' === $resolved['template_id'] && array() === $resolved['settings'] );

// 4.2 Scope default campaign template.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => 'milestone_chain' ) );
$resolved = $engine->resolve_campaign( array() );
check( 'campaign scope default resolves', 'milestone_chain' === $resolved['template_id'] );
check( 'campaign settings complete', count( $resolved['settings'] ) === count( $chain->schema() ) );

// 4.3 display_rules.template_id wins.
$resolved = $engine->resolve_campaign( array( 'template_id' => 'milestone_chain', 'template_settings' => array( 'showLabels' => false, 'dotColor' => '#123456' ) ) );
check( 'display_rules template_id wins', 'milestone_chain' === $resolved['template_id'] );
check( 'display_rules settings applied', false === $resolved['settings']['showLabels'] && '#123456' === $resolved['settings']['dotColor'] );

// 4.4 Removed campaign template falls back to the scope default.
$resolved = $engine->resolve_campaign( array( 'template_id' => 'removed' ) );
check( 'removed campaign template falls back to the default', 'milestone_chain' === $resolved['template_id'] );

// 4.5 No scope default → removed campaign template means per-goal cards.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$resolved = $engine->resolve_campaign( array( 'template_id' => 'removed' ) );
check( 'removed campaign template with no default resolves to empty', '' === $resolved['template_id'] );
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => 'milestone_chain' ) );

// 4.6 A goal-scoped template is never valid for campaigns.

check( 'goal template rejected in campaign scope', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_CAMPAIGN, 'template-1' ) );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 5. Extensibility: a custom template via faracart_template_classes
// ---------------------------------------------------------------------------
echo "\n== 5. Extensibility (custom template) ==\n";

class FaraCart_Test_Template extends AbstractTemplate {
	public function id() {
		return 'test_custom';
	}
	public function label() {
		return 'Test custom';
	}
	public function description() {
		return 'A test-only custom template.';
	}
	public function scope() {
		return 'goal';
	}
	public function version() {
		return 1;
	}
	public function schema() {
		return array(
			// A template-specific key that the legacy token mapper never
			// touches, so the resolution default is deterministic.
			'glowColor' => array(
				'type'    => 'color',
				'label'   => 'Glow',
				'default' => '#112233',
			),
		);
	}
}

add_filter(
	'faracart_template_classes',
	function ( $classes ) {
		$classes['test_custom'] = 'FaraCart_Test_Template';

		return $classes;
	}
);

$custom_registry = new TemplateRegistry();
$custom_engine   = new TemplateEngine( $custom_registry, $settings );

check( 'custom template registered through the filter', $custom_registry->has( 'test_custom' ) );
check( 'custom template is a Template', $custom_registry->get( 'test_custom' ) instanceof Template );
check( 'custom template usable in goal scope', $custom_engine->is_registered( TemplateEngine::SCOPE_GOAL, 'test_custom' ) );

$resolved = $custom_engine->resolve_goal( goal_display( array( 'template_id' => 'test_custom' ) ) );
check( 'custom template resolves with its schema defaults', 'test_custom' === $resolved['template_id'] && '#112233' === $resolved['settings']['glowColor'] );

remove_all_filters( 'faracart_template_classes' );

// ---------------------------------------------------------------------------
// 6. Persisted old ids never map (no migration step needed)
// ---------------------------------------------------------------------------
echo "\n== 6. Persisted old ids never map ==\n";

$wpdb = $GLOBALS['wpdb'];
$goals_table = Schema::table( 'goals' );

// A stored old id in display_settings resolves through the normal chain
// (scope default → store-wide → hardcoded fallback) — it is never
// translated to a different template.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'template-2' );

$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'card' ) ) );
check( 'stored old template_id falls back to the store-wide template', 'template-2' === $resolved['template_id'] );

$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'ring' ) ) );
check( 'stored ring id is never mapped to template-3', 'template-2' === $resolved['template_id'] );

$settings->set( 'template_defaults', array( 'goal' => 'template-4', 'campaign' => '' ) );
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'percentage' ) ) );
check( 'stored old id falls back to the scope default', 'template-4' === $resolved['template_id'] );

$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'frontend_template', '' );
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'basic' ) ) );
check( 'stored old id falls back to the hardcoded template-1', 'template-1' === $resolved['template_id'] );

// The Installer no longer ships a template-storage migration: the old
// method is gone and nothing copies display_settings.template onto
// template_id.
check( 'legacy template migration method removed', ! method_exists( Installer::class, 'maybe_migrate_template_storage' ) );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 7. Settings REST (template keys)
// ---------------------------------------------------------------------------
echo "\n== 7. Settings REST ==\n";

$save = $settings_ctrl->save_args();

check( 'save schema carries template_defaults', isset( $save['template_defaults'] ) && 'object' === $save['template_defaults']['type'] );
check( 'template_defaults has a validate callback', is_callable( $save['template_defaults']['validate_callback'] ) );
check( 'save schema carries template_settings', isset( $save['template_settings'] ) && 'object' === $save['template_settings']['type'] );
check( 'template_settings has validate + sanitize callbacks', is_callable( $save['template_settings']['validate_callback'] ) && is_callable( $save['template_settings']['sanitize_callback'] ) );

check( 'valid template defaults accepted', true === $settings_ctrl->validate_template_defaults( array( 'goal' => 'template-1', 'campaign' => 'milestone_chain' ) ) );
check( 'empty defaults accepted', true === $settings_ctrl->validate_template_defaults( array( 'goal' => '', 'campaign' => '' ) ) );
check( 'unknown goal default rejected', false === $settings_ctrl->validate_template_defaults( array( 'goal' => 'countdown' ) ) );
check( 'goal template rejected as campaign default', false === $settings_ctrl->validate_template_defaults( array( 'campaign' => 'template-1' ) ) );

$sanitized = $settings_ctrl->sanitize_template_settings(
	array(
		'goal'     => array(
			'template-1'  => array( 'accent' => '#ff0000', 'radius' => 999, 'bogus_key' => 'x' ),
			'not_here'    => array( 'accent' => '#00ff00' ),
		),
		'campaign' => array(
			'milestone_chain' => array( 'dotColor' => '#123456', 'showLabels' => 1 ),
		),
		'bad_scope' => array(),
	)
);

check( 'sanitizer keeps valid goal templates', '#ff0000' === $sanitized['goal']['template-1']['accent'] );
check( 'sanitizer clamps against the schema', 5 === $sanitized['goal']['template-1']['radius'] );
check( 'sanitizer drops unknown keys', ! isset( $sanitized['goal']['template-1']['bogus_key'] ) );
check( 'sanitizer drops unregistered templates', ! isset( $sanitized['goal']['not_here'] ) );
check( 'sanitizer cleans the campaign scope', '#123456' === $sanitized['campaign']['milestone_chain']['dotColor'] && true === $sanitized['campaign']['milestone_chain']['showLabels'] );
check( 'sanitizer drops unknown scopes', ! isset( $sanitized['bad_scope'] ) );

// Sync: frontend_template ↔ template_defaults.goal.
$wpdb->query( 'START TRANSACTION' );

try {
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/settings' );
	$req->set_param( 'template_defaults', array( 'goal' => 'template-2', 'campaign' => 'milestone_chain' ) );
	$req->set_param( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
	$resp = $settings_ctrl->handle_save( $req );
	$data = $resp->get_data()['data'];

	check( 'template_defaults persisted', 'template-2' === $data['template_defaults']['goal'] && 'milestone_chain' === $data['template_defaults']['campaign'] );
	// frontend_template and template_defaults are deliberately NOT synced:
	// frontend_template and template_defaults.goal both accept the design
	// enum values, and back-syncing would silently overwrite the
	// Appearance page's selection — the TemplateEngine already handles the
	// correct fallback chain. frontend_template must NOT have been
	// overwritten with the template_defaults.goal value (it stays at
	// whatever the DB held before this save — definitely not 'template-2').
	check( 'frontend_template not synced from template_defaults', 'template-2' !== $data['frontend_template'] );
	check( 'template_versions recorded', isset( $data['template_versions']['goal']['template-2'] ) && 1 === $data['template_versions']['goal']['template-2'] );

	// The legacy picker no longer drives the scope default — the two are
	// independent settings (see the sync comment above).
	$req2 = new \WP_REST_Request( 'POST', '/faracart/v1/settings' );
	$req2->set_param( 'frontend_template', 'template-1' );
	$resp2 = $settings_ctrl->handle_save( $req2 );
	$data2 = $resp2->get_data()['data'];

	check( 'legacy picker persists on its own key', 'template-1' === $data2['frontend_template'] );
	check( 'template_defaults.goal not overwritten by legacy picker', 'template-2' === $data2['template_defaults']['goal'] );

	// template_settings are sanitized through the full save path.
	$req3 = new \WP_REST_Request( 'POST', '/faracart/v1/settings' );
	$req3->set_param(
		'template_settings',
		array(
			'goal'     => array( 'template-1' => array( 'accent' => 'bad', 'radius' => 999 ) ),
			'campaign' => array(),
		)
	);
	$resp3 = $settings_ctrl->handle_save( $req3 );
	$data3 = $resp3->get_data()['data'];

	check( 'invalid color falls back in the full save path', '#f97316' === $data3['template_settings']['goal']['template-1']['accent'] );
	check( 'radius clamped in the full save path', 5 === $data3['template_settings']['goal']['template-1']['radius'] );
} finally {
	$wpdb->query( 'ROLLBACK' );

	wp_cache_delete( Settings::OPTION_NAME, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 8. Admin TemplatesController payload
// ---------------------------------------------------------------------------
echo "\n== 8. TemplatesController payload ==\n";

$resp = $templates_ctrl->handle_index( new \WP_REST_Request( 'GET', '/faracart/v1/templates' ) );
$data = $resp->get_data()['data'];

check( 'payload lists the two scopes', array( 'goal', 'campaign' ) === $data['scopes'] );
check( 'goal default resolves', isset( $data['defaults']['goal'] ) && '' !== $data['defaults']['goal'] );
check( 'campaign default is empty by default', '' === $data['defaults']['campaign'] );
check( 'payload carries six goal definitions', 6 === count( $data['goal'] ) );

$gauge_definition = null;
foreach ( $data['goal'] as $definition ) {
	if ( 'template-3' === $definition['id'] ) {
		$gauge_definition = $definition;
		break;
	}
}
$gauge_keys = $gauge_definition ? array_map(
	function ( $field ) {
		return $field['key'];
	},
	$gauge_definition['schema']
) : array();
check( 'payload carries the gauge definition with its schema', null !== $gauge_definition && in_array( 'ringSize', $gauge_keys, true ) && in_array( 'strokeWidth', $gauge_keys, true ) );
check( 'payload carries both campaign definitions', 2 === count( $data['campaign'] ) && in_array( 'milestone_chain', array_column( $data['campaign'], 'id' ), true ) && in_array( 'campaign_progress', array_column( $data['campaign'], 'id' ), true ) );

$definition_shape_ok = true;
foreach ( array_merge( $data['goal'], $data['campaign'] ) as $definition ) {
	foreach ( array( 'id', 'label', 'description', 'version', 'scope', 'schema', 'settings' ) as $key ) {
		if ( ! array_key_exists( $key, $definition ) ) {
			$definition_shape_ok = false;
		}
	}
	if ( ! is_array( $definition['schema'] ) || count( $definition['schema'] ) !== count( $definition['settings'] ) ) {
		$definition_shape_ok = false;
	}
	foreach ( $definition['schema'] as $field ) {
		if ( ! isset( $field['key'], $field['type'], $field['label'], $field['default'] ) ) {
			$definition_shape_ok = false;
		}
	}
}
check( 'every definition carries the full contract', $definition_shape_ok );

check( 'settings include the shared accent token', isset( $data['goal'][0]['settings']['accent'] ) );
check( 'versions map per scope', isset( $data['versions']['goal']['template-1'], $data['versions']['campaign']['milestone_chain'] ) );

// ---------------------------------------------------------------------------
// 9. Frontend payload integration
// ---------------------------------------------------------------------------
echo "\n== 9. Frontend payload integration ==\n";

// shape_goal carries the resolved template + settings.
$goal = new \FaraCart\Goals\Goal( array(
	'id'               => 1,
	'name'             => 'Payload Goal',
	'status'           => 'active',
	'type'             => 'amount',
	'target'           => 100,
	'calculation_mode' => 'subtotal',
	'display_settings' => array( 'template_id' => 'template-4', 'template_settings' => array( 'radius' => 4 ) ),
) );

$ctx    = new \FaraCart\Goals\CartContext( array( 'subtotal' => 40, 'total' => 40, 'items' => array() ) );
$result = $container->get( \FaraCart\Goals\GoalEngine::class )->evaluate( $goal, $ctx );
$shaped = $frontend->shape_goal( $goal, $result, $ctx );

check( 'shape_goal carries the resolved template', 'template-4' === $shaped['template'] );
check( 'shape_goal carries the resolved settings', 4 === $shaped['template_settings']['radius'] );
check( 'shape_goal settings are schema-complete', count( $shaped['template_settings'] ) === count( $registry->get( 'template-4' )->schema() ) );

// /progress builds campaign template groups from the DB rows.
$campaigns_table = Schema::table( 'campaigns' );
$wpdb->query( 'START TRANSACTION' );

try {
	// Drop pre-existing goals inside the transaction (this dev database
	// ships with a leftover active goal) so the campaign group checks see
	// exactly the two seeded milestones; the rollback restores every
	// deleted row.
	$wpdb->query( "DELETE FROM {$goals_table}" );

	$wpdb->insert(
		$campaigns_table,
		array(
			'name'          => 'Template Chain Campaign',
			'description'   => '',
			'status'        => 'active',
			'priority'      => 10,
			'display_rules' => wp_json_encode( array( 'template_id' => 'milestone_chain', 'template_settings' => array( 'showLabels' => false ) ) ),
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		)
	);
	$campaign_id = (int) $wpdb->insert_id;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Chain Goal A',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'campaign_id'      => $campaign_id,
			'menu_order'       => 0,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Chain Goal B',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 200,
			'calculation_mode' => 'subtotal',
			'priority'         => 10,
			'campaign_id'      => $campaign_id,
			'menu_order'       => 1,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);

	$cart = WC()->cart;
	$cart->cart_contents['tpl1'] = array(
		'key'               => 'tpl1',
		'product_id'        => 0,
		'variation_id'      => 0,
		'quantity'          => 1,
		'data'              => new \WC_Product_Simple(),
		'line_subtotal'     => 150.0,
		'line_total'        => 150.0,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);

	$resp = $frontend->handle_progress( new \WP_REST_Request( 'GET', '/faracart/v1/progress' ), $cart );
	$payload = $resp->get_data()['data'];

	check( 'progress payload carries campaign groups', isset( $payload['campaigns'] ) );
	check( 'campaign group resolves the chain template', isset( $payload['campaigns'][0] ) && 'milestone_chain' === $payload['campaigns'][0]['template'] );
	check( 'campaign group carries the campaign name', 'Template Chain Campaign' === $payload['campaigns'][0]['name'] );
	check( 'campaign group settings applied', false === $payload['campaigns'][0]['settings']['showLabels'] );
	check( 'campaign goals carry the campaign id', 2 === count( $payload['goals'] ) && (int) $payload['goals'][0]['campaign_id'] === $campaign_id );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "TEMPLATE TEST FAILED\n" : "TEMPLATE TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
