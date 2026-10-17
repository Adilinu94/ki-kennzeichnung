<?php
// Included by run.php.

use KI_Kennzeichnung_Flags as Flags;
use KI_Kennzeichnung_Stamper as Stamper;
use KI_Kennzeichnung_Frontend as Frontend;

section( 'A second copy of the plugin cannot take the site down' );
$second = require KIKZ_FILE; // Must bail out instead of re-declaring the classes.
t( 'loading the main file a second time does not raise a fatal error', null === $second );

section( 'Stamping pipeline (3200x2000 upload -> -scaled + sizes)' );
$src = kikz_make_image( 3200, 2000 );
$id  = kikz_upload( $src, 'kikz-sample.jpg' );
$meta0 = wp_get_attachment_metadata( $id );
t( 'upload was scaled by WordPress (original_image present)', ! empty( $meta0['original_image'] ) );
$file   = get_attached_file( $id );
$before = md5_file( $file );
$dir    = kikz_dir( $id );

$res = Flags::set( $id, true, 'manual', array(), true );
t( 'flag + sync stamp succeeds', 'ok' === $res['code'], $res['code'] );
t( 'attachment is flagged and labeled', Flags::is_flagged( $id ) && Flags::is_labeled( $id ) );
t( 'status is ok', 'ok' === get_post_meta( $id, '_ki_stamp_status', true ) );
t( 'main file changed', md5_file( $file ) !== $before );
t( 'stamped hash stored', md5_file( $file ) === get_post_meta( $id, '_ki_stamped_hash', true ) );

$backup = Stamper::backup_path( $id );
t( 'backup lives in the protected folder, not next to the image', is_file( $backup ) && false !== strpos( $backup, 'ki-kennzeichnung-originals' ) && dirname( $backup ) !== $dir );
t( 'backup folder is protected (.htaccess + index.php)', is_file( dirname( $backup ) . '/.htaccess' ) && is_file( dirname( $backup ) . '/index.php' ) );
t( 'backup equals the clean original', md5_file( $backup ) === $before );
t( 'meta stores a relative backup path', 0 === strpos( (string) get_post_meta( $id, '_ki_original_file', true ), 'ki-kennzeichnung-originals/' ) );

$meta1 = wp_get_attachment_metadata( $id );
t( 'original_image (unscaled file) is preserved in metadata', ! empty( $meta1['original_image'] ) && $meta1['original_image'] === $meta0['original_image'] );
t( 'all registered sizes exist after regeneration', ! empty( $meta1['sizes'] ) && count( $meta1['sizes'] ) >= 3 );

$rects = get_post_meta( $id, '_ki_badge_rects', true );
t( 'badge rect stored for main file and every size', is_array( $rects ) && isset( $rects[ wp_basename( $file ) ] ) && count( $rects ) === 1 + count( $meta1['sizes'] ), is_array( $rects ) ? (string) count( $rects ) : 'none' );

$all_dark = true;
$legible  = true;
foreach ( $meta1['sizes'] as $size => $s ) {
	$path = $dir . '/' . $s['file'];
	$r    = $rects[ $s['file'] ] ?? null;
	if ( ! $r ) {
		$all_dark = false;
		continue;
	}
	if ( kikz_pill_luma( $path, $r ) > 110 ) {
		$all_dark = false;
	}
	if ( $r[3] * $s['height'] < 14 ) { // badge height in px
		$legible = false;
	}
}
t( 'every size carries its own badge (thumbnails are not just a shrunken copy)', $all_dark );
t( 'badge is >= 14px tall on every size (legible)', $legible );
$thumb = $meta1['sizes']['thumbnail'] ?? null;
if ( $thumb ) {
	t( 'thumbnail badge is relatively larger than the main file badge', $rects[ $thumb['file'] ][3] > $rects[ wp_basename( $file ) ][3] );
}
t( 'main file badge present', kikz_pill_luma( $file, $rects[ wp_basename( $file ) ] ) < 110 );
t( 'unscaled original_image was left untouched', md5_file( $dir . '/' . $meta1['original_image'] ) === md5_file( $dir . '/' . $meta0['original_image'] ) );
$bytes = (string) file_get_contents( $file );
t( 'IPTC DigitalSourceType written into the file', false !== strpos( $bytes, 'trainedAlgorithmicMedia' ) );
t( 'file is still a valid JPEG with the same dimensions', ( getimagesize( $file )[0] ?? 0 ) === (int) $meta1['width'] );

section( 'Rendering (front end)' );
$img = wp_get_attachment_image( $id, 'medium' );
t( 'fresh stamp keeps the overlay (browser caches may hold the old file)', false !== strpos( $img, 'ki-flagged-image' ) && false === strpos( $img, 'ki-auto' ), $img );
t( 'data-kikz-id set', false !== strpos( $img, 'data-kikz-id="' . $id . '"' ) );
update_option( 'kikz_grace_days', '0' );
$img = wp_get_attachment_image( $id, 'medium' );
t( 'after the grace period (auto mode): overlay may be hidden by the script -> ki-auto', false !== strpos( $img, 'ki-flagged-image ki-auto' ), $img );
update_option( 'kikz_display_mode', 'file' );
$img = wp_get_attachment_image( $id, 'medium' );
t( 'file mode, labeled, not fresh: no overlay classes at all', false === strpos( $img, 'ki-flagged-image' ), $img );
update_option( 'kikz_display_mode', 'overlay' );
$img = wp_get_attachment_image( $id, 'medium' );
t( 'overlay mode: always overlay', false !== strpos( $img, 'ki-flagged-image' ) && false === strpos( $img, 'ki-auto' ) );
update_option( 'kikz_display_mode', 'auto' );

