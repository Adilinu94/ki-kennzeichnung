<?php
/**
 * The "second active copy" guard, one scenario per process (see guard-case.php).
 *   php tests/test-guard.php
 */
$fails = 0;
function t( string $name, bool $cond, string $extra = '' ): void {
	global $fails;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $name . ( $cond || '' === $extra ? '' : " [$extra]" ) . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}
function run_case( string $case ): array {
	$raw = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/guard-case.php' ) . ' ' . escapeshellarg( $case ) . ' 2>&1' );
	$j   = json_decode( trim( (string) substr( $raw, (int) strrpos( $raw, '{' ) ) ), true );
	return array( is_array( $j ) ? $j : array(), $raw );
}
preg_match( "/define\( 'KIKZ_VERSION', '([\d.]+)' \);/", (string) file_get_contents( __DIR__ . '/../ki-kennzeichnung.php' ), $m );
$version = $m[1] ?? '?';

echo "An old 1.x copy is already loaded (it declares the class but never defined KIKZ_VERSION)\n";
list( $r, $raw ) = run_case( 'old1x' );
t( 'no fatal error: this copy steps aside', array() !== $r && false === stripos( $raw, 'Fatal' ), $raw );
t( 'it warns the administrator', ! empty( $r['notice_hooked'] ) );
t( 'it does not load itself (no constants, no hooks)', null === $r['constant'] && empty( $r['plugin_loaded'] ) );

echo "Another 2.x copy is already loaded\n";
list( $r, $raw ) = run_case( 'copy2x' );
t( 'no fatal error', array() !== $r && false === stripos( $raw, 'Fatal' ), $raw );
t( 'this copy warns and does not initialise', ! empty( $r['notice_hooked'] ) && empty( $r['plugin_loaded'] ) );
t( 'it does not even declare its classes', empty( $r['init_available'] ) );

echo "Nothing else is loaded\n";
list( $r, $raw ) = run_case( 'clean' );
t( 'the plugin loads normally (regression: the guard must never lock out the only copy)', array() !== $r && $version === ( $r['constant'] ?? null ), $raw );
t( 'it hooks into plugins_loaded and shows no warning', ! empty( $r['plugin_loaded'] ) && empty( $r['notice_hooked'] ) );
t( 'the bootstrap class is available', ! empty( $r['init_available'] ) );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
