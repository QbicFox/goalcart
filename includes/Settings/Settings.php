<?php
/**
 * Settings management for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Settings;

use GoalCart\Goals\Goal;
use GoalCart\Hooks\HookManager;

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
	const OPTION_NAME = 'goalcart_settings';

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
		'currency_display'      => 'symbol',           // symbol | code | name
		'default_goal_behavior' => 'all',              // all | first | closest
		'calculation_mode'      => 'subtotal',         // subtotal | discounted_subtotal | total

		// Frontend (P18-T02).
		'frontend_template'     => 'basic',
		'frontend_animation'    => true,
		'frontend_locations'    => array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ),
		'frontend_mobile'       => 'show',             // show | hide
		'frontend_bar_height'   => 10,
		'frontend_accent'       => '#2271b1',
		'frontend_bg'           => '#ffffff',
		'frontend_border'       => '#dcdcde',
		'frontend_text'         => '#1d2327',
		'frontend_radius'       => 10,
		'frontend_css_class'    => '',
		'frontend_custom_css'   => '',

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
 * its own mode, through the goalcart_default_calculation_mode filter
 * (Goal::default_calculation_mode). The remaining settings are read
 * directly by their consumers (ProgressUI, FrontendController,
 * CartIntegration, Tracker) through the same service instance.
 *
 * @param HookManager $hooks Hook manager.
 * @return void
 */
	public function register( HookManager $hooks ) {
		$hooks->add_filter(
			'goalcart_default_calculation_mode',
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
}
