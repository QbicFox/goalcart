<?php
/**
 * Goal Cart pluggable template engine tests (Phase 12 → engine).
 *
 * Boots WordPress and exercises the template engine end to end:
 *
 *  - the registry contract: every built-in template implements Template,
 *    the four Goal templates (basic / percentage / milestone / card) plus
 *    the first Campaign template (milestone_chain) are registered, each
 *    with a stable id, label, description, scope, version and a settings
 *    schema whose defaults drive `default_settings()`
 *  - schema validation: sanitize_settings() clamps numbers, validates
 *    colors/enums/booleans, strips tags from CSS, drops unknown keys, and
 *    scope checks reject the wrong template in the wrong scope
 *  - resolution order: item override → scope default → legacy
 *    frontend_template → hardcoded 'basic' fallback, with the legacy
 *    `display_settings.template` alias read as the pre-engine storage
 *    shape, and a removed template id falling back to the scope default
 *    instead of failing
 *  - campaign resolution: campaign display_rules drive a campaign-scoped
 *    template; none configured means per-goal cards ('' template id)
 *  - extensibility: a custom template registers through the
 *    goalcart_template_classes filter and resolves through the engine
 *  - migration: Installer::maybe_migrate_template_storage() copies legacy
 *    display_settings.template onto template_id (+ empty
 *    template_settings), is idempotent, and never touches unknown values
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
require dirname( __DIR__ ) . '/goalcart.php';

use GoalCart\REST\FrontendController;
use GoalCart\REST\SettingsController;
use GoalCart\REST\TemplatesController;
use GoalCart\Settings\Settings;
use GoalCart\Templates\AbstractTemplate;
use GoalCart\Templates\Template;
use GoalCart\Templates\TemplateEngine;
use GoalCart\Templates\TemplateRegistry;
use GoalCart\Database\Installer;
use GoalCart\Database\Schema;

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
	return new \GoalCart\Goals\Goal( array(
		'id'               => 1,
		'name'             => 'Template Test Goal',
		'status'           => 'active',
		'type'             => 'amount',
		'target'           => 100,
		'calculation_mode' => 'subtotal',
		'display_settings' => $display,
	) );
}

$container = \GoalCart\Plugin::instance()->container();

$engine        = $container->get( TemplateEngine::class );
$registry      = $container->get( TemplateRegistry::class );
$settings      = $container->get( Settings::class );
$settings_ctrl = $container->get( SettingsController::class );
$templates_ctrl = $container->get( TemplatesController::class );
$frontend      = $container->get( FrontendController::class );

// Snapshot the in-memory settings so every section can restore them.
$all_before = $settings->all();

// Deterministic baseline: clear the per-scope defaults so resolution runs
// against the legacy surface only until a section sets them explicitly.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'basic' );

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

check( 'goal scope has the four built-ins', array( 'basic', 'card', 'milestone', 'percentage' ) === $goal_ids );

$campaign_ids = array_map(
	function ( $template ) {
		return $template->id();
	},
	$registry->for_scope( TemplateEngine::SCOPE_CAMPAIGN )
);

check( 'campaign scope has the milestone chain', array( 'milestone_chain' ) === $campaign_ids );
check( 'all() exposes five templates', 5 === count( $registry->all() ) );

foreach ( $registry->all() as $template ) {
	check( 'template implements the contract', $template instanceof Template );
	check( 'template id is a non-empty string', is_string( $template->id() ) && '' !== $template->id() );
	check( 'template has a label', is_string( $template->label() ) && '' !== $template->label() );
	check( 'template has a version', is_int( $template->version() ) && $template->version() >= 1 );
}

// Schema contract: every field declares type + default, and
// default_settings() derives from the schema.
$basic = $registry->get( 'basic' );
$schema = $basic->schema();

check( 'basic schema exposes the legacy surface', array_key_exists( 'accent', $schema ) && array_key_exists( 'barHeight', $schema ) && array_key_exists( 'animation', $schema ) && array_key_exists( 'customCss', $schema ) );

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

// ---------------------------------------------------------------------------
// 2. Schema validation (engine-level)
// ---------------------------------------------------------------------------
echo "\n== 2. Schema validation ==\n";

check( 'basic is registered for the goal scope', $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'basic' ) );
check( 'chain is registered for the campaign scope', $engine->is_registered( TemplateEngine::SCOPE_CAMPAIGN, 'milestone_chain' ) );
check( 'chain rejected for the goal scope', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'milestone_chain' ) );
check( 'unknown id rejected', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, 'countdown' ) );
check( 'empty id rejected', ! $engine->is_registered( TemplateEngine::SCOPE_GOAL, '' ) );
check( 'normalize rejects unknown ids', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'countdown' ) );
check( 'normalize keeps known ids', 'card' === $engine->normalize_template_id( TemplateEngine::SCOPE_GOAL, 'card' ) );

// Colors, numbers, booleans, enums, CSS and unknown keys.
$cleaned = $engine->sanitize_settings(
	$registry->get( 'basic' ),
	array(
		'accent'     => 'not-a-color',
		'bg'         => '#ff0000',
		'radius'     => 999,
		'barHeight'  => -5,
		'animation'  => 1,
		'showMessage' => 'yes',
		'cssClass'   => '  my-class  ',
		'customCss'  => '<script>alert(1)</script>.gc { color: red; }',
		'bogus_key'  => 'dropped',
	)
);

check( 'invalid color falls back to the schema default', '#2271b1' === $cleaned['accent'] );
check( 'valid color kept', '#ff0000' === $cleaned['bg'] );
check( 'radius clamped to max', 40 === $cleaned['radius'] );
check( 'barHeight clamped to min', 4 === $cleaned['barHeight'] );
check( 'bool normalized', true === $cleaned['animation'] && true === $cleaned['showMessage'] );
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

// ---------------------------------------------------------------------------
// 3. Goal resolution (override → scope default → legacy → fallback)
// ---------------------------------------------------------------------------
echo "\n== 3. Goal resolution ==\n";

// 3.1 Hardcoded fallback: nothing configured anywhere.
$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'basic' );

$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'fallback resolves to basic', 'basic' === $resolved['template_id'] );
check( 'fallback settings are complete', count( $resolved['settings'] ) === count( $schema ) );
check( 'fallback settings carry the schema defaults', '#2271b1' === $resolved['settings']['accent'] );

// 3.2 Legacy store-wide template.
$settings->set( 'frontend_template', 'card' );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'legacy frontend_template resolves', 'card' === $resolved['template_id'] );

// 3.3 Scope default beats the legacy surface.
$settings->set( 'template_defaults', array( 'goal' => 'milestone', 'campaign' => '' ) );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'scope default wins over legacy template', 'milestone' === $resolved['template_id'] );

// 3.4 Item override beats the scope default.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'percentage' ) ) );
check( 'item template_id wins', 'percentage' === $resolved['template_id'] );

// 3.5 Migration alias: pre-engine display_settings.template reads as the
// template id (lossless either way — the Installer also copies it).
$resolved = $engine->resolve_goal( goal_display( array( 'template' => 'card' ) ) );
check( 'legacy display_settings.template alias resolves', 'card' === $resolved['template_id'] );

// 3.6 Explicit template_id beats the legacy alias.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'basic', 'template' => 'card' ) ) );
check( 'template_id beats the legacy alias', 'basic' === $resolved['template_id'] );

// 3.7 Removed template: falls back to the scope default, never fails.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'countdown' ) ) );
check( 'removed template falls back to the scope default', 'milestone' === $resolved['template_id'] );

// 3.8 Legacy appearance tokens flow into unconfigured templates (shared
// schema keys only — the basic template exposes the legacy surface).
$settings->set( 'template_defaults', array( 'goal' => 'basic', 'campaign' => '' ) );
$settings->set( 'frontend_accent', '#ff0000' );
$settings->set( 'frontend_bg', '#f0f0f0' );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'legacy accent flows into an unconfigured template', '#ff0000' === $resolved['settings']['accent'] );
check( 'legacy bg flows into an unconfigured template', '#f0f0f0' === $resolved['settings']['bg'] );

// 3.9 Stored per-template appearance overrides the legacy tokens.
$settings->set(
	'template_settings',
	array(
		'goal'     => array( 'basic' => array( 'accent' => '#00aa00', 'barHeight' => 22 ) ),
		'campaign' => array(),
	)
);
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'stored template appearance wins over legacy tokens', '#00aa00' === $resolved['settings']['accent'] && 22 === $resolved['settings']['barHeight'] );

// 3.10 Per-goal override on top of the stored appearance.
$resolved = $engine->resolve_goal( goal_display( array( 'template_id' => 'basic', 'template_settings' => array( 'accent' => '#0000ff' ) ) ) );
check( 'per-goal settings override the stored appearance', '#0000ff' === $resolved['settings']['accent'] );

// 3.11 Template-specific schema keys stay out of the legacy mapping —
// the milestone template's dots never inherit the legacy accent.
$settings->set( 'template_defaults', array( 'goal' => 'milestone', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$resolved = $engine->resolve_goal( goal_display( array() ) );
check( 'milestone keeps its own dot defaults', '#dcdcde' === $resolved['settings']['dotColor'] && ! array_key_exists( 'accent', $resolved['settings'] ) );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 4. Campaign resolution
// ---------------------------------------------------------------------------
echo "\n== 4. Campaign resolution ==\n";

$settings->set( 'template_defaults', array( 'goal' => '', 'campaign' => '' ) );
$settings->set( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
$settings->set( 'frontend_template', 'basic' );

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

check( 'goal template rejected in campaign scope', '' === $engine->normalize_template_id( TemplateEngine::SCOPE_CAMPAIGN, 'card' ) );

$settings->set_many( $all_before );

// ---------------------------------------------------------------------------
// 5. Extensibility: a custom template via goalcart_template_classes
// ---------------------------------------------------------------------------
echo "\n== 5. Extensibility (custom template) ==\n";

class GoalCart_Test_Template extends AbstractTemplate {
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
	'goalcart_template_classes',
	function ( $classes ) {
		$classes['test_custom'] = 'GoalCart_Test_Template';

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

remove_all_filters( 'goalcart_template_classes' );

// ---------------------------------------------------------------------------
// 6. Migration (Installer::maybe_migrate_template_storage)
// ---------------------------------------------------------------------------
echo "\n== 6. Migration ==\n";

$wpdb = $GLOBALS['wpdb'];
$goals_table = Schema::table( 'goals' );
$wpdb->query( 'START TRANSACTION' );

try {
	$legacy_id = 0;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Legacy template goal',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'display_settings' => wp_json_encode( array( 'template' => 'card' ) ),
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$legacy_id = (int) $wpdb->insert_id;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Bogus template goal',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'display_settings' => wp_json_encode( array( 'template' => 'bogus' ) ),
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$bogus_id = (int) $wpdb->insert_id;

	$wpdb->insert(
		$goals_table,
		array(
			'name'             => 'Already migrated goal',
			'description'      => '',
			'status'           => 'active',
			'type'             => 'amount',
			'target'           => 100,
			'calculation_mode' => 'subtotal',
			'display_settings' => wp_json_encode( array( 'template' => 'basic', 'template_id' => 'percentage', 'template_settings' => array() ) ),
			'priority'         => 10,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		)
	);
	$migrated_id = (int) $wpdb->insert_id;

	$ref = new ReflectionMethod( Installer::class, 'maybe_migrate_template_storage' );
	$ref->setAccessible( true );
	$ref->invoke( null );

	$legacy_row = $wpdb->get_row( $wpdb->prepare( "SELECT display_settings FROM {$goals_table} WHERE id = %d", $legacy_id ), ARRAY_A );
	$legacy_decoded = json_decode( $legacy_row['display_settings'], true );

	check( 'legacy template copied to template_id', 'card' === $legacy_decoded['template_id'] );
	check( 'legacy template kept as alias', 'card' === $legacy_decoded['template'] );
	check( 'empty template_settings added', array() === $legacy_decoded['template_settings'] );

	$bogus_row = $wpdb->get_row( $wpdb->prepare( "SELECT display_settings FROM {$goals_table} WHERE id = %d", $bogus_id ), ARRAY_A );
	$bogus_decoded = json_decode( $bogus_row['display_settings'], true );
	check( 'unknown legacy value left untouched', ! isset( $bogus_decoded['template_id'] ) );

	$migrated_row = $wpdb->get_row( $wpdb->prepare( "SELECT display_settings FROM {$goals_table} WHERE id = %d", $migrated_id ), ARRAY_A );
	$migrated_decoded = json_decode( $migrated_row['display_settings'], true );
	check( 'already-migrated row untouched', 'percentage' === $migrated_decoded['template_id'] );

	// Re-run: idempotent (rows already carry template_id).
	$ref->invoke( null );
	$again = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT display_settings FROM {$goals_table} WHERE id = %d", $legacy_id ) ), true );
	check( 're-run is a no-op', 'card' === $again['template_id'] && array() === $again['template_settings'] );

	// The migration is lossless at the engine level too: the legacy alias
	// still resolves before the row is ever rewritten.
	$resolved = $engine->resolve_goal( goal_display( array( 'template' => 'card' ) ) );
	check( 'legacy storage shape still resolves pre-migration', 'card' === $resolved['template_id'] );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

check( 'migration row residue rolled back', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE name = %s", 'Legacy template goal' ) ) );

// ---------------------------------------------------------------------------
// 7. Settings REST (template keys)
// ---------------------------------------------------------------------------
echo "\n== 7. Settings REST ==\n";

$save = $settings_ctrl->save_args();

check( 'save schema carries template_defaults', isset( $save['template_defaults'] ) && 'object' === $save['template_defaults']['type'] );
check( 'template_defaults has a validate callback', is_callable( $save['template_defaults']['validate_callback'] ) );
check( 'save schema carries template_settings', isset( $save['template_settings'] ) && 'object' === $save['template_settings']['type'] );
check( 'template_settings has validate + sanitize callbacks', is_callable( $save['template_settings']['validate_callback'] ) && is_callable( $save['template_settings']['sanitize_callback'] ) );

check( 'valid template defaults accepted', true === $settings_ctrl->validate_template_defaults( array( 'goal' => 'milestone', 'campaign' => 'milestone_chain' ) ) );
check( 'empty defaults accepted', true === $settings_ctrl->validate_template_defaults( array( 'goal' => '', 'campaign' => '' ) ) );
check( 'unknown goal default rejected', false === $settings_ctrl->validate_template_defaults( array( 'goal' => 'countdown' ) ) );
check( 'goal template rejected as campaign default', false === $settings_ctrl->validate_template_defaults( array( 'campaign' => 'card' ) ) );

$sanitized = $settings_ctrl->sanitize_template_settings(
	array(
		'goal'     => array(
			'basic'       => array( 'accent' => '#ff0000', 'radius' => 999, 'bogus_key' => 'x' ),
			'not_here'    => array( 'accent' => '#00ff00' ),
		),
		'campaign' => array(
			'milestone_chain' => array( 'dotColor' => '#123456', 'showLabels' => 1 ),
		),
		'bad_scope' => array(),
	)
);

check( 'sanitizer keeps valid goal templates', '#ff0000' === $sanitized['goal']['basic']['accent'] );
check( 'sanitizer clamps against the schema', 40 === $sanitized['goal']['basic']['radius'] );
check( 'sanitizer drops unknown keys', ! isset( $sanitized['goal']['basic']['bogus_key'] ) );
check( 'sanitizer drops unregistered templates', ! isset( $sanitized['goal']['not_here'] ) );
check( 'sanitizer cleans the campaign scope', '#123456' === $sanitized['campaign']['milestone_chain']['dotColor'] && true === $sanitized['campaign']['milestone_chain']['showLabels'] );
check( 'sanitizer drops unknown scopes', ! isset( $sanitized['bad_scope'] ) );

// Sync: frontend_template ↔ template_defaults.goal.
$wpdb->query( 'START TRANSACTION' );

try {
	$req = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req->set_param( 'template_defaults', array( 'goal' => 'milestone', 'campaign' => 'milestone_chain' ) );
	$req->set_param( 'template_settings', array( 'goal' => array(), 'campaign' => array() ) );
	$resp = $settings_ctrl->handle_save( $req );
	$data = $resp->get_data()['data'];

	check( 'template_defaults persisted', 'milestone' === $data['template_defaults']['goal'] && 'milestone_chain' === $data['template_defaults']['campaign'] );
	check( 'frontend_template synced to the goal default', 'milestone' === $data['frontend_template'] );
	check( 'template_versions recorded', isset( $data['template_versions']['goal']['milestone'] ) && 1 === $data['template_versions']['goal']['milestone'] );

	// The reverse direction: the legacy picker drives the scope default.
	$req2 = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req2->set_param( 'frontend_template', 'card' );
	$resp2 = $settings_ctrl->handle_save( $req2 );
	$data2 = $resp2->get_data()['data'];

	check( 'legacy picker syncs the goal default', 'card' === $data2['template_defaults']['goal'] );

	// template_settings are sanitized through the full save path.
	$req3 = new \WP_REST_Request( 'POST', '/goalcart/v1/settings' );
	$req3->set_param(
		'template_settings',
		array(
			'goal'     => array( 'basic' => array( 'accent' => 'bad', 'radius' => 999 ) ),
			'campaign' => array(),
		)
	);
	$resp3 = $settings_ctrl->handle_save( $req3 );
	$data3 = $resp3->get_data()['data'];

	check( 'invalid color falls back in the full save path', '#2271b1' === $data3['template_settings']['goal']['basic']['accent'] );
	check( 'radius clamped in the full save path', 40 === $data3['template_settings']['goal']['basic']['radius'] );
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

$resp = $templates_ctrl->handle_index( new \WP_REST_Request( 'GET', '/goalcart/v1/templates' ) );
$data = $resp->get_data()['data'];

check( 'payload lists the two scopes', array( 'goal', 'campaign' ) === $data['scopes'] );
check( 'goal default resolves', isset( $data['defaults']['goal'] ) && '' !== $data['defaults']['goal'] );
check( 'campaign default is empty by default', '' === $data['defaults']['campaign'] );
check( 'payload carries four goal definitions', 4 === count( $data['goal'] ) );
check( 'payload carries the chain definition', 1 === count( $data['campaign'] ) && 'milestone_chain' === $data['campaign'][0]['id'] );

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

check( 'settings include the legacy fallback tokens', isset( $data['goal'][0]['settings']['accent'] ) );
check( 'versions map per scope', isset( $data['versions']['goal']['basic'], $data['versions']['campaign']['milestone_chain'] ) );

// ---------------------------------------------------------------------------
// 9. Frontend payload integration
// ---------------------------------------------------------------------------
echo "\n== 9. Frontend payload integration ==\n";

// shape_goal carries the resolved template + settings.
$goal = new \GoalCart\Goals\Goal( array(
	'id'               => 1,
	'name'             => 'Payload Goal',
	'status'           => 'active',
	'type'             => 'amount',
	'target'           => 100,
	'calculation_mode' => 'subtotal',
	'display_settings' => array( 'template_id' => 'card', 'template_settings' => array( 'radius' => 20 ) ),
) );

$ctx    = new \GoalCart\Goals\CartContext( array( 'subtotal' => 40, 'total' => 40, 'items' => array() ) );
$result = $container->get( \GoalCart\Goals\GoalEngine::class )->evaluate( $goal, $ctx );
$shaped = $frontend->shape_goal( $goal, $result, $ctx );

check( 'shape_goal carries the resolved template', 'card' === $shaped['template'] );
check( 'shape_goal carries the resolved settings', 20 === $shaped['template_settings']['radius'] );
check( 'shape_goal settings are schema-complete', count( $shaped['template_settings'] ) === count( $registry->get( 'card' )->schema() ) );

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

	$resp = $frontend->handle_progress( new \WP_REST_Request( 'GET', '/goalcart/v1/progress' ), $cart );
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
