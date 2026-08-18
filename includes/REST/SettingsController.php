<?php

/**
 * REST controller for plugin settings.
 *
 * @package FaraCart
 */

namespace FaraCart\REST;

use FaraCart\Hooks\HookManager;
use FaraCart\Settings\Settings;
use FaraCart\Templates\TemplateEngine;
use FaraCart\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Class SettingsController
 *
 * Phase 7 (REST API / AJAX Layer) settings endpoints:
 *
 *  - `GET  /faracart/v1/settings` — the full settings array (merged with
 *    defaults).
 *  - `POST /faracart/v1/settings` — saves a validated settings array.
 *    Every key is validated/sanitized through the REST arg schema, unknown
 *    keys are ignored, and the persisted values are returned so the UI can
 *    sync its state.
 *	 * The persisted option (Settings::OPTION_NAME) drives every consumer
 * (storefront widgets, mission calculation, tracking, the admin display
 * mode), so saving here changes behavior immediately. Phase 18 adds the
 * full surface — general, frontend, mission calculation, performance,
 * advanced — to the schema and sanitizer, and the GET response carries
 * the developer-hooks reference in meta for the Advanced tab.
 *
 * Mirrors the reference plugin (WooInsights\REST\SettingsController).
 */
class SettingsController extends BaseController
{

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Template engine (pluggable templates): validates the per-scope
	 * template defaults and per-template settings on save. Null when not
	 * injected — resolved lazily from the plugin container so bare
	 * constructions (tests) keep working.
	 *
	 * @var TemplateEngine|null
	 */
	protected $templates;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings instance.
	 * @param TemplateEngine|null $templates Template engine (optional).
	 */
	public function __construct(Settings $settings, ?TemplateEngine $templates = null)
	{
		$this->settings = $settings;
		$this->templates = $templates;
	}

