<?php
/**
 * FaraCart frontend progress UI tests (P11-T01 / P11-T02 / P11-T03).
 *
 * Boots WordPress and exercises the Phase 11 storefront widget layer:
 *
 *  - the ProgressUI service resolves from the container
 *  - hook registration for every display location (cart, mini-cart,
 *    checkout, shop, product, sticky bar) and the asset/config prints
 *  - shortcode registration, unique container ids, markup shape
 *  - the duplicate-render guard (a location renders exactly once)
 *  - the frontend config payload (endpoint, currency, reward labels)
 *  - the master enabled gate (settings toggle + faracart_frontend_enabled)
 *  - page gating: the shortcode in a post content enables assets
 *  - progress templates & appearance (Phase 12): config template/
 *    animation/appearance keys, per-widget shortcode template override,
 *    custom container class, token + custom CSS output, template filter
 *
 * Read-only like the other suites: no DB writes, no product/cart
 * creation. Settings are flipped in memory only and restored.
 *
 * Run: php tests/frontend-test.php   (from the plugin directory)
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

use FaraCart\Frontend\ProgressUI;

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

/**
 * The registered priority of a callback on a hook.
 *
 * `has_action( $tag, $callback, $priority )` cannot match array callbacks
 * (the priority form indexes callbacks by unique id, and the callback is
 * an array), so resolve the unique id the way WP_Hook::add_filter does.
 *
 * @param string   $tag      Hook name.
 * @param callable $callback Registered callback.
 * @return int|false
 */
function hook_priority( $tag, $callback ) {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $tag ] ) ) {
		return false;
	}

	$id = _wp_filter_build_unique_id( $tag, $callback, 10 );

	foreach ( $wp_filter[ $tag ]->callbacks as $priority => $bucket ) {
		if ( isset( $bucket[ $id ] ) ) {
			return $priority;
		}
	}

	return false;
}

$container = \FaraCart\Plugin::instance()->container();
$ui        = $container->get( ProgressUI::class );
$settings  = $container->get( \FaraCart\Settings\Settings::class );

// ---------------------------------------------------------------------------
// 1. Service wiring (P11-T01)
// ---------------------------------------------------------------------------
echo "\n== 1. Service wiring ==\n";

check( 'ProgressUI resolves from container', $ui instanceof ProgressUI );
check( 'is_enabled defaults to the settings toggle', true === $ui->is_enabled() );
check( 'default locations include cart/mini-cart/checkout/shop/product/sticky', array() === array_diff(
	array( 'cart', 'mini-cart', 'checkout', 'shop', 'product', 'sticky' ),
	$ui->locations()
) );

// ---------------------------------------------------------------------------
// 2. Hook registration (P11-T02 — display locations + assets/config)
// ---------------------------------------------------------------------------
echo "\n== 2. Hook registration ==\n";

check( 'wp_enqueue_scripts hooked', false !== has_action( 'wp_enqueue_scripts', array( $ui, 'enqueue_assets' ) ) );
check( 'wp_footer config print hooked at 5', 5 === hook_priority( 'wp_footer', array( $ui, 'print_config' ) ) );
check( 'wp_footer sticky bar hooked at 20', 20 === hook_priority( 'wp_footer', array( $ui, 'render_sticky_bar' ) ) );
check( 'init shortcode registration hooked', false !== has_action( 'init', array( $ui, 'register_shortcode' ) ) );
check( 'cart location hooked', false !== has_action( 'woocommerce_before_cart', array( $ui, 'render_cart_widget' ) ) );
check( 'cart bottom location hooked', false !== has_action( 'woocommerce_after_cart', array( $ui, 'render_cart_widget_bottom' ) ) );
check( 'mini-cart location hooked', false !== has_action( 'woocommerce_after_mini_cart', array( $ui, 'render_mini_cart_widget' ) ) );
check( 'mini-cart top location hooked', false !== has_action( 'woocommerce_before_mini_cart', array( $ui, 'render_mini_cart_widget_top' ) ) );
check( 'checkout location hooked', false !== has_action( 'woocommerce_before_checkout_form', array( $ui, 'render_checkout_widget' ) ) );
check( 'checkout bottom location hooked', false !== has_action( 'woocommerce_after_checkout_form', array( $ui, 'render_checkout_widget_bottom' ) ) );
check( 'shop location hooked', false !== has_action( 'woocommerce_archive_description', array( $ui, 'render_shop_widget' ) ) );
check( 'shop bottom location hooked', false !== has_action( 'woocommerce_after_shop_loop', array( $ui, 'render_shop_widget_bottom' ) ) );
check( 'product location hooked at 45', 45 === hook_priority( 'woocommerce_single_product_summary', array( $ui, 'render_product_widget' ) ) );
check( 'product bottom location hooked', 20 === hook_priority( 'woocommerce_after_single_product_summary', array( $ui, 'render_product_widget_bottom' ) ) );

// ---------------------------------------------------------------------------
// 3. Shortcode + container markup (P11-T02 — configurable widget)
// ---------------------------------------------------------------------------
echo "\n== 3. Shortcode & markup ==\n";

// 'init' never fires in CLI, so register the shortcode directly.
$ui->register_shortcode();

check( 'shortcode registered', shortcode_exists( ProgressUI::SHORTCODE ) );

