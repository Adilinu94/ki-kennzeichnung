<?php
/**
 * Integration tests against a real WordPress install (SQLite is fine).
 *
 *   KIKZ_WP_LOAD=/path/to/wp/wp-load.php php tests/integration/run.php [core|elementor]
 *
 * The plugin must be active (and Elementor for the elementor suite). The tests
 * create their own attachments/posts and clean up after themselves.
 */

$kikz_wp_load = getenv( 'KIKZ_WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $kikz_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found. Set KIKZ_WP_LOAD.\n" );
	exit( 2 );
}
require $kikz_wp_load;
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

if ( ! class_exists( 'KI_Kennzeichnung' ) ) {
	fwrite( STDERR, "KI-Kennzeichnung is not active.\n" );
	exit( 2 );
}

$GLOBALS['kikz_fails']   = 0;
$GLOBALS['kikz_checks']  = 0;
$GLOBALS['kikz_cleanup'] = array();

function t( string $name, $cond, string $extra = '' ): void {
	$GLOBALS['kikz_checks']++;
	$ok = (bool) $cond;
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $name . ( $ok || '' === $extra ? '' : " [$extra]" ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['kikz_fails']++;
	}
}

function section( string $title ): void {
	echo "\n== $title ==\n";
}

/** Gradient JPEG that is mid-brightness everywhere, so a dark badge is detectable. */
function kikz_make_image( int $w, int $h, string $type = 'jpeg' ): string {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y += 4 ) {
		for ( $x = 0; $x < $w; $x += 4 ) {
			$c = imagecolorallocate( $im, (int) ( 130 + 90 * $x / $w ), (int) ( 140 + 80 * $y / $h ), 190 );
			imagefilledrectangle( $im, $x, $y, $x + 3, $y + 3, $c );
		}
	}
	$file = wp_tempnam( 'kikz' ) . ( 'png' === $type ? '.png' : '.jpg' );
	'png' === $type ? imagepng( $im, $file ) : imagejpeg( $im, $file, 92 );
	imagedestroy( $im );
	return $file;
}

/** Upload a file through the normal pipeline (metadata generation incl. scaling). */
function kikz_upload( string $src, string $name ): int {
	$up   = wp_upload_dir();
	$dest = wp_unique_filename( $up['path'], $name );
	copy( $src, $up['path'] . '/' . $dest );
	$file = $up['path'] . '/' . $dest;
	$id   = wp_insert_attachment(
		array(
			'post_mime_type' => wp_check_filetype( $dest )['type'],
			'post_title'     => pathinfo( $dest, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
		),
		$file
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	$GLOBALS['kikz_cleanup'][] = $id;
	return (int) $id;
}

function kikz_luma( string $file, float $fx, float $fy ): int {
	$im = imagecreatefromstring( (string) file_get_contents( $file ) );
	$c  = imagecolorsforindex( $im, imagecolorat( $im, (int) ( $fx * imagesx( $im ) ), (int) ( $fy * imagesy( $im ) ) ) );
	imagedestroy( $im );
	return (int) ( 0.299 * $c['red'] + 0.587 * $c['green'] + 0.114 * $c['blue'] );
}

/** Luma inside the pill (top band between pill edge and text) of a stamped file. */
function kikz_pill_luma( string $file, array $rect ): int {
	return kikz_luma( $file, $rect[0] + $rect[2] * 0.5, $rect[1] + $rect[3] * 0.1 );
}

function kikz_dir( int $id ): string {
	return dirname( (string) get_attached_file( $id ) );
}

function kikz_reset_options(): void {
	foreach ( array( 'display_mode' => 'auto', 'grace_days' => '7', 'badge_text' => 'KI-generiert', 'badge_corner' => 'bottom-right', 'badge_size' => 'medium', 'badge_theme' => 'dark', 'badge_short' => '0', 'auto_detect' => '1', 'alt_suffix' => 'KI-generiert', 'badge_margin' => '10' ) as $k => $v ) {
		update_option( 'kikz_' . $k, $v );
	}
}

/** Remove leftovers of an earlier aborted run so every run starts from a clean slate. */
function kikz_purge_test_data(): void {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND (p.post_title LIKE 'kikz-%' OR p.post_title IN ('holiday-photo','ordinary-landscape','admin-owned','midjourney-castle','dbg') OR p.post_title LIKE 'dbg-%')" );
	foreach ( (array) $ids as $leftover ) {
		wp_delete_attachment( (int) $leftover, true );
	}
	delete_option( 'kikz_queue' );
	delete_transient( 'kikz_queue_lock' );
	delete_transient( 'kikz_purge_pending' );
}

kikz_purge_test_data();
kikz_reset_options();
wp_set_current_user( 1 );
$suite = $argv[1] ?? 'all';

if ( in_array( $suite, array( 'all', 'core' ), true ) ) {
	require __DIR__ . '/core.php';
}
if ( in_array( $suite, array( 'all', 'elementor' ), true ) ) {
	require __DIR__ . '/elementor.php';
}
if ( in_array( $suite, array( 'all', 'uninstall' ), true ) ) {
	require __DIR__ . '/uninstall.php';
}
if ( 'e2e' === $suite ) {
	require __DIR__ . '/e2e.php'; // Needs a running web server, see e2e.sh.
}

// Cleanup.
foreach ( array_unique( $GLOBALS['kikz_cleanup'] ) as $cid ) {
	if ( 'attachment' === get_post_type( $cid ) ) {
		wp_delete_attachment( $cid, true );
	} elseif ( get_post( $cid ) ) {
		wp_delete_post( $cid, true );
	}
}

echo "\n" . ( $GLOBALS['kikz_fails'] ? $GLOBALS['kikz_fails'] . ' FAILED of ' : 'ALL PASSED: ' ) . $GLOBALS['kikz_checks'] . " checks\n";
exit( $GLOBALS['kikz_fails'] ? 1 : 0 );
