<?php
/**
 * FaraCart Phase 33.7 (Frontend Upsell Integration) tests.
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then
 * exercises the storefront upsell surface end to end:
 *
 *  - route registration for the public `GET /faracart/v1/upsell/rank`
 *  - the rank arg schema (mission_id / limit / cart bounds)
 *  - public access: an anonymous GET dispatches with 200 (no capability)
 *  - the payload shape (available + context + per-product score
 *    breakdowns, reasons, catalog fields)
 *  - mission gap calculation against the LIVE cart: a money mission target
 *    minus the evaluated cart value — the storefront sends only
 *    mission_id + limit, the server derives the gap (never trusted from
 *    the client)
 *  - graceful degradation: closed gap / no mission / disabled filter all
 *    return an unavailable payload with a reason — never a fabricated
 *    list
 *  - the storefront config (`ProgressUI::frontend_config()`) carries
 *    the upsell block (rank + track endpoints, limit, localized labels)
 *    and withholds it while analytics are off
 *  - the frontend asset wiring: the panel component, the upsell track
 *    reporter and the mobile/theme-safe styles
 *
 * Read-only like the other suites: the only writes (fixture products, a
 * mission row, the live cart, cache invalidation) happen inside a single
 * database transaction that is rolled back, the live cart is emptied,
 * and the absence of any residue is asserted afterwards.
 *
 * Run: php tests/upsell-frontend-test.php   (from the plugin directory)
 */

// Locate wp-load.php by walking up from this file.
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

// Locale-independent reason assertions: force en_US and unload the
// faracart domain (the same convention message-test/upsell-test use) so
// the 'closed' / 'disabled' reason substrings the suite asserts stay
// English. The hard-block keeps the just-in-time loader from reloading
// the fa_IR .mo when the locale stack pops back mid-suite.
switch_to_locale( 'en_US' );
unload_textdomain( 'faracart' );
$GLOBALS['l10n_unloaded']['faracart'] = true;

use FaraCart\Analytics\RevenueRepository;
use FaraCart\Analytics\UpsellRanker;
use FaraCart\Database\Installer;
use FaraCart\Database\Schema;
use FaraCart\Frontend\ProgressUI;
use FaraCart\REST\UpsellController;

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

function close( $a, $b, $eps = 0.01 ) {
	return abs( (float) $a - (float) $b ) < $eps;
}

function route_exists( $routes, $pattern ) {
	return isset( $routes[ $pattern ] );
}

// The Phase 33 tables are created by Installer::maybe_upgrade(), which
// runs on plugins_loaded / admin_init — neither fires in CLI after
// wp-load.
Installer::maybe_create_tables();

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'faracart_rest_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'faracart_rest_test_ready' );
}

$container = \FaraCart\Plugin::instance()->container();