$out = do_shortcode( '[faracart_progress]' );
check( 'shortcode renders a container', false !== strpos( $out, 'data-faracart-widget' ) );
check( 'shortcode container has unique id', false !== strpos( $out, 'id="faracart-shortcode-1"' ) );
check( 'shortcode defaults to full variant', false !== strpos( $out, 'data-faracart-variant="full"' ) );

$out = do_shortcode( '[faracart_progress variant="compact"]' );
check( 'shortcode accepts compact variant', false !== strpos( $out, 'data-faracart-variant="compact"' ) );

$out_a = do_shortcode( '[faracart_progress]' );
$out_b = do_shortcode( '[faracart_progress]' );

preg_match( '/id="(faracart-shortcode-\d+)"/', $out_a, $m_a );
preg_match( '/id="(faracart-shortcode-\d+)"/', $out_b, $m_b );
check( 'repeated shortcode ids stay unique', isset( $m_a[1], $m_b[1] ) && $m_a[1] !== $m_b[1] );

$markup = $ui->widget_container( 'faracart-test', 'full' );
check( 'widget container carries aria-live', false !== strpos( $markup, 'aria-live="polite"' ) );
check( 'bogus variant normalizes to full', false !== strpos( $ui->widget_container( 'faracart-x', 'banana' ), 'data-faracart-variant="full"' ) );

// ---------------------------------------------------------------------------
// 4. Duplicate-render guard (P11 — no double injection)
// ---------------------------------------------------------------------------
echo "\n== 4. Duplicate-render guard ==\n";

// Keep this source-level duplicate test deterministic when a developer has
// saved the optional position setting as bottom on the live test site.
$settings->set( 'frontend_position', 'top' );

ob_start();
$ui->render_cart_widget();
$ui->render_cart_widget(); // second call must be suppressed
$cart_out = ob_get_clean();

check( 'cart location renders once', 1 === substr_count( $cart_out, 'id="faracart-cart"' ) );
check( 'cart widget markup is escaped/safe', false === strpos( $cart_out, '<script' ) );

$bottom_ui = new ProgressUI( $settings );
$settings->set( 'frontend_position', 'bottom' );
ob_start();
$bottom_ui->render_cart_widget();
$top_suppressed = ob_get_clean();
ob_start();
$bottom_ui->render_cart_widget_bottom();
$bottom_rendered = ob_get_clean();
check( 'top cart hook is suppressed for bottom position', '' === $top_suppressed );
check( 'bottom cart hook renders for bottom position', false !== strpos( $bottom_rendered, 'id="faracart-cart"' ) );
$settings->set( 'frontend_position', 'top' );

// ---------------------------------------------------------------------------
// 5. Frontend config payload (P11-T02)
// ---------------------------------------------------------------------------
echo "\n== 5. Frontend config ==\n";

$config = $ui->frontend_config();
check( 'config has a progress endpoint', isset( $config['endpoint'] ) && '' !== $config['endpoint'] );
check( 'config endpoint points at /progress', false !== strpos( $config['endpoint'], '/faracart/v1/progress' ) );
check( 'config has a currency key', array_key_exists( 'currency', $config ) );
check( 'config has a page position key', array_key_exists( 'position', $config ) );
check( 'config is RTL-aware', array_key_exists( 'isRtl', $config ) );
check( 'config labels cover reward types', isset( $config['labels']['free_shipping'], $config['labels']['percent_discount'], $config['labels']['fixed_discount'], $config['labels']['free_gift'], $config['labels']['coupon'] ) );

// Phase 33.7 (Frontend Upsell Integration): the config carries the
// smart-upsell panel contract — public rank endpoint, upsell track
// endpoint, limit and localized panel labels.
$upsell_config = $config['upsells'] ?? null;
check( 'config carries the upsell block', is_array( $upsell_config ) && ! empty( $upsell_config['enabled'] ) );
check( 'upsell endpoint points at the public rank route', false !== strpos( $upsell_config['endpoint'] ?? '', '/faracart/v1/upsell/rank' ) );
check( 'upsell track endpoint points at the upsell track route', false !== strpos( $upsell_config['trackEndpoint'] ?? '', '/faracart/v1/upsell/track' ) );
check( 'upsell limit is bounded', isset( $upsell_config['limit'] ) && (int) $upsell_config['limit'] >= 1 && (int) $upsell_config['limit'] <= 6 );
check( 'upsell labels cover the panel strings', isset( $upsell_config['labels']['heading'], $upsell_config['labels']['add'], $upsell_config['labels']['adding'], $upsell_config['labels']['added'], $upsell_config['labels']['unavailable'] ) );

// The public progress payload must never be cached: WP sends no cache
// headers for guest REST requests, so a browser holding the first
// response would keep showing the previous cart's progress after the
// shopper adds/removes items. The endpoint stamps Cache-Control:
// no-store and the storefront JS cache-busts every poll.
$progress_resp = $container->get( \FaraCart\REST\FrontendController::class )
	->handle_progress( new \WP_REST_Request( 'GET', '/faracart/v1/progress' ) );
$progress_headers = $progress_resp->get_headers();
$progress_cc      = isset( $progress_headers['Cache-Control'] ) ? (string) $progress_headers['Cache-Control'] : '';
check( 'progress response forbids caching (no-store)', false !== strpos( $progress_cc, 'no-store' ) );

$frontend_js = (string) file_get_contents( FARACART_PATH . 'assets/js/frontend.js' );
check( 'frontend JS cache-busts the progress poll', false !== strpos( $frontend_js, "'_='" ) && false !== strpos( $frontend_js, 'Date.now()' ) );