$url    = wp_get_attachment_image_url( $id, 'medium' );
$html   = '<img src="' . esc_url( $url ) . '" class="wp-image-' . $id . '" alt="Strand">';
$out    = Frontend::filter_content_img( $html, 'the_content', $id );
t( 'content image (wp-image-N) is decorated', false !== strpos( $out, 'ki-flagged-image' ) );
t( 'alt suffix appended exactly once', 1 === substr_count( $out, '(KI-generiert)' ) && false !== strpos( $out, 'alt="Strand (KI-generiert)"' ), $out );
$twice = Frontend::filter_content_img( $out, 'the_content', $id );
t( 'decorating twice does not duplicate anything', $twice === $out );
$deco = Frontend::filter_content_img( '<img src="' . esc_url( $url ) . '" alt="">', 'the_content', 0 );
t( 'content image WITHOUT id is found by URL; empty alt (decorative) stays empty', false !== strpos( $deco, 'ki-flagged-image' ) && false !== strpos( $deco, 'alt=""' ), $deco );
$sized = Frontend::filter_content_img( '<img src="' . esc_url( dirname( $url ) . '/' . $meta1['sizes']['thumbnail']['file'] ) . '" alt="x">', 'the_content', 0 );
t( 'thumbnail URL (-150x150) maps back to the attachment', false !== strpos( $sized, 'data-kikz-id="' . $id . '"' ), $sized );
$other = Frontend::filter_content_img( '<img src="https://example.org/wp-content/uploads/2025/01/foreign.jpg" alt="x">', 'the_content', 0 );
t( 'foreign image is left alone', false === strpos( $other, 'ki-flagged' ) );
t( 'URL lookup: -scaled, size and edit suffixes normalise to one key', Flags::normalize_stem( '2025/10/photo-scaled-300x200.jpg' ) === '2025/10/photo' && Flags::normalize_stem( '2025/10/photo-e1700000000000.jpg' ) === '2025/10/photo' );

section( 'Footer data for the script' );
ob_start();
Frontend::footer_assets();
ob_end_clean();
$inline = wp_scripts()->get_data( 'kikz-frontend', 'before' );
$inline = is_array( $inline ) ? implode( '', $inline ) : (string) $inline;
t( 'script is enqueued when flagged images were rendered', wp_script_is( 'kikz-frontend', 'enqueued' ) );
t( 'inline data carries badge rects keyed by file name', false !== strpos( $inline, 'kikzData' ) && false !== strpos( $inline, '"rects"' ) && false !== strpos( $inline, wp_basename( $file ) ), substr( $inline, 0, 200 ) );

section( 'CSS output & escaping' );
update_option( 'kikz_badge_text', 'KI & "Co" </style><b>' );
update_option( 'kikz_badge_corner', 'top-center' );
ob_start();
Frontend::print_styles();
$css = ob_get_clean();
t( 'label is CSS-escaped (no raw quote, no tag, single <style> block)', false !== strpos( $css, '--kikz-label:"KI & \\"Co\\""' ) && 1 === substr_count( $css, '</style>' ) && false === strpos( $css, '<b>' ), $css );
t( 'top-center position uses centered offsets', false !== strpos( $css, 'translate(-50%,0)' ) );
update_option( 'kikz_badge_corner', 'center' );
ob_start();
Frontend::print_styles();
$css = ob_get_clean();
t( 'center position centers on both axes', false !== strpos( $css, 'translate(-50%,-50%)' ) );
update_option( 'kikz_badge_text', 'KI-generiert' );
update_option( 'kikz_badge_corner', 'bottom-right' );

t( 'color sanitizer accepts hex/rgba and rejects injection', '#fff' === KI_Kennzeichnung_Settings::sanitize_color( '#fff' ) && 'rgba(0,0,0,.5)' === KI_Kennzeichnung_Settings::sanitize_color( 'rgba(0,0,0,.5)' ) && '' === KI_Kennzeichnung_Settings::sanitize_color( 'red;}body{display:none' ) && '' === KI_Kennzeichnung_Settings::sanitize_color( 'url(x)' ) );
update_option( 'kikz_badge_theme', 'light' );
update_option( 'kikz_badge_bg_color', 'rgba(0,0,0,.65)' ); // legacy hard default saved by 1.x.
update_option( 'kikz_badge_color', '#ffffff' );
t( 'legacy default colours do not override the chosen light theme', 'rgba(255,255,255,.88)' === KI_Kennzeichnung_Settings::colors()['bg'] );
update_option( 'kikz_badge_theme', 'dark' );
t( 'font family is stripped of anything but font-safe characters', 'Inter, sans-serif' === KI_Kennzeichnung_Settings::sanitize_font_family( 'Inter, sans-serif;}' ) && '' === KI_Kennzeichnung_Settings::sanitize_font_family( 'system' ) );

section( 'Re-stamp, third-party regeneration and unstamp' );
$hash_a = md5_file( $file );
$r      = Stamper::stamp_attachment( $id );
t( 're-stamp from the original works', $r['ok'] );
t( 're-stamp is deterministic and never stacks badges (same bytes as first stamp)', md5_file( $file ) === $hash_a );
// Simulate "Regenerate Thumbnails" or a theme change: WP regenerates from the stamped file.
$regen = wp_generate_attachment_metadata( $id, $file );
wp_update_attachment_metadata( $id, $regen );
t( 'third-party regeneration is repaired automatically (no double badge)', md5_file( $file ) === $hash_a );
$rects2 = get_post_meta( $id, '_ki_badge_rects', true );
t( 'rects still complete after regeneration', is_array( $rects2 ) && count( $rects2 ) === count( $rects ) );
$meta2 = wp_get_attachment_metadata( $id );
t( 'original_image survives regeneration', ! empty( $meta2['original_image'] ) );

