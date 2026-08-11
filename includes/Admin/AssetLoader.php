<?php
/**
 * Asset loader for the React admin application.
 *
 * @package GoalCart
 */

namespace GoalCart\Admin;

use GoalCart\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class AssetLoader
 *
 * Enqueues the built React admin app (Vite) into the WordPress admin.
 *
 * Production: reads `admin-app/dist/.vite/manifest.json` and enqueues the
 * hashed entry JS/CSS with cache-busting versions.
 *
 * Development: when the Vite dev server is reachable (or the
 * `GOALCART_DEV_SERVER_URL` constant is defined), enqueues the
 * `@vite/client` HMR runtime plus the TypeScript entry straight from the
 * dev server, so changes hot-reload inside WP admin without a build step.
 *
 * Boot data (nonce, REST base, user, caps, locale) is localized on the
 * app script handle so the React shell can authenticate REST requests
 * and render in the site's locale (Phase 2: nonce strategy).
 *
 * Mirrors the reference plugin (WooInsights\Admin\AssetLoader).
 */
class AssetLoader {

	/**
	 * Script/style handle for the admin app.
	 *
	 * @var string
	 */
	const HANDLE = 'goalcart-admin';

	/**
	 * Settings instance (for the boot-data fullscreen flag).
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
	 * Manifest path relative to the plugin root.
	 *
	 * @var string
	 */
	const MANIFEST_PATH = 'admin-app/dist/.vite/manifest.json';

	/**
	 * Built assets directory relative to the plugin root.
	 *
	 * @var string
	 */
	const BUILD_DIR = 'admin-app/dist';

	/**
	 * Default Vite dev server origin.
	 *
	 * @var string
	 */
	const DEFAULT_DEV_SERVER = 'http://localhost:5173';

	/**
	 * Enqueue the admin app assets.
	 *
	 * @return void
	 */
	public function enqueue() {
		// Vite emits ES modules; force type="module" on our script tags
		// (see add_module_type()).
		add_filter( 'wp_script_attributes', array( $this, 'add_module_type' ) );

		$dev_url = $this->dev_server_url();

		if ( $dev_url ) {
			$this->enqueue_dev( $dev_url );
			return;
		}

		$this->enqueue_production();
	}

	/**
	 * Reason the admin app is unavailable, for admin notices.
	 *
	 * @return string Empty when loadable, otherwise a readable hint.
	 */
	public function load_hint() {
		if ( $this->dev_server_url() ) {
			return '';
		}

		if ( ! file_exists( $this->manifest_path() ) ) {
			return sprintf(
				/* translators: %s: built manifest path. */
				__( 'The admin app is not built yet (%s is missing). Run npm install && npm run build inside the admin-app directory, or start npm run dev in a local/development environment for hot reload.', 'goalcart' ),
				esc_html( $this->relative_path( $this->manifest_path() ) )
			);
		}

		return '';
	}