check( 'frontend JS adopts the payload tracking nonce', false !== strpos( $frontend_js, 'tracking_nonce' ) && false !== strpos( $frontend_js, 'tracking.nonce' ) );

// Every eligible goal renders as its own stacked card — a campaign's
// milestones each get a full card instead of one featured card + a tiny
// ladder. The stack wrapper, the per-goal loop and the ineligible skip
// are all asserted on the source so a regression back to the
// featured-only render cannot slip through.
check( 'frontend JS stacks one card per eligible goal', false !== strpos( $frontend_js, 'faracart-widget__goals' ) && false !== strpos( $frontend_js, 'for ( var i = 0; i < goals.length; i++ )' ) );
check( 'frontend JS skips ineligible goals when rendering', false !== strpos( $frontend_js, 'goal.eligible === false' ) && false !== strpos( $frontend_js, 'continue;' ) );
check( 'frontend JS renders each goal card with its own template', false !== strpos( $frontend_js, 'goalContainer( goal, data.currency || cfg.currency, variant, widgetTemplate( container, goal ) )' ) );
check( 'frontend JS keeps the sticky bar featured-only', false !== strpos( $frontend_js, 'var goal = featuredGoal( goals );' ) );
check( 'sticky auto-hide tracks the previous scroll position', false !== strpos( $frontend_js, 'var previousY = stickyLastScrollY;' ) && false !== strpos( $frontend_js, 'stickyAutoHidden = true;' ) );
check( 'sticky auto-hide refreshes without payload changes', false !== strpos( $frontend_js, "stickyConfig.behavior === 'auto_hide'" ) );
check( 'sticky suggestions work in compact layout', false !== strpos( $frontend_js, 'if ( sticky.suggestions ) {' ) );

// Live cart-change refresh (Phase 11): every WooCommerce cart-mutation
// signal must reach the widgets through ONE centralized bridge — the
// classic jQuery events (incl. coupon / emptied), the Blocks wc-blocks_*
// DOM events and the wc/store/cart data store — with a supersede guard
// so a stale in-flight response can never overwrite fresher progress.
check( 'frontend JS binds the coupon/emptied classic cart events', false !== strpos( $frontend_js, "'applied_coupon'" ) && false !== strpos( $frontend_js, "'removed_coupon'" ) && false !== strpos( $frontend_js, "'wc_cart_emptied'" ) );
check( 'frontend JS normalizes every signal through one cart-changed bridge', false !== strpos( $frontend_js, 'faracart:cart-changed' ) && false !== strpos( $frontend_js, 'emitCartChanged' ) );
check( 'frontend JS subscribes to the Blocks cart data store', false !== strpos( $frontend_js, 'wc/store/cart' ) && false !== strpos( $frontend_js, 'wpData.subscribe' ) );
check( 'frontend JS folds cart totals into the Blocks store fingerprint', false !== strpos( $frontend_js, "totals.total_price" ) );
check( 'frontend JS clears the updating state on any request end', false !== strpos( $frontend_js, 'request.onloadend' ) );
check( 'frontend JS binds the Blocks add/remove DOM events', false !== strpos( $frontend_js, 'wc-blocks_added_to_cart' ) && false !== strpos( $frontend_js, 'wc-blocks_removed_from_cart' ) );
check( 'frontend JS supersedes stale refresh responses', false !== strpos( $frontend_js, 'fetchEpoch' ) && false !== strpos( $frontend_js, 'activeFetch' ) );
check( 'frontend JS debounces cart-change refreshes', false !== strpos( $frontend_js, 'cartFollowUpTimer' ) && false !== strpos( $frontend_js, 'refresh( { updating: true } )' ) );
check( 'frontend JS shows a subtle updating state while refreshing', false !== strpos( $frontend_js, 'faracart-widget--updating' ) );

// Self-healing tracking nonce (Phase 28): every /progress response mints
// a fresh faracart_track nonce so frontend.js can adopt it after a
// cached page served an expired or foreign one. The toggles are pinned
// on (deterministic baseline — the stored option may hold non-default
// values) so the assertions are environment-independent.
$analytics_before = $settings->get( 'analytics_enabled', true );
$enabled_before   = $settings->get( 'enabled', true );
$settings->set( 'analytics_enabled', true );
$settings->set( 'enabled', true );

try {
	$pinned_resp = $container->get( \FaraCart\REST\FrontendController::class )
		->handle_progress( new \WP_REST_Request( 'GET', '/faracart/v1/progress' ) );
	$pinned_data = $pinned_resp->get_data();
	$tracking_nonce = isset( $pinned_data['data']['tracking_nonce'] ) ? (string) $pinned_data['data']['tracking_nonce'] : '';
	check( 'progress payload carries a tracking nonce', '' !== $tracking_nonce );
	check( 'tracking nonce verifies for the track action', false !== wp_verify_nonce( $tracking_nonce, \FaraCart\Analytics\Tracker::TRACK_NONCE_ACTION ) );

	// The nonce is withheld while analytics are off (mirrors the config print).
	$settings->set( 'analytics_enabled', false );
	$off_resp = $container->get( \FaraCart\REST\FrontendController::class )
		->handle_progress( new \WP_REST_Request( 'GET', '/faracart/v1/progress' ) );
	$off_data = $off_resp->get_data();
	check( 'analytics off withholds the tracking nonce', empty( $off_data['data']['tracking_nonce'] ) );
} finally {
	$settings->set( 'analytics_enabled', $analytics_before );
	$settings->set( 'enabled', $enabled_before );
}

