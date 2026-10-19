<?php
/**
 * Creates the pages for the browser test and prints their URLs as JSON.
 *   KIKZ_WP_LOAD=/path/wp-load.php php tests/browser/fixtures.php
 * Everything is created with the plugin in "auto" mode, stamped, and out of the cache grace period,
 * i.e. the situation where the script decides whether the burned-in badge is enough.
 */
require getenv( 'KIKZ_WP_LOAD' );
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
wp_set_current_user( 1 );

update_option( 'kikz_display_mode', 'auto' );
update_option( 'kikz_grace_days', '0' );
update_option( 'kikz_footnote_on', '0' );

/** A recognisable "photo": diagonal colour field plus a grid, so cropping is visible in screenshots. */
function kb_image( int $w, int $h, string $label ): string {
	$im = imagecreatetruecolor( $w, $h );
	for ( $y = 0; $y < $h; $y += 5 ) {
		for ( $x = 0; $x < $w; $x += 5 ) {
			imagefilledrectangle( $im, $x, $y, $x + 4, $y + 4, imagecolorallocate( $im, 70 + (int) ( 150 * $x / $w ), 90 + (int) ( 120 * $y / $h ), 200 - (int) ( 90 * $x / $w ) ) );
		}
	}
	$line = imagecolorallocate( $im, 255, 255, 255 );
	for ( $x = 0; $x < $w; $x += (int) ( $w / 10 ) ) {
		imageline( $im, $x, 0, $x, $h, $line );
	}
	for ( $y = 0; $y < $h; $y += (int) ( $h / 6 ) ) {
		imageline( $im, 0, $y, $w, $y, $line );
	}
	imagestring( $im, 5, 20, 20, $label . " {$w}x{$h}", imagecolorallocate( $im, 0, 0, 0 ) );
	$file = wp_tempnam( 'kb' ) . '.jpg';
	imagejpeg( $im, $file, 90 );
	imagedestroy( $im );
	return $file;
}

function kb_upload( string $src, string $name, bool $flag = true ): int {
	$up   = wp_upload_dir();
	$dest = wp_unique_filename( $up['path'], $name );
	copy( $src, $up['path'] . '/' . $dest );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => pathinfo( $dest, PATHINFO_FILENAME ), 'post_status' => 'inherit' ), $up['path'] . '/' . $dest );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['path'] . '/' . $dest ) );
	if ( $flag ) {
		KI_Kennzeichnung_Flags::set( $id, true, 'manual', array(), true );
	}
	return (int) $id;
}

function kb_media( int $id ): array {
	return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id, 'size' => '', 'alt' => '', 'source' => 'library' );
}

$tag   = 'kbrowser';
$big   = kb_upload( kb_image( 1500, 1000, 'AI-BIG' ), "$tag-big.jpg" );
$small = kb_upload( kb_image( 800, 533, 'AI-SMALL' ), "$tag-small.jpg" );
$human = kb_upload( kb_image( 1200, 800, 'HUMAN' ), "$tag-human.jpg", false );

function kb_box( string $id, array $s, array $kids = array() ): array {
	return array( 'id' => $id, 'elType' => 'container', 'settings' => $s + array( 'content_width' => 'full' ), 'elements' => $kids, 'isInner' => false );
}
$bg = function ( int $img, string $pos = 'center center' ): array {
	return array( 'background_background' => 'classic', 'background_image' => kb_media( $img ), 'background_size' => 'cover', 'background_position' => $pos, 'background_repeat' => 'no-repeat' );
};
$px = function ( int $n ): array {
	return array( 'unit' => 'px', 'size' => $n );
};

