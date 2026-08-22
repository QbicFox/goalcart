<?php

/**
 * Settings management for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Settings;

use FaraCart\Missions\Mission;
use FaraCart\Hooks\HookManager;
use FaraCart\Utils\Currency;

defined('ABSPATH') || exit;

/**
 * Class Settings
 *
 * Loads, caches, and persists plugin settings in a single WordPress option.
 * Settings ships the full surface: general (mission behavior, default
 * calculation basis), frontend (locations,
 * template, animation, mobile behavior), mission calculation (tax / discount /
 * shipping / sale / virtual inclusion), performance (caching, analytics,
 * suggestions) and advanced (debug mode, logging, custom CSS, developer
 * hooks). Every default below preserves the previous behavior, so
 * existing installs upgrade with no visible change.
 *
 * Mirrors the reference plugin (WooInsights\Settings\Settings).
 */
class Settings
{

	/**
	 * Option name holding the settings array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'faracart_settings';

	/**
	 * The storefront mission template ids the frontend_template setting
	 * accepts. Single source of truth shared by the REST schema
	 * (SettingsController::save_args) and the save sanitizer. Only the six
	 * current design templates are valid; retired pre-design ids (basic /
	 * percentage / milestone / card / ring) are never mapped and consumers
	 * (TemplateEngine, ProgressUI) fall back to template-1 for anything
	 * unregistered.
	 *
	 * @var array<int, string>
	 */
	const MISSION_TEMPLATES = array('template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6');

	/**
	 * The storefront widget locations the frontend_locations setting
	 * accepts. Single source of truth shared by the REST schema / sanitizer
	 * (SettingsController).
	 *
	 * @var array<int, string>
	 */
	const DISPLAY_LOCATIONS = array('cart', 'mini-cart', 'checkout', 'shop', 'product');

	/**
	 * The floating-button position presets the floating_desktop /
	 * floating_mobile settings accept. Single source of truth shared by
	 * the read-time normalization (Settings::all), the REST schema and
	 * the save sanitizer, so the three can never drift apart.
	 *
	 * @var array<int, string>
	 */
	const FLOATING_PRESETS = array('top-left', 'top-right', 'center-left', 'center-right', 'bottom-left', 'bottom-right');

	/**
	 * Default settings, merged with stored values on load.
	 * * The frontend_* keys are the progress-template surface
	 * (template variant + appearance tokens consumed by the storefront
	 * widgets and the Appearance admin page). adds the general /
	 * mission-calculation / performance / advanced sections without touching
	 * these.
	 *
	 * @var array<string, mixed>
	 */
	protected $defaults = array(
		// General (P18-T01).
		'enabled'               => true,
		'fullscreen_dashboard'  => true,
		// Currency is NOT a FaraCart setting: every FaraCart-rendered
		// amount follows WooCommerce's own currency configuration (unit,
		// symbol, position, separators, decimals) via Utils\Currency.
		'default_mission_behavior' => 'all',              // all | first | closest
		'conflict_resolution'   => 'cumulative',       // cumulative | best | first 
		'calculation_mode'      => 'subtotal',         // subtotal | discounted_subtotal | total

		// Frontend (P18-T02). adds the countdown + celebration toggles.
		'frontend_template'     => 'template-1',
		'frontend_animation'    => true,
		'frontend_locations'    => array('cart', 'mini-cart', 'checkout', 'shop', 'product'),
		'frontend_position'     => 'top',               // top | bottom for page widgets
		'frontend_mobile'       => 'show',             // show | hide
		'frontend_bar_height'   => 10,
		'frontend_accent'       => '#2271b1',
		'frontend_bg'           => '#ffffff',
		'frontend_border'       => '#dcdcde',
		'frontend_text'         => '#1d2327',
		'frontend_radius'       => 10,
		'frontend_css_class'    => '',
		'frontend_custom_css'   => '',
		'frontend_countdown'    => true,               // live countdown chips on missions with an end time.
		'frontend_celebrate'    => true,               // completion celebration animation.

		// Floating widget (the floating missions/campaigns button + drawer).
		// The position preset is the only position control — it picks a
		// physical side/edge (RTL stable) and the drawer always opens
		// toward the screen center from it; pixel offsets fine-tune the
		// spot. Desktop is the default; mobile can reuse it
		// (floating_mobile_use_desktop) or pin its own so the button never
		// clashes with mobile UI.
		'floating_enabled'            => false,
		'floating_desktop'            => array(
			'preset'   => 'bottom-right',  // top/center/bottom × left/right
			'offset_x' => 20,              // px from the chosen side
			'offset_y' => 80,              // px from the chosen edge / center
		),
		'floating_mobile'             => array(
			'preset'   => 'bottom-right',
			'offset_x' => 16,
			'offset_y' => 100,
		),
		'floating_mobile_use_desktop' => true,          // mobile reuses the desktop position
		'floating_show_desktop'       => true,
		'floating_show_mobile'        => true,
		'floating_button_size'        => 56,            // px (diameter)
		'floating_animation'          => true,
		'floating_icon'               => '',            // custom glyph/emoji ('' = default)
		'floating_label'              => '',            // custom tooltip/label ('' = default)

		// Mission Calculation (P18-T03). Each default preserves the
		// previous engine behavior: taxes stay out of the subtotal
		// bases, discounts count, shipping stays in the total basis, and
		// sale / virtual items always count.
		'calculation_include_tax'      => false,
		'calculation_include_discount' => true,
		'calculation_include_shipping' => true,
		'calculation_include_sale'     => true,
		'calculation_include_virtual'  => true,

		// Performance (P18-T04).
		'performance_caching'     => false,
		'analytics_enabled'       => true,
		'performance_suggestions' => true,

		// advanced upsell ranking: how the suggestion engine
		// ranks candidates — balanced | price | popularity.
		'suggestions_ranking'     => 'balanced',

		// Template engine (pluggable progress templates): the per-scope
		// default template ids, the per-template default appearance and the
		// per-template schema versions. Empty defaults fall back to the
		// legacy frontend_* surface above, so existing stores see no change
		// until they configure a template explicitly.
		'template_defaults' => array(
			'mission'     => '', // '' = legacy frontend_template.
			'campaign' => '', // '' = no campaign template (per-mission cards).
		),
		'template_settings' => array(
			'mission'     => array(),
			'campaign' => array(),
		),
		'template_versions' => array(
			'mission'     => array(),
			'campaign' => array(),
		),

		// Advanced (P18-T05). Debug mode / logging are developer features
		// now — controlled by the FARACART_DEBUG / FARACART_LOGGING constants
		// or the faracart_debug_mode / faracart_logging_enabled filters (see
		// Utils\Logger), not by a settings option.
		'developer_hooks' => true,
	);