// ---------------------------------------------------------------------------
// 6. Enabled gate (P11-T03 — master toggle + filter)
// ---------------------------------------------------------------------------
echo "\n== 6. Enabled gate ==\n";

$enabled_before = $settings->get( 'enabled', true );

$settings->set( 'enabled', false );
check( 'disabled setting turns the UI off', false === $ui->is_enabled() );
check( 'disabled shortcode renders nothing', '' === do_shortcode( '[faracart_progress]' ) );

ob_start();
$ui->render_cart_widget();
$off_out = ob_get_clean();
check( 'disabled widget locations render nothing', '' === $off_out );

$settings->set( 'enabled', true );

add_filter( 'faracart_frontend_enabled', '__return_false' );
check( 'faracart_frontend_enabled filter overrides', false === $ui->is_enabled() );
remove_filter( 'faracart_frontend_enabled', '__return_false' );
check( 'filter removal restores enabled', true === $ui->is_enabled() );

// Shopper-facing widgets are hidden from logged-in site admins browsing
// the storefront (staff testing their own shop shouldn't see the
// customer funnel). An administrator is created inside a transaction so
// the check is deterministic on any install; the user + usermeta rows
// are rolled back afterwards. A fresh ProgressUI instance is used for
// the location render so the duplicate-render guard (already tripped on
// the shared instance) cannot mask the result.
$wpdb = $GLOBALS['wpdb'];
$wpdb->query( 'START TRANSACTION' );

$admin_id = null;

try {
	$admin_id = wp_insert_user( array(
		'user_login' => 'faracart_admin_visibility_test',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'faracart-admin-visibility@example.test',
		'role'       => 'administrator',
	) );

	check( 'admin test user created', ! is_wp_error( $admin_id ) && $admin_id > 0 );

	if ( ! is_wp_error( $admin_id ) && $admin_id > 0 ) {
		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $admin_id );

		check( 'logged-in admin does not see the UI', false === $ui->is_enabled() );
		check( 'admin shortcode renders nothing', '' === do_shortcode( '[faracart_progress]' ) );

		$fresh_ui = new \FaraCart\Frontend\ProgressUI( $settings );
		ob_start();
		$fresh_ui->render_cart_widget();
		$admin_out = ob_get_clean();
		check( 'admin widget locations render nothing', '' === $admin_out );

		// The visibility decision is filterable (e.g. hide for every
		// logged-in user, or for shop managers too).
		add_filter( 'faracart_frontend_visible_to_user', '__return_true' );
		check( 'visibility filter forces the UI back on', true === $ui->is_enabled() );
		remove_filter( 'faracart_frontend_visible_to_user', '__return_true' );

		wp_set_current_user( $previous_user );
	}
} finally {
	$wpdb->query( 'ROLLBACK' );
}

if ( $admin_id && ! is_wp_error( $admin_id ) ) {
	wp_cache_flush();
}

// Pin the toggle so the guest assertion is deterministic on its own (the
// stored option may hold a non-default value), then restore.
$settings->set( 'enabled', true );
check( 'guest visitor sees the UI again', true === $ui->is_enabled() );
$settings->set( 'enabled', $enabled_before );

// ---------------------------------------------------------------------------
// 7. Page gating (P11-T03 — assets only on widget-capable pages)
// ---------------------------------------------------------------------------
echo "\n== 7. Page gating ==\n";

// `page_needs_widget()` is protected; exercise it through reflection so
// the gating logic stays private while remaining testable.
$ref = new \ReflectionMethod( $ui, 'page_needs_widget' );
$ref->setAccessible( true );

// CLI: no cart/checkout query and no post → no assets.
check( 'no widget page → no assets', false === $ref->invoke( $ui ) );

// A real post whose content carries the shortcode must enable assets.
// Inserted inside a transaction (get_post() re-fetches from the DB in
// this WP version, so a fake object would not survive) and rolled back;
// the post cache is cleaned afterwards so the rollback stays observable.
$wpdb = $GLOBALS['wpdb'];
$wpdb->query( 'START TRANSACTION' );

try {
	$post_id = wp_insert_post( array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'FaraCart frontend test',
		'post_content' => '[faracart_progress]',
	), true );

	check( 'shortcode post inserted', ! is_wp_error( $post_id ) && $post_id > 0 );

	$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post'] = get_post( $post_id );

	check( 'shortcode post enables page widget detection', true === $ref->invoke( $ui ) );

	// Assets must be versioned by filemtime so the storefront never
	// serves a stale cached frontend.js/css after an edit — the static
	// FARACART_VERSION only changes between releases, which would leave
	// every browser on the old bundle (and every template looking the
	// same).
	$ui->enqueue_assets();

	$scripts = isset( wp_scripts()->registered[ ProgressUI::HANDLE ] ) ? wp_scripts()->registered[ ProgressUI::HANDLE ] : null;
	$styles  = isset( wp_styles()->registered[ ProgressUI::HANDLE ] ) ? wp_styles()->registered[ ProgressUI::HANDLE ] : null;

	check( 'frontend js enqueued', null !== $scripts && isset( $scripts->src ) && false !== strpos( $scripts->src, 'assets/js/frontend.js' ) );
	check( 'frontend js versioned by filemtime', isset( $scripts->ver ) && (string) filemtime( FARACART_PATH . 'assets/js/frontend.js' ) === (string) $scripts->ver );
	check( 'frontend css versioned by filemtime', isset( $styles->ver ) && (string) filemtime( FARACART_PATH . 'assets/css/frontend.css' ) === (string) $styles->ver );

	$GLOBALS['post'] = $previous_post;
} finally {
	$wpdb->query( 'ROLLBACK' );
}

