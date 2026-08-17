<?php
/**
 * Settings management for FaraCart.
 *
 * @package FaraCart
 */

namespace FaraCart\Settings;

use FaraCart\Goals\Goal;
use FaraCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Loads, caches, and persists plugin settings in a single WordPress option.
 * Phase 18 (Settings) ships the full surface: general (currency display,
 * default goal behavior, default calculation basis), frontend (locations,
 * template, animation, mobile behavior), goal calculation (tax / discount /
 * shipping / sale / virtual inclusion), performance (caching, analytics,
 * suggestions) and advanced (debug mode, logging, custom CSS, developer
 * hooks). Every default below preserves the pre-Phase-18 behavior, so
 * existing installs upgrade with no visible change.
 *
 * Mirrors the reference plugin (WooInsights\Settings\Settings).
 */
class Settings {

	/**
	 * Option name holding the settings array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'faracart_settings';

	/**
	 * The storefront goal template ids the frontend_template setting
	 * accepts. Single source of truth shared by the REST schema
	 * (SettingsController::save_args), the sanitizer and the read-time
	 * self-heal, so the three can never drift apart. Only the six current
	 * design templates are valid; retired pre-design ids (basic /
	 * percentage / milestone / card / ring) are never mapped — stored
	 * values outside this list self-heal to the default.
	 *
	 * @var array<int, string>
	 */
	const GOAL_TEMPLATES = array( 'template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6' );

	/**
	 * Default settings, merged with stored values on load.
	 * * The frontend_* keys are the Phase 12 progress-template surface
 * (template variant + appearance tokens consumed by the storefront
 * widgets and the Appearance admin page). Phase 18 adds the general /
 * goal-calculation / performance / advanced sections without touching
 * these.
 *
 * @var array<string, mixed>
 */
	protected $defaults = array(
		// General (P18-T01).
		'enabled'               => true,
		'fullscreen_dashboard'  => true,
		// Display currency unit override: '' = follow the WooCommerce store
		// currency (get_woocommerce_currency). A 3-letter ISO code overrides
		// it for every FaraCart-rendered amount (admin dashboard, storefront
		// widgets, previews, server-rendered messages) — see currency().
		'currency'              => '',
		'currency_display'      => 'symbol',           // symbol | code | name
		'default_goal_behavior' => 'all',              // all | first | closest
		'conflict_resolution'   => 'cumulative',       // cumulative | best | first (Phase 26)
		'calculation_mode'      => 'subtotal',         // subtotal | discounted_subtotal | total

		// Frontend (P18-T02). Phase 32 adds the countdown + celebration
		// toggles and the advanced sticky-bar surface.
		'frontend_template'     => 'template-1',
		'frontend_animation'    => true,
		'frontend_locations'    => array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ),
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
		'frontend_countdown'    => true,               // Phase 32: live countdown chips on goals with an end time.
		'frontend_celebrate'    => true,               // Phase 32: completion celebration animation.

		// Phase 32 (advanced sticky bar).
		'sticky_position'       => 'bottom',           // bottom | top
		'sticky_behavior'       => 'dismissible',      // dismissible | auto_hide
		'sticky_delay'          => 0,                  // seconds before the bar appears (0 = immediately)
		'sticky_countdown'      => false,              // show the countdown chip in the bar
		'sticky_suggestions'    => false,              // show the top suggestion in the bar
		'sticky_display'        => 'compact',          // compact | full

		// Floating widget (the floating goals/campaigns button + drawer).
		// Each position object carries the physical side the admin chose
		// (left/right × top/center/bottom) plus pixel offsets. Desktop is
		// the default; mobile can reuse it (floating_mobile_use_desktop)
		// or pin its own so the button never clashes with mobile UI.
		'floating_enabled'            => false,
		'floating_desktop'            => array(
			'horizontal' => 'right',   // left | right
			'vertical'   => 'bottom',  // top | center | bottom
			'offset_x'   => 20,        // px from the chosen side
			'offset_y'   => 80,        // px from the chosen edge / center
		),
		'floating_mobile'             => array(
			'horizontal' => 'right',
			'vertical'   => 'bottom',
			'offset_x'   => 16,
			'offset_y'   => 100,
		),
		'floating_mobile_use_desktop' => true,          // mobile reuses the desktop position
		'floating_show_desktop'       => true,
		'floating_show_mobile'        => true,
		'floating_button_size'        => 56,            // px (diameter)
		'floating_animation'          => true,
		'floating_drawer_direction'   => 'auto',        // auto | left | right | up | down
		'floating_icon'               => '',            // custom glyph/emoji ('' = default)
		'floating_label'              => '',            // custom tooltip/label ('' = default)

		// Goal Calculation (P18-T03). Each default preserves the
		// pre-Phase-18 engine behavior: taxes stay out of the subtotal
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

		// Phase 32 (advanced upsell ranking): how the suggestion engine
		// ranks candidates — balanced | price | popularity.
		'suggestions_ranking'     => 'balanced',