	/**
	 * The template engine, resolved lazily when not injected.
	 *
	 * @return TemplateEngine
	 */
	protected function templates()
	{
		if (null === $this->templates) {
			$this->templates = \FaraCart\Plugin::instance()->container()->get(TemplateEngine::class);
		}

		return $this->templates;
	}

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register(HookManager $hooks)
	{
		$hooks->add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Register the settings routes.
	 *
	 * @return void
	 */
	public function register_routes()
	{
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array($this, 'handle_get'),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'handle_save'),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => $this->save_args(),
			)
		);
	}

	/**
	 * Handle a settings read request.
	 *
	 * Settings reads are a single option lookup — no transient cache needed
	 * (the response is always current, which matters right after a save
	 * elsewhere).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_get($request)
	{
		$meta = array(
			// Phase 18 (Advanced → developer hooks): the reference list of
			// public faracart_* hooks, rendered by the Settings page.
			'hooks' => HookManager::documented_hooks(),
			// Phase 32 (customer-role conditions): the editable role list
			// for the mission builder's role picker.
			'roles' => $this->role_options(),
		);

		if ($this->settings->get('logging_enabled', false)) {
			$meta['log_path'] = Logger::path();
		}

		return $this->success($this->settings->all(), $meta);
	}

	/**
	 * Handle a settings save request.
	 *
	 * Accepts a partial or full settings object; only known keys are
	 * applied, so a form always saving the whole object cannot clobber keys
	 * it does not know about. Returns the persisted settings.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_save($request)
	{
		$values = $request->get_params();

		$clean = array();

		foreach ($this->settings->defaults() as $key => $default) {
			if (! array_key_exists($key, $values)) {
				continue;
			}

			$clean[$key] = $this->sanitize_setting($key, $values[$key]);
		}

		// Template-engine bookkeeping: saving per-template settings records
		// the current schema versions for future migrations.
		//
		// Note: the template_defaults.mission and frontend_template values are
		// deliberately NOT synced here — they are independent settings with
		// different validation scopes. frontend_template only accepts the
		// six design template ids (template-1 … template-6) via the REST
		// schema, while template_defaults.mission can hold any valid
		// pluggable-template id (e.g. milestone_chain). Back-syncing them
		// would either corrupt frontend_template with an out-of-enum value
		// (causing a 400 error on the next Settings-page save) or silently
		// overwrite the Appearance page's template selection. The
		// TemplateEngine already handles the correct fallback chain:
		// template_defaults.mission → frontend_template → template-1. Old
		// pre-design ids are never mapped.

		if (isset($clean['template_settings'])) {
			// Defense in depth: the REST arg schema runs the same sanitizer
			// on real dispatches; sanitizing here too keeps direct saves
			// schema-safe, so a bad template_settings payload can never be
			// persisted.
			$clean['template_settings'] = $this->sanitize_template_settings($clean['template_settings']);
			$clean['template_versions'] = $this->templates()->versions();
		}

		if (empty($clean)) {
			return $this->error(
				'faracart_settings_empty',
				__('No settings were provided to save.', 'faracart'),
				400
			);
		}

		$this->settings->set_many($clean);

		if (! $this->settings->save()) {
			return $this->error(
				'faracart_settings_save_failed',
				__('Could not save the settings. Please try again.', 'faracart'),
				500
			);
		}

		// Phase 18 (Advanced → logging): record the save when logging is
		// enabled, and fire the developer-hooks action (Phase 28 API) so
		// integrations can react to configuration changes.
		Logger::write('Settings saved: ' . wp_json_encode($clean), 'debug');

		/**
		 * Fires after plugin settings are persisted through the REST API.
		 *
		 * @param array<string, mixed> $clean     Sanitized key/value pairs.
		 * @param Settings             $settings  Settings service.
		 */
		do_action('faracart_settings_saved', $clean, $this->settings);

		return $this->success($this->settings->all());
	}

	/**
	 * Argument schema for the save route.
	 *
	 * Each known setting is validated by the REST layer (types, ranges) so
	 * a bad request fails with a structured 400 before any option is
	 * touched. Phase 12 adds the storefront progress-template surface
	 * (variant enum, ranges for height/radius, boolean animation).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function save_args()
	{
		$bool = array('type' => 'boolean');

		return array(
			// General (P18-T01).
			'enabled'               => $bool,
			'fullscreen_dashboard'  => $bool,
			// Display currency unit override ('' = follow the store currency).
			'currency'              => array('type' => 'string'),
			'currency_display'      => array('type' => 'string', 'enum' => array('symbol', 'code', 'name')),
			'default_mission_behavior' => array('type' => 'string', 'enum' => array('all', 'first', 'closest')),
			'conflict_resolution'   => array('type' => 'string', 'enum' => array('cumulative', 'best', 'first')),
			'calculation_mode'      => array(
				'type' => 'string',
				'enum' => array('subtotal', 'discounted_subtotal', 'total'),
			),

			// Frontend (P18-T02).
			'frontend_template'     => array(
				'type' => 'string',
				'enum' => Settings::MISSION_TEMPLATES,
			),
			'frontend_animation'    => $bool,
			'frontend_locations'    => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => Settings::DISPLAY_LOCATIONS,
				),
			),
			'frontend_position'     => array('type' => 'string', 'enum' => array('top', 'bottom')),
			'frontend_mobile'       => array('type' => 'string', 'enum' => array('show', 'hide')),
			'frontend_bar_height'   => array('type' => 'integer', 'minimum' => 4, 'maximum' => 48),
			'frontend_accent'       => array('type' => 'string'),
			'frontend_bg'           => array('type' => 'string'),
			'frontend_border'       => array('type' => 'string'),
			'frontend_text'         => array('type' => 'string'),
			'frontend_radius'       => array('type' => 'integer', 'minimum' => 0, 'maximum' => 40),
			'frontend_css_class'    => array('type' => 'string'),
			'frontend_custom_css'   => array('type' => 'string'),
			// Phase 32 (countdown + celebration).
			'frontend_countdown'    => $bool,
			'frontend_celebrate'    => $bool,

			// Floating widget (the floating missions/campaigns button + drawer).
			'floating_enabled'            => $bool,
			'floating_desktop'            => $this->floating_position_schema(),
			'floating_mobile'             => $this->floating_position_schema(),
			'floating_mobile_use_desktop' => $bool,
			'floating_show_desktop'       => $bool,
			'floating_show_mobile'        => $bool,
			'floating_button_size'        => array('type' => 'integer', 'minimum' => 32, 'maximum' => 96),
			'floating_animation'          => $bool,
			'floating_icon'               => array('type' => 'string'),
			'floating_label'              => array('type' => 'string'),

			// Mission Calculation (P18-T03).
			'calculation_include_tax'      => $bool,
			'calculation_include_discount' => $bool,
			'calculation_include_shipping' => $bool,
			'calculation_include_sale'     => $bool,
			'calculation_include_virtual'  => $bool,

			// Performance (P18-T04).
			'performance_caching'     => $bool,
			'analytics_enabled'       => $bool,
			'performance_suggestions' => $bool,
			// Phase 32 (advanced upsell ranking).
			'suggestions_ranking'     => array(
				'type' => 'string',
				'enum' => array('balanced', 'price', 'popularity'),
			),

			// Advanced (P18-T05).
			'debug_mode'      => $bool,
			'logging_enabled' => $bool,
			'developer_hooks' => $bool,

			// Template engine (pluggable progress templates).
			'template_defaults' => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'mission'     => array('type' => 'string'),
					'campaign' => array('type' => 'string'),
				),
				'additionalProperties' => false,
				'validate_callback'    => array($this, 'validate_template_defaults'),
			),
			'template_settings' => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
				'validate_callback'    => array($this, 'validate_template_settings'),
				'sanitize_callback'    => array($this, 'sanitize_template_settings'),
			),
		);
	}

	/**
	 * The REST arg schema for one floating-button position object.
	 *
	 * Shared by floating_desktop and floating_mobile so the two can never
	 * drift apart. The position preset is the only position control — it
	 * picks a physical side/edge that must keep its visual result in RTL,
	 * so it is never a logical start/end. The drawer always opens toward
	 * the screen center from the preset; there is no direction setting.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function floating_position_schema()
	{
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array(
				'preset'   => array(
					'type' => 'string',
					'enum' => Settings::FLOATING_PRESETS,
				),
				'offset_x' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 200),
				'offset_y' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 200),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Normalize a floating-button position object.
	 *
	 * Delegates to the single shared implementation on Settings: validates
	 * the preset enum, clamps the offsets, migrates pre-preset
	 * horizontal/vertical axes to the equivalent preset, and falls back to
	 * the documented default.
	 *
	 * @param mixed $value   Raw position value.
	 * @param mixed $default The setting's default position array.
	 * @return array<string, string|int>
	 */
	protected function sanitize_floating_position($value, $default)
	{
		return Settings::normalize_floating_position($value, $default);
	}

	/**
	 * Validate the per-scope template defaults against the registries.
	 *
	 * @param mixed $value Raw template_defaults value.
	 * @return bool
	 */
	public function validate_template_defaults($value)
	{
		if (! is_array($value)) {
			return false;
		}

		foreach (array('mission', 'campaign') as $scope) {
			if (! array_key_exists($scope, $value)) {
				continue;
			}

			$id = (string) $value[$scope];

			if ('' !== $id && ! $this->templates()->is_registered($scope, $id)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate the per-template settings payload (scopes must be known).
	 *
	 * @param mixed $value Raw template_settings value.
	 * @return bool
	 */
	public function validate_template_settings($value)
	{
		if (! is_array($value)) {
			return false;
		}		foreach ( array_keys( $value ) as $scope ) {
			if ( ! in_array( $scope, array( 'mission', 'campaign' ), true ) ) {
				return false;
			}

			$per_template = $value[ $scope ];

			if ( ! is_array( $per_template ) ) {
				return false;
			}

			foreach ( array_keys( $per_template ) as $template_id ) {
				if ( ! $this->templates()->is_registered( $scope, $template_id ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize the per-template settings against each template's schema.
	 *
	 * @param mixed $value Raw template_settings value.
	 * @return array<string, array<string, mixed>>
	 */
	public function sanitize_template_settings($value)
	{
		$clean = array(
			'mission'     => array(),
			'campaign' => array(),
		);

		if (! is_array($value)) {
			return $clean;
		}		foreach ( array( 'mission', 'campaign' ) as $scope ) {
			if ( ! isset( $value[ $scope ] ) || ! is_array( $value[ $scope ] ) ) {
				continue;
			}

			$clean[ $scope ] = $this->templates()->sanitize_scope_settings( $scope, $value[ $scope ] );
		}

		return $clean;
	}

	/**
	 * The editable role options (slug => translated name) for the builder.
	 *
	 * @return array<string, string>
	 */
	protected function role_options()
	{
		if (! function_exists('wp_roles')) {
			return array();
		}

		$roles = wp_roles()->get_names();
		$names = array();

		foreach ((array) $roles as $slug => $name) {
			$names[(string) $slug] = translate_user_role($name);
		}

		return $names;
	}

	/**
	 * Sanitize a single setting value by key.
	 *
	 * Runs after the REST schema validation; normalizes the value before it
	 * is persisted. Color keys fall back to their defaults on invalid
	 * input, template values normalize to the enum, and ranges are clamped
	 * (schema catches most of this on server dispatch; direct saves still
	 * land here).
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value from the request.
	 * @return mixed
	 */
	protected function sanitize_setting($key, $value)
	{
		$defaults = $this->settings->defaults();

		switch ($key) {
			case 'fullscreen_dashboard':
			case 'enabled':
			case 'frontend_animation':
			case 'frontend_countdown':
			case 'frontend_celebrate':
			case 'floating_enabled':
			case 'floating_mobile_use_desktop':
			case 'floating_show_desktop':
			case 'floating_show_mobile':
			case 'floating_animation':
			case 'calculation_include_tax':
			case 'calculation_include_discount':
			case 'calculation_include_shipping':
			case 'calculation_include_sale':
			case 'calculation_include_virtual':
			case 'performance_caching':
			case 'analytics_enabled':
			case 'performance_suggestions':
			case 'debug_mode':
			case 'logging_enabled':
			case 'developer_hooks':
				return (bool) $value;

			case 'currency':
				// An uppercase 3-letter ISO-4217 code, or '' to follow the
				// WooCommerce store currency. Anything else falls back to the
				// default (empty = store currency).
				$code = strtoupper(trim((string) $value));

				return '' !== $code && preg_match('/^[A-Z]{3}$/', $code) ? $code : $defaults['currency'];

			case 'currency_display':
				return in_array($value, array('symbol', 'code', 'name'), true) ? $value : $defaults['currency_display'];

			case 'default_mission_behavior':
				return in_array($value, array('all', 'first', 'closest'), true) ? $value : $defaults['default_mission_behavior'];

			case 'conflict_resolution':
				return in_array($value, array('cumulative', 'best', 'first'), true) ? $value : $defaults['conflict_resolution'];

			case 'calculation_mode':
				return in_array($value, array('subtotal', 'discounted_subtotal', 'total'), true) ? $value : $defaults['calculation_mode'];

			case 'frontend_position':
				return in_array($value, array('top', 'bottom'), true) ? $value : $defaults['frontend_position'];

			case 'frontend_mobile':
				return in_array($value, array('show', 'hide'), true) ? $value : $defaults['frontend_mobile'];

			case 'floating_desktop':
			case 'floating_mobile':
				return $this->sanitize_floating_position($value, $defaults[$key]);

			case 'floating_button_size':
				return min(96, max(32, (int) $value));

			case 'floating_icon':
				return trim(sanitize_text_field((string) $value));

			case 'floating_label':
				return trim(sanitize_text_field((string) $value));

			case 'suggestions_ranking':
				return in_array($value, array('balanced', 'price', 'popularity'), true) ? $value : $defaults['suggestions_ranking'];

			case 'frontend_locations':
				$cleaned  = array_filter(array_map('sanitize_key', (array) $value), function ($location) {
					return in_array($location, Settings::DISPLAY_LOCATIONS, true);
				});

				return array_values(array_unique($cleaned));

			case 'template_defaults':
				return $value;

			case 'frontend_template':
				return in_array($value, Settings::MISSION_TEMPLATES, true) ? $value : $defaults['frontend_template'];

			case 'frontend_bar_height':
				return min(48, max(4, (int) $value));

			case 'frontend_accent':
			case 'frontend_bg':
			case 'frontend_border':
			case 'frontend_text':
				$color = sanitize_hex_color($value);

				return $color ? $color : $defaults[$key];

			case 'frontend_radius':
				return min(40, max(0, (int) $value));

			case 'frontend_css_class':
				return trim(sanitize_text_field((string) $value));

			case 'frontend_custom_css':
				// Admin-authored CSS (manage_options gate on the route); keep
				// it tag-free and bounded.
				return substr(trim(wp_strip_all_tags((string) $value)), 0, 16000);

			default:
				return $value;
		}
	}
}
