<?php
/**
 * FaraCart internationalization tests.
 *
 * Boots WordPress and verifies the translation posture end-to-end:
 *
 *  - plugin header contract (Text Domain + Domain Path) and the text
 *    domain loaded on `init` via load_plugin_textdomain
 *  - the POT pipeline: languages/faracart.pot exists, carries the
 *    standard headers, contains sampled PHP + admin React strings, and
 *    is in sync with the source (`php bin/extract-pot.php --check`)
 *  - no hard-coded Persian/Arabic characters anywhere in PHP/TS/JS
 *    source (Persian is supported through locale-aware formatting and
 *    translation files, never through hard-coded strings)
 *  - every WordPress translation call in PHP and the admin React app
 *    passes the `faracart` text domain
 *  - the storefront config carries the site locale and isRtl, and the
 *    storefront JS formats with the site locale via Intl (Persian
 *    digits for fa_IR)
 *  - RTL end-to-end: admin mount dir attribute, MUI theme direction,
 *    the stylis RTL plugin, and physical-direction-free frontend CSS
 *  - the admin Intl formatting (lib/format.ts, date-range, analytics)
 *    is locale-driven, and wp_set_script_translations is wired to the
 *    admin handle
 *  - the PO → MO + JED build pipeline (bin/build-i18n.php) produces a
 *    valid gettext MO (magic bytes) and a JED JSON for the admin handle
 *  - the shipped languages/faracart-fa_IR.po covers the key storefront
 *    strings AND every admin-dashboard string referenced from the POT
 *    (admin-app/src), the compiled .mo/.json artifacts exist, and the
 *    strings resolve to Persian end-to-end through WP's just-in-time
 *    loader (switch_to_locale + Plugin::load_textdomain + __())
 *
 * The only writes are a temp .po build under sys_get_temp_dir(), which
 * is removed afterwards.
 *
 * Run: php tests/i18n-test.php (from the plugin directory)
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
use FaraCart\Settings\Settings;

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

$root = dirname( __DIR__ );

function read_source( $path ) {
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
}

function php_files( $root ) {
	$files = array( $root . '/ravis-faracart.php' );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}

	return $files;
}

function admin_sources( $root ) {
	$files = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/admin-app/src', FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( in_array( strtolower( $file->getExtension() ), array( 'ts', 'tsx' ), true ) ) {
			$files[] = $file->getPathname();
		}
	}

	return $files;
}

// ---------------------------------------------------------------------------
// 1. Plugin header + text domain
// ---------------------------------------------------------------------------
echo "\n== 1. Text domain ==\n";

$header = read_source( $root . '/ravis-faracart.php' );
check( 'plugin header declares Text Domain: faracart', false !== strpos( $header, 'Text Domain:       faracart' ) );
check( 'plugin header declares Domain Path: /languages', false !== strpos( $header, 'Domain Path:       /languages' ) );
check( 'languages directory exists', is_dir( $root . '/languages' ) );
check( 'load_textdomain hooked on init', false !== has_action( 'init', array( FaraCart\Plugin::instance(), 'load_textdomain' ) ) );

$plugin_src = read_source( $root . '/includes/Plugin.php' );
check(
	'load_plugin_textdomain uses the languages path',
	false !== strpos( $plugin_src, "load_plugin_textdomain( 'faracart', false, dirname( FARACART_BASENAME ) . '/languages' )" )
);

// ---------------------------------------------------------------------------
// 1b. fa_IR storefront translation (PO -> MO -> just-in-time load)
// ---------------------------------------------------------------------------
echo "\n== 1b. fa_IR storefront translation ==\n";

$fa_po       = read_source( $root . '/languages/faracart-fa_IR.po' );
$fa_jed      = read_source( $root . '/languages/faracart-fa_IR-faracart-admin.json' );	$fa_expected = array(
		'Free shipping'                            => 'ارسال رایگان',
		'Percentage discount'                      => 'تخفیف درصدی',
		'Fixed discount'                           => 'تخفیف ثابت',
		'Free gift'                                => 'هدیه رایگان',
		'Coupon'                                   => 'کوپن',
		'Mission reward'                           => 'پاداش ماموریت',
		'You reached your mission!'                => 'ماموریت خود را تکمیل کردید!',
		'Only {remaining} left to reach your mission' => 'تنها {remaining} تا تکمیل ماموریت شما باقی مانده است',
	);

check( 'fa_IR .po exists', is_file( $root . '/languages/faracart-fa_IR.po' ) );

$fa_in_po = 0;
foreach ( $fa_expected as $msgid => $msgstr ) {
	if ( false !== strpos( $fa_po, 'msgid "' . $msgid . '"' ) && false !== strpos( $fa_po, 'msgstr "' . $msgstr . '"' ) ) {
		$fa_in_po++;
	}
}
check( 'fa_IR .po carries all key storefront translations', count( $fa_expected ) === $fa_in_po );

check( 'fa_IR .mo built artifact exists', is_file( $root . '/languages/faracart-fa_IR.mo' ) );
check( 'fa_IR admin JED exists for the admin handle', is_file( $root . '/languages/faracart-fa_IR-faracart-admin.json' ) );
check( 'fa_IR admin JED carries Persian translations', false !== strpos( $fa_jed, 'ارسال رایگان' ) );

// End-to-end: switch to fa_IR, re-register the custom path exactly as `init`
// does, then let WP's just-in-time loader translate through the compiled MO.
// NOTE: do not call unload_textdomain() first -- since WP 6.5 the JIT loader
// permanently short-circuits for domains marked unloaded ($l10n_unloaded).
// Defensively clear the flag so this section stays order-independent.
unset( $GLOBALS['l10n_unloaded']['faracart'] );
switch_to_locale( 'fa_IR' );
FaraCart\Plugin::instance()->load_textdomain();

$fa_jit_fail = 0;
foreach ( $fa_expected as $msgid => $msgstr ) {
	if ( __( $msgid, 'faracart' ) !== $msgstr ) {
		$fa_jit_fail++;
	}
}
check( 'JIT load translates storefront strings to Persian', 0 === $fa_jit_fail );

restore_previous_locale();

// Every admin-dashboard string in the POT must carry a non-empty Persian
// translation, and the .po must never hold duplicate msgids.
$pot_all      = read_source( $root . '/languages/faracart.pot' );
$po_msgstr    = array();
$po_msgid_raw = array();

foreach ( preg_split( "/\n\n/", $fa_po ) as $block ) {
	if ( ! preg_match( '/\nmsgid ("(?:[^"\\\\]|\\\\.)*")/s', "\n" . $block, $m ) ) {
		continue;
	}
	$msgid = json_decode( $m[1] );
	if ( null === $msgid || '' === $msgid ) {
		continue;
	}
	$po_msgid_raw[] = $msgid;
	if ( preg_match( '/\nmsgstr ("(?:[^"\\\\]|\\\\.)*")/s', "\n" . $block, $s ) ) {
		$po_msgstr[ $msgid ] = json_decode( $s[1] );
	}
}

$admin_total = 0;
$admin_done  = 0;

foreach ( preg_split( "/\n\n/", $pot_all ) as $block ) {
	if ( false === strpos( $block, 'admin-app/src' ) ) {
		continue;
	}
	if ( ! preg_match( '/\nmsgid ("(?:[^"\\\\]|\\\\.)*")/s', "\n" . $block, $m ) ) {
		continue;
	}
	$msgid = json_decode( $m[1] );
	if ( null === $msgid || '' === $msgid ) {
		continue;
	}
	$admin_total++;
	if ( isset( $po_msgstr[ $msgid ] ) && '' !== $po_msgstr[ $msgid ] ) {
		$admin_done++;
	}
}

check( 'fa_IR translates every admin dashboard string', $admin_total === $admin_done && $admin_total > 0 );
check( 'fa_IR .po has no duplicate msgids', count( $po_msgid_raw ) === count( array_unique( $po_msgid_raw ) ) );
check( 'fa_IR admin JED carries dashboard labels', false !== strpos( $fa_jed, 'داشبورد' ) && false !== strpos( $fa_jed, 'افزودن ماموریت' ) );

// ---------------------------------------------------------------------------
// 2. POT pipeline
// ---------------------------------------------------------------------------
echo "\n== 2. POT generation ==\n";

$pot = read_source( $root . '/languages/faracart.pot' );
check( 'POT file exists', is_file( $root . '/languages/faracart.pot' ) );
check( 'POT declares Content-Type charset UTF-8', false !== strpos( $pot, 'Content-Type: text/plain; charset=UTF-8' ) );
check( 'POT declares Plural-Forms', false !== strpos( $pot, 'Plural-Forms: nplurals=2; plural=(n != 1);' ) );
check( 'POT declares X-Domain faracart', false !== strpos( $pot, 'X-Domain: faracart' ) );	foreach ( array(
		'PHP string sampled'       => 'The mission could not be found.',
		'PHP message-engine string' => 'Only {remaining} left to reach your mission',
		'PHP reward label'         => 'Free shipping',
		'Admin React string'       => 'Dashboard',
		'Admin builder string'     => 'Add mission',
		'Admin preview string'     => 'Could not load the preview.',
		'Admin date-range string'  => 'Date range',
	) as $label => $needle ) {
	check( "POT contains {$label}", false !== strpos( $pot, 'msgid "' . $needle . '"' ) );
}

check( 'POT carries a healthy entry count', substr_count( $pot, "\nmsgid " ) >= 400 );

// The committed POT must be in sync with the source (deterministic extractor).
if ( function_exists( 'exec' ) ) {
	exec( 'cd ' . escapeshellarg( $root ) . ' && php bin/extract-pot.php --check 2>&1', $extract_out, $extract_code );
	check( 'extractor --check passes (POT in sync with source)', 0 === $extract_code );
} else {
	print "SKIP extractor --check (exec disabled)\n";
}

// ---------------------------------------------------------------------------
// 3. No hard-coded Persian / non-latin source strings
// ---------------------------------------------------------------------------
echo "\n== 3. No hard-coded Persian ==\n";

$scan_paths = array_merge(
	php_files( $root ),
	admin_sources( $root ),
	array( $root . '/assets/js/frontend.js' )
);
$persian_hits = array();

foreach ( $scan_paths as $path ) {
	$source = read_source( $path );

	if ( preg_match( '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $source ) ) {
		$persian_hits[] = str_replace( $root, '', $path );
	}
}

check( 'no Persian/Arabic characters in PHP/TS/JS source', empty( $persian_hits ) );

// ---------------------------------------------------------------------------
// 4. Every translation call carries the faracart domain
// ---------------------------------------------------------------------------
echo "\n== 4. Translation calls use the text domain ==\n";

$call_pattern = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_x|_ex|esc_html_x|esc_attr_x|_n|_nx)\s*\(/';
$domainless  = array();

foreach ( array_merge( php_files( $root ), admin_sources( $root ) ) as $path ) {
	$source = read_source( $path );

	if ( ! preg_match_all( $call_pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}

	foreach ( $matches[0] as $match ) {
		$tail = substr( $source, $match[1], 400 );

		if ( false === strpos( $tail, "'faracart'" ) && false === strpos( $tail, '"faracart"' ) ) {
			$line = substr_count( substr( $source, 0, $match[1] ), "\n" ) + 1;
			$domainless[] = str_replace( $root, '', $path ) . ':' . $line;
		}
	}
}

check( 'no domain-less translation calls in PHP/React', empty( $domainless ) );

// ---------------------------------------------------------------------------
// 5. Storefront locale + RTL config
// ---------------------------------------------------------------------------
echo "\n== 5. Storefront locale & RTL ==\n";

$settings = new Settings();
$ui       = new ProgressUI( $settings );
$config   = $ui->frontend_config();

check( 'storefront config carries the site locale', isset( $config['locale'] ) && $config['locale'] === get_locale() );
check( 'storefront config is RTL-aware', array_key_exists( 'isRtl', $config ) && $config['isRtl'] === is_rtl() );

$frontend_js = read_source( $root . '/assets/js/frontend.js' );
check( 'frontend JS derives uiLocale from cfg.locale', false !== strpos( $frontend_js, 'String( cfg.locale ).replace( \'_\', \'-\' )' ) );
check( 'formatMoney uses WooCommerce currency fields', false !== strpos( $frontend_js, 'cfg.currencyDecimals' ) && false !== strpos( $frontend_js, 'cfg.currencyPosition' ) );
check( 'formatNumber uses the site locale', false !== strpos( $frontend_js, 'new Intl.NumberFormat( uiLocale )' ) );

// ---------------------------------------------------------------------------
// 6. RTL end-to-end (admin + storefront CSS)
// ---------------------------------------------------------------------------
echo "\n== 6. RTL support ==\n";

$admin_src = read_source( $root . '/includes/Admin/Admin.php' );
check( 'admin mount sets a dir attribute from is_rtl()', false !== strpos( $admin_src, 'is_rtl()' ) && false !== strpos( $admin_src, "'rtl' : 'ltr'" ) );

$theme_src = read_source( $root . '/admin-app/src/theme/index.ts' );
check( 'MUI theme direction flips on boot.isRtl', false !== strpos( $theme_src, "boot.isRtl ? 'rtl' : 'ltr'" ) );

$providers_src = read_source( $root . '/admin-app/src/providers/AppProviders.tsx' );
check( 'Emotion cache RTL-flips via the stylis plugin', false !== strpos( $providers_src, 'stylis-plugin-rtl' ) );

$frontend_css = read_source( $root . '/assets/css/frontend.css' );

// The floating widget is the one intentional exception to the physical
// left/right rule: its position axes are PHYSICAL sides (left/right ×
// top/center/bottom) that must keep their visual result in RTL — the
// admin picks a side explicitly, so it must not flip like a logical
// start/end would. Strip that block before the check.
$floating_marker = '/* ------------------------------------------------------------------ *' . "\n * Floating widget (floating missions/campaigns button + drawer)";
$floating_start  = strpos( $frontend_css, $floating_marker );

if ( false !== $floating_start ) {
	$next_header = strpos( $frontend_css, '/* ------------------------------------------------------------------ *', $floating_start + strlen( $floating_marker ) );
	$frontend_css = substr( $frontend_css, 0, $floating_start )
		. ( false !== $next_header ? substr( $frontend_css, $next_header ) : '' );
}

check( 'storefront CSS has no physical left/right properties (floating widget excluded — physical sides by design)', ! preg_match( '/(?<![a-z-])(?:left|right)\s*:/i', $frontend_css ) );

// ---------------------------------------------------------------------------
// 7. Admin locale-aware Intl formatting + script translations
// ---------------------------------------------------------------------------
echo "\n== 7. Admin Intl & translations wiring ==\n";

$format_src = read_source( $root . '/admin-app/src/lib/format.ts' );
check( 'admin format.ts is locale-driven (Intl + boot.locale)', false !== strpos( $format_src, 'Intl.NumberFormat' ) && false !== strpos( $format_src, 'boot.locale' ) );

$date_range_src = read_source( $root . '/admin-app/src/date-range/dateRange.ts' );
check( 'admin date range formats via Intl.DateTimeFormat', false !== strpos( $date_range_src, 'Intl.DateTimeFormat' ) && false !== strpos( $date_range_src, 'boot.locale' ) );

$calendar_src = read_source( $root . '/admin-app/src/lib/calendar.ts' );
check( 'wheel date picker is calendar-aware (Intl + boot.locale)', false !== strpos( $calendar_src, 'Intl.DateTimeFormat' ) && false !== strpos( $calendar_src, 'boot.locale' ) );

$picker_src = read_source( $root . '/admin-app/src/components/date-range/CustomRangePicker.tsx' );
check( 'custom range picker uses the calendar-aware wheel date fields', false !== strpos( $picker_src, 'WheelDateField' ) );

$analytics_src = read_source( $root . '/admin-app/src/routes/Analytics.tsx' );
check( 'analytics formats via Intl.DateTimeFormat', false !== strpos( $analytics_src, 'Intl.DateTimeFormat' ) && false !== strpos( $analytics_src, 'boot.locale' ) );

$asset_src = read_source( $root . '/includes/Admin/AssetLoader.php' );
check( 'wp_set_script_translations wired for the admin handle', false !== strpos( $asset_src, 'wp_set_script_translations' ) && false !== strpos( $asset_src, "'faracart-admin'" ) );
check( 'admin script depends on wp-i18n', false !== strpos( $asset_src, "'wp-i18n'" ) );

$shim_src = read_source( $root . '/admin-app/src/lib/wp-i18n.ts' );
check( 'admin i18n shim delegates to window.wp.i18n', false !== strpos( $shim_src, 'typeof wp !== \'undefined\' && wp.i18n' ) );

// ---------------------------------------------------------------------------
// 8. PO → MO + JED build pipeline
// ---------------------------------------------------------------------------
echo "\n== 8. Translation build pipeline ==\n";

$tmp_dir = sys_get_temp_dir() . '/faracart-i18n-' . getmypid();	try {
	mkdir( $tmp_dir, 0755, true );

	$po = "msgid \"\"\n"
		. "msgstr \"\"\n"
		. "\"Project-Id-Version: FaraCart 0.1.0\\n\"\n"
		. "\"Plural-Forms: nplurals=2; plural=(n > 1);\\n\"\n"
		. "\"X-Domain: faracart\\n\"\n"
		. "\n"
		. "msgid \"Dashboard\"\n"
		. "msgstr \"داشبورد\"\n"
		. "\n"
		. "msgid \"Free shipping\"\n"
		. "msgstr \"ارسال رایگان\"\n";

	file_put_contents( $tmp_dir . '/faracart-fa_IR.po', $po );

	if ( function_exists( 'exec' ) ) {
		exec( 'cd ' . escapeshellarg( $root ) . ' && php bin/build-i18n.php --dir ' . escapeshellarg( $tmp_dir ) . ' 2>&1', $build_out, $build_code );
		check( 'build-i18n exits cleanly', 0 === $build_code );

		$mo_path = $tmp_dir . '/faracart-fa_IR.mo';
		$jed_path = $tmp_dir . '/faracart-fa_IR-faracart-admin.json';

		check( 'MO file produced', is_file( $mo_path ) );

		$mo = read_source( $mo_path );
		$head = strlen( $mo ) >= 28 ? unpack( 'Vmagic/Vrev/Vn/Vo/Vt/Vhs/Vho', substr( $mo, 0, 28 ) ) : array();
		check( 'MO has the gettext magic bytes', isset( $head['magic'] ) && 0x950412de === $head['magic'] );
		check( 'MO carries header + 2 strings', isset( $head['n'] ) && 3 === $head['n'] );

		check( 'JED JSON produced for the admin handle', is_file( $jed_path ) && 'faracart-fa_IR-faracart-admin.json' === basename( $jed_path ) );

		$jed = json_decode( read_source( $jed_path ), true );
		check( 'JED domain is faracart', is_array( $jed ) && 'faracart' === ( $jed['domain'] ?? '' ) );
		check( 'JED carries the locale data', is_array( $jed ) && isset( $jed['locale_data']['faracart']['Dashboard'][0] ) && 'داشبورد' === $jed['locale_data']['faracart']['Dashboard'][0] );

		exec( 'cd ' . escapeshellarg( $root ) . ' && php bin/build-i18n.php --dir ' . escapeshellarg( $tmp_dir ) . ' --check 2>&1', $check_out, $check_code );
		check( 'build-i18n --check passes after build', 0 === $check_code );
	} else {
		print "SKIP build pipeline (exec disabled)\n";
	}
} finally {
	if ( is_dir( $tmp_dir ) ) {
		foreach ( glob( $tmp_dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $tmp_dir );
	}
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n==========================================\n";
echo "Checks: {$checks}  Failures: {$failures}\n";
echo $failures > 0 ? "I18N TEST FAILED\n" : "I18N TEST PASSED\n";
exit( $failures > 0 ? 1 : 0 );
