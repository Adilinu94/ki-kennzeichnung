<?php
// One scenario per process (a fatal error would end the whole test run otherwise).
//   php tests/guard-case.php old1x   -> a 1.x class (no KIKZ_VERSION constant) is already loaded
//   php tests/guard-case.php copy2x  -> another 2.x copy already defined KIKZ_VERSION
//   php tests/guard-case.php clean   -> nothing loaded yet: the plugin must load normally
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function add_action( $tag ) { $GLOBALS['hooks'][] = $tag; }
function add_filter( $tag ) { $GLOBALS['hooks'][] = $tag; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/'; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function register_deactivation_hook() {}
function current_user_can() { return true; }
function esc_html__( $s ) { return $s; }
function add_shortcode() {}

$case = $argv[1] ?? 'clean';
if ( 'old1x' === $case ) {
	eval( 'final class KI_Kennzeichnung { private const VERSION = "1.3.0"; }' ); // What 1.3.0 declares.
}
if ( 'copy2x' === $case ) {
	define( 'KIKZ_VERSION', '2.0.0' );
}
$result = require __DIR__ . '/../ki-kennzeichnung.php';
echo json_encode( array(
	'returned'       => $result,
	'notice_hooked'  => in_array( 'admin_notices', $GLOBALS['hooks'], true ),
	'plugin_loaded'  => in_array( 'plugins_loaded', $GLOBALS['hooks'], true ),
	'constant'       => defined( 'KIKZ_VERSION' ) ? KIKZ_VERSION : null,
	'init_available' => method_exists( 'KI_Kennzeichnung', 'init' ),
) );