if ( isset( $post_id ) && ! is_wp_error( $post_id ) ) {
	clean_post_cache( $post_id );
	check( 'shortcode post rolled back', null === get_post( $post_id ) );
}

// ---------------------------------------------------------------------------
// 8. Progress templates & appearance (P12-T01 / P12-T02)
// ---------------------------------------------------------------------------
echo "\n== 8. Templates & appearance ==\n";

// The Appearance admin screen's campaign live-preview stamps the sample
// campaign id onto its sample milestones — without it PreviewWidget's
// grouping (goal.campaign_id -> campaign) never joins them and the
// campaign template (milestone chain / campaign progress) never
// renders: the merchant would see three plain basic goal cards instead
// of the campaign readout. Source-scanned so that regression cannot
// slip through silently.
$appearance_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/routes/Appearance.tsx' );
check( 'Appearance campaign preview stamps the sample campaign id on milestones', false !== strpos( $appearance_tsx, 'campaign_id: campaign.campaign_id' ) );

// The builder preview panels render the payload-resolved template — the
// backend's TemplateEngine is the single template-resolution mechanism
// shared with the storefront — instead of forcing a template from a
// preview-side selector. Source-scanned so a preview-side template
// override (which would let the preview drift from the storefront) or a
// device-width/simulation control cannot silently come back.
$preview_panel_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/components/preview/PreviewPanel.tsx' );
check( 'Goal preview renders the payload-resolved template', false !== strpos( $preview_panel_tsx, 'goals[0]?.template' ) );
check( 'Campaign preview renders the payload-resolved template', false !== strpos( $preview_panel_tsx, 'campaigns?.[0]?.template' ) );
check( 'Preview panel has no forced template override', false === strpos( $preview_panel_tsx, 'templateOverride' ) );
check( 'Preview panel has no device-width frame', false === strpos( $preview_panel_tsx, 'DEVICE_WIDTHS' ) && false === strpos( $preview_panel_tsx, 'frameWidth' ) );
// A goal whose form target is still 0 (a fresh or cleared target) would
// evaluate server-side as trivially complete (target <= 0 -> 100%) — the
// preview must not claim a fake "100% complete" card for an unsaved
// draft. Source-scanned so the configuring hint cannot silently drop.
check( 'Goal preview shows a hint for target-less goals', false !== strpos( $preview_panel_tsx, 'target <= 0' ) && false !== strpos( $preview_panel_tsx, 'Set a target to preview progress.' ) );

// The preview controls expose no simulation inputs or template selector —
// the preview consumes the form's own configuration (selected template /
// global default) and only the progress-state preset remains.
$preview_controls_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/components/preview/PreviewControls.tsx' );
check( 'Preview controls keep no simulated amount field', false === strpos( $preview_controls_tsx, 'Simulated cart amount' ) );
check( 'Preview controls keep no simulated quantity field', false === strpos( $preview_controls_tsx, 'Simulated quantity' ) );
check( 'Preview controls keep no simulated reward control', false === strpos( $preview_controls_tsx, 'Simulated reward' ) );
check( 'Preview controls keep no device-width control', false === strpos( $preview_controls_tsx, 'Device width' ) );
check( 'Preview controls keep no template selector', false === strpos( $preview_controls_tsx, 'Template' ) );

$config = $ui->frontend_config();
check( 'config carries the template', isset( $config['template'] ) && 'template-1' === $config['template'] );
check( 'config carries the animation flag', array_key_exists( 'animation', $config ) && true === $config['animation'] );
check( 'config appearance has accent', isset( $config['appearance']['accent'] ) && '#2271b1' === $config['appearance']['accent'] );
check( 'config appearance has radius', isset( $config['appearance']['radius'] ) && 10 === $config['appearance']['radius'] );
check( 'config appearance has bar height', isset( $config['appearance']['barHeight'] ) && 10 === $config['appearance']['barHeight'] );

// Shortcode template override lands on the container (per-widget template).
$out = do_shortcode( '[faracart_progress template="template-2"]' );
check( 'shortcode template override', false !== strpos( $out, 'data-faracart-template="template-2"' ) );
$out = do_shortcode( '[faracart_progress template="bogus"]' );
check( 'bogus shortcode template ignored', false === strpos( $out, 'data-faracart-template' ) );

// Settings drive the config, the container class and the inline CSS.
$settings->set( 'frontend_template', 'template-3' );
$settings->set( 'frontend_css_class', 'fancy-store' );
$settings->set( 'frontend_custom_css', '.faracart-card { padding: 2rem; }' );
$settings->set( 'frontend_accent', 'nonsense' );

check( 'config template follows settings', 'template-3' === $ui->frontend_config()['template'] );
check( 'invalid color falls back in config', '#2271b1' === $ui->frontend_config()['appearance']['accent'] );

$markup = $ui->widget_container( 'faracart-test', 'full' );
check( 'custom css class on container', false !== strpos( $markup, 'fancy-store' ) );