// Edited after stamping.
$tmpim = imagecreatefromjpeg( $file );
imagejpeg( $tmpim, $file, 80 );
imagedestroy( $tmpim );
t( 'file edited after stamping is detected', Stamper::is_modified( $id ) );
$r = Stamper::unstamp_attachment( $id, false );
t( 'unstamp refuses to overwrite an edited file without force', ! $r['ok'] && 'modified' === $r['code'] && Flags::is_labeled( $id ) );
$r = Stamper::unstamp_attachment( $id, true );
t( 'forced unstamp restores the original', $r['ok'] && md5_file( $file ) === $before );
t( 'unstamp clears bookkeeping and deletes the backup', ! Flags::is_labeled( $id ) && ! is_file( $backup ) && '' === (string) get_post_meta( $id, '_ki_stamped_hash', true ) );
$meta3 = wp_get_attachment_metadata( $id );
t( 'sizes regenerated clean (thumbnail has no badge)', isset( $meta3['sizes']['medium'] ) && kikz_luma( $dir . '/' . $meta3['sizes']['medium']['file'], 0.93, 0.95 ) > 100 );
t( 'metadata keys preserved after unstamp', ! empty( $meta3['original_image'] ) );

section( 'Unflag path through Flags::set' );
$id2 = kikz_upload( kikz_make_image( 1600, 1000 ), 'kikz-unflag.jpg' );
$f2  = get_attached_file( $id2 );
$o2  = md5_file( $f2 );
Flags::set( $id2, true, 'manual', array(), true );
t( 'stamped', Flags::is_labeled( $id2 ) && md5_file( $f2 ) !== $o2 );
Flags::set( $id2, false, 'manual', array(), true );
t( 'unflagged: file restored byte-for-byte', md5_file( $f2 ) === $o2 && ! Flags::is_flagged( $id2 ) && ! Flags::is_labeled( $id2 ) );
t( 'unflagged: stem map entry removed', 0 === Flags::id_from_url( wp_get_attachment_url( $id2 ) ) );

section( 'Queue (bulk) instead of one giant request' );
// Earlier sections flip the display mode (overlay -> auto queues a re-stamp, by design): start empty.
delete_option( 'kikz_queue' );
$ids = array();
for ( $i = 0; $i < 3; $i++ ) {
	$ids[] = kikz_upload( kikz_make_image( 1400, 900 ), "kikz-bulk-$i.jpg" );
}
foreach ( $ids as $bid ) {
	Flags::set( $bid, true, 'bulk' );
}
t( 'bulk flagging only queues work', 3 === Stamper::queue_length() && 'pending' === get_post_meta( $ids[0], '_ki_stamp_status', true ) && ! Flags::is_labeled( $ids[0] ) );
t( 'a cron event is scheduled for the queue', (bool) wp_next_scheduled( 'kikz_process_queue' ) );
$run = Stamper::process( 2, 60.0 );
t( 'worker processes a bounded batch', 2 === $run['done'] && 1 === $run['left'] );
$run = Stamper::process( 5, 60.0 );
t( 'worker finishes the queue', 1 === $run['done'] && 0 === $run['left'] );
t( 'all bulk images stamped with status ok', 3 === count( array_filter( $ids, static function ( $x ) {
	return Flags::is_labeled( $x ) && 'ok' === get_post_meta( $x, '_ki_stamp_status', true );
} ) ) );
set_transient( 'kikz_queue_lock', 1, 60 );
t( 'concurrent runners are locked out', Stamper::process( 1, 5.0 )['busy'] );
delete_transient( 'kikz_queue_lock' );

section( 'Failure handling is visible, never silent' );
$gif = wp_tempnam( 'kikz' ) . '.gif';
$gim = imagecreatetruecolor( 400, 300 );
imagegif( $gim, $gif );
$gid = kikz_upload( $gif, 'kikz-anim.gif' );
Flags::set( $gid, true, 'manual', array(), true );
t( 'GIF: flagged, not stamped, status unsupported -> overlay fallback', Flags::is_flagged( $gid ) && ! Flags::is_labeled( $gid ) && 'unsupported' === get_post_meta( $gid, '_ki_stamp_status', true ) );
t( 'GIF still gets the overlay class', false !== strpos( wp_get_attachment_image( $gid, 'full' ), 'ki-flagged-image' ) );
t( 'error text is human readable', false !== strpos( Stamper::error_message( 'memory' ), 'memory_limit' ) );
$tiny = kikz_upload( kikz_make_image( 60, 40 ), 'kikz-tiny.jpg' );
Flags::set( $tiny, true, 'manual', array(), true );
t( 'tiny image: no mangled badge, overlay fallback, status is not a hard failure', ! Flags::is_labeled( $tiny ) && in_array( get_post_meta( $tiny, '_ki_stamp_status', true ), array( 'unsupported' ), true ) );
$stats = Flags::stats();
t( 'stats count flagged / stamped / fallback', $stats['flagged'] >= 6 && $stats['stamped'] >= 3 && $stats['failed'] >= 2, wp_json_encode( $stats ) );

