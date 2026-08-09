<?php
/**
 * REST controller for plugin settings.
 *
 * @package GoalCart
 */

namespace GoalCart\REST;

use GoalCart\Hooks\HookManager;
use GoalCart\Settings\Settings;
use GoalCart\Utils\Logger;

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
 *	 * The persisted option (Settings::OPTION_NAME) drives every consumer
	 * (storefront widgets, goal calculation, tracking, the admin display
	 * mode), so saving here changes behavior immediately. Phase 18 adds the
	 * full surface — general, frontend, goal calculation, performance,
	 * advanced — to the schema and sanitizer, and the GET response carries
	 * the developer-hooks reference in meta for the Advanced tab.
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
		$meta = array(
			// Phase 18 (Advanced → developer hooks): the reference list of
			// public goalcart_* hooks, rendered by the Settings page.
			'hooks' => HookManager::documented_hooks(),
		);

		if ( $this->settings->get( 'logging_enabled', false ) ) {
			$meta['log_path'] = Logger::path();
		}

		return $this->success( $this->settings->all(), $meta );
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

		// Phase 18 (Advanced → logging): record the save when logging is
		// enabled, and fire the developer-hooks action (Phase 28 API) so
		// integrations can react to configuration changes.
		Logger::write( 'Settings saved: ' . wp_json_encode( $clean ), 'debug' );

		/**
		 * Fires after plugin settings are persisted through the REST API.
		 *
		 * @param array<string, mixed> $clean     Sanitized key/value pairs.
		 * @param Settings             $settings  Settings service.
		 */
		do_action( 'goalcart_settings_saved', $clean, $this->settings );

		return $this->success( $this->settings->all() );
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
	public function save_args() {
		$bool = array( 'type' => 'boolean' );

		return array(
			// General (P18-T01).
			'enabled'               => $bool,
			'fullscreen_dashboard'  => $bool,
			'currency_display'      => array( 'type' => 'string', 'enum' => array( 'symbol', 'code', 'name' ) ),
			'default_goal_behavior' => array( 'type' => 'string', 'enum' => array( 'all', 'first', 'closest' ) ),
			'calculation_mode'      => array(
				'type' => 'string',
				'enum' => array( 'subtotal', 'discounted_subtotal', 'total' ),
			),

			// Frontend (P18-T02).
			'frontend_template'     => array(
				'type' => 'string',
				'enum' => array( 'basic', 'percentage', 'milestone', 'card' ),
			),
			'frontend_animation'    => $bool,
			'frontend_locations'    => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ),
				),
			),
			'frontend_mobile'       => array( 'type' => 'string', 'enum' => array( 'show', 'hide' ) ),
			'frontend_bar_height'   => array( 'type' => 'integer', 'minimum' => 4, 'maximum' => 48 ),
			'frontend_accent'       => array( 'type' => 'string' ),
			'frontend_bg'           => array( 'type' => 'string' ),
			'frontend_border'       => array( 'type' => 'string' ),
			'frontend_text'         => array( 'type' => 'string' ),
			'frontend_radius'       => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 40 ),
			'frontend_css_class'    => array( 'type' => 'string' ),
			'frontend_custom_css'   => array( 'type' => 'string' ),

			// Goal Calculation (P18-T03).
			'calculation_include_tax'      => $bool,
			'calculation_include_discount' => $bool,
			'calculation_include_shipping' => $bool,
			'calculation_include_sale'     => $bool,
			'calculation_include_virtual'  => $bool,

			// Performance (P18-T04).
			'performance_caching'     => $bool,
			'analytics_enabled'       => $bool,
			'performance_suggestions' => $bool,

			// Advanced (P18-T05).
			'debug_mode'      => $bool,
			'logging_enabled' => $bool,
			'developer_hooks' => $bool,
		);
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
	protected function sanitize_setting( $key, $value ) {
		$defaults = $this->settings->defaults();

		switch ( $key ) {
			case 'fullscreen_dashboard':
			case 'enabled':
			case 'frontend_animation':
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

			case 'currency_display':
				return in_array( $value, array( 'symbol', 'code', 'name' ), true ) ? $value : $defaults['currency_display'];

			case 'default_goal_behavior':
				return in_array( $value, array( 'all', 'first', 'closest' ), true ) ? $value : $defaults['default_goal_behavior'];

			case 'calculation_mode':
				return in_array( $value, array( 'subtotal', 'discounted_subtotal', 'total' ), true ) ? $value : $defaults['calculation_mode'];

			case 'frontend_mobile':
				return in_array( $value, array( 'show', 'hide' ), true ) ? $value : $defaults['frontend_mobile'];

			case 'frontend_locations':
				$allowed  = array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' );
				$cleaned  = array_filter( array_map( 'sanitize_key', (array) $value ), function ( $location ) use ( $allowed ) {
					return in_array( $location, $allowed, true );
				} );

				return array_values( array_unique( $cleaned ) );

			case 'frontend_template':
				$templates = array( 'basic', 'percentage', 'milestone', 'card' );

				return in_array( $value, $templates, true ) ? $value : $defaults['frontend_template'];

			case 'frontend_bar_height':
				return min( 48, max( 4, (int) $value ) );

			case 'frontend_accent':
			case 'frontend_bg':
			case 'frontend_border':
			case 'frontend_text':
				$color = sanitize_hex_color( $value );

				return $color ? $color : $defaults[ $key ];

			case 'frontend_radius':
				return min( 40, max( 0, (int) $value ) );

			case 'frontend_css_class':
				return trim( sanitize_text_field( (string) $value ) );

			case 'frontend_custom_css':
				// Admin-authored CSS (manage_options gate on the route); keep
				// it tag-free and bounded.
				return substr( trim( wp_strip_all_tags( (string) $value ) ), 0, 16000 );

			default:
				return $value;
		}
	}
}