$css = $ui->appearance_css();
check( 'token css sets accent', false !== strpos( $css, '--faracart-accent:#2271b1' ) );
check( 'token css sets bar height', false !== strpos( $css, '--faracart-bar-height:10px' ) );
check( 'custom css appended', false !== strpos( $css, 'padding: 2rem' ) );

add_filter( 'faracart_frontend_template', function () {
	return 'template-4';
} );
check( 'template filter overrides', 'template-4' === $ui->template() );
remove_all_filters( 'faracart_frontend_template' );

// Restore the Phase 11-visible defaults.
$settings->set( 'frontend_template', 'template-1' );
$settings->set( 'frontend_css_class', '' );
$settings->set( 'frontend_custom_css', '' );
$settings->set( 'frontend_accent', '#2271b1' );

// ---------------------------------------------------------------------------
// 9. WooCommerce Blocks compatibility (P19 — render_block for Cart/Checkout/Mini Cart)
// ---------------------------------------------------------------------------
echo "\n== 9. WooCommerce Blocks compatibility ==\n";

// A fresh instance: earlier sections already rendered the classic cart
// widget (and the plugin's duplicate guard is location-scoped per
// instance), so block tests run against an untouched widget registry.
$block_ui = new \FaraCart\Frontend\ProgressUI( $settings );

// The render_block filter is registered on the booted shared instance.
check( 'render_block filter wired', false !== has_filter( 'render_block', array( $ui, 'render_block_widget' ) ) );

// Block widget appends after the block markup; unrelated blocks pass through.
$out = $block_ui->render_block_widget( '<figure>cart block</figure>', array( 'blockName' => 'woocommerce/cart' ) );
check( 'cart block gains the full widget container', false !== strpos( $out, 'id="faracart-cart"' ) );
check( 'cart block widget is the full variant', false !== strpos( $out, 'data-faracart-variant="full"' ) );
check( 'cart block content preserved', false !== strpos( $out, '<figure>cart block</figure>' ) );

$out = $block_ui->render_block_widget( '<div>checkout</div>', array( 'blockName' => 'woocommerce/checkout' ) );
check( 'checkout block gains the widget container', false !== strpos( $out, 'id="faracart-checkout"' ) );

$out = $block_ui->render_block_widget( '<div>mini cart</div>', array( 'blockName' => 'woocommerce/mini-cart' ) );
check( 'mini-cart block gains a compact widget', false !== strpos( $out, 'id="faracart-mini-cart"' ) && false !== strpos( $out, 'data-faracart-variant="compact"' ) );

$out = $block_ui->render_block_widget( '<p>plain</p>', array( 'blockName' => 'core/paragraph' ) );
check( 'non-woocommerce block untouched', '<p>plain</p>' === $out );

// Duplicate-guard across the two render paths: a fresh instance renders
// the classic mini-cart action once, then a second injection through the
// block path is suppressed (one widget per page location).
$guard_ui = new \FaraCart\Frontend\ProgressUI( $settings );

ob_start();
$guard_ui->render_mini_cart_widget();
$mini_out = ob_get_clean();
check( 'mini-cart widget renders exactly once through the classic action', 1 === substr_count( $mini_out, 'id="faracart-mini-cart"' ) );

$out = $guard_ui->render_block_widget( '<p>late</p>', array( 'blockName' => 'woocommerce/mini-cart' ) );
check( 'duplicate-guard suppresses the second mini-cart widget', '<p>late</p>' === $out );

// ---------------------------------------------------------------------------
// 10. Gutenberg block (P21 — faracart/progress)
// ---------------------------------------------------------------------------
echo "\n== 10. Gutenberg block ==\n";

$ui->register_block();

check(
	'block type registered',
	class_exists( 'WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( \FaraCart\Frontend\ProgressUI::BLOCK )
);

$block_type = class_exists( 'WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( \FaraCart\Frontend\ProgressUI::BLOCK ) : null;

if ( $block_type ) {
	check( 'block has a render callback', is_callable( $block_type->render_callback ) );
	check( 'block api version 2', isset( $block_type->api_version ) && 2 === (int) $block_type->api_version );

	$block_out = call_user_func( $block_type->render_callback, array( 'variant' => 'compact' ), '' );
	check( 'block renders a widget container', false !== strpos( $block_out, 'data-faracart-widget' ) );
	check( 'block container is compact', false !== strpos( $block_out, 'data-faracart-variant="compact"' ) );
	check( 'block container id is unique', preg_match( '/id="faracart-block-\d+"/', $block_out ) === 1 );
	check( 'block template attr passes through', false === strpos( $block_out, 'data-faracart-template' ) );

	$block_out = call_user_func( $block_type->render_callback, array( 'variant' => 'full', 'template' => 'template-2' ), '' );
	check( 'block template override lands on container', false !== strpos( $block_out, 'data-faracart-template="template-2"' ) );
	check( 'repeated block ids stay unique', preg_match( '/id="faracart-block-(\d+)"/', $block_out, $m ) === 1 && $m[1] !== '1' );

	// A page carrying the block needs the storefront assets.
	$ref_block = new \ReflectionMethod( $ui, 'page_needs_widget' );
	$ref_block->setAccessible( true );

	$wpdb = $GLOBALS['wpdb'];
	$wpdb->query( 'START TRANSACTION' );

	try {
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'FaraCart block test',
			'post_content' => '<!-- wp:faracart/progress {"variant":"compact"} /-->',
		), true );

		check( 'block post inserted', ! is_wp_error( $post_id ) && $post_id > 0 );

		$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = get_post( $post_id );

		check( 'block page detected for assets', true === $ref_block->invoke( $ui ) );

		$GLOBALS['post'] = $previous_post;
	} finally {
		$wpdb->query( 'ROLLBACK' );
	}

	if ( isset( $post_id ) && ! is_wp_error( $post_id ) ) {
		clean_post_cache( $post_id );
	}
} else {
	echo "SKIP Gutenberg block checks (block registry unavailable)\n";
}