$controller = $container->get( UpsellController::class );
$ranker     = $container->get( UpsellRanker::class );
$repo       = $container->get( RevenueRepository::class );
$settings   = $container->get( \FaraCart\Settings\Settings::class );
$missions      = $container->get( \FaraCart\Missions\MissionRepository::class );
$ui         = $container->get( ProgressUI::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Route registration
// ---------------------------------------------------------------------------
echo "\n== 1. Route registration ==\n";

check( '/faracart/v1/upsell/rank registered', route_exists( $routes, '/faracart/v1/upsell/rank' ) );
check( '/faracart/v1/upsell/track registered', route_exists( $routes, '/faracart/v1/upsell/track' ) );
check( 'rank route declared READABLE', ! empty( $routes['/faracart/v1/upsell/rank'][0]['methods'] ) );

// ---------------------------------------------------------------------------
// 2. Arg-schema validation
// ---------------------------------------------------------------------------
echo "\n== 2. Input validation ==\n";

$rank_args = $controller->rank_args();

check( 'rank mission_id rejects negatives', is_wp_error( rest_validate_value_from_schema( -1, $rank_args['mission_id'], 'mission_id' ) ) );
check( 'rank mission_id accepts zero', true === rest_validate_value_from_schema( 0, $rank_args['mission_id'], 'mission_id' ) );
check( 'rank limit rejects zero', is_wp_error( rest_validate_value_from_schema( 0, $rank_args['limit'], 'limit' ) ) );
check( 'rank limit rejects > 10', is_wp_error( rest_validate_value_from_schema( 11, $rank_args['limit'], 'limit' ) ) );
check( 'rank limit accepts 3', true === rest_validate_value_from_schema( 3, $rank_args['limit'], 'limit' ) );
check( 'rank cart rejects non-array', is_wp_error( rest_validate_value_from_schema( 'abc', $rank_args['cart'], 'cart' ) ) );
check( 'rank cart accepts an id list', true === rest_validate_value_from_schema( array( 12, 34 ), $rank_args['cart'], 'cart' ) );
check( 'rank remaining rejects negatives', is_wp_error( rest_validate_value_from_schema( -5, $rank_args['remaining'], 'remaining' ) ) );

// ---------------------------------------------------------------------------
// 3. Public access
// ---------------------------------------------------------------------------
echo "\n== 3. Public access ==\n";

$req  = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
$resp = $server->dispatch( $req );

// No mission and no remaining → the ranker degrades gracefully, but the
// route itself must be publicly reachable (200, not 401/403).
check( 'anonymous rank dispatch is allowed (200)', 200 === $resp->get_status() );
check( 'rank payload wraps in data', isset( $resp->get_data()['data'] ) );

$data = $resp->get_data()['data'];
check( 'rank payload exposes availability', array_key_exists( 'available', $data ) );
check( 'rank payload exposes status', array_key_exists( 'status', $data ) );
check( 'rank payload exposes a context echo', isset( $data['context'] ) );
check( 'rank payload exposes the score weights', is_array( $data['weights'] ?? null ) );
check( 'no explicit mission falls back to the featured active mission', isset( $data['context']['mission_id'] ) && (int) $data['context']['mission_id'] > 0 );

// A nonexistent mission id can never produce a fabricated list.
$ghost_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
$ghost_req->set_param( 'mission_id', 99999999 );
$ghost_data = $server->dispatch( $ghost_req )->get_data()['data'];
check( 'unknown mission → unavailable with a reason', empty( $ghost_data['available'] ) && '' !== (string) $ghost_data['reason'] && 0 === count( $ghost_data['recommendations'] ) );

// ---------------------------------------------------------------------------
// 4. Transactional fixtures: live-cart gap + ranking + degradation
// ---------------------------------------------------------------------------
echo "\n== 4. Live-cart fixtures (rolled back) ==\n";

$missions_table     = Schema::table( 'missions' );
$upsell_events_t = Schema::table( 'upsell_events' );
$missions_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" );
$events_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_events_t}" );

// Cache-generation baseline BEFORE the transaction: product saves fire
// save_post_product → RevenueRepository::invalidate().
$version_start = (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 );

$wpdb->query( 'START TRANSACTION' );

