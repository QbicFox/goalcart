<?php
/**
 * Compile FaraCart .po translation files into .mo and JED JSON.
 *
 * Phase 27 (Internationalization): for every `languages/faracart-<locale>.po`
 * this writes:
 *
 *  - `languages/faracart-<locale>.mo`      — GNU gettext machine object
 *    (the classic PHP `load_textdomain` binary; also consumed by the
 *    WordPress plugin-translation loader from the Domain Path).
 *  - `languages/faracart-<locale>-faracart-admin.json` — JED JSON for the
 *    React admin app, named for WP 7's `load_script_textdomain()`
 *    convention `{domain}-{locale}-{handle}.json` (verified against the
 *    installed core), loaded via `wp_set_script_translations( 'faracart-admin',
 *    'faracart', FARACART_PATH . 'languages' )`.
 *
 * A compact native implementation so translators ship .po files without
 * needing gettext tooling or wp-cli. Run with `--check` to verify every
 * .po is newer than (or equal to) its compiled outputs.
 *
 * Usage:
 *   php bin/build-i18n.php             # compile all languages/faracart-*.po
 *   php bin/build-i18n.php --check     # exit 1 when any output is stale
 *   php bin/build-i18n.php --dir /tmp/pofiles
 *
 * @package FaraCart
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root  = dirname( __DIR__ );
$dir   = $root . '/languages';
$check = false;

for ( $i = 1; $i < $argc; $i++ ) {
	if ( '--check' === $argv[ $i ] ) {
		$check = true;
	} elseif ( '--dir' === $argv[ $i ] && isset( $argv[ $i + 1 ] ) ) {
		$dir = $argv[ ++$i ];
	}
}

/**
 * Parse a .po document into entries.
 *
 * @param string $content .po content.
 * @return array<int, array<string, string>> Entries with msgctxt, msgid,
 *                 msgid_plural, msgstr, msgstr_plural keys.
 */
function gc_i18n_parse_po( $content ) {
	// Normalize multiline quoted strings into single lines first. Loop
	// until no consecutive quoted pairs remain, so a msgstr wrapped
	// across three or more lines never drops its tail.
	do {
		$normalized = preg_replace( '/"((?:[^"\\\\]|\\\\.)*)"\s*\n\s*"((?:[^"\\\\]|\\\\.)*)"/s', '"\\1\\2"', $content );
		$changed    = $normalized !== $content;
		$content    = $normalized;
	} while ( $changed );

	$entries = array();
	$current = null;

	foreach ( preg_split( '/\r?\n/', $content ) as $line ) {
		if ( '' === trim( $line ) || '#' === $line[0] ) {
			if ( null !== $current && isset( $current['msgid'] ) ) {
				$entries[] = $current;
				$current   = null;
			}
			continue;
		}

		if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\\[\\d+\\])?)\\s+(.*)$/s', trim( $line ), $m ) ) {
			if ( null === $current ) {
				$current = array( 'msgctxt' => '', 'msgid' => '', 'msgid_plural' => '', 'msgstr' => '', 'msgstr_plural' => '' );
			}

			$value = (string) json_decode( $m[2] );

			if ( 'msgctxt' === $m[1] ) {
				$current['msgctxt'] = $value;
			} elseif ( 'msgid' === $m[1] ) {
				$current['msgid'] = $value;
			} elseif ( 'msgid_plural' === $m[1] ) {
				$current['msgid_plural'] = $value;
			} elseif ( 'msgstr[0]' === $m[1] ) {
				$current['msgstr'] = $value;
			} elseif ( 'msgstr[1]' === $m[1] ) {
				$current['msgstr_plural'] = $value;
			} else {
				$current['msgstr'] = $value;
			}
		}
	}

	if ( null !== $current && isset( $current['msgid'] ) ) {
		$entries[] = $current;
	}

	return $entries;
}

/**
 * Build the binary .mo content for a set of .po entries.
 *
 * GNU gettext MO v1 layout: 7 little-endian longs (magic, revision, N,
 * original-table offset, translation-table offset, hash size 0, hash
 * offset 0), then N (length, offset) pairs for originals, N for
 * translations, then the string data (originals then translations).
 * Offsets are relative to the start of the file.
 *
 * @param array<int, array<string, string>> $entries Parsed .po entries.
 * @return string
 */