// ---------------------------------------------------------------------------
// 11. Smart Recommendations presentation (Improvement.md Phase 7 / §33–§34)
// ---------------------------------------------------------------------------
echo "\n== 11. Recommendations presentation ==\n";

// The Phase 7 redesign simplifies the recommendation presentation: the
// primary card shows the business outcome first (recommended target,
// "Confidence: High/Medium/Low" label, expected impact range, expected
// profit with the §34 unavailable state, plain-English "Why?" bullets)
// and moves the raw scoring details (score, component scores, ratios)
// behind the Advanced details expander. Source-scanned so a regression
// back to the raw numeric-first layout cannot slip through silently.
$recommendations_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/routes/Recommendations.tsx' );
check( 'recommendations page composes a business confidence label', false !== strpos( $recommendations_tsx, 'confidenceTier(' ) && false !== strpos( $recommendations_tsx, ': ${tier.label}' ) );
check( 'recommendations page labels expected impact in business terms', false !== strpos( $recommendations_tsx, "__('Expected impact', 'faracart')" ) && false !== strpos( $recommendations_tsx, "__('average basket value', 'faracart')" ) );
check( 'recommendations page shows the Why? reasons on the primary card', false !== strpos( $recommendations_tsx, "__('Why?', 'faracart')" ) && false !== strpos( $recommendations_tsx, 'candidate.reasons.map' ) );
check( 'recommendations page explains unavailable expected profit (§34)', false !== strpos( $recommendations_tsx, "__('Add product cost data to estimate profitability.', 'faracart')" ) );
check( 'recommendations page hides raw scoring behind Advanced details', false !== strpos( $recommendations_tsx, "__('Advanced details', 'faracart')" ) && false !== strpos( $recommendations_tsx, 'Scoring factors' ) );
check( 'recommendations page no longer leads with the raw confidence percent', false === strpos( $recommendations_tsx, 'formatPercent(top.confidence / 100)' ) && false === strpos( $recommendations_tsx, 'formatPercent(candidate.confidence / 100)' ) );
check( 'recommendations page keeps the explicit apply + dismiss flow', false !== strpos( $recommendations_tsx, "__('Apply recommendation', 'faracart')" ) && false !== strpos( $recommendations_tsx, "__('Dismiss', 'faracart')" ) && false !== strpos( $recommendations_tsx, 'ConfirmDialog' ) );

// UICHANGES.md Best-Recommendation UX: the page renders ONLY the single
// backend-ranked best recommendation (`payload.recommendation`), never the
// full ranked-candidate list. The backend still generates + ranks every
// candidate deterministically and returns them in the API payload; the
// UI simply never renders the list. Source-scanned so a "Ranked
// candidates" list cannot silently reappear.
check( 'recommendations page renders the top recommendation card', false !== strpos( $recommendations_tsx, '<TopRecommendationCard' ) );
check( 'recommendations page never renders the ranked-candidate list', false === strpos( $recommendations_tsx, 'Ranked candidates' ) && false === strpos( $recommendations_tsx, 'candidates.map' ) && false === strpos( $recommendations_tsx, 'CandidateRow' ) );
check( 'recommendations page relies on the backend best (payload.recommendation)', false !== strpos( $recommendations_tsx, 'const top = payload?.recommendation' ) );
check( 'recommendations page keeps an empty state when no recommendation exists', false !== strpos( $recommendations_tsx, "__('No recommendation available', 'faracart')" ) );

// Percentage safety in the analyzed-store-data section: the order-value
// distribution is an array of buckets with a 0-1 share each (formatted
// with formatPercent), never a Record<string, number> that renders
// NaN%. The margin factor (a 0-1 rate) and coverage (0-100 points) use
// the correct formatters — no division-by-100, no object arithmetic.
$format_src = (string) file_get_contents( FARACART_PATH . 'admin-app/src/lib/format.ts' );
check( 'formatPercent guards non-finite/missing values with an em dash', false !== strpos( $format_src, '!Number.isFinite(value)' ) && false !== strpos( $format_src, "return '—'" ) );
check( 'formatPercentValue guards non-finite/missing values too', false !== strpos( $format_src, 'export function formatPercentValue(value: number | null | undefined)' ) );
check( 'distribution renders each bucket label + share, not Object.entries', false !== strpos( $recommendations_tsx, 'data.distribution.map((bucket)' ) && false === strpos( $recommendations_tsx, 'Object.entries(data.distribution)' ) && false === strpos( $recommendations_tsx, 'formatBucket' ) );
check( 'distribution share formatted as a 0-1 rate percentage', false !== strpos( $recommendations_tsx, 'formatPercent(bucket.share)' ) );
check( 'margin factor uses the 0-1 rate formatter (formatPercent)', false !== strpos( $recommendations_tsx, "formatPercent(factors.margin_pct)" ) );
check( 'coverage percentage is not divided by 100', false !== strpos( $recommendations_tsx, 'formatPercentValue(coverage.product_coverage.coverage_pct)' ) && false === strpos( $recommendations_tsx, 'coverage_pct / 100' ) );