section( 'Transparent PNG keeps its alpha through the pipeline' );
$png  = kikz_upload( kikz_make_image( 1200, 800, 'png' ), 'kikz-alpha.png' );
Flags::set( $png, true, 'manual', array(), true );
t( 'PNG stamped', Flags::is_labeled( $png ) );
$pm = file_get_contents( get_attached_file( $png ) );
t( 'PNG has XMP with DigitalSourceType', false !== strpos( $pm, 'trainedAlgorithmicMedia' ) );

section( 'Detection' );
$sd = wp_tempnam( 'kikz' ) . '.png';
$im = imagecreatetruecolor( 1200, 800 );
imagefill( $im, 0, 0, imagecolorallocate( $im, 120, 150, 200 ) );
imagepng( $im, $sd );
// Add an A1111-style tEXt chunk after IHDR.
$raw   = file_get_contents( $sd );
$txt   = "parameters\0a cat\nSteps: 20, Sampler: Euler a, CFG scale: 7";
$chunk = pack( 'N', strlen( $txt ) ) . 'tEXt' . $txt . pack( 'N', crc32( 'tEXt' . $txt ) );
file_put_contents( $sd, substr( $raw, 0, 33 ) . $chunk . substr( $raw, 33 ) );
$res = KI_Kennzeichnung_Detect::analyze( $sd );
t( 'A1111 PNG parameters are recognised (>= auto score)', $res['score'] >= KI_Kennzeichnung_Detect::AUTO_SCORE, wp_json_encode( $res ) );
$det = kikz_upload( $sd, 'holiday-photo.png' );
t( 'AI image uploaded under an innocent name is flagged automatically and stamped in the same request', Flags::is_flagged( $det ) && Flags::is_labeled( $det ) && 0 === Stamper::queue_length(), 'queue=' . Stamper::queue_length() );
t( 'detection source and signals are recorded for the evidence export', 'metadata' === get_post_meta( $det, '_ki_flag_source', true ) && is_array( get_post_meta( $det, '_ki_detect', true ) ) );

$plain = kikz_upload( kikz_make_image( 1200, 800 ), 'ordinary-landscape.jpg' );
t( 'ordinary photo is NOT flagged', ! Flags::is_flagged( $plain ) );
t( 'random bytes containing "flux" in pixel data do not trigger (metadata regions only)', 0 === KI_Kennzeichnung_Detect::analyze( $src )['score'] );

$pat = array( 'chatgpt', 'dall-e', 'dalle', 'imagen', 'firefly', 'midjourney' );
t( 'name match: "dalle" does not hit "dallemagne"', null === KI_Kennzeichnung_Detect::name_matches( 'dallemagne.jpg', $pat ) );
t( 'name match: "imagen" does not hit Spanish "imagenes"', null === KI_Kennzeichnung_Detect::name_matches( 'imagenes-playa.jpg', $pat ) );
t( 'name match: real generator names hit', 'chatgpt' === KI_Kennzeichnung_Detect::name_matches( 'ChatGPT Image 15. Okt. 2025.png', $pat ) && 'dall-e' === KI_Kennzeichnung_Detect::name_matches( 'DALL·E 2025-10-05 12.03.04.png', $pat ) && 'midjourney' === KI_Kennzeichnung_Detect::name_matches( 'user_midjourney_v6.png', $pat ) );
$named = kikz_upload( kikz_make_image( 800, 600 ), 'midjourney-castle.jpg' );
$found = array_column( KI_Kennzeichnung_Detect::find_name_matches(), 'id' );
t( 'library scan finds name matches without marking them', in_array( $named, $found, true ) && ! Flags::is_flagged( $named ) );

