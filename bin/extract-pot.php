<?php
/**
 * POT extractor for FaraCart (PHP + admin React strings).
 *
 * Phase 27 (Internationalization): scans the PHP layer (ravis-faracart.php,
 * includes/) and the admin React app (admin-app/src) for WordPress
 * translation-function calls and emits `languages/faracart.pot` — the
 * English source template translators work from. Output is deterministic
 * (sorted, deduped, with source references) so CI can verify freshness
 * with `--check`.
 *
 * The same extraction pattern covers PHP and TS/TSX because both call
 * `__( 'text', 'faracart' )` with the same syntax. Mirrors the reference
 * plugin's `makepot` / `i18n:extract` npm scripts; a self-contained PHP
 * implementation so no wp-cli is required.
 *
 * Usage:
 *   php bin/extract-pot.php             # write languages/faracart.pot
 *   php bin/extract-pot.php --check     # exit 1 when the POT is stale
 *   php bin/extract-pot.php --out /path/to.pot
 *
 * @package FaraCart
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root  = dirname( __DIR__ );
$out   = $root . '/languages/faracart.pot';
$check = false;

for ( $i = 1; $i < $argc; $i++ ) {
	if ( '--check' === $argv[ $i ] ) {
		$check = true;
	} elseif ( '--out' === $argv[ $i ] && isset( $argv[ $i + 1 ] ) ) {
		$out = $argv[ ++$i ];
	}
}

/**
 * Recursively list the files to scan.
 *
 * @param string $root Repo root.
 * @return array<int, string> Absolute file paths.
 */
function gc_i18n_files( $root ) {
	$targets = array(
		$root . '/ravis-faracart.php',
		$root . '/includes',
		$root . '/admin-app/src',
	);

	$files = array();

	foreach ( $targets as $path ) {
		if ( is_dir( $path ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
			);

			/** @var SplFileInfo $file */
			foreach ( $iterator as $file ) {
				if ( in_array( strtolower( $file->getExtension() ), array( 'php', 'ts', 'tsx' ), true ) ) {
					$files[] = $file->getPathname();
				}
			}
		} elseif ( is_file( $path ) ) {
			$files[] = $path;
		}
	}

	sort( $files );

	return $files;
}

/**
 * Unquote a captured PHP/JS string literal.
 *
 * @param string $s Raw captured literal including quotes.
 * @return string Unescaped content.
 */
function gc_i18n_unquote( $s ) {
	$quote = $s[0];
	$body  = substr( $s, 1, -1 );

	// Never extract interpolated strings (PHP "$var" / "{$var}" / JS
	// "${var}"). A literal dollar sign without a variable ("Save $5")
	// is a perfectly translatable string and is kept. sprintf positional
	// placeholders ("%1$s", "%2$d") are placeholders, not variables —
	// strip their "%<n>$" prefix first so they don't trip the guard.
	$interpolated = preg_replace( '/%\d+\$/', '', $body );

	if ( preg_match( '/\$(?:\{?[A-Za-z_])/', $interpolated ) ) {
		return '';
	}

	if ( "'" === $quote ) {
		return str_replace( "\\'", "'", str_replace( '\\\\', '\\', $body ) );
	}

	return str_replace( '\\"', '"', str_replace( '\\\\', '\\', $body ) );
}

/**
 * Extract translatable strings from a single file.
 *
 * @param string $path Absolute file path.
 * @param string $root Repo root (for relative references).
 * @return array<string, array<string, mixed>> key => entry (msgctxt, msgid,
 *                 msgid_plural, refs).
 */