try {
	$mission_id = $missions->create(
		array(
			'name'             => 'P33.7 Frontend Upsell Mission',
			'type'             => 'amount',
			'target'           => 2000000,
			'status'           => 'active',
			'calculation_mode' => 'subtotal',
		)
	);
	check( 'fixture mission created', $mission_id > 0 );

	$make_product = function ( $title, $price, $stock = null ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);
		$product = wc_get_product( $post_id );
		$product->set_regular_price( (string) $price );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}

		$product->save();

		return $product;
	};

	$gap_product    = $make_product( 'P33.7 Gap Filler', 490000, 50 );  // ratio 1.09 → sweet band.
	$expensive      = $make_product( 'P33.7 Expensive', 1800000, 10 );  // ratio 4.0 → hard decay.
	$in_cart_product = $make_product( 'P33.7 Already In Cart', 300000, 20 );
	$out_of_stock   = $make_product( 'P33.7 Sold Out', 450000, 0 );
	$out_of_stock->set_stock_status( 'outofstock' );
	$out_of_stock->save();

	$product_ids = array(
		$gap_product->get_id(),
		$expensive->get_id(),
		$in_cart_product->get_id(),
		$out_of_stock->get_id(),
	);
	check( 'fixture products created', 4 === count( $product_ids ) );

	// Live cart: the in-cart product (300,000) is the only item, so the
	// money mission's current = 300,000 and the remaining gap = 1,700,000.
	$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;
	check( 'live cart available', $cart instanceof \WC_Cart );

	if ( $cart instanceof \WC_Cart ) {
		$added = $cart->add_to_cart( $in_cart_product->get_id(), 1 );
		check( 'live cart holds the fixture product', false !== $added && 1 === $cart->get_cart_contents_count() );
	}

	// --- Live-cart gap: the storefront sends ONLY mission_id + limit. ---
	$live_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
	$live_req->set_param( 'mission_id', (int) $mission_id );
	$live_req->set_param( 'limit', 5 );
	$live_resp = $controller->handle_rank( $live_req );
	$live      = $live_resp->get_data()['data'];

	check( 'live-cart ranking is available', ! empty( $live['available'] ) );
	check(
		'live-cart gap computed server-side (target − cart value)',
		close( 1700000, $live['context']['remaining'] )
	);
	check(
		'live-cart value derived from the cart',
		close( 300000, $live['context']['cart_value'] )
	);
	check( 'context echoes the live cart product ids', in_array( $in_cart_product->get_id(), $live['context']['cart'], true ) );

	$live_ids = array_map( function ( $item ) {
		return (int) $item['product_id'];
	}, $live['recommendations'] );

	check( 'sold-out product excluded from recommendations', ! in_array( $out_of_stock->get_id(), $live_ids, true ) );
	check( 'gap-filler product is recommended', in_array( $gap_product->get_id(), $live_ids, true ) );

	$top = isset( $live['recommendations'][0] ) ? $live['recommendations'][0] : null;
	check( 'product row exposes catalog fields', null !== $top && isset( $top['product_id'], $top['name'], $top['price_html'], $top['permalink'] ) );
	check( 'product row exposes the score breakdown', null !== $top && isset( $top['components']['price_gap'], $top['components']['relevance'], $top['components']['popularity'], $top['components']['inventory'], $top['components']['margin'], $top['components']['conversion'] ) );
	check( 'product row exposes plain-English reasons', null !== $top && is_array( $top['reasons'] ) && count( $top['reasons'] ) >= 1 );
	check( 'product row exposes historical conversion stats', null !== $top && isset( $top['conversion']['impressions'], $top['conversion']['orders'] ) );

	// P22-style redaction: even when the store stores product costs, the
	// PUBLIC payload must never carry the margin/profit data — the admin
	// surfaces keep it behind manage_options.
	$costs_product = wc_get_product( $gap_product->get_id() );
	$costs_product->update_meta_data( '_cost', '245000' );
	$costs_product->save();

	$costs = $container->get( \FaraCart\Analytics\RewardCostEstimator::class );
	$margin = $costs->product_margin( $gap_product->get_id() );
	check( 'fixture product margin is readable server-side', null !== $margin );

	$public_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
	$public_req->set_param( 'mission_id', (int) $mission_id );
	$public_req->set_param( 'cart_value', 1550000 );
	$public = $controller->handle_rank( $public_req )->get_data()['data'];
	$public_top = isset( $public['recommendations'][0] ) ? $public['recommendations'][0] : null;

	check( 'public payload strips estimated_profit', null !== $public_top && null === $public_top['estimated_profit'] );
	check( 'public payload strips profit_available', null !== $public_top && false === $public_top['profit_available'] );
	check( 'public payload strips factors.margin_pct', null !== $public_top && null === ( $public_top['factors']['margin_pct'] ?? null ) );
	check( 'public payload drops the margin reason bullets', null !== $public_top && 0 === count( array_filter( $public_top['reasons'], function ( $r ) {
		return false !== stripos( (string) $r, 'margin' );
	} ) ) );

	// The admin detail endpoint still exposes the margin (proving the data
	// exists and only the public route redacts it).
	$admin_detail = $repo->upsell_product_detail( $gap_product->get_id(), array( 'remaining' => 450000 ) );
	check( 'admin detail still exposes the margin', null !== $admin_detail && ! empty( $admin_detail['profit_available'] ) && null !== $admin_detail['estimated_profit'] );

	// --- Explicit context override (embedded consumers / tests). ---
	$override_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
	$override_req->set_param( 'mission_id', (int) $mission_id );
	$override_req->set_param( 'cart_value', 1550000 );
	$override_req->set_param( 'cart', array( $in_cart_product->get_id() ) );
	$override = $controller->handle_rank( $override_req )->get_data()['data'];

	check( 'explicit cart_value derives the same gap', close( 450000, $override['context']['remaining'] ) );
	check( 'explicit cart ids echoed back', in_array( $in_cart_product->get_id(), $override['context']['cart'], true ) );

	// --- Closed gap → unavailable, never a fabricated list. ---
	$closed_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
	$closed_req->set_param( 'mission_id', (int) $mission_id );
	$closed_req->set_param( 'cart_value', 2000000 );
	$closed = $controller->handle_rank( $closed_req )->get_data()['data'];

	check( 'closed gap → unavailable', empty( $closed['available'] ) && 0 === count( $closed['recommendations'] ) );
	check( 'closed gap explains the reason', false !== strpos( (string) $closed['reason'], 'closed' ) );

	// --- Disabled filter → unavailable. ---
	add_filter( 'faracart_upsells_enabled', '__return_false' );
	$disabled_req = new \WP_REST_Request( 'GET', '/faracart/v1/upsell/rank' );
	$disabled_req->set_param( 'remaining', 450000 );
	$disabled = $controller->handle_rank( $disabled_req )->get_data()['data'];
	remove_all_filters( 'faracart_upsells_enabled' );

	check( 'disabled flag → unavailable with reason', empty( $disabled['available'] ) && false !== strpos( (string) $disabled['reason'], 'disabled' ) );

	// The ranker never writes anything — even the live-cart fixture run.
	check( 'no upsell events written by ranking', $events_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$upsell_events_t}" ) );

	if ( $cart instanceof \WC_Cart ) {
		$cart->empty_cart();
	}

	$wpdb->query( 'ROLLBACK' );
} catch ( \Throwable $e ) {
	if ( isset( $cart ) && $cart instanceof \WC_Cart ) {
		$cart->empty_cart();
	}
	$wpdb->query( 'ROLLBACK' );
	echo 'EXCEPTION: ' . $e->getMessage() . "\n";
	$failures++;
}