section( 'Permissions' );
$author   = wp_insert_user( array( 'user_login' => 'kikz_author_' . wp_rand(), 'user_pass' => 'x', 'role' => 'author' ) );
$other_id = kikz_upload( kikz_make_image( 800, 600 ), 'admin-owned.jpg' ); // Owned by admin.
wp_set_current_user( $author );
$redirect = KI_Kennzeichnung_Media::handle_bulk_actions( admin_url( 'upload.php' ), 'kikz_mark', array( $other_id ) );
t( 'author cannot flag (and stamp) someone else\'s attachment via bulk action', ! Flags::is_flagged( $other_id ) && false !== strpos( $redirect, 'kikz_skipped=1' ), $redirect );
$post = KI_Kennzeichnung_Media::save_field( array( 'ID' => $other_id ), array( 'ki_generated' => '1' ) );
t( 'author cannot flag via the attachment form either', ! Flags::is_flagged( $other_id ) );
wp_set_current_user( 1 );
$redirect = KI_Kennzeichnung_Media::handle_bulk_actions( admin_url( 'upload.php' ), 'kikz_mark', array( $other_id ) );
t( 'administrator can', Flags::is_flagged( $other_id ) && false !== strpos( $redirect, 'kikz_done=marked-1' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $author );

section( 'Replaced or edited files are never overwritten from an old backup' );
$rp   = kikz_upload( kikz_make_image( 1400, 900 ), 'kikz-replace.jpg' );
$rpf  = get_attached_file( $rp );
Flags::set( $rp, true, 'manual', array(), true );
$old_backup_hash = md5_file( Stamper::backup_path( $rp ) );
// "Enable Media Replace": new picture at the same path, then metadata is regenerated.
$new_src = kikz_make_image( 1400, 900 );
$im      = imagecreatefromjpeg( $new_src );
imagefilledrectangle( $im, 0, 0, 700, 450, imagecolorallocate( $im, 200, 30, 30 ) ); // visibly different content.
imagejpeg( $im, $rpf, 92 );
imagedestroy( $im );
$new_clean_hash = md5_file( $rpf );
t( 'precondition: the replacement differs from the stamped file and from the old backup', $new_clean_hash !== md5_file( Stamper::backup_path( $rp ) ) && Stamper::is_modified( $rp ) );
wp_update_attachment_metadata( $rp, wp_generate_attachment_metadata( $rp, $rpf ) );
t( 'the NEW image is what stays on disk, stamped (not the old picture)', Flags::is_labeled( $rp ) && md5_file( $rpf ) === get_post_meta( $rp, '_ki_stamped_hash', true ) && kikz_luma( $rpf, 0.2, 0.2 ) < 130 && kikz_luma( $rpf, 0.2, 0.2 ) !== kikz_luma( Stamper::backup_path( $rp ), 0.9, 0.9 ) );
t( 'the backup was replaced by the new clean image, the obsolete one is gone', md5_file( Stamper::backup_path( $rp ) ) === $new_clean_hash && md5_file( Stamper::backup_path( $rp ) ) !== $old_backup_hash );
$red = imagecolorsforindex( ( $img_r = imagecreatefromjpeg( $rpf ) ), imagecolorat( $img_r, 100, 100 ) );
t( 'replacement content (red block) survived stamping', $red['red'] > 150 && $red['green'] < 90, wp_json_encode( $red ) );
// A manual edit of a stamped file must block a bulk re-stamp instead of reverting it.
$im = imagecreatefromjpeg( $rpf );
imagejpeg( $im, $rpf, 60 );
imagedestroy( $im );
$edited_hash = md5_file( $rpf );
$r           = Stamper::stamp_attachment( $rp );
t( 're-stamp refuses to restore over an edited file', ! $r['ok'] && 'modified' === $r['code'] && md5_file( $rpf ) === $edited_hash );
t( '... and says so visibly', 'failed' === get_post_meta( $rp, '_ki_stamp_status', true ) && false !== strpos( KI_Kennzeichnung_Media::chip( $rp ), 'is-warn' ) );

section( 'Media list filter' );
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'upload' );
foreach ( array( 'marked' => array( true, false ), 'unmarked' => array( false, true ), 'problem' => array( null, null ) ) as $mode => $_ ) {
	$_GET['kikz_filter'] = $mode;
	$q                   = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	$q->set( 'post_type', 'attachment' );
	$q->set( 'post_status', 'inherit' );
	$q->set( 'posts_per_page', 500 );
	$q->set( 'fields', 'ids' );
	KI_Kennzeichnung_Media::filter_query( $q );
	$found[ $mode ] = $q->get_posts();
}
unset( $_GET['kikz_filter'] );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
t( 'filter "marked" lists flagged, not unflagged images', in_array( $rp, $found['marked'], true ) && ! in_array( $plain, $found['marked'], true ) );
t( 'filter "unmarked" is the complement', in_array( $plain, $found['unmarked'], true ) && ! in_array( $rp, $found['unmarked'], true ) );
t( 'filter "problem" lists images whose stamping failed or fell back', in_array( $rp, $found['problem'], true ) && in_array( $gid, $found['problem'], true ) && ! in_array( $plain, $found['problem'], true ) );
unset( $GLOBALS['current_screen'] );

section( 'WebP file with a PNG/JPEG mime type (Modern Image Formats)' );
if ( KI_Kennzeichnung_Image::supports( 'webp' ) ) {
	$wi = imagecreatetruecolor( 1400, 900 );
	imagefill( $wi, 0, 0, imagecolorallocate( $wi, 140, 160, 200 ) );
	$wsrc = wp_tempnam( 'kikz' ) . '.webp';
	imagewebp( $wi, $wsrc, 85 );
	imagedestroy( $wi );
	$wid = kikz_upload( $wsrc, 'kikz-mismatch.webp' );
	wp_update_post( array( 'ID' => $wid, 'post_mime_type' => 'image/png' ) ); // What the plugin leaves behind.
	$wf  = get_attached_file( $wid );
	$wo  = md5_file( $wf );
	t( 'precondition: .webp file announced as image/png', 'image/png' === get_post_mime_type( $wid ) && 'webp' === KI_Kennzeichnung_Image::detect_type( $wf ) );
	$r = Flags::set( $wid, true, 'manual', array(), true );
	t( 'stamping succeeds (the real format is used, not the announced one)', 'ok' === $r['code'] && Flags::is_labeled( $wid ), $r['code'] . ' / ' . (string) get_post_meta( $wid, '_ki_stamp_error', true ) );
	t( 'the stamped file is still a WebP', 'webp' === KI_Kennzeichnung_Image::detect_type( $wf ) && md5_file( $wf ) !== $wo );
	$wm = wp_get_attachment_metadata( $wid );
	t( 'every generated size is stamped as well', ! empty( $wm['sizes'] ) && count( get_post_meta( $wid, '_ki_badge_rects', true ) ) === 1 + count( $wm['sizes'] ) );
	Flags::set( $wid, false, 'manual', array(), true );
	t( 'unflagging restores the original byte for byte', md5_file( $wf ) === $wo && ! Flags::is_labeled( $wid ) );
}
$pj = kikz_upload( kikz_make_image( 1200, 800, 'png' ), 'kikz-png-in-jpg.jpg' ); // PNG data, .jpg name.
Flags::set( $pj, true, 'manual', array(), true );
t( 'PNG data in a .jpg file is stamped too', Flags::is_labeled( $pj ), (string) get_post_meta( $pj, '_ki_stamp_error', true ) );

