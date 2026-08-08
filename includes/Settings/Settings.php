<?php
/**
 * Settings management for Goal Cart.
 *
 * @package GoalCart
 */

namespace GoalCart\Settings;

use GoalCart\Hooks\HookManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 *
 * Loads, caches, and persists plugin settings in a single WordPress option.
 * The full settings surface (general, frontend, goal calculation,
 * performance, advanced) is designed in Phase 18; the foundation ships
 * the master toggle and the admin display-mode flag the shell needs.
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
	 *
	 * The frontend_* keys are the Phase 12 progress-template surface
	 * (template variant + appearance tokens consumed by the storefront
	 * widgets and the Appearance admin page). Phase 18 grows the general /
	 * goal-calculation / performance / advanced sections without touching
	 * these.
	 *
	 * @var array<string, mixed>
	 */
	protected $defaults = array(
		'enabled'               => true,
		'fullscreen_dashboard'  => true,
		'frontend_template'     => 'basic',
		'frontend_animation'    => true,
		'frontend_bar_height'   => 10,
		'frontend_accent'       => '#2271b1',
		'frontend_bg'           => '#ffffff',
		'frontend_border'       => '#dcdcde',
		'frontend_text'         => '#1d2327',
		'frontend_radius'       => 10,
		'frontend_css_class'    => '',
		'frontend_custom_css'   => '',
	);

	/**
	 * Cached settings array.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $settings;

	/**
	 * Register settings hooks.
	 *
	 * Settings are served over REST in Phase 7/18; this class itself
	 * registers no hooks — it is kept as a plain service so the Admin,
	 * Tracker and other components read the same option.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		// No hooks to register.
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
	 * @return bool
	 */
	public function save() {
		$saved = update_option( self::OPTION_NAME, $this->all(), false );

		return $saved;
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