// The options/transient writes happened inside the rolled-back
// transaction, but WP's in-memory caches still hold the pre-rollback
// values — flush so the verification reads see the real database.
wp_cache_flush();

// ---------------------------------------------------------------------------
// 5. Rollback verification
// ---------------------------------------------------------------------------
echo "\n== 5. Rollback verification ==\n";

check( 'missions row count unchanged after rollback', $missions_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$missions_table}" ) );
check( 'cache generation returns to the pre-test baseline', $version_start === (int) get_option( RevenueRepository::CACHE_VERSION_OPTION, 1 ) );

$leftover_products = get_posts(
	array(
		'post_type'   => 'product',
		'post_status' => 'publish',
		'fields'      => 'ids',
		'numberposts' => -1,
		'title'       => 'P33.7 ',
	)
);
check( 'no P33.7 fixture products remain', 0 === count( $leftover_products ) );

// ---------------------------------------------------------------------------
// 6. Storefront config (Phase 33.7 frontend contract)
// ---------------------------------------------------------------------------
echo "\n== 6. Storefront config ==\n";

$analytics_before = $settings->get( 'analytics_enabled', true );
$enabled_before   = $settings->get( 'enabled', true );
$settings->set( 'analytics_enabled', true );
$settings->set( 'enabled', true );