function gc_i18n_extract_file( $path, $root ) {
	$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$q = "'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"";

	$patterns = array(
		// __( 'text', 'faracart' ) / _e / esc_html__ / esc_attr__ / esc_html_e.
		// The optional trailing comma before ')' tolerates the prettier
		// multi-line call style (a domain argument followed by ',').
		'/\\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\\(\\s*(' . $q . ')(?:\\s*,\\s*(' . $q . '))?\\s*,?\\s*\\)/s' => array( 'msgid' => 1, 'domain' => 2 ),
		// _x( 'text', 'context', 'faracart' ) / _ex / esc_html_x / esc_attr_x.
		'/\\b(?:_x|_ex|esc_html_x|esc_attr_x)\\(\\s*(' . $q . ')\\s*,\\s*(' . $q . ')(?:\\s*,\\s*(' . $q . '))?\\s*,?\\s*\\)/s' => array( 'msgid' => 1, 'msgctxt' => 2, 'domain' => 3 ),
		// _n( 'single', 'plural', $count, 'faracart' ).
		'/\\b_n\\(\\s*(' . $q . ')\\s*,\\s*(' . $q . ')\\s*,\\s*[^,)]+\\s*,\\s*(' . $q . ')\\s*,?\\s*\\)/s' => array( 'msgid' => 1, 'msgid_plural' => 2, 'domain' => 3 ),
		// _nx( 'single', 'plural', $count, 'context', 'faracart' ).
		'/\\b_nx\\(\\s*(' . $q . ')\\s*,\\s*(' . $q . ')\\s*,\\s*[^,)]+\\s*,\\s*(' . $q . ')\\s*,\\s*(' . $q . ')\\s*,?\\s*\\)/s' => array( 'msgid' => 1, 'msgid_plural' => 2, 'msgctxt' => 3, 'domain' => 4 ),
	);

	$entries = array();

	foreach ( $patterns as $pattern => $map ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		foreach ( $matches[0] as $i => $whole ) {
			$msgid = gc_i18n_unquote( $matches[ $map['msgid'] ][ $i ][0] );

			if ( '' === $msgid ) {
				continue;
			}

			$msgctxt = isset( $map['msgctxt'] ) && isset( $matches[ $map['msgctxt'] ][ $i ] )
				? gc_i18n_unquote( $matches[ $map['msgctxt'] ][ $i ][0] )
				: '';

			$msgid_plural = isset( $map['msgid_plural'] ) && isset( $matches[ $map['msgid_plural'] ][ $i ] )
				? gc_i18n_unquote( $matches[ $map['msgid_plural'] ][ $i ][0] )
				: '';

			$line = substr_count( substr( $source, 0, $whole[1] ), "\n" ) + 1;

			$key = $msgctxt . "\x04" . $msgid . "\x04" . $msgid_plural;

			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array(
					'msgctxt'      => $msgctxt,
					'msgid'        => $msgid,
					'msgid_plural' => $msgid_plural,
					'refs'         => array(),
				);
			}

			$ref = ltrim( str_replace( $root, '', $path ), '/' ) . ':' . $line;

			if ( ! in_array( $ref, $entries[ $key ]['refs'], true ) ) {
				$entries[ $key ]['refs'][] = $ref;
			}
		}
	}

	return $entries;
}

/**
 * Escape a string for POT output.
 *
 * @param string $s Raw string.
 * @return string
 */
function gc_i18n_pot_escape( $s ) {
	return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s );
}

/**
 * Render the POT document for a set of entries.
 *
 * @param array<string, array<string, mixed>> $entries Entries.
 * @param string                              $version Plugin version.
 * @return string
 */
function gc_i18n_render_pot( array $entries, $version ) {
	$lines   = array();
	$lines[] = '# Copyright (C) 2026 FaraCart Contributors';
	$lines[] = '# This file is distributed under the same license as the FaraCart plugin (GPL-2.0-or-later).';
	$lines[] = '#';
	$lines[] = 'msgid ""';
	$lines[] = 'msgstr ""';
	$lines[] = '"Project-Id-Version: FaraCart ' . gc_i18n_pot_escape( $version ) . '\\n"';
	$lines[] = '"Report-Msgid-Bugs-To: https://faracart.com/support\\n"';
	$lines[] = '"MIME-Version: 1.0\\n"';
	$lines[] = '"Content-Type: text/plain; charset=UTF-8\\n"';
	$lines[] = '"Content-Transfer-Encoding: 8bit\\n"';
	$lines[] = '"POT-Creation-Date: ' . gmdate( 'Y-m-d\\TH:i:s\\Z' ) . '\\n"';
	$lines[] = '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"';
	$lines[] = '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"';
	$lines[] = '"Language-Team: LANGUAGE <LL@li.org>\\n"';
	$lines[] = '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"';
	$lines[] = '"X-Domain: faracart\\n"';
	$lines[] = '';

	$sorted = $entries;

	usort( $sorted, function ( $a, $b ) {
		return strcmp( $a['msgctxt'] . "\x04" . $a['msgid'] . "\x04" . $a['msgid_plural'], $b['msgctxt'] . "\x04" . $b['msgid'] . "\x04" . $b['msgid_plural'] );
	} );

	foreach ( $sorted as $entry ) {
		foreach ( $entry['refs'] as $ref ) {
			$lines[] = '#: ' . $ref;
		}

		if ( '' !== $entry['msgctxt'] ) {
			$lines[] = 'msgctxt "' . gc_i18n_pot_escape( $entry['msgctxt'] ) . '"';
		}

		$lines[] = 'msgid "' . gc_i18n_pot_escape( $entry['msgid'] ) . '"';

		if ( '' !== $entry['msgid_plural'] ) {
			$lines[] = 'msgid_plural "' . gc_i18n_pot_escape( $entry['msgid_plural'] ) . '"';
		}

		$lines[] = 'msgstr ""';
		$lines[] = '';
	}

	return implode( "\n", $lines ) . "\n";
}

