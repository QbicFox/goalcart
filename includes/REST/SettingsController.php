<?php
/**
 * REST controller for plugin settings.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class SettingsController
 *
 * Phase 7 (REST API / AJAX Layer) settings endpoints:
 *
 *  - `GET  /goalcart/v1/settings` — the full settings array (merged with
 *    defaults).
 *  - `POST /goalcart/v1/settings` — saves a validated settings array.
 *    Every key is validated/sanitized through the REST arg schema, unknown
 *    keys are ignored, and the persisted values are returned so the UI can
 *    sync its state.
 *
 * The persisted option (Settings::OPTION_NAME) already drives the plugin
 * toggle and the admin display mode, so saving here changes behavior
 * immediately. The full settings surface (general, frontend, goal
 * calculation, performance, advanced) is designed in Phase 18; the schema
 * grows there without touching the route wiring.
 *
 * Mirrors the reference plugin (WooInsights\REST\SettingsController).
 */
class SettingsController extends BaseController {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register REST hooks.
	 *
	 * @param HookManager $hooks Hook manager.
	 * @return void
	 */
	public function register( HookManager $hooks ) {
		$hooks->add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the settings routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => $this->get_permission_callback(),
				'args'                => array(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_save' ),
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
	public function handle_get( $request ) {
		return $this->success( $this->settings->all() );
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
	public function handle_save( $request ) {
		$values = $request->get_params();

		$clean = array();

		foreach ( $this->settings->defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$clean[ $key ] = $this->sanitize_setting( $key, $values[ $key ] );
		}

		if ( empty( $clean ) ) {
			return $this->error(
				'goalcart_settings_empty',
				__( 'No settings were provided to save.', 'goalcart' ),
				400
			);
		}

		$this->settings->set_many( $clean );

		if ( ! $this->settings->save() ) {
			return $this->error(
				'goalcart_settings_save_failed',
				__( 'Could not save the settings. Please try again.', 'goalcart' ),
				500
			);
		}

		return $this->success( $this->settings->all() );
	}

	/**
	 * Argument schema for the save route.
	 *
	 * Each known setting is validated by the REST layer (types, ranges) so
	 * a bad request fails with a structured 400 before any option is
	 * touched.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function save_args() {
		return array(
			'enabled'             => array( 'type' => 'boolean' ),
			'fullscreen_dashboard' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * Sanitize a single setting value by key.
	 *
	 * Runs after the REST schema validation; normalizes the value before it
	 * is persisted.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value from the request.
	 * @return mixed
	 */
	protected function sanitize_setting( $key, $value ) {
		switch ( $key ) {
			case 'fullscreen_dashboard':
			case 'enabled':
				return (bool) $value;

			default:
				return $value;
		}
	}
}
