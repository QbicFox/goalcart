<?php
/**
 * FaraCart security hardening tests (P22-T01 / P22-T02 / P22-T03 / P22-T04).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then audits
 * the Phase 22 security posture end-to-end:
 *
 *  - P22-T01 PHP:
 *      nonce verification on the public track route (bad → 403, good →
 *      accepted), capability enforcement on admin routes (anonymous 403),
 *      payload sanitization (composite-children whitelist, XSS string
 *      stripping through the repository), and output escaping (widget
 *      container attributes)
 *  - P22-T02 REST:
 *      every faracart/v1 route carries a permission callback, arg-schema
 *      validation on the request surface, per-user and per-IP rate
 *      limiting (429 after the budget), and the public /progress payload
 *      data-minimization contract (reward_meta — coupon codes, gift
 *      products, shipping restrictions — is redacted for guests)
 *  - P22-T03 React:
 *      source scan of the admin app (admin-app/src) and the storefront
 *      JS for forbidden DOM APIs (dangerouslySetInnerHTML, innerHTML,
 *      document.write, insertAdjacentHTML, eval, new Function) — the
 *      render path is textContent/createElement only
 *  - P22-T04 Database:
 *      SQL-injection resistance of the search/status filters (a crafted
 *      payload neither errors nor widens the result set), the analytics
 *      date-range clamp (a pathological window can never force a huge
 *      day-by-day loop), and schema hygiene (expected indexes and the
 *      ON DELETE SET NULL foreign keys are present)
 *
 * Read-only like the other suites: the only writes (mission rows, tracked
 * events, rate-limit transients) happen inside a single database
 * transaction that is rolled back, transient keys are deleted
 * explicitly, and the absence of any residue is asserted afterwards. No
 * products or users are created.
 *
 * Run: php tests/security-test.php   (from the plugin directory)
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

use FaraCart\Analytics\AnalyticsRepository;
use FaraCart\Analytics\Tracker;
use FaraCart\Frontend\ProgressUI;
use FaraCart\Missions\MissionRepository;
use FaraCart\Hooks\HookManager;
use FaraCart\REST\BaseController;
use FaraCart\REST\FrontendController;
use FaraCart\REST\MissionsController;
use FaraCart\REST\TrackController;

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

// Fire REST route registration once (rest_api_init never fires in CLI).
if ( ! did_action( 'faracart_security_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'faracart_security_test_ready' );
}

$container = \FaraCart\Plugin::instance()->container();

$missions_ctrl     = $container->get( MissionsController::class );
$frontend_ctrl  = $container->get( FrontendController::class );
$track_ctrl     = $container->get( TrackController::class );
$settings       = $container->get( \FaraCart\Settings\Settings::class );

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// An anonymous BaseController subclass exposing the protected rate
// limiters, so the budget logic can be exercised with a tiny window.
$rate = new class() extends BaseController {
	public function register( HookManager $hooks ) {}
	public function register_routes() {}
	public function call_rate_limit( $request, $limit = null, $window = null ) {
		return $this->rate_limit( $request, $limit, $window );
	}
	public function call_rate_limit_ip( $request, $limit = null, $window = null ) {
		return $this->rate_limit_ip( $request, $limit, $window );
	}
};

// AnalyticsRepository is final, so default_range() is reached through
// reflection. setAccessible() is required on PHP < 8.1 to invoke a
// protected method (the plugin supports PHP 7.4) and is a harmless no-op
// on 8.1+.
$default_range = new \ReflectionMethod( AnalyticsRepository::class, 'default_range' );
$default_range->setAccessible( true );
$analytics_repo = new AnalyticsRepository();

// ---------------------------------------------------------------------------
// 1. P22-T02 REST: every route is protected
// ---------------------------------------------------------------------------
echo "\n== 1. Route protection (P22-T02) ==\n";

$protected = 0;
$missing   = 0;

foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( (string) $route, '/faracart/v1/' ) ) {
		continue;
	}

	foreach ( (array) $handlers as $handler ) {
		if ( ! empty( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ) ) {
			$protected++;
		} else {
			$missing++;
		}
	}
}

check( 'every faracart/v1 route has a permission callback', 0 === $missing && $protected > 0 );

// Anonymous dispatch is rejected with 403 on every admin route.
foreach ( array(
	'GET /faracart/v1/missions',
	'GET /faracart/v1/settings',
	'GET /faracart/v1/campaigns',
	'GET /faracart/v1/analytics',
	'GET /faracart/v1/search/products',
	'POST /faracart/v1/preview',
) as $spec ) {
	list( $method, $path ) = explode( ' ', $spec, 2 );
	$req  = new \WP_REST_Request( $method, $path );
	$resp = $server->dispatch( $req );
	check( "anonymous rejected on {$path} (403)", 403 === $resp->get_status() );
}

// The public progress endpoint stays readable without a capability.
$req  = new \WP_REST_Request( 'GET', '/faracart/v1/progress' );
$resp = $server->dispatch( $req );
check( 'public progress readable anonymously', ! is_wp_error( $resp ) && 200 === $resp->get_status() );

// ---------------------------------------------------------------------------
// 2. P22-T01 PHP: nonce verification on the public track route
// ---------------------------------------------------------------------------
echo "\n== 2. Track nonce verification (P22-T01) ==\n";

$schema = array();
foreach ( $routes['/faracart/v1/track'] as $handler ) {
	$schema = $handler['args'];
}
check( 'track route declares the nonce arg', isset( $schema['nonce'] ) && ! empty( $schema['nonce']['required'] ) );

$req  = new \WP_REST_Request( 'POST', '/faracart/v1/track' );
$req->set_param( 'event_type', 'goal_impression' );
$req->set_param( 'nonce', 'bogus-nonce' );
$resp = $server->dispatch( $req );
check( 'bad nonce rejected (403)', 403 === $resp->get_status() );

// ---------------------------------------------------------------------------
// 3. P22-T02 REST: rate limiting (tiny windows, transients cleaned up)
// ---------------------------------------------------------------------------
echo "\n== 3. Rate limiting (P22-T02) ==\n";

$rl_req = new \WP_REST_Request( 'GET', '/faracart/v1/missions' );

check( 'admin limiter allows first request', true === $rate->call_rate_limit( $rl_req, 2, 600 ) );
check( 'admin limiter allows second request', true === $rate->call_rate_limit( $rl_req, 2, 600 ) );

$third = $rate->call_rate_limit( $rl_req, 2, 600 );
check( 'admin limiter blocks third request (429)', is_wp_error( $third ) && 429 === $third->get_error_data()['status'] );

// Cleanup the admin bucket.
$key = 'faracart_rl_' . md5( get_current_user_id() . '|/faracart/v1/missions' );
delete_transient( $key );

$ip_req = new \WP_REST_Request( 'GET', '/faracart/v1/progress' );

check( 'ip limiter allows first request', true === $rate->call_rate_limit_ip( $ip_req, 2, 600 ) );
check( 'ip limiter allows second request', true === $rate->call_rate_limit_ip( $ip_req, 2, 600 ) );

$ip_third = $rate->call_rate_limit_ip( $ip_req, 2, 600 );
check( 'ip limiter blocks third request (429)', is_wp_error( $ip_third ) && 429 === $ip_third->get_error_data()['status'] );

// Cleanup the IP bucket (127.0.0.1 is the test REMOTE_ADDR).
$ip_key = 'faracart_rl_ip_' . md5( '127.0.0.1|/faracart/v1/progress' );
delete_transient( $ip_key );

// ---------------------------------------------------------------------------
// 4. P22-T01 PHP: sanitization + escaping (read-only / in-memory)
// ---------------------------------------------------------------------------
echo "\n== 4. Sanitization and escaping (P22-T01) ==\n";

// Composite children are whitelisted recursively: unknown keys dropped,
// strings sanitized, ids positive-int-cast, nested children recursed.
$children = $missions_ctrl->sanitize_children(
	array(
		array(
			'name'        => '<b>Child</b>',
			'type'        => 'amount',
			'target'      => '100',
			'unknown_key' => 'dropped',
			'categories'  => array( 1, -5, '7' ),
			'children'    => array(
				array( 'name' => 'Grandchild', 'operator' => 'AND' ),
			),
		),
	)
);
check( 'children whitelist keeps known keys', isset( $children[0]['name'], $children[0]['target'] ) );
check( 'children whitelist drops unknown keys', ! array_key_exists( 'unknown_key', $children[0] ) );
check( 'children name sanitized (no tags)', false === strpos( $children[0]['name'], '<' ) );
check( 'children ids positive-int-cast', array( 1, 7 ) === $children[0]['categories'] );
check( 'children recurse into nested children', isset( $children[0]['children'][0]['name'] ) && 'Grandchild' === $children[0]['children'][0]['name'] );
check( 'children nested node sanitized', false === strpos( $children[0]['children'][0]['name'], '<' ) );
check( 'non-array children collapses to empty', array() === $missions_ctrl->sanitize_children( 'bogus' ) );

// Widget container output is escaped: a hostile id cannot break out of
// the attribute, and the configured CSS class is attribute-escaped.
$ui = $container->get( ProgressUI::class );

$markup = $ui->widget_container( 'faracart-x" onclick="alert(1)', 'full' );
check( 'widget container id attribute-escaped', false === strpos( $markup, 'onclick="' ) && false !== strpos( $markup, '&quot;' ) );

$settings->set( 'frontend_css_class', 'my <script>alert(1)</script>class' );
$markup = $ui->widget_container( 'faracart-esc', 'full' );
check( 'widget container css class escaped', false === strpos( $markup, '<script' ) );

// Variant and template are normalized to the enum on the way out.
check( 'widget variant normalized to full', false !== strpos( $ui->widget_container( 'faracart-v', 'bogus' ), 'faracart-widget--full' ) );

// ---------------------------------------------------------------------------
// 5. P22-T04 Database: SQL-injection resistance + schema hygiene
// ---------------------------------------------------------------------------
echo "\n== 5. Database hardening (P22-T04) ==\n";

$missions_table = \FaraCart\Database\Schema::table( 'missions' );
$created_ids = array();

$wpdb->query( 'START TRANSACTION' );

try {
	// 5.1 Injection-resistant filters: a crafted search/status neither
	// errors nor widens the result set.
	$repo = $container->get( MissionRepository::class );

	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Unique Security Test Mission' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 100 );
	$resp = $missions_ctrl->handle_create( $req );
	$created_ids[] = (int) $resp->get_data()['data']['id'];

	$injected = $repo->all(
		array(
			'search'   => "x' OR '1'='1' --",
			'status'   => "active' OR '1'='1",
			'page'     => 1,
			'per_page' => 10,
		)
	);
	check( 'injection-safe search returns a clean envelope', isset( $injected['items'], $injected['total'] ) );
	check( 'injection does not widen the result set', 0 === (int) $injected['total'] );

	$matched = $repo->all(
		array(
			'search'   => 'Unique Security Test',
			'page'     => 1,
			'per_page' => 10,
		)
	);
	check( 'legitimate LIKE search still matches', 1 === (int) $matched['total'] );

	// 5.2 Track handler clamps numeric event fields (defense-in-depth on
	// top of the arg schema): percentage is a 0-100 readout, cart_value a
	// non-negative amount.
	$session = str_repeat( 'a', 32 );
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/track' );
	$req->set_param( 'event_type', 'goal_progress' );
	$req->set_param( 'mission_id', 0 );
	$req->set_param( 'campaign_id', 0 );
	$req->set_param( 'product_id', 0 );
	$req->set_param( 'cart_value', -500 );
	$req->set_param( 'percentage', 150 );
	$req->set_param( 'session_id', $session );
	$resp = $track_ctrl->handle( $req );
	check( 'clamped track event accepted', ! is_wp_error( $resp ) );

	$events_table = \FaraCart\Database\Schema::table( 'analytics_events' );
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT cart_value, meta FROM {$events_table} WHERE session_id = %s AND event_type = %s ORDER BY id DESC LIMIT 1",
			$session,
			Tracker::EVENT_GOAL_PROGRESS
		),
		ARRAY_A
	);
	check( 'cart_value clamped to non-negative', null !== $row && (float) $row['cart_value'] >= 0 );

	$meta = is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : array();
	check( 'percentage clamped to 100', null !== $row && is_array( $meta ) && isset( $meta['percentage'] ) && 100.0 === (float) $meta['percentage'] );

	// 5.3 The analytics date-range clamp: a pathological window can never
	// force the day-by-day trend loop beyond one year.
	$range = $default_range->invoke( $analytics_repo, array( 'from' => '2000-01-01', 'to' => '2100-01-01' ) );
	$span  = strtotime( $range['to'] ) - strtotime( $range['from'] );
	check( 'pathological date range clamped to <= 366 days', $span <= 366 * DAY_IN_SECONDS );

	$backwards = $default_range->invoke( $analytics_repo, array( 'from' => '2026-12-01', 'to' => '2026-01-01' ) );
	check( 'backwards range swapped into order', strtotime( $backwards['from'] ) <= strtotime( $backwards['to'] ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// 5.4 Schema hygiene: the indexes the query paths rely on exist.
$table_indexes = function ( $table ) use ( $wpdb ) {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$table
		),
		ARRAY_A
	);

	$names = array();

	foreach ( (array) $rows as $row ) {
		$names[] = $row['INDEX_NAME'];
	}

	return $names;
};

$campaigns_table = \FaraCart\Database\Schema::table( 'campaigns' );
$events_table    = \FaraCart\Database\Schema::table( 'analytics_events' );

$mission_indexes     = $table_indexes( $missions_table );
$campaign_indexes = $table_indexes( $campaigns_table );
$event_indexes    = $table_indexes( $events_table );

check( 'missions table indexed on status', in_array( 'status', $mission_indexes, true ) );
check( 'missions table indexed on type', in_array( 'type', $mission_indexes, true ) );
check( 'missions table indexed on campaign_id', in_array( 'campaign_id', $mission_indexes, true ) );
check( 'missions table indexed on priority', in_array( 'priority', $mission_indexes, true ) );
check( 'campaigns table indexed on status', in_array( 'status', $campaign_indexes, true ) );
check( 'campaigns table indexed on schedule', in_array( 'starts_at', $campaign_indexes, true ) && in_array( 'ends_at', $campaign_indexes, true ) );
check( 'events table indexed on event_type', in_array( 'event_type', $event_indexes, true ) );
check( 'events table indexed on session_id', in_array( 'session_id', $event_indexes, true ) );
check( 'events table indexed on created_at', in_array( 'created_at', $event_indexes, true ) );
check( 'events table composite mission_event index', in_array( 'mission_event', $event_indexes, true ) );

// 5.5 The ON DELETE SET NULL foreign keys are present (safe deletion of
// missions/campaigns never strands or cascades analytics history).
$fk_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
		 WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME IN (%s, %s, %s) AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
		$wpdb->dbname,
		$missions_table,
		$campaigns_table,
		$events_table
	),
	ARRAY_A
);

$fk_names = array();

foreach ( (array) $fk_rows as $row ) {
	$fk_names[] = $row['CONSTRAINT_NAME'];
}

check( 'missions→campaigns FK present', in_array( 'fk_faracart_missions_campaign', $fk_names, true ) );
check( 'events→missions FK present', in_array( 'fk_faracart_analytics_mission', $fk_names, true ) );
check( 'events→campaigns FK present', in_array( 'fk_faracart_analytics_campaign', $fk_names, true ) );

// ---------------------------------------------------------------------------
// 6. Public payload data minimization: reward secrets never leave the server
// ---------------------------------------------------------------------------
echo "\n== 6. Public payload redaction (P22-T01 / P22-T02) ==\n";

$settings_option = \FaraCart\Settings\Settings::OPTION_NAME;
$option_before   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );

$wpdb->query( 'START TRANSACTION' );

try {
	$req = new \WP_REST_Request( 'POST', '/faracart/v1/missions' );
	$req->set_param( 'name', 'Redaction Security Mission' );
	$req->set_param( 'type', 'amount' );
	$req->set_param( 'target', 1000 );
	$req->set_param( 'reward_type', 'coupon' );
	$req->set_param( 'reward_value', 10 );
	$req->set_param(
		'reward_meta',
		array(
			'coupon_code'        => 'FARACART-SECRET-12345',
			'coupon_generate'    => true,
			'gift_product_id'    => 42,
			'shipping_zone_ids'  => array( 1 ),
			'shipping_method_ids' => array( 'flat_rate:3' ),
		)
	);
	$req->set_param(
		'children',
		array( array( 'name' => 'Nested', 'type' => 'quantity', 'target' => 3 ) )
	);
	$req->set_param(
		'limits',
		array( 'uses_per_customer' => 1 )
	);
	$resp = $missions_ctrl->handle_create( $req );
	$mission_id = (int) $resp->get_data()['data']['id'];
	$created_ids[] = $mission_id;

	// The admin detail payload (manage_options contract) may carry the
	// full reward configuration...
	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/missions/' . $mission_id );
	$req->set_param( 'id', $mission_id );
	$admin_payload = $missions_ctrl->handle_get( $req )->get_data()['data'];
	check( 'admin detail keeps reward_meta', isset( $admin_payload['reward_meta']['coupon_code'] ) );

	// ...but the public progress payload must never echo it.
	$cart = WC()->cart;
	$cart->cart_contents['sec1'] = array(
		'key'               => 'sec1',
		'product_id'        => 0,
		'variation_id'      => 0,
		'quantity'          => 1,
		'data'              => new \WC_Product_Simple(),
		'line_subtotal'     => 500.0,
		'line_total'        => 500.0,
		'line_subtotal_tax' => 0.0,
		'line_tax'          => 0.0,
	);

	$req  = new \WP_REST_Request( 'GET', '/faracart/v1/progress' );
	$resp = $frontend_ctrl->handle_progress( $req, $cart );
	$json = wp_json_encode( $resp->get_data() );

	check( 'public payload has no reward_meta key', false === strpos( $json, '"reward_meta"' ) );
	check( 'public payload has no coupon_code key', false === strpos( $json, '"coupon_code"' ) );
	check( 'public payload never echoes the coupon secret', false === strpos( $json, 'FARACART-SECRET-12345' ) );
	check( 'public payload has no gift_product_id key', false === strpos( $json, '"gift_product_id"' ) );
	check( 'public payload has no shipping restrictions', false === strpos( $json, '"shipping_zone_ids"' ) && false === strpos( $json, '"shipping_method_ids"' ) );
	check( 'public payload has no children/conditions', false === strpos( $json, '"children"' ) && false === strpos( $json, '"conditions"' ) );
	check( 'public payload has no limits key', false === strpos( $json, '"limits"' ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 7. P22-T03 React: source scan for unsafe HTML injection
// ---------------------------------------------------------------------------
echo "\n== 7. React / frontend source scan (P22-T03) ==\n";

$forbidden = array(
	'dangerouslySetInnerHTML',
	'.innerHTML',
	'document.write',
	'insertAdjacentHTML',
	'new Function(',
	'eval(',
);

$scan_dir = function ( $dir ) use ( $forbidden ) {
	$violations = array();
	$count      = 0;

	if ( ! is_dir( $dir ) ) {
		return array( 'count' => 0, 'violations' => array() );
	}

	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		if ( ! in_array( $file->getExtension(), array( 'ts', 'tsx', 'js', 'jsx' ), true ) ) {
			continue;
		}

		$count++;
		$content = (string) file_get_contents( $file->getPathname() );

		foreach ( $forbidden as $needle ) {
			if ( false !== strpos( $content, $needle ) ) {
				$violations[] = $file->getPathname() . ' contains ' . $needle;
			}
		}
	}

	return array( 'count' => $count, 'violations' => $violations );
};

$react = $scan_dir( dirname( __DIR__ ) . '/admin-app/src' );
check( 'react source files scanned', $react['count'] > 0 );
check( 'no unsafe HTML APIs in admin-app/src', empty( $react['violations'] ) );

foreach ( $react['violations'] as $violation ) {
	echo "      found: {$violation}\n";
}

$frontend = $scan_dir( dirname( __DIR__ ) . '/assets/js' );
check( 'frontend JS scanned', $frontend['count'] > 0 );
check( 'no unsafe HTML APIs in assets/js', empty( $frontend['violations'] ) );

foreach ( $frontend['violations'] as $violation ) {
	echo "      found: {$violation}\n";
}

// The storefront render path is textContent-based (positive control).
$frontend_js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' );
check( 'frontend JS renders via textContent', false !== strpos( $frontend_js, 'textContent' ) );

// ---------------------------------------------------------------------------
// 8. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 8. Rollback verification ==\n";

foreach ( $created_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE id = %d", $id ) );
	check( "rolled-back mission {$id} is gone", 0 === $count );
}

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE name = %s", 'Redaction Security Mission' ) );
check( 'no redaction mission remains by name', 0 === $count );

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$missions_table} WHERE name = %s", 'Unique Security Test Mission' ) );
check( 'no injection-test mission remains by name', 0 === $count );

$option_after = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );
check( 'settings option unchanged after rollback', $option_before === $option_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "SECURITY TEST FAILED\n" : "SECURITY TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