/**
 * Parse the msgid/msgctxt/plural keys out of an existing POT.
 *
 * @param string $content POT content.
 * @return array<string, true> key set (same keying as extraction).
 */
function gc_i18n_pot_keys( $content ) {
	$keys = array();
	$ctx  = '';
	$id   = '';
	$plu  = '';

	foreach ( preg_split( '/\r?\n/', $content ) as $line ) {
		if ( 0 === strpos( $line, 'msgctxt ' ) ) {
			$ctx = (string) json_decode( substr( $line, 8 ) );
		} elseif ( 0 === strpos( $line, 'msgid ' ) ) {
			$id = (string) json_decode( substr( $line, 6 ) );
		} elseif ( 0 === strpos( $line, 'msgid_plural ' ) ) {
			$plu = (string) json_decode( substr( $line, 13 ) );
		} elseif ( 0 === strpos( $line, 'msgstr ' ) ) {
			// The empty msgid "" header entry is not a translatable string.
			if ( '' !== $id ) {
				$keys[ $ctx . "\x04" . $id . "\x04" . $plu ] = true;
			}

			$ctx = '';
			$id  = '';
			$plu = '';
		}
	}

	return $keys;
}

$entries = array();

foreach ( gc_i18n_files( $root ) as $file ) {
	foreach ( gc_i18n_extract_file( $file, $root ) as $key => $entry ) {
		if ( isset( $entries[ $key ] ) ) {
			$entries[ $key ]['refs'] = array_merge( $entries[ $key ]['refs'], $entry['refs'] );
		} else {
			$entries[ $key ] = $entry;
		}
	}
}

$version = '0.1.0';

if ( is_file( $root . '/ravis-faracart.php' ) ) {
	$header = (string) file_get_contents( $root . '/ravis-faracart.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( preg_match( '/^ \* Version:\s*(.+)$/m', $header, $m ) ) {
		$version = trim( $m[1] );
	}
}

$pot = gc_i18n_render_pot( $entries, $version );

if ( $check ) {
	$current = is_file( $out ) ? (string) file_get_contents( $out ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	$current_keys = gc_i18n_pot_keys( $current );
	$new_keys     = array();

	foreach ( $entries as $key => $entry ) {
		$new_keys[ $key ] = true;
	}

	$missing = array_diff_key( $new_keys, $current_keys );
	$stale   = array_diff_key( $current_keys, $new_keys );

	if ( $missing || $stale ) {
		fwrite( STDERR, "POT is out of date ({$out}).\n" );

		foreach ( $missing as $key => $_ ) {
			$parts = explode( "\x04", $key );
			fwrite( STDERR, "  missing: " . ( $parts[1] ?? $parts[0] ) . "\n" );
		}

		foreach ( $stale as $key => $_ ) {
			$parts = explode( "\x04", $key );
			fwrite( STDERR, "  stale:   " . ( $parts[1] ?? $parts[0] ) . "\n" );
		}

		exit( 1 );
	}

	fwrite( STDOUT, 'POT is up to date (' . count( $entries ) . " entries).\n" );
	exit( 0 );
}

$dir = dirname( $out );

if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}

file_put_contents( $out, $pot ); // phpcs:ignore WordPress.WP.AlternativeFunctions

fwrite( STDOUT, 'Wrote ' . count( $entries ) . " entries to {$out}.\n" );
exit( 0 );