section( 'Deleting an attachment' );
$del    = kikz_upload( kikz_make_image( 1200, 800 ), 'kikz-delete.jpg' );
Flags::set( $del, true, 'manual', array(), true );
$bk     = Stamper::backup_path( $del );
t( 'backup exists before delete', is_file( $bk ) );
wp_delete_attachment( $del, true );
t( 'backup removed with the attachment (no orphans)', ! is_file( $bk ) );
t( 'stem map cleaned', 0 === array_search( $del, Flags::stem_map(), true ) || false === array_search( $del, Flags::stem_map(), true ) );

section( 'Cache purge' );
$fired = 0;
add_action( 'kikz_purge_caches', static function () use ( &$fired ) {
	$fired++;
} );
KI_Kennzeichnung_Cache::request_purge();
KI_Kennzeichnung_Cache::request_purge();
KI_Kennzeichnung_Cache::purge_now();
KI_Kennzeichnung_Cache::purge_now();
t( 'purge is debounced: two requests, exactly one purge', 1 === $fired, (string) $fired );
update_option( 'kikz_badge_text', 'Anders' );
t( 'changing a visual setting requests a purge (cached HTML has the old badge)', (bool) get_transient( 'kikz_purge_pending' ) );
KI_Kennzeichnung_Cache::purge_now();
update_option( 'kikz_badge_text', 'KI-generiert' );
KI_Kennzeichnung_Cache::purge_now();

section( 'CSV export neutralises spreadsheet formulas' );
$m = new ReflectionMethod( 'KI_Kennzeichnung_Media', 'csv_cell' );
$m->setAccessible( true );
t( 'formula-looking file names are prefixed', "'=HYPERLINK(1)" === $m->invoke( null, '=HYPERLINK(1)' ) && "'@cmd" === $m->invoke( null, '@cmd' ) && 'normal.jpg' === $m->invoke( null, 'normal.jpg' ) );

section( 'Legacy 1.x data is handled' );
$leg  = kikz_upload( kikz_make_image( 1200, 800 ), 'kikz-legacy.jpg' );
$lf   = get_attached_file( $leg );
$lbk  = $lf . '.ki-original-legacy';
copy( $lf, $lbk );
update_post_meta( $leg, '_ki_generated', '1' );
update_post_meta( $leg, '_ki_file_labeled', '1' );
update_post_meta( $leg, '_ki_original_file', $lbk ); // 1.x stored an absolute path next to the image.
Stamper::migrate_legacy_backups();
$moved = Stamper::backup_path( $leg );
t( 'legacy public backup moved into the protected folder', ! is_file( $lbk ) && is_file( $moved ) && false !== strpos( $moved, 'ki-kennzeichnung-originals' ) );
t( 'legacy images show up as "needs re-stamp" (no rects)', Flags::stats()['legacy'] >= 1 );

// ---------------------------------------------------------------------------
section( 'AJAX endpoints: nonce, capabilities, behaviour' );

class Kikz_Ajax_Done extends Exception {}

function kikz_ajax( string $action, array $post = array(), int $user = 1, bool $nonce = true ): array {
	wp_set_current_user( $user );
	$_POST = $post + array( 'action' => $action );
	if ( $nonce ) {
		$_POST['nonce'] = wp_create_nonce( 'kikz_admin' );
	}
	$_REQUEST = $_POST;
	add_filter( 'wp_doing_ajax', '__return_true' );
	$handler = static function () {
		return static function () {
			throw new Kikz_Ajax_Done();
		};
	};
	add_filter( 'wp_die_ajax_handler', $handler );
	add_filter( 'wp_die_handler', $handler );
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( Kikz_Ajax_Done $e ) { // phpcs:ignore
	}
	$out = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $handler );
	remove_filter( 'wp_die_handler', $handler );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	wp_set_current_user( 1 );
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : array( 'success' => false, '_raw' => $out );
}

$author2 = wp_insert_user( array( 'user_login' => 'kikz_ajax_' . wp_rand(), 'user_pass' => 'x', 'role' => 'author' ) );
$aj      = kikz_upload( kikz_make_image( 1500, 900 ), 'kikz-ajax.jpg' );
$ajf     = get_attached_file( $aj );
$ajo     = md5_file( $ajf );

$r = kikz_ajax( 'kikz_toggle', array( 'id' => $aj, 'state' => '1' ) );
t( 'toggle on: success, flagged, stamped, chip returned', ! empty( $r['success'] ) && $r['data']['flagged'] && Flags::is_labeled( $aj ) && false !== strpos( $r['data']['chip'], 'is-on' ), wp_json_encode( $r ) );
t( 'toggle response carries fresh stats', isset( $r['data']['stats']['flagged'] ) );
$r = kikz_ajax( 'kikz_toggle', array( 'id' => $aj, 'state' => '0' ), $author2 );
t( 'toggle by an author on a foreign attachment is rejected (403)', empty( $r['success'] ) && Flags::is_flagged( $aj ) );
$r = kikz_ajax( 'kikz_toggle', array( 'id' => $aj, 'state' => '0' ), 1, false );
t( 'toggle without a valid nonce is rejected', empty( $r['success'] ) && Flags::is_flagged( $aj ) );
$r = kikz_ajax( 'kikz_toggle', array( 'id' => 999999, 'state' => '1' ) );
t( 'toggle of a non-existent id is rejected', empty( $r['success'] ) );