$tree = array(
	// A: wide strip, centered cover -> bottom-right corner is cropped away.
	kb_box( 'boxA0001', $bg( $big ) + array( 'width' => $px( 1000 ), 'min_height' => $px( 260 ) ) ),
	// B: aspect ratio matches (3:2), large enough: burned-in badge visible and legible.
	kb_box( 'boxB0001', $bg( $big, 'right bottom' ) + array( 'width' => $px( 900 ), 'min_height' => $px( 600 ) ) ),
	// C: same box but too small: badge would be illegible.
	kb_box( 'boxC0001', $bg( $big, 'right bottom' ) + array( 'width' => $px( 450 ), 'min_height' => $px( 300 ) ) ),
	// D: tall mobile-like box, centered cover: crops left/right.
	kb_box( 'boxD0001', $bg( $big ) + array( 'width' => $px( 380 ), 'min_height' => $px( 640 ) ) ),
	// E: human photo, must stay untouched.
	kb_box( 'boxE0001', $bg( $human ) + array( 'width' => $px( 600 ), 'min_height' => $px( 200 ) ) ),
	// F: image widget, 50% wide and centered inside a wide container (container wider than image).
	kb_box( 'boxF0001', array( 'width' => $px( 1000 ) ), array(
		array( 'id' => 'imgF0001', 'elType' => 'widget', 'widgetType' => 'image', 'elements' => array(), 'settings' => array( 'image' => kb_media( $big ), 'image_size' => 'full', 'width' => array( 'unit' => '%', 'size' => 50 ), 'align' => 'center' ) ),
	) ),
	// G: image widget at natural size (small file): burned-in badge is fine.
	kb_box( 'boxG0001', array( 'width' => $px( 1000 ) ), array(
		array( 'id' => 'imgG0001', 'elType' => 'widget', 'widgetType' => 'image', 'elements' => array(), 'settings' => array( 'image' => kb_media( $small ), 'image_size' => 'full', 'align' => 'left' ) ),
	) ),
	// H: image widget with a fixed height + object-fit cover (crops the badge).
	kb_box( 'boxH0001', array( 'width' => $px( 600 ) ), array(
		array( 'id' => 'imgH0001', 'elType' => 'widget', 'widgetType' => 'image', 'elements' => array(), 'settings' => array( 'image' => kb_media( $big ), 'image_size' => 'full', 'height' => $px( 200 ), 'object_fit' => 'cover' ) ),
	) ),
);

$pid = wp_insert_post( array( 'post_title' => 'kbrowser elementor', 'post_type' => 'page', 'post_status' => 'publish' ) );
update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
update_post_meta( $pid, '_wp_page_template', 'elementor_canvas' );
update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

// Classic content: figure, link, downscaled thumbnail, cropped box.
$large = wp_get_attachment_image_url( $big, 'large' );
$full  = wp_get_attachment_url( $big );
$thumb = wp_get_attachment_image_url( $big, 'medium' );
$content = '<!-- wp:image {"id":' . $big . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large" id="wpFigure"><img src="' . esc_url( $large ) . '" alt="Strand" class="wp-image-' . $big . '"/></figure><!-- /wp:image -->'
	. '<div id="scaledBox" style="width:300px"><img style="width:100%;height:auto;display:block" src="' . esc_url( $full ) . '" alt="skaliert" class="wp-image-' . $big . '"></div>'
	. '<div id="cropBox" style="width:300px;height:300px"><img style="width:100%;height:100%;object-fit:cover;display:block" src="' . esc_url( $full ) . '" alt="zugeschnitten" class="wp-image-' . $big . '"></div>'
	. '<div id="cardBox" class="card" style="width:600px"><a href="#"><img style="width:100%;height:auto;display:block" src="' . esc_url( $large ) . '" alt="Karte"></a></div>'
	. '<div id="humanBox" style="width:300px"><img style="width:100%;height:auto;display:block" src="' . esc_url( wp_get_attachment_url( $human ) ) . '" alt="Mensch" class="wp-image-' . $human . '"></div>';
$cid = wp_insert_post( array( 'post_title' => 'kbrowser classic', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ) );

\Elementor\Plugin::$instance->files_manager->clear_cache();
KI_Kennzeichnung_Cache::purge_now();
echo wp_json_encode( array(
	'elementor' => get_permalink( $pid ),
	'classic'   => get_permalink( $cid ),
	'big'       => $big,
	'small'     => $small,
	'human'     => $human,
	'rects'     => get_post_meta( $big, '_ki_badge_rects', true ),
	'ids'       => array( 'pages' => array( $pid, $cid ), 'attachments' => array( $big, $small, $human ) ),
) );