	/**
	 * Get the Vite dev server URL when it should be used.
	 *
	 * Priority: the `GOALCART_DEV_SERVER_URL` constant, then automatic
	 * detection when the WordPress environment type is local/development.
	 * When the admin is served over HTTPS, the https variant is tried first
	 * because browsers block mixed http content on https pages.
	 *
	 * @return string Dev server origin, or '' when unavailable.
	 */
	public function dev_server_url() {
		if ( defined( 'GOALCART_DEV_SERVER_URL' ) && GOALCART_DEV_SERVER_URL ) {
			return untrailingslashit( (string) GOALCART_DEV_SERVER_URL );
		}

		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( ! in_array( $env, array( 'local', 'development' ), true ) ) {
			return '';
		}

		$candidates = is_ssl()
			? array( 'https://localhost:5173', self::DEFAULT_DEV_SERVER )
			: array( self::DEFAULT_DEV_SERVER );

		foreach ( $candidates as $candidate ) {
			if ( $this->is_dev_server_up( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Enqueue assets from the Vite dev server (HMR).
	 *
	 * @param string $url Dev server origin.
	 * @return void
	 */
	protected function enqueue_dev( $url ) {
		wp_enqueue_script(
			self::HANDLE . '-vite-client',
			$url . '/@vite/client',
			array(),
			null,
			$this->script_args()
		);

		wp_enqueue_script(
			self::HANDLE,
			$url . '/src/main.tsx',
			array( 'wp-i18n', self::HANDLE . '-vite-client' ),
			null,
			$this->script_args()
		);

		$this->finish_enqueue( self::HANDLE );
	}

	/**
	 * Enqueue the production build from the Vite manifest.
	 *
	 * @return void
	 */
	protected function enqueue_production() {
		$entry = $this->manifest_entry();

		if ( null === $entry ) {
			return;
		}

		$base_url = GOALCART_URL . self::BUILD_DIR . '/';
		$base_dir = GOALCART_PATH . self::BUILD_DIR . '/';

		$version = file_exists( $base_dir . $entry['file'] )
			? (string) filemtime( $base_dir . $entry['file'] )
			: GOALCART_VERSION;

		wp_enqueue_script(
			self::HANDLE,
			$base_url . $entry['file'],
			array( 'wp-i18n' ),
			$version,
			$this->script_args()
		);

		foreach ( $entry['css'] as $css ) {
			wp_enqueue_style( self::HANDLE, $base_url . $css, array(), $version );
		}

		$this->finish_enqueue( self::HANDLE );
	}

	/**
	 * Localize boot data and wire up translations for the app handle.
	 *
	 * @param string $handle Enqueued script handle.
	 * @return void
	 */
	protected function finish_enqueue( $handle ) {
		wp_localize_script( $handle, 'goalcart', $this->boot_data() );
		wp_set_script_translations( $handle, 'goalcart', GOALCART_PATH . 'languages' );
	}

	/**
	 * Boot data passed to the React app via wp_localize_script().
	 *
	 * Nonce, REST base URLs, current user, capabilities, locale and site
	 * info. Extensible via the 'goalcart_admin_boot_data' filter.
	 *
	 * @return array<string, mixed>
	 */
	public function boot_data() {
		$user = wp_get_current_user();

		$data = array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'restBase' => esc_url_raw( rest_url( 'goalcart/v1' ) ),
			'restUrl'  => esc_url_raw( rest_url() ),
			'adminUrl' => admin_url(),
			'homeUrl'  => home_url( '/' ),
			'siteName' => get_bloginfo( 'name' ),
			'locale'       => get_locale(),
			'isRtl'        => is_rtl(),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'currentDate'  => current_time( 'Y-m-d' ),
			'userId'   => (int) $user->ID,
			'user'     => array(
				'displayName' => $user->display_name,
				'avatarUrl'   => (string) get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			),
			'caps'     => array(
				'manageOptions'     => current_user_can( 'manage_options' ),
				'manageWooCommerce' => current_user_can( 'manage_woocommerce' ),
			),
			'version'  => GOALCART_VERSION,
			'isPro'    => false,
			// Whether the dashboard should open in full-screen mode (hides
			// the WP admin chrome). The React shell initializes from this
			// and switches live when the Settings toggle is saved.
			'fullscreen' => (bool) $this->settings->get( 'fullscreen_dashboard', true ),
			// The persisted dashboard theme (light | dark). The React shell
			// initializes the MUI theme from this so the first render matches
			// the setting (the `goalcart-dark` body class from
			// Admin::admin_body_class() covers the pre-mount paint).
			'adminTheme'  => 'dark' === $this->settings->get( 'admin_theme', 'light' ) ? 'dark' : 'light',
		);

		/**
		 * Filter the boot data passed to the React admin app.
		 *
		 * @param array<string, mixed> $data Boot data.
		 */
		return apply_filters( 'goalcart_admin_boot_data', $data );
	}

	/**
	 * Script enqueue args for the admin app.
	 *
	 * Note: WP's `wp_enqueue_script()` `$args` array only supports
	 * `in_footer`, `strategy`, `fetchpriority` and `module_dependencies`
	 * keys (WP 7.0 raises `_doing_it_wrong` for anything else). The
	 * `type="module"` attribute is added separately via
	 * add_module_type() so Vite's ESM output is not parsed as a classic
	 * script.
	 *
	 * @return array<string, mixed>
	 */
	protected function script_args() {
		return array(
			'in_footer' => true,
		);
	}

	/**
	 * Add `type="module"` to the admin app script tags.
	 *
	 * Without it, the browser parses Vite's ES module bundle as a classic
	 * script and throws "SyntaxError: Unexpected token 'export'".
	 *
	 * @param array<string, string|bool> $attributes Script tag attributes.
	 * @return array<string, string|bool>
	 */
	public function add_module_type( $attributes ) {
		if ( empty( $attributes['id'] ) ) {
			return $attributes;
		}

		$app_ids = array(
			self::HANDLE . '-js',
			self::HANDLE . '-vite-client-js',
		);

		if ( in_array( $attributes['id'], $app_ids, true ) ) {
			$attributes['type'] = 'module';
		}

		return $attributes;
	}

	/**
	 * Whether the Vite dev server is currently reachable.
	 *
	 * Cached in a transient for a few seconds so admin pages are not
	 * slowed down by a socket call on every request.
	 *
	 * @param string $url Dev server origin.
	 * @return bool
	 */
	protected function is_dev_server_up( $url ) {
		$cache_key = 'goalcart_dev_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$response = wp_remote_get(
			trailingslashit( $url ),
			array(
				'timeout'     => 1,
				'redirection' => 0,
			)
		);

		$up = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

		set_transient( $cache_key, $up ? 1 : 0, 10 );

		return $up;
	}

	/**
	 * Path to the built asset manifest.
	 *
	 * @return string
	 */
	protected function manifest_path() {
		return GOALCART_PATH . self::MANIFEST_PATH;
	}

	/**
	 * Read and decode the Vite build manifest.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function manifest() {
		$path = $this->manifest_path();

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * The manifest entry for the admin app.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function manifest_entry() {
		$manifest = $this->manifest();

		if ( null === $manifest || empty( $manifest['src/main.tsx'] ) || ! is_array( $manifest['src/main.tsx'] ) ) {
			return null;
		}

		$entry = $manifest['src/main.tsx'];

		if ( empty( $entry['file'] ) ) {
			return null;
		}

		$entry['css'] = isset( $entry['css'] ) && is_array( $entry['css'] ) ? $entry['css'] : array();

		return $entry;
	}

	/**
	 * Convert an absolute path to a plugin-relative path.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected function relative_path( $path ) {
		return ltrim( str_replace( GOALCART_PATH, '', $path ), '/' );
	}
}