		// Template engine (pluggable progress templates): the per-scope
		// default template ids, the per-template default appearance and the
		// per-template schema versions. Empty defaults fall back to the
		// legacy frontend_* surface above, so existing stores see no change
		// until they configure a template explicitly.
		'template_defaults' => array(
			'goal'     => '', // '' = legacy frontend_template.
			'campaign' => '', // '' = no campaign template (per-goal cards).
		),
		'template_settings' => array(
			'goal'     => array(),
			'campaign' => array(),
		),
		'template_versions' => array(
			'goal'     => array(),
			'campaign' => array(),
		),

		// Advanced (P18-T05).
		'debug_mode'      => false,
		'logging_enabled' => false,
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
 * Phase 18 wires the settings into behavior here: the store-wide default
 * money basis (calculation_mode) applies to any goal that does not pin
 * its own mode, through the faracart_default_calculation_mode filter
 * (Goal::default_calculation_mode). The remaining settings are read
 * directly by their consumers (ProgressUI, FrontendController,
 * CartIntegration, Tracker) through the same service instance.
 *
 * @param HookManager $hooks Hook manager.
 * @return void
 */
	public function register( HookManager $hooks ) {
		$hooks->add_filter(
			'faracart_default_calculation_mode',
			array( $this, 'apply_default_calculation_mode' ),
			10,
			2
		);
	}

	/**
	 * Resolve the store-wide default calculation basis.
	 *
	 * Applies the Phase 18 `calculation_mode` setting to money-style goal
	 * types (amount, category, composite) that do not pin their own mode;
	 * quantity/distinct-quantity/weight/product goals keep their type
	 * defaults (they measure items, not money).
	 *
	 * @param mixed  $mode Default mode from Goal::default_calculation_mode().
	 * @param string $type Goal type.
	 * @return string
	 */
	public function apply_default_calculation_mode( $mode, $type ) {
		if ( in_array(
			(string) $type,
			array( Goal::TYPE_QUANTITY, Goal::TYPE_DISTINCT_QUANTITY, Goal::TYPE_WEIGHT, Goal::TYPE_PRODUCT ),
			true
		) ) {
			return (string) $mode;
		}

		$configured = $this->get( 'calculation_mode', Goal::MODE_SUBTOTAL );

		if ( in_array( $configured, array( Goal::MODE_SUBTOTAL, Goal::MODE_DISCOUNTED_SUBTOTAL, Goal::MODE_TOTAL ), true ) ) {
			return $configured;
		}

		return (string) $mode;
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		if ( null === $this->settings ) {
			$stored = get_option( self::OPTION_NAME, array() );
			$this->settings = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults );

			// Self-heal a corrupted frontend_template. The setting only
			// accepts the six design template ids (template-1 … template-6)
			// via the REST schema; a stored value outside that enum (e.g. a
			// retired pre-design id such as 'card' or 'ring' back-synced by
			// an older version before the sync was removed) is served to the
			// Settings page and rejected on the next save with a 400.
			// Falling back to the default keeps every consumer schema-safe —
			// the TemplateEngine already resolves template_defaults.goal
			// before frontend_template, so the storefront template
			// selection is unaffected.
			if ( ! in_array( (string) $this->settings['frontend_template'], self::GOAL_TEMPLATES, true ) ) {
				$this->settings['frontend_template'] = $this->defaults['frontend_template'];
			}
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
	public function get( $key, $default = null ) {
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Set a single setting in memory.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return $this
	 */
	public function set( $key, $value ) {
		$this->all();
		$this->settings[ $key ] = $value;

		return $this;
	}

	/**
	 * Replace multiple settings in memory.
	 *
	 * @param array<string, mixed> $values Key/value pairs.
	 * @return $this
	 */
	public function set_many( array $values ) {
		$this->all();

		foreach ( $values as $key => $value ) {
			$this->settings[ $key ] = $value;
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
	public function save() {
		$all = $this->all();

		if ( $all === get_option( self::OPTION_NAME, null ) ) {
			return true;
		}

		return update_option( self::OPTION_NAME, $all, false );
	}

	/**
	 * Reset all settings to defaults (does not persist automatically).
	 *
	 * @return $this
	 */
	public function reset() {
		$this->settings = $this->defaults;

		return $this;
	}

	/**
	 * Get the default settings array.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults() {
		return $this->defaults;
	}

	/**
	 * Resolve the display currency unit.
	 *
	 * Single source of truth for the currency every FaraCart-rendered
	 * amount is labelled with (admin dashboard, storefront widgets,
	 * previews and server-rendered messages). An empty `currency` setting
	 * follows the WooCommerce store currency; a configured 3-letter ISO
	 * code overrides it. Filterable via `faracart_currency` so
	 * integrations can pin the display unit without touching the option.
	 *
	 * @return string Uppercase ISO-4217 code.
	 */
	public function currency() {
		$configured = strtoupper( trim( (string) $this->get( 'currency', '' ) ) );

		if ( '' !== $configured ) {
			$configured = (string) apply_filters( 'faracart_currency', $configured );

			return '' !== $configured ? $configured : 'USD';
		}

		$store = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';

		/**
		 * Filter the resolved display currency.
		 *
		 * @param string $currency Uppercase ISO-4217 code.
		 */
		return (string) apply_filters( 'faracart_currency', $store );
	}
}
