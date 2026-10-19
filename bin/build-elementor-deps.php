<?php
/**
 * Rebuilds Elementor's scoped dependencies (Twig, namespace "ElementorDeps") from the upstream
 * sources, because a git checkout of Elementor does not contain the generated vendor_prefixed/.
 * Needed only to render Elementor 4 atomic elements in the test environment.
 *
 *   php bin/build-elementor-deps.php <twig-src> <deprecation-contracts-src> <elementor-plugin-dir>
 */

if ( $argc < 4 ) {
	fwrite( STDERR, "usage: build-elementor-deps.php <twig> <deprecation-contracts> <elementor-dir>\n" );
	exit( 2 );
}
list( , $twig, $contracts, $target ) = $argv;

function kikz_prefix_code( string $code ): string {
	// "Twig\Foo" and "Twig\\Foo" (string literals) -> "ElementorDeps\Twig\Foo".
	$code = preg_replace( '/(?<![A-Za-z0-9_\\\\])Twig(\\\\{1,2})(?=[A-Za-z])/', 'ElementorDeps$1Twig$1', $code );
	$code = preg_replace( '/\bnamespace Twig;/', 'namespace ElementorDeps\\Twig;', $code );
	// Symfony contracts that Twig references.
	$code = preg_replace( '/(?<![A-Za-z0-9_\\\\])Symfony(\\\\{1,2})(?=[A-Za-z])/', 'ElementorDeps$1Symfony$1', $code );
	return $code;
}

function kikz_copy_prefixed( string $from, string $to ): int {
	$n  = 0;
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$rel = substr( $file->getPathname(), strlen( $from ) );
		$dst = $to . $rel;
		if ( ! is_dir( dirname( $dst ) ) ) {
			mkdir( dirname( $dst ), 0777, true );
		}
		file_put_contents( $dst, kikz_prefix_code( (string) file_get_contents( $file->getPathname() ) ) );
		$n++;
	}
	return $n;
}

$base = rtrim( $target, '/' ) . '/vendor_prefixed/twig';
$a    = kikz_copy_prefixed( rtrim( $twig, '/' ) . '/src', $base . '/twig/src' );
$b    = kikz_copy_prefixed( rtrim( $contracts, '/' ), $base . '/symfony/deprecation-contracts' );

// Autoloader (Elementor itself relies on Composer's classmap, which a checkout does not have).
file_put_contents(
	rtrim( $target, '/' ) . '/vendor_prefixed/autoload-shim.php',
	"<?php\nspl_autoload_register( static function ( \$class ) {\n\t\$prefix = 'ElementorDeps\\\\Twig\\\\';\n\tif ( 0 !== strpos( \$class, \$prefix ) ) {\n\t\treturn;\n\t}\n\t\$file = __DIR__ . '/twig/twig/src/' . str_replace( '\\\\', '/', substr( \$class, strlen( \$prefix ) ) ) . '.php';\n\tif ( is_file( \$file ) ) {\n\t\trequire \$file;\n\t}\n} );\n"
);
echo "prefixed $a Twig files and $b contract files into $base\n";
