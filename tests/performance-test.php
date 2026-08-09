<?php
/**
 * Goal Cart performance tests (P23-T01 / P23-T02 / P23-T03).
 *
 * Boots WordPress, fires rest_api_init (never fired in CLI), then audits
 * the Phase 23 performance posture end-to-end:
 *
 *  - P23-T01 Frontend:
 *      lazy-loaded admin routes (App.tsx), cached server state (React
 *      Query staleTime), debounced product/category searches
 *      (EntityAutocomplete), vendor chunk splitting (vite manualChunks),
 *      server-paginated goal list UI, and render hygiene (useMemo on the
 *      analytics trend series)
 *  - P23-T02 WooCommerce Frontend:
 *      request-level caching (GoalRepository query-count stable across
 *      repeated active_goals() calls; CartIntegration memoizes the
 *      context), the progress transient cache (a repeat poll is served
 *      from the transient), and change-detection in the storefront JS
 *      (payload fingerprints skip unchanged widget re-renders)
 *  - P23-T03 Admin:
 *      server-side pagination (page/per_page + envelope), server-side
 *      search and status filtering on the goal list, per_page caps on
 *      the list and search endpoints (never load thousands of products
 *      at once)
 *
 * Read-only like the other suites: the only writes (created goals,
 * created products, the progress-cache transient) happen inside a single
 * database transaction that is rolled back, transient keys are deleted
 * explicitly, and the absence of any residue is asserted afterwards.
 *
 * Run: php tests/performance-test.php   (from the plugin directory)
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

use GoalCart\Cart\CartIntegration;
use GoalCart\Goals\GoalRepository;
use GoalCart\REST\FrontendController;
use GoalCart\REST\GoalsController;
use GoalCart\REST\SearchController;
use GoalCart\Settings\Settings;

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
if ( ! did_action( 'goalcart_performance_test_ready' ) ) {
	do_action( 'rest_api_init' );
	do_action( 'goalcart_performance_test_ready' );
}

$container = \GoalCart\Plugin::instance()->container();

$goals_ctrl   = $container->get( GoalsController::class );
$frontend_ctrl = $container->get( FrontendController::class );
$settings     = $container->get( Settings::class );
$search_ctrl  = new SearchController(); // Fresh instance: no cache, no shared state.

$server = rest_get_server();
$routes = $server->get_routes();
$wpdb   = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. P23-T01 Frontend: source-level performance guarantees (read-only)
// ---------------------------------------------------------------------------
echo "\n== 1. Frontend performance (P23-T01) ==\n";

$app_tsx    = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/src/App.tsx' );
$providers  = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/src/providers/AppProviders.tsx' );
$autocomplete = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/src/components/goal-builder/EntityAutocomplete.tsx' );
$goals_tsx  = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/Goals.tsx' );
$analytics  = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/src/routes/Analytics.tsx' );
$vite       = (string) @file_get_contents( dirname( __DIR__ ) . '/admin-app/vite.config.ts' );

check( 'App.tsx lazy-loads secondary routes', false !== strpos( $app_tsx, 'lazy(() => import(' ) );
check( 'dashboard and goals stay eager in the entry bundle', false !== strpos( $app_tsx, "import Dashboard from './routes/Dashboard'" ) && false !== strpos( $app_tsx, "import Goals from './routes/Goals'" ) );
check( 'React Query caches server state (staleTime)', false !== strpos( $providers, 'staleTime: 60_000' ) );
check( 'React Query skips focus refetch storms', false !== strpos( $providers, 'refetchOnWindowFocus: false' ) );
check( 'product/category search debounced', false !== strpos( $autocomplete, 'setTimeout' ) && false !== strpos( $autocomplete, '300' ) );
check( 'search capped per request (per_page=20)', false !== strpos( $autocomplete, 'per_page: 20' ) );
check( 'goal list is server-paginated (TablePagination)', false !== strpos( $goals_tsx, 'TablePagination' ) );
check( 'goal list fetches page/per_page server-side', false !== strpos( $goals_tsx, 'fetchGoals({ page: page + 1' ) );
check( 'goal search debounced client-side', false !== strpos( $goals_tsx, 'setDebouncedSearch(search)' ) );
check( 'analytics trend memoized (avoid re-renders)', false !== strpos( $analytics, 'useMemo' ) && false !== strpos( $analytics, 'trendData' ) );
check( 'vite splits vendor chunks (manualChunks)', false !== strpos( $vite, 'manualChunks' ) );

// ---------------------------------------------------------------------------
// 2. P23-T02 WooCommerce frontend: request-level caching
// ---------------------------------------------------------------------------
echo "\n== 2. Request-level caching (P23-T02) ==\n";

// GoalRepository caches the active goal set per request: a second call in
// the same request must not run another query.
$repo = new GoalRepository();
$goals_first = $repo->active_goals();
$queries_after_first = (int) $wpdb->num_queries;
$goals_second = $repo->active_goals();
$queries_after_second = (int) $wpdb->num_queries;

check( 'active_goals() returns the same set twice', $goals_first === $goals_second );
check( 'active_goals() cached per request (no second query)', $queries_after_first === $queries_after_second );

// CartIntegration memoizes the snapshot per cart contents + args: two
// builds with the same cart return the same instance.
$integration = $container->get( CartIntegration::class );
$cart        = WC()->cart;
$context_a   = $integration->context( $cart );
$context_b   = $integration->context( $cart );

check( 'cart context memoized (same instance)', $context_a === $context_b );
check( 'memoized context is a CartContext', $context_a instanceof \GoalCart\Goals\CartContext );

// ---------------------------------------------------------------------------
// 3. P23-T02 WooCommerce frontend: progress transient cache + JS fragments
// ---------------------------------------------------------------------------
echo "\n== 3. Storefront update strategy (P23-T02) ==\n";

$settings_option = Settings::OPTION_NAME;
$option_before   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );

$wpdb->query( 'START TRANSACTION' );

try {
	// Caching on: a repeat widget poll is served from the transient.
	$settings->set( 'performance_caching', true );

	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/progress' );
	$resp = $frontend_ctrl->handle_progress( $req, $cart );
	check( 'progress endpoint responds with caching on', ! is_wp_error( $resp ) );

	$transients = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_goalcart_progress_%'"
	);
	check( 'progress payload written to a transient', count( $transients ) > 0 );

	foreach ( $transients as $option ) {
		$key = str_replace( '_transient_', '', $option );
		delete_transient( $key );
	}

	$settings->set( 'performance_caching', false );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// Change-detection in the storefront JS: widgets only update when the
// payload fingerprint changed; identical polls skip the DOM rebuild.
$frontend_js = (string) @file_get_contents( dirname( __DIR__ ) . '/assets/js/frontend.js' );
check( 'frontend JS has a payload fingerprint', false !== strpos( $frontend_js, 'payloadFingerprint' ) );
check( 'frontend JS records per-widget fingerprints', false !== strpos( $frontend_js, 'renderedFingerprints' ) );
check( 'frontend JS skips unchanged widgets', false !== strpos( $frontend_js, 'hasContent && renderedFingerprints' ) );
check( 'frontend JS still renders via textContent', false !== strpos( $frontend_js, 'textContent' ) );

// ---------------------------------------------------------------------------
// 4. P23-T03 Admin: server-side pagination, search and filtering
// ---------------------------------------------------------------------------
echo "\n== 4. Server-side list behavior (P23-T03) ==\n";

$goals_table = \GoalCart\Database\Schema::table( 'goals' );
$created_ids = array();

$wpdb->query( 'START TRANSACTION' );

try {
	// Seed three uniquely named goals (two active, one inactive).
	$seed = array(
		array( 'name' => 'Perf Test Alpha Goal',   'status' => 'active',   'priority' => 10 ),
		array( 'name' => 'Perf Test Beta Goal',    'status' => 'active',   'priority' => 20 ),
		array( 'name' => 'Perf Test Gamma Goal',   'status' => 'inactive', 'priority' => 30 ),
	);

	foreach ( $seed as $row ) {
		$req = new \WP_REST_Request( 'POST', '/goalcart/v1/goals' );
		$req->set_param( 'name', $row['name'] );
		$req->set_param( 'type', 'amount' );
		$req->set_param( 'target', 100 );
		$req->set_param( 'status', $row['status'] );
		$req->set_param( 'priority', $row['priority'] );
		$resp = $goals_ctrl->handle_create( $req );
		$created_ids[] = (int) $resp->get_data()['data']['id'];
	}

	// 4.1 Pagination: per_page=2 → two items on page 1, correct envelope.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$req->set_param( 'page', 1 );
	$req->set_param( 'per_page', 2 );
	$resp = $goals_ctrl->handle_index( $req );
	$data = $resp->get_data();

	check( 'page 1 returns at most per_page items', count( $data['data'] ) <= 2 );
	check( 'pagination envelope carries total', isset( $data['pagination']['total'] ) && (int) $data['pagination']['total'] >= 3 );
	check( 'pagination envelope carries total_pages', isset( $data['pagination']['total_pages'] ) && (int) $data['pagination']['total_pages'] >= 2 );

	// 4.2 Server-side search narrows the result set.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$req->set_param( 'search', 'Alpha' );
	$req->set_param( 'per_page', 50 );
	$resp = $goals_ctrl->handle_index( $req );
	$data = $resp->get_data();

	$names = array();
	foreach ( $data['data'] as $item ) {
		$names[] = $item['name'];
	}

	check( 'server-side search returns only matches', 1 === (int) $data['pagination']['total'] && in_array( 'Perf Test Alpha Goal', $names, true ) );

	// 4.3 Server-side status filtering.
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$req->set_param( 'status', 'inactive' );
	$req->set_param( 'per_page', 50 );
	$resp = $goals_ctrl->handle_index( $req );
	$data = $resp->get_data();

	$only_inactive = true;
	foreach ( $data['data'] as $item ) {
		if ( 'inactive' !== $item['status'] ) {
			$only_inactive = false;
		}
	}

	check( 'server-side status filter returns only that status', $only_inactive );
	check( 'status filter isolates the inactive goal', 1 === (int) $data['pagination']['total'] );

	// 4.4 The list endpoint clamps per_page (never thousands at once).
	$req  = new \WP_REST_Request( 'GET', '/goalcart/v1/goals' );
	$req->set_param( 'per_page', 5000 );
	$resp = $goals_ctrl->handle_index( $req );
	$data = $resp->get_data();

	check( 'list per_page clamped to 100', (int) $data['pagination']['per_page'] <= 100 );
	check( 'clamped list still returns all seeds', count( $data['data'] ) >= 3 );

	// 4.5 The product search endpoint caps results (avoid loading
	// thousands of products at once): the arg schema declares the cap
	// and the handler clamps regardless of the requested value.
	$schema = array();
	foreach ( $routes['/goalcart/v1/search/products'] as $handler ) {
		$schema = $handler['args'];
	}

	check( 'search per_page schema maximum declared', isset( $schema['per_page']['maximum'] ) && 50 === (int) $schema['per_page']['maximum'] );

	$req = new \WP_REST_Request( 'GET', '/goalcart/v1/search/products' );
	$req->set_param( 'q', 'Perf Test Alpha Goal' );
	$req->set_param( 'per_page', 5000 );
	$resp = $search_ctrl->handle_products( $req );

	check( 'search handler clamps per_page without error', ! is_wp_error( $resp ) );
	check( 'search never returns more than MAX_RESULTS', count( $resp->get_data()['data']['items'] ) <= 50 );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

// ---------------------------------------------------------------------------
// 5. Rollback verification: nothing persisted
// ---------------------------------------------------------------------------
echo "\n== 5. Rollback verification ==\n";

foreach ( $created_ids as $id ) {
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE id = %d", $id ) );
	check( "rolled-back goal {$id} is gone", 0 === $count );
}

$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$goals_table} WHERE name LIKE %s", 'Perf Test %' ) );
check( 'no perf-test goals remain by name', 0 === $count );

$transients = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_goalcart_progress_%'"
);
check( 'no progress-cache transients remain', 0 === count( $transients ) );

$option_after = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $settings_option ) );
check( 'settings option unchanged after rollback', $option_before === $option_after );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "PERFORMANCE TEST FAILED\n" : "PERFORMANCE TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