try {
	$config = $ui->frontend_config();
	$upsell = $config['upsells'] ?? null;

	check( 'config carries the upsell block', is_array( $upsell ) );
	check( 'upsell block enabled by default', ! empty( $upsell['enabled'] ) );
	check( 'upsell block has the public rank endpoint', isset( $upsell['endpoint'] ) && false !== strpos( $upsell['endpoint'], '/faracart/v1/upsell/rank' ) );
	check( 'upsell block has the track endpoint', isset( $upsell['trackEndpoint'] ) && false !== strpos( $upsell['trackEndpoint'], '/faracart/v1/upsell/track' ) );
	check( 'upsell block limit is 1–6', isset( $upsell['limit'] ) && (int) $upsell['limit'] >= 1 && (int) $upsell['limit'] <= 6 );
	check( 'upsell labels cover the panel strings', isset( $upsell['labels']['heading'], $upsell['labels']['add'], $upsell['labels']['adding'], $upsell['labels']['added'], $upsell['labels']['unavailable'] ) );

	// The panel gate mirrors the ranker gate: analytics off → disabled
	// (the JS then renders no panel).
	$settings->set( 'analytics_enabled', false );
	$off = $ui->frontend_config()['upsells'] ?? null;
	check( 'upsells disabled when analytics are off', is_array( $off ) && empty( $off['enabled'] ) && '' === $off['endpoint'] );
} finally {
	$settings->set( 'analytics_enabled', $analytics_before );
	$settings->set( 'enabled', $enabled_before );
}

// ---------------------------------------------------------------------------
// 7. Frontend asset wiring
// ---------------------------------------------------------------------------
echo "\n== 7. Frontend asset wiring ==\n";

$frontend_js = (string) file_get_contents( FARACART_PATH . 'assets/js/frontend.js' );
$frontend_css = (string) file_get_contents( FARACART_PATH . 'assets/css/frontend.css' );

check( 'frontend JS defines the upsell panel component', false !== strpos( $frontend_js, 'function upsellPanel' ) );
check( 'frontend JS reports upsell events to the upsell track endpoint', false !== strpos( $frontend_js, 'function sendUpsellTrack' ) && false !== strpos( $frontend_js, 'upsells.trackEndpoint' ) );
check( 'frontend JS reports upsell_impression once per mission+product', false !== strpos( $frontend_js, 'reportedUpsellImpressions' ) && false !== strpos( $frontend_js, "'upsell_impression'" ) );
check( 'frontend JS reports upsell_clicked on add', false !== strpos( $frontend_js, "'upsell_clicked'" ) );
check( 'frontend JS reports upsell_added after a successful add', false !== strpos( $frontend_js, "'upsell_added'" ) );
check( 'frontend JS hides the panel for completed/closed missions', false !== strpos( $frontend_js, 'mission.completed' ) && false !== strpos( $frontend_js, 'remaining' ) );
check( 'frontend JS degrades to the redirect add-to-cart without AJAX', false !== strpos( $frontend_js, 'add-to-cart=' ) );
check( 'frontend JS refreshes through the cart-changed bridge after adding', false !== strpos( $frontend_js, 'emitCartChanged' ) );
check( 'frontend JS reuses the last ranking per mission:gap', false !== strpos( $frontend_js, 'upsellRankCache' ) );
check( 'frontend JS binds the upsell panel in init', false !== strpos( $frontend_js, 'bindUpsellPanel' ) );

check( 'frontend CSS styles the upsell panel', false !== strpos( $frontend_css, '.faracart-upsells' ) );
check( 'frontend CSS styles the add button with the accent token', false !== strpos( $frontend_css, 'var(--faracart-accent)' ) );
check( 'frontend CSS is theme-safe (scoped faracart classes)', 0 === preg_match( '/\.faracart-upsells\s*,\s*(body|html|div\b)/', $frontend_css ) );
check( 'frontend CSS has the mobile horizontal strip', false !== strpos( $frontend_css, 'scroll-snap-type' ) );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "UPSELL FRONTEND TEST FAILED\n" : "UPSELL FRONTEND TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