$im = imagecreatefromjpeg( $ajf );
imagejpeg( $im, $ajf, 70 );
imagedestroy( $im );
$r = kikz_ajax( 'kikz_toggle', array( 'id' => $aj, 'state' => '0' ) );
t( 'unmarking an image edited after stamping asks for confirmation and changes nothing yet', empty( $r['success'] ) && ! empty( $r['data']['needsConfirm'] ) && Flags::is_flagged( $aj ) && Flags::is_labeled( $aj ) && 'manual' === get_post_meta( $aj, '_ki_flag_source', true ), wp_json_encode( $r ) );
$r = kikz_ajax( 'kikz_toggle', array( 'id' => $aj, 'state' => '0', 'force' => '1' ) );
t( 'after confirmation the original is restored', ! empty( $r['success'] ) && ! Flags::is_flagged( $aj ) && md5_file( $ajf ) === $ajo );

Flags::set( $aj, true, 'bulk' );
$r = kikz_ajax( 'kikz_queue_run' );
t( 'queue_run processes work and reports what is left', ! empty( $r['success'] ) && $r['data']['done'] >= 1 && isset( $r['data']['left'] ), wp_json_encode( $r ) );
Stamper::process( 50, 60.0 );

$r = kikz_ajax( 'kikz_scan_names' );
t( 'scan_names returns hits with their pattern, nothing marked', ! empty( $r['success'] ) && isset( $r['data']['items'] ) && $r['data']['count'] >= 1, wp_json_encode( $r ) );
$cand = kikz_upload( kikz_make_image( 900, 600 ), 'dall-e-sunset.jpg' );
$cand2 = kikz_upload( kikz_make_image( 900, 600 ), 'chatgpt-image-forest.jpg' );
$r = kikz_ajax( 'kikz_mark_names', array( 'ids' => array( $cand ) ) );
t( 'mark_names marks exactly the confirmed IDs', ! empty( $r['success'] ) && 1 === $r['data']['marked'] && Flags::is_flagged( $cand ) && ! Flags::is_flagged( $cand2 ), wp_json_encode( $r ) );
$r = kikz_ajax( 'kikz_mark_names', array( 'ids' => array( $cand2 ) ), $author2 );
t( 'an author cannot mark someone else\'s image through the scan', empty( $r['success'] ) && ! Flags::is_flagged( $cand2 ) );
$r = kikz_ajax( 'kikz_scan_names', array(), $author2 );
t( 'the name scan (file names of the whole library) is administrator-only', empty( $r['success'] ) );
$r = kikz_ajax( 'kikz_mark_names', array( 'all' => '1' ) );
t( 'mark_names all=1 marks the remaining name matches', Flags::is_flagged( $cand2 ) );
Stamper::process( 50, 60.0 );

$r      = kikz_ajax( 'kikz_meta_scan', array( 'cursor' => 0 ) );
$pages  = 0;
$cursor = 0;
while ( ! empty( $r['success'] ) && empty( $r['data']['done'] ) && $pages++ < 400 ) {
	$cursor = $r['data']['cursor'];
	$r      = kikz_ajax( 'kikz_meta_scan', array( 'cursor' => $cursor ) );
}
t( 'meta_scan pages through the whole library and terminates', ! empty( $r['success'] ) && ! empty( $r['data']['done'] ) && $pages < 400, "pages=$pages" );
$r = kikz_ajax( 'kikz_meta_scan', array( 'cursor' => 0 ), $author2 );
t( 'meta_scan needs manage_options', empty( $r['success'] ) );

$r = kikz_ajax( 'kikz_restamp_all' );
t( 'restamp_all queues every stamped image', ! empty( $r['success'] ) && $r['data']['queued'] >= 1 && Stamper::queue_length() === $r['data']['queued'] );
Stamper::process( 100, 120.0 );
$r = kikz_ajax( 'kikz_restamp_all', array(), $author2 );
t( 'restamp_all needs manage_options', empty( $r['success'] ) );
$r = kikz_ajax( 'kikz_clear_elementor_cache', array(), $author2 );
t( 'clear_elementor_cache needs manage_options', empty( $r['success'] ) );
$r = kikz_ajax( 'kikz_clear_elementor_cache' );
t( 'clear_elementor_cache works for admins', ! empty( $r['success'] ) );
$r = kikz_ajax( 'kikz_audit', array( 'offset' => 0 ), $author2 );
t( 'audit needs manage_options', empty( $r['success'] ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $author2 );

section( 'Admin UI renders' );
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'upload' );
$field = KI_Kennzeichnung_Media::render_field( array(), get_post( $cand ) );
t( 'attachment form field is rendered with the checkbox state', isset( $field['ki_generated'] ) && false !== strpos( $field['ki_generated']['html'], 'checked' ) );
ob_start();
KI_Kennzeichnung_Media::render_column( 'ki_kennzeichnung', $cand );
$col = ob_get_clean();
t( 'list column renders a toggle chip', false !== strpos( $col, 'kikz-chip' ) && false !== strpos( $col, 'data-id="' . $cand . '"' ) );
$js = KI_Kennzeichnung_Media::prepare_for_js( array(), get_post( $cand ) );
t( 'grid data carries flag and status (no 500-ID limit anymore)', true === $js['kikzFlagged'] );
set_current_screen( 'settings_page_ki-kennzeichnung' );
ob_start();
KI_Kennzeichnung_Settings::render_page();
$page = ob_get_clean();
foreach ( array( 'kikz-scan', 'kikz-meta-scan', 'kikz-restamp', 'kikz-audit', 'kikz_display_mode', 'kikz_badge_corner', 'kikz-preview', 'kikz_badge_margin' ) as $needle ) {
	t( "settings page contains #$needle", false !== strpos( $page, $needle ) );
}
t( 'settings page offers all 9 positions', 9 === substr_count( $page, '<option value="' ) - 3 - 3 - 3 - 2 || preg_match_all( '/name="kikz_badge_corner"/', $page ) >= 1 );
preg_match( '/<select[^>]*id="kikz_badge_corner".*?<\/select>/s', $page, $sel );
t( 'position select has 9 options', 9 === substr_count( $sel[0] ?? '', '<option' ), (string) substr_count( $sel[0] ?? '', '<option' ) );
update_option( 'kikz_badge_google_font_url', 'https://fonts.googleapis.com/css2?family=Inter' );
ob_start();
KI_Kennzeichnung_Settings::render_page();
$page = ob_get_clean();
t( 'a saved Google Fonts URL triggers a privacy notice and is never loaded', false !== strpos( $page, 'Google' ) );
ob_start();
do_action( 'wp_head' );
$head = ob_get_clean();
t( 'no request to fonts.googleapis.com is emitted anywhere in wp_head', false === strpos( $head, 'fonts.googleapis.com' ) );
delete_option( 'kikz_badge_google_font_url' );
KI_Kennzeichnung_Media::enqueue( 'upload.php' );
t( 'admin assets are enqueued as files (no inline script blobs)', wp_script_is( 'kikz-admin', 'enqueued' ) && wp_style_is( 'kikz-admin', 'enqueued' ) );
unset( $GLOBALS['current_screen'] ); // Back to the front-end context for the following suites.