function gc_i18n_build_mo( array $entries ) {
	// Build the (original, translation) pairs; the empty msgid "" entry
	// carries the headers.
	$pairs = array();

	foreach ( $entries as $entry ) {
		if ( '' === $entry['msgid'] ) {
			$pairs[] = array( '', $entry['msgstr'] );
			continue;
		}

		$original = ( '' !== $entry['msgctxt'] ? $entry['msgctxt'] . "\x04" : '' ) . $entry['msgid'];
		$translation = $entry['msgstr'];

		if ( '' !== $entry['msgid_plural'] ) {
			$original    .= "\x00" . $entry['msgid_plural'];
			$translation .= "\x00" . $entry['msgstr_plural'];
		}

		$pairs[] = array( $original, $translation );
	}

	// Keys must be sorted in the MO format so gettext can binary-search;
	// translations move in parallel.
	usort( $pairs, function ( $a, $b ) {
		return strcmp( $a[0], $b[0] );
	} );

	$count      = count( $pairs );
	$header     = 28;
	$o_off      = $header;
	$t_off      = $o_off + $count * 8;
	$o_data_off = $t_off + $count * 8;
	$t_data_off = $o_data_off + array_sum( array_map( 'strlen', array_column( $pairs, 0 ) ) );

	$o_table = '';
	$offset  = $o_data_off;

	foreach ( $pairs as $pair ) {
		$o_table .= pack( 'VV', strlen( $pair[0] ), $offset );
		$offset  += strlen( $pair[0] );
	}

	$t_table = '';
	$offset  = $t_data_off;

	foreach ( $pairs as $pair ) {
		$t_table .= pack( 'VV', strlen( $pair[1] ), $offset );
		$offset  += strlen( $pair[1] );
	}

	$o_data = implode( '', array_column( $pairs, 0 ) );
	$t_data = implode( '', array_column( $pairs, 1 ) );

	return pack( 'V*', 0x950412de, 0, $count, $o_off, $t_off, 0, 0 )
		. $o_table . $t_table . $o_data . $t_data;
}

/**
 * Extract the Plural-Forms header from the .po header entry.
 *
 * @param array<int, array<string, string>> $entries Parsed entries.
 * @return string
 */
function gc_i18n_plural_forms( array $entries ) {
	foreach ( $entries as $entry ) {
		if ( '' === $entry['msgid'] && preg_match( '/Plural-Forms:\\s*([^\\\\\\n]+)/', $entry['msgstr'], $m ) ) {
			return trim( $m[1] );
		}
	}

	return 'nplurals=2; plural=(n != 1);';
}

/**
 * Build the JED JSON for the admin handle.
 *
 * @param string                              $locale Locale code (e.g. fa_IR).
 * @param array<int, array<string, string>>   $entries Parsed .po entries.
 * @return string JSON document.
 */
function gc_i18n_build_jed( $locale, array $entries ) {
	$data = array(
		'domain'      => 'faracart',
		'locale_data' => array(
			'faracart' => array(
				'' => array(
					'domain'       => 'faracart',
					'lang'         => $locale,
					'plural-forms' => gc_i18n_plural_forms( $entries ),
				),
			),
		),
	);

	foreach ( $entries as $entry ) {
		if ( '' === $entry['msgid'] ) {
			continue;
		}

		$key = ( '' !== $entry['msgctxt'] ? $entry['msgctxt'] . "\x04" : '' ) . $entry['msgid'];

		$data['locale_data']['faracart'][ $key ] = array( $entry['msgstr'] );
	}

	return wp_json_encode_compat( $data );
}

/**
 * wp_json_encode with pretty printing, without requiring WordPress.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_compat( $data ) {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

	return false === $json ? '{}' : $json . "\n";
}

$po_files = glob( $dir . '/faracart-*.po' );

if ( false === $po_files ) {
	$po_files = array();
}

$any_output = false;
$failed     = false;

foreach ( $po_files as $po_path ) {
	$locale = basename( $po_path, '.po' );
	$locale = preg_replace( '/^faracart-/', '', $locale );

	$mo_path = dirname( $po_path ) . '/faracart-' . $locale . '.mo';
	$jed_path = dirname( $po_path ) . '/faracart-' . $locale . '-faracart-admin.json';

	$entries = gc_i18n_parse_po( (string) file_get_contents( $po_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( $check ) {
		$po_mtime = filemtime( $po_path );
		$stale    = false;

		foreach ( array( $mo_path, $jed_path ) as $out_path ) {
			if ( ! file_exists( $out_path ) || filemtime( $out_path ) < $po_mtime ) {
				$stale = true;
				break;
			}
		}

		if ( $stale ) {
			fwrite( STDERR, "Compiled output is stale for {$po_path}.\n" );
			$failed = true;
		}

		continue;
	}

	file_put_contents( $mo_path, gc_i18n_build_mo( $entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	file_put_contents( $jed_path, gc_i18n_build_jed( $locale, $entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	fwrite( STDOUT, "Built {$locale}: " . basename( $mo_path ) . ' + ' . basename( $jed_path ) . "\n" );
	$any_output = true;
}

if ( $check ) {
	exit( $failed ? 1 : 0 );
}

if ( ! $any_output ) {
	fwrite( STDOUT, "No faracart-*.po files found in {$dir} — nothing to build.\n" );
}

exit( 0 );