// ---------------------------------------------------------------------------
// 12. Upsell Analytics presentation (Improvement.md Phase 8 / §35)
// ---------------------------------------------------------------------------
echo "\n== 12. Upsell Analytics presentation ==\n";

// The Phase 8 redesign makes purchases/sales the primary metrics: the
// table leads with Product / Orders / Sales / Estimated profit /
// Conversion and hides the interaction funnel (impressions, clicks,
// adds, CTR, add-to-cart rate) plus the upsell score behind a "Show
// interaction details" toggle; a summary strip answers the first-screen
// question at a glance. Source-scanned so the commercial-first layout
// cannot silently regress to a score/metrics-first table.
$upsell_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/routes/UpsellAnalytics.tsx' );
check( 'upsell table leads with the commercial columns', false !== strpos( $upsell_tsx, "__('Orders', 'faracart')" ) && false !== strpos( $upsell_tsx, "__('Estimated profit', 'faracart')" ) && false !== strpos( $upsell_tsx, "__('Conversion', 'faracart')" ) );
check( 'upsell orders column comes before the interaction funnel', strpos( $upsell_tsx, "__('Orders', 'faracart')" ) < strpos( $upsell_tsx, "__('Impressions', 'faracart')" ) );
check( 'upsell interaction details sit behind the show-details toggle', false !== strpos( $upsell_tsx, "__('Show interaction details', 'faracart')" ) && false !== strpos( $upsell_tsx, 'showDetails && (' ) );
check( 'upsell score is inside the interaction-details block', strpos( $upsell_tsx, "__('Score', 'faracart')" ) > strpos( $upsell_tsx, 'showDetails && (' ) );
check( 'upsell page shows a commercial summary strip', false !== strpos( $upsell_tsx, "__('Purchased Orders', 'faracart')" ) && false !== strpos( $upsell_tsx, "__('Sales', 'faracart')" ) );
check( 'upsell funnel rates never fabricate a denominator', false !== strpos( $upsell_tsx, 'denominator > 0 ? formatPercent' ) && false !== strpos( $upsell_tsx, '\'—\'' ) );
check( 'upsell top-performing view sorts by purchases then sales', false !== strpos( $upsell_tsx, 'b.orders - a.orders || b.revenue - a.revenue' ) );
check( 'upsell per-product score breakdown stays available', false !== strpos( $upsell_tsx, 'ProductDetailDialog' ) && false !== strpos( $upsell_tsx, 'Score breakdown' ) );

// 13. UX polish states (Improvement.md Phase 9 / §43-§46)
// ---------------------------------------------------------------------------
echo "\n== 13. UX polish states ==\n";

// §44 — the two empty states are distinct: no interactions at all vs
// interactions without any attributed purchase. Source-scanned so they
// cannot silently merge back into a single "no data" message.
$revenue_tsx  = (string) file_get_contents( FARACART_PATH . 'admin-app/src/routes/RevenueOverview.tsx' );
$analytics_tsx = (string) file_get_contents( FARACART_PATH . 'admin-app/src/routes/Analytics.tsx' );
$profit_card   = (string) file_get_contents( FARACART_PATH . 'admin-app/src/components/revenue/EstimatedProfitCard.tsx' );
check( 'overview offers the no-sales-data empty state', false !== strpos( $revenue_tsx, "__('No sales data yet', 'faracart')" ) );
check( 'overview offers the distinct no-purchases-yet empty state', false !== strpos( $revenue_tsx, "__('No purchases yet', 'faracart')" ) );
check( 'overview no-purchases-yet only fires with activity but zero orders', false !== strpos( $revenue_tsx, 'summary.funnel.views > 0 && summary.orders === 0' ) );
check( 'analytics offers the no-sales-data empty state', false !== strpos( $analytics_tsx, "__('No sales data yet', 'faracart')" ) );
check( 'analytics offers the distinct no-purchases-yet empty state', false !== strpos( $analytics_tsx, "__('No purchases yet', 'faracart')" ) );
check( 'analytics no-purchases-yet only fires with funnel views and zero purchases', false !== strpos( $analytics_tsx, 'funnel.views > 0 &&' ) && false !== strpos( $analytics_tsx, 'funnel.converted === 0' ) );
check( 'the no-purchases-yet copy follows section 44', false !== strpos( $revenue_tsx, 'but no attributed purchases have been recorded for this period' ) && false !== strpos( $analytics_tsx, 'but no attributed purchases have been recorded for this period' ) );

// §43/§46 — loading skeletons, query errors and the profit-unavailable state
// must stay present (no blank cards), and the observed-impact disclaimer
// stays subtle instead of legal-style.
check( 'pages render loading skeletons', false !== strpos( $revenue_tsx, 'Skeleton' ) && false !== strpos( $analytics_tsx, 'Skeleton' ) );
check( 'pages surface query errors instead of blank cards', false !== strpos( $revenue_tsx, 'query.isError' ) && false !== strpos( $analytics_tsx, 'analyticsQuery.isError' ) );
check( 'profit card keeps the unavailable state', false !== strpos( $profit_card, "__('Not available', 'faracart')" ) );
check( 'observed-impact disclaimer stays subtle and present', false !== strpos( $revenue_tsx, "__('Observed impact', 'faracart')" ) && false !== strpos( $revenue_tsx, 'AOV comparisons are observed impact' ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "FRONTEND TEST FAILED\n" : "FRONTEND TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