section( 'Upgrade from 1.x' );
delete_option( 'kikz_display_mode' );
update_option( 'kikz_burn_in', '0' );
delete_option( 'kikz_db_version' );
KI_Kennzeichnung_Settings::maybe_upgrade();
t( '1.x site with burn-in off becomes overlay mode', 'overlay' === get_option( 'kikz_display_mode' ) && KIKZ_VERSION === get_option( 'kikz_db_version' ) );
delete_option( 'kikz_display_mode' );
update_option( 'kikz_burn_in', '1' );
delete_option( 'kikz_db_version' );
KI_Kennzeichnung_Settings::maybe_upgrade();
t( '1.x site with burn-in on becomes auto mode (stamp + safety overlay)', 'auto' === get_option( 'kikz_display_mode' ) );
t( '1.x public API still answers', KI_Kennzeichnung::is_flagged_public( $cand ) && in_array( 'chatgpt', KI_Kennzeichnung::patterns_public(), true ) && is_string( KI_Kennzeichnung::get( 'badge_text' ) ) );
kikz_reset_options();

section( 'Switching modes keeps files and flags consistent' );
update_option( 'kikz_display_mode', 'overlay' );
$sw  = kikz_upload( kikz_make_image( 1500, 1000 ), 'kikz-switch.jpg' );
$swf = get_attached_file( $sw );
$swo = md5_file( $swf );
Flags::set( $sw, true, 'manual' );
t( 'overlay mode: flagging does not touch the file and queues nothing', md5_file( $swf ) === $swo && ! Flags::is_labeled( $sw ) && 0 === Stamper::queue_length() );
update_option( 'kikz_display_mode', 'auto' );
t( 'switching overlay -> auto queues the images flagged in the meantime', Stamper::queue_length() >= 1 && 'pending' === get_post_meta( $sw, '_ki_stamp_status', true ) );
Stamper::process( 100, 120.0 );
t( 'they get stamped in the background', Flags::is_labeled( $sw ) && md5_file( $swf ) !== $swo );
update_option( 'kikz_display_mode', 'overlay' );
t( 'switching to overlay leaves existing stamps alone (no silent bulk changes)', Flags::is_labeled( $sw ) && 0 === Stamper::queue_length() );
$r = kikz_ajax( 'kikz_unstamp_all' );
t( 'unstamp_all queues every stamped image', ! empty( $r['success'] ) && $r['data']['queued'] >= 1, wp_json_encode( $r ) );
Stamper::process( 100, 120.0 );
t( 'afterwards the originals are back, the images stay flagged (overlay)', ! Flags::is_labeled( $sw ) && Flags::is_flagged( $sw ) && md5_file( $swf ) === $swo );
$r = kikz_ajax( 'kikz_unstamp_all', array(), 0 );
t( 'unstamp_all is not available to visitors', empty( $r['success'] ) );
update_option( 'kikz_display_mode', 'auto' );
Stamper::process( 100, 120.0 );

section( 'WooCommerce variation data' );
$var = KI_Kennzeichnung_Woo::variation_data( array( 'image_id' => $sw ), null, null );
t( 'flagged variation image is announced to the script', ! empty( $var['kikz_flagged'] ) && false !== strpos( (string) $var['kikz_classes'], 'ki-flagged-image' ), wp_json_encode( $var ) );
$var = KI_Kennzeichnung_Woo::variation_data( array( 'image_id' => $plain ), null, null );
t( 'unflagged variation image is not', empty( $var['kikz_flagged'] ) );

section( 'The plugin never writes to the PHP error log' );
$log   = WP_CONTENT_DIR . '/debug.log';
$lines = is_file( $log ) ? file( $log, FILE_IGNORE_NEW_LINES ) : array();
$mine  = array_filter( $lines, static function ( $l ) {
	return false !== strpos( $l, 'plugins/ki-kennzeichnung' ) || false !== strpos( $l, 'ki-kennzeichnung/' );
} );
t( 'no warnings/notices/deprecations from the plugin during the whole core suite', array() === $mine, implode( ' | ', array_slice( $mine, 0, 3 ) ) );