	/**
	 * Cached settings array.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $settings;

	/** * Register settings hooks.
	 *
	 * wires the settings into behavior here: the store-wide default
	 * money basis (calculation_mode) applies to any mission that does not pin
	 * its own mode, through the faracart_default_calculation_mode filter
	 * (Mission::default_calculation_mode). The remaining settings are read
	 * directly by their consumers (ProgressUI, FrontendController,
	 * CartIntegration, Tracker) through the same service instance.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register(HookManager $hooks)
	{
		$hooks->add_filter(
			'faracart_default_calculation_mode',
			array($this, 'apply_default_calculation_mode'),
			10,
			2
		);
	}

	/**
	 * Resolve the store-wide default calculation basis.
	 *
	 * Applies the `calculation_mode` setting to money-style mission
	 * types (amount, category, composite) that do not pin their own mode;
	 * quantity/distinct-quantity/weight/product missions keep their type
	 * defaults (they measure items, not money).
	 *
	 * @param mixed  $mode Default mode from Mission::default_calculation_mode().
	 * @param string $type Mission type.
	 * @return string
	 */
	public function apply_default_calculation_mode($mode, $type)
	{
		if (in_array(
			(string) $type,
			array(Mission::TYPE_QUANTITY, Mission::TYPE_DISTINCT_QUANTITY, Mission::TYPE_WEIGHT, Mission::TYPE_PRODUCT),
			true
		)) {
			return (string) $mode;
		}

		$configured = $this->get('calculation_mode', Mission::MODE_SUBTOTAL);

		if (in_array($configured, array(Mission::MODE_SUBTOTAL, Mission::MODE_DISCOUNTED_SUBTOTAL, Mission::MODE_TOTAL), true)) {
			return $configured;
		}

		return (string) $mode;
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * Only keys that exist in the defaults are returned — a stored option
	 * that carries legacy/unknown keys (e.g. debug_mode / logging_enabled
	 * from before they became developer features) is filtered, so the API
	 * surface never advertises settings the UI no longer has.
	 *
	 * @return array<string, mixed>
	 */
	public function all()
	{
		if (null === $this->settings) {
			$stored = get_option(self::OPTION_NAME, array());
			$stored = is_array($stored) ? array_intersect_key($stored, $this->defaults) : array();
			$this->settings = wp_parse_args($stored, $this->defaults);
		}

		return $this->settings;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value when the key is missing.
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$settings = $this->all();

		return array_key_exists($key, $settings) ? $settings[$key] : $default;
	}

	/**
	 * Set a single setting in memory.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return $this
	 */
	public function set($key, $value)
	{
		$this->all();

		if (array_key_exists($key, $this->defaults)) {
			$this->settings[$key] = $value;
		}

		return $this;
	}

	/**
	 * Replace multiple settings in memory.
	 *
	 * @param array<string, mixed> $values Key/value pairs.
	 * @return $this
	 */
	public function set_many(array $values)
	{
		$this->all();

		foreach ($values as $key => $value) {
			if (array_key_exists($key, $this->defaults)) {
				$this->settings[$key] = $value;
			}
		}

		return $this;
	}

	/**
	 * Persist the current settings to the database.
	 *
	 * `update_option()` returns `false` both for real failures *and* for
	 * no-op writes (when the new value equals the stored one). Treating the
	 * no-op as a failure would make saving unchanged settings 500, so a
	 * byte-identical option counts as a successful save.
	 *
	 * @return bool
	 */
	public function save()
	{
		$all = $this->all();

		if ($all === get_option(self::OPTION_NAME, null)) {
			return true;
		}

		return update_option(self::OPTION_NAME, $all, false);
	}

	/**
	 * Reset all settings to defaults (does not persist automatically).
	 *
	 * @return $this
	 */
	public function reset()
	{
		$this->settings = $this->defaults;

		return $this;
	}

	/**
	 * Get the default settings array.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults()
	{
		return $this->defaults;
	}

	/**
	 * Normalize a floating-button position object.
	 *
	 * Validates the preset enum and clamps the offsets so a malformed
	 * submitted value can never reach the admin form or the storefront;
	 * anything unknown falls back to the documented default. Pre-preset
	 * horizontal × vertical values (older stored settings, or legacy
	 * clients) are migrated to the equivalent preset so they keep their
	 * visual result — the preset is the authoritative position control.
	 *
	 * Shared by the REST save sanitizer (SettingsController::
	 * sanitize_floating_position) so the normalization logic lives in
	 * exactly one place.
	 *
	 * @param mixed $value   Raw position value.
	 * @param mixed $default The setting's default position array.
	 * @return array<string, string|int>
	 */
	public static function normalize_floating_position($value, $default)
	{
		$default = is_array($default) ? $default : array(
			'preset'   => 'bottom-right',
			'offset_x' => 20,
			'offset_y' => 80,
		);

		if (! is_array($value)) {
			return $default;
		}

		if (isset($value['preset']) && in_array($value['preset'], self::FLOATING_PRESETS, true)) {
			$preset = $value['preset'];
		} elseif (isset($value['horizontal']) && isset($value['vertical'])) {
			// Legacy migration: horizontal × vertical → the matching preset.
			$preset = self::preset_from_axes($value['horizontal'], $value['vertical'], $default['preset']);
		} else {
			$preset = $default['preset'];
		}

		return array(
			'preset'   => $preset,
			'offset_x' => isset($value['offset_x']) ? min(200, max(0, (int) $value['offset_x'])) : (int) $default['offset_x'],
			'offset_y' => isset($value['offset_y']) ? min(200, max(0, (int) $value['offset_y'])) : (int) $default['offset_y'],
		);
	}

	/**
	 * Map a legacy horizontal × vertical axes pair to a position preset.
	 *
	 * @param mixed  $horizontal 'left' | 'right' (or anything else).
	 * @param mixed  $vertical   'top' | 'center' | 'bottom' (or anything else).
	 * @param string $fallback   The preset to return for unknown axes.
	 * @return string
	 */
	protected static function preset_from_axes($horizontal, $vertical, $fallback)
	{
		$presets = array(
			'left_top'     => 'top-left',
			'right_top'    => 'top-right',
			'left_center'  => 'center-left',
			'right_center' => 'center-right',
			'left_bottom'  => 'bottom-left',
			'right_bottom' => 'bottom-right',
		);

		$key = sanitize_key($horizontal) . '_' . sanitize_key($vertical);

		return isset($presets[$key]) ? $presets[$key] : $fallback;
	}

	/**
	 * Resolve the display currency unit.
	 *
	 * Backward-compatible accessor that now delegates to WooCommerce's
	 * own currency configuration (Utils\Currency::code()), the single
	 * source of truth. Kept so existing consumers (boot data, frontend
	 * config, payloads) need no signature change.
	 *
	 * @return string Uppercase ISO-4217 code.
	 */
	public function currency()
	{
		return Currency::code();
	}
}
