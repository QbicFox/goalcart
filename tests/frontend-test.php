<?php
/**
 * Goal Cart frontend progress UI tests (P11-T01 / P11-T02 / P11-T03).
 *
 * Boots WordPress and exercises the Phase 11 storefront widget layer:
 *
 *  - the ProgressUI service resolves from the container
 *  - hook registration for every display location (cart, mini-cart,
 *    checkout, shop, product, sticky bar) and the asset/config prints
 *  - shortcode registration, unique container ids, markup shape
 *  - the duplicate-render guard (a location renders exactly once)
 *  - the frontend config payload (endpoint, currency, reward labels)
 *  - the master enabled gate (settings toggle + goalcart_frontend_enabled)
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
require dirname( __DIR__ ) . '/goalcart.php';

use GoalCart\Frontend\ProgressUI;

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

$container = \GoalCart\Plugin::instance()->container();
$ui        = $container->get( ProgressUI::class );
$settings  = $container->get( \GoalCart\Settings\Settings::class );

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
check( 'mini-cart location hooked', false !== has_action( 'woocommerce_after_mini_cart', array( $ui, 'render_mini_cart_widget' ) ) );
check( 'checkout location hooked', false !== has_action( 'woocommerce_before_checkout_form', array( $ui, 'render_checkout_widget' ) ) );
check( 'shop location hooked', false !== has_action( 'woocommerce_archive_description', array( $ui, 'render_shop_widget' ) ) );
check( 'product location hooked at 45', 45 === hook_priority( 'woocommerce_single_product_summary', array( $ui, 'render_product_widget' ) ) );

// ---------------------------------------------------------------------------
// 3. Shortcode + container markup (P11-T02 — configurable widget)
// ---------------------------------------------------------------------------
echo "\n== 3. Shortcode & markup ==\n";

// 'init' never fires in CLI, so register the shortcode directly.
$ui->register_shortcode();

check( 'shortcode registered', shortcode_exists( ProgressUI::SHORTCODE ) );

$out = do_shortcode( '[goalcart_progress]' );
check( 'shortcode renders a container', false !== strpos( $out, 'data-goalcart-widget' ) );
check( 'shortcode container has unique id', false !== strpos( $out, 'id="goalcart-shortcode-1"' ) );
check( 'shortcode defaults to full variant', false !== strpos( $out, 'data-goalcart-variant="full"' ) );

$out = do_shortcode( '[goalcart_progress variant="compact"]' );
check( 'shortcode accepts compact variant', false !== strpos( $out, 'data-goalcart-variant="compact"' ) );

$out_a = do_shortcode( '[goalcart_progress]' );
$out_b = do_shortcode( '[goalcart_progress]' );

preg_match( '/id="(goalcart-shortcode-\d+)"/', $out_a, $m_a );
preg_match( '/id="(goalcart-shortcode-\d+)"/', $out_b, $m_b );
check( 'repeated shortcode ids stay unique', isset( $m_a[1], $m_b[1] ) && $m_a[1] !== $m_b[1] );

$markup = $ui->widget_container( 'goalcart-test', 'full' );
check( 'widget container carries aria-live', false !== strpos( $markup, 'aria-live="polite"' ) );
check( 'bogus variant normalizes to full', false !== strpos( $ui->widget_container( 'goalcart-x', 'banana' ), 'data-goalcart-variant="full"' ) );

// ---------------------------------------------------------------------------
// 4. Duplicate-render guard (P11 — no double injection)
// ---------------------------------------------------------------------------
echo "\n== 4. Duplicate-render guard ==\n";

ob_start();
$ui->render_cart_widget();
$ui->render_cart_widget(); // second call must be suppressed
$cart_out = ob_get_clean();

check( 'cart location renders once', 1 === substr_count( $cart_out, 'id="goalcart-cart"' ) );
check( 'cart widget markup is escaped/safe', false === strpos( $cart_out, '<script' ) );

// ---------------------------------------------------------------------------
// 5. Frontend config payload (P11-T02)
// ---------------------------------------------------------------------------
echo "\n== 5. Frontend config ==\n";

$config = $ui->frontend_config();
check( 'config has a progress endpoint', isset( $config['endpoint'] ) && '' !== $config['endpoint'] );
check( 'config endpoint points at /progress', false !== strpos( $config['endpoint'], '/goalcart/v1/progress' ) );
check( 'config has a currency key', array_key_exists( 'currency', $config ) );
check( 'config is RTL-aware', array_key_exists( 'isRtl', $config ) );
check( 'config labels cover reward types', isset( $config['labels']['free_shipping'], $config['labels']['percent_discount'], $config['labels']['fixed_discount'], $config['labels']['free_gift'], $config['labels']['coupon'] ) );

// The public progress payload must never be cached: WP sends no cache
// headers for guest REST requests, so a browser holding the first
// response would keep showing the previous cart's progress after the
// shopper adds/removes items. The endpoint stamps Cache-Control:
// no-store and the storefront JS cache-busts every poll.
$progress_resp = $container->get( \GoalCart\REST\FrontendController::class )
	->handle_progress( new \WP_REST_Request( 'GET', '/goalcart/v1/progress' ) );
$progress_headers = $progress_resp->get_headers();
$progress_cc      = isset( $progress_headers['Cache-Control'] ) ? (string) $progress_headers['Cache-Control'] : '';
check( 'progress response forbids caching (no-store)', false !== strpos( $progress_cc, 'no-store' ) );

$frontend_js = (string) file_get_contents( GOALCART_PATH . 'assets/js/frontend.js' );
check( 'frontend JS cache-busts the progress poll', false !== strpos( $frontend_js, "'_='" ) && false !== strpos( $frontend_js, 'Date.now()' ) );

check( 'frontend JS adopts the payload tracking nonce', false !== strpos( $frontend_js, 'tracking_nonce' ) && false !== strpos( $frontend_js, 'tracking.nonce' ) );

// Every eligible goal renders as its own stacked card — a campaign's
// milestones each get a full card instead of one featured card + a tiny
// ladder. The stack wrapper, the per-goal loop and the ineligible skip
// are all asserted on the source so a regression back to the
// featured-only render cannot slip through.
check( 'frontend JS stacks one card per eligible goal', false !== strpos( $frontend_js, 'goalcart-widget__goals' ) && false !== strpos( $frontend_js, 'for ( var i = 0; i < goals.length; i++ )' ) );
check( 'frontend JS skips ineligible goals when rendering', false !== strpos( $frontend_js, 'goal.eligible === false' ) && false !== strpos( $frontend_js, 'continue;' ) );
check( 'frontend JS renders each goal card with its own template', false !== strpos( $frontend_js, 'goalContainer( goal, data.currency || cfg.currency, variant, widgetTemplate( container, goal ) )' ) );
check( 'frontend JS keeps the sticky bar featured-only', false !== strpos( $frontend_js, 'var goal = featuredGoal( goals );' ) );

// Live cart-change refresh (Phase 11): every WooCommerce cart-mutation
// signal must reach the widgets through ONE centralized bridge — the
// classic jQuery events (incl. coupon / emptied), the Blocks wc-blocks_*
// DOM events and the wc/store/cart data store — with a supersede guard
// so a stale in-flight response can never overwrite fresher progress.
check( 'frontend JS binds the coupon/emptied classic cart events', false !== strpos( $frontend_js, "'applied_coupon'" ) && false !== strpos( $frontend_js, "'removed_coupon'" ) && false !== strpos( $frontend_js, "'wc_cart_emptied'" ) );
check( 'frontend JS normalizes every signal through one cart-changed bridge', false !== strpos( $frontend_js, 'goalcart:cart-changed' ) && false !== strpos( $frontend_js, 'emitCartChanged' ) );
check( 'frontend JS subscribes to the Blocks cart data store', false !== strpos( $frontend_js, 'wc/store/cart' ) && false !== strpos( $frontend_js, 'wpData.subscribe' ) );
check( 'frontend JS folds cart totals into the Blocks store fingerprint', false !== strpos( $frontend_js, "totals.total_price" ) );
check( 'frontend JS clears the updating state on any request end', false !== strpos( $frontend_js, 'request.onloadend' ) );
check( 'frontend JS binds the Blocks add/remove DOM events', false !== strpos( $frontend_js, 'wc-blocks_added_to_cart' ) && false !== strpos( $frontend_js, 'wc-blocks_removed_from_cart' ) );
check( 'frontend JS supersedes stale refresh responses', false !== strpos( $frontend_js, 'fetchEpoch' ) && false !== strpos( $frontend_js, 'activeFetch' ) );
check( 'frontend JS debounces cart-change refreshes', false !== strpos( $frontend_js, 'cartFollowUpTimer' ) && false !== strpos( $frontend_js, 'refresh( { updating: true } )' ) );
check( 'frontend JS shows a subtle updating state while refreshing', false !== strpos( $frontend_js, 'goalcart-widget--updating' ) );

// Self-healing tracking nonce (Phase 28): every /progress response mints
// a fresh goalcart_track nonce so frontend.js can adopt it after a
// cached page served an expired or foreign one. The toggles are pinned
// on (deterministic baseline — the stored option may hold non-default
// values) so the assertions are environment-independent.
$analytics_before = $settings->get( 'analytics_enabled', true );
$enabled_before   = $settings->get( 'enabled', true );
$settings->set( 'analytics_enabled', true );
$settings->set( 'enabled', true );

try {
	$pinned_resp = $container->get( \GoalCart\REST\FrontendController::class )
		->handle_progress( new \WP_REST_Request( 'GET', '/goalcart/v1/progress' ) );
	$pinned_data = $pinned_resp->get_data();
	$tracking_nonce = isset( $pinned_data['data']['tracking_nonce'] ) ? (string) $pinned_data['data']['tracking_nonce'] : '';
	check( 'progress payload carries a tracking nonce', '' !== $tracking_nonce );
	check( 'tracking nonce verifies for the track action', false !== wp_verify_nonce( $tracking_nonce, \GoalCart\Analytics\Tracker::TRACK_NONCE_ACTION ) );

	// The nonce is withheld while analytics are off (mirrors the config print).
	$settings->set( 'analytics_enabled', false );
	$off_resp = $container->get( \GoalCart\REST\FrontendController::class )
		->handle_progress( new \WP_REST_Request( 'GET', '/goalcart/v1/progress' ) );
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
check( 'disabled shortcode renders nothing', '' === do_shortcode( '[goalcart_progress]' ) );

ob_start();
$ui->render_cart_widget();
$off_out = ob_get_clean();
check( 'disabled widget locations render nothing', '' === $off_out );

$settings->set( 'enabled', true );

add_filter( 'goalcart_frontend_enabled', '__return_false' );
check( 'goalcart_frontend_enabled filter overrides', false === $ui->is_enabled() );
remove_filter( 'goalcart_frontend_enabled', '__return_false' );
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
		'user_login' => 'goalcart_admin_visibility_test',
		'user_pass'  => wp_generate_password(),
		'user_email' => 'goalcart-admin-visibility@example.test',
		'role'       => 'administrator',
	) );

	check( 'admin test user created', ! is_wp_error( $admin_id ) && $admin_id > 0 );

	if ( ! is_wp_error( $admin_id ) && $admin_id > 0 ) {
		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $admin_id );

		check( 'logged-in admin does not see the UI', false === $ui->is_enabled() );
		check( 'admin shortcode renders nothing', '' === do_shortcode( '[goalcart_progress]' ) );

		$fresh_ui = new \GoalCart\Frontend\ProgressUI( $settings );
		ob_start();
		$fresh_ui->render_cart_widget();
		$admin_out = ob_get_clean();
		check( 'admin widget locations render nothing', '' === $admin_out );

		// The visibility decision is filterable (e.g. hide for every
		// logged-in user, or for shop managers too).
		add_filter( 'goalcart_frontend_visible_to_user', '__return_true' );
		check( 'visibility filter forces the UI back on', true === $ui->is_enabled() );
		remove_filter( 'goalcart_frontend_visible_to_user', '__return_true' );

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
		'post_title'   => 'Goal Cart frontend test',
		'post_content' => '[goalcart_progress]',
	), true );

	check( 'shortcode post inserted', ! is_wp_error( $post_id ) && $post_id > 0 );

	$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post'] = get_post( $post_id );

	check( 'shortcode post enables page widget detection', true === $ref->invoke( $ui ) );

	// Assets must be versioned by filemtime so the storefront never
	// serves a stale cached frontend.js/css after an edit — the static
	// GOALCART_VERSION only changes between releases, which would leave
	// every browser on the old bundle (and every template looking the
	// same).
	$ui->enqueue_assets();

	$scripts = isset( wp_scripts()->registered[ ProgressUI::HANDLE ] ) ? wp_scripts()->registered[ ProgressUI::HANDLE ] : null;
	$styles  = isset( wp_styles()->registered[ ProgressUI::HANDLE ] ) ? wp_styles()->registered[ ProgressUI::HANDLE ] : null;

	check( 'frontend js enqueued', null !== $scripts && isset( $scripts->src ) && false !== strpos( $scripts->src, 'assets/js/frontend.js' ) );
	check( 'frontend js versioned by filemtime', isset( $scripts->ver ) && (string) filemtime( GOALCART_PATH . 'assets/js/frontend.js' ) === (string) $scripts->ver );
	check( 'frontend css versioned by filemtime', isset( $styles->ver ) && (string) filemtime( GOALCART_PATH . 'assets/css/frontend.css' ) === (string) $styles->ver );

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
$appearance_tsx = (string) file_get_contents( GOALCART_PATH . 'admin-app/src/routes/Appearance.tsx' );
check( 'Appearance campaign preview stamps the sample campaign id on milestones', false !== strpos( $appearance_tsx, 'campaign_id: campaign.campaign_id' ) );

$config = $ui->frontend_config();
check( 'config carries the template', isset( $config['template'] ) && 'basic' === $config['template'] );
check( 'config carries the animation flag', array_key_exists( 'animation', $config ) && true === $config['animation'] );
check( 'config appearance has accent', isset( $config['appearance']['accent'] ) && '#2271b1' === $config['appearance']['accent'] );
check( 'config appearance has radius', isset( $config['appearance']['radius'] ) && 10 === $config['appearance']['radius'] );
check( 'config appearance has bar height', isset( $config['appearance']['barHeight'] ) && 10 === $config['appearance']['barHeight'] );

// Shortcode template override lands on the container (per-widget template).
$out = do_shortcode( '[goalcart_progress template="card"]' );
check( 'shortcode template override', false !== strpos( $out, 'data-goalcart-template="card"' ) );
$out = do_shortcode( '[goalcart_progress template="bogus"]' );
check( 'bogus shortcode template ignored', false === strpos( $out, 'data-goalcart-template' ) );

// Settings drive the config, the container class and the inline CSS.
$settings->set( 'frontend_template', 'percentage' );
$settings->set( 'frontend_css_class', 'fancy-store' );
$settings->set( 'frontend_custom_css', '.goalcart-card { padding: 2rem; }' );
$settings->set( 'frontend_accent', 'nonsense' );

check( 'config template follows settings', 'percentage' === $ui->frontend_config()['template'] );
check( 'invalid color falls back in config', '#2271b1' === $ui->frontend_config()['appearance']['accent'] );

$markup = $ui->widget_container( 'goalcart-test', 'full' );
check( 'custom css class on container', false !== strpos( $markup, 'fancy-store' ) );

$css = $ui->appearance_css();
check( 'token css sets accent', false !== strpos( $css, '--goalcart-accent:#2271b1' ) );
check( 'token css sets bar height', false !== strpos( $css, '--goalcart-bar-height:10px' ) );
check( 'custom css appended', false !== strpos( $css, 'padding: 2rem' ) );

add_filter( 'goalcart_frontend_template', function () {
	return 'milestone';
} );
check( 'template filter overrides', 'milestone' === $ui->template() );
remove_all_filters( 'goalcart_frontend_template' );

// Restore the Phase 11-visible defaults.
$settings->set( 'frontend_template', 'basic' );
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
$block_ui = new \GoalCart\Frontend\ProgressUI( $settings );

// The render_block filter is registered on the booted shared instance.
check( 'render_block filter wired', false !== has_filter( 'render_block', array( $ui, 'render_block_widget' ) ) );

// Block widget appends after the block markup; unrelated blocks pass through.
$out = $block_ui->render_block_widget( '<figure>cart block</figure>', array( 'blockName' => 'woocommerce/cart' ) );
check( 'cart block gains the full widget container', false !== strpos( $out, 'id="goalcart-cart"' ) );
check( 'cart block widget is the full variant', false !== strpos( $out, 'data-goalcart-variant="full"' ) );
check( 'cart block content preserved', false !== strpos( $out, '<figure>cart block</figure>' ) );

$out = $block_ui->render_block_widget( '<div>checkout</div>', array( 'blockName' => 'woocommerce/checkout' ) );
check( 'checkout block gains the widget container', false !== strpos( $out, 'id="goalcart-checkout"' ) );

$out = $block_ui->render_block_widget( '<div>mini cart</div>', array( 'blockName' => 'woocommerce/mini-cart' ) );
check( 'mini-cart block gains a compact widget', false !== strpos( $out, 'id="goalcart-mini-cart"' ) && false !== strpos( $out, 'data-goalcart-variant="compact"' ) );

$out = $block_ui->render_block_widget( '<p>plain</p>', array( 'blockName' => 'core/paragraph' ) );
check( 'non-woocommerce block untouched', '<p>plain</p>' === $out );

// Duplicate-guard across the two render paths: a fresh instance renders
// the classic mini-cart action once, then a second injection through the
// block path is suppressed (one widget per page location).
$guard_ui = new \GoalCart\Frontend\ProgressUI( $settings );

ob_start();
$guard_ui->render_mini_cart_widget();
$mini_out = ob_get_clean();
check( 'mini-cart widget renders exactly once through the classic action', 1 === substr_count( $mini_out, 'id="goalcart-mini-cart"' ) );

$out = $guard_ui->render_block_widget( '<p>late</p>', array( 'blockName' => 'woocommerce/mini-cart' ) );
check( 'duplicate-guard suppresses the second mini-cart widget', '<p>late</p>' === $out );

// ---------------------------------------------------------------------------
// 10. Gutenberg block (P21 — goalcart/progress)
// ---------------------------------------------------------------------------
echo "\n== 10. Gutenberg block ==\n";

$ui->register_block();

check(
	'block type registered',
	class_exists( 'WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( \GoalCart\Frontend\ProgressUI::BLOCK )
);

$block_type = class_exists( 'WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( \GoalCart\Frontend\ProgressUI::BLOCK ) : null;

if ( $block_type ) {
	check( 'block has a render callback', is_callable( $block_type->render_callback ) );
	check( 'block api version 2', isset( $block_type->api_version ) && 2 === (int) $block_type->api_version );

	$block_out = call_user_func( $block_type->render_callback, array( 'variant' => 'compact' ), '' );
	check( 'block renders a widget container', false !== strpos( $block_out, 'data-goalcart-widget' ) );
	check( 'block container is compact', false !== strpos( $block_out, 'data-goalcart-variant="compact"' ) );
	check( 'block container id is unique', preg_match( '/id="goalcart-block-\d+"/', $block_out ) === 1 );
	check( 'block template attr passes through', false === strpos( $block_out, 'data-goalcart-template' ) );

	$block_out = call_user_func( $block_type->render_callback, array( 'variant' => 'full', 'template' => 'card' ), '' );
	check( 'block template override lands on container', false !== strpos( $block_out, 'data-goalcart-template="card"' ) );
	check( 'repeated block ids stay unique', preg_match( '/id="goalcart-block-(\d+)"/', $block_out, $m ) === 1 && $m[1] !== '1' );

	// A page carrying the block needs the storefront assets.
	$ref_block = new \ReflectionMethod( $ui, 'page_needs_widget' );
	$ref_block->setAccessible( true );

	$wpdb = $GLOBALS['wpdb'];
	$wpdb->query( 'START TRANSACTION' );

	try {
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Goal Cart block test',
			'post_content' => '<!-- wp:goalcart/progress {"variant":"compact"} /-->',
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
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "FRONTEND TEST FAILED\n" : "FRONTEND TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
