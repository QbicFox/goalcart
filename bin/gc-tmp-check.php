<?php
// Quick check: does the POT extractor extract "%1$s"-style placeholders?
$tests = array(
	'plain %s'    => 'Value %s here',
	'positional %1$s' => 'Value %1$s here',
	'positional %2$d' => 'Value %2$d here',
	'plain %d'    => 'Count %d here',
);

$q = "'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"";

foreach ( $tests as $label => $literal ) {
	$source = "__('" . $literal . "', 'goalcart');";
	if ( ! preg_match( '/\\b(?:__|_e)\\(\\s*(' . $q . ')(?:\\s*,\\s*(' . $q . '))?\\s*\\)/s', $source, $m ) ) {
		echo "{$label}: NO MATCH\n";
		continue;
	}
	$body = substr( $m[1], 1, -1 );
	// Mirror gc_i18n_unquote's interpolation guard.
	$skipped = (bool) preg_match( '/\\$(?:\\{?[A-Za-z_])/', $body );
	echo "{$label}: extracted=" . ( $skipped ? 'NO (interpolation guard)' : 'YES' ) . " literal={$literal}\n";
}

echo "--- POT contains %1\$s msgids? ---\n";
$pot = (string) file_get_contents( __DIR__ . '/../languages/goalcart.pot' );
$found = array();
foreach ( preg_split( "/\r?\n/", $pot ) as $line ) {
	if ( 0 === strpos( $line, 'msgid "' ) && false !== strpos( $line, '$' ) ) {
		$found[] = trim( $line );
	}
}
echo 'msgids containing a dollar: ' . count( $found ) . "\n";
foreach ( array_slice( $found, 0, 10 ) as $f ) {
	echo "  {$f}\n";
}
