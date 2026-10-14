<?php
/**
 * Standalone tests for KI_Kennzeichnung_Image (no WordPress needed).
 * Run: php tests/test-image.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-kikz-image.php';

$fails = 0;
function t( string $name, bool $cond, string $extra = '' ): void {
	global $fails;
	echo ( $cond ? "  ok   " : "  FAIL " ) . $name . ( $cond ? '' : ( $extra ? " ($extra)" : '' ) ) . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

$tmp  = sys_get_temp_dir() . '/kikz-test-' . getmypid();
@mkdir( $tmp );
$font = __DIR__ . '/../fonts/DejaVuSans.ttf';
$opts = array(
	'label'          => 'KI-generiert',
	'short_label'    => 'KI',
	'position'       => 'bottom-right',
	'factor'         => 0.01,
	'min_px'         => 11,
	'bg'             => array( 0, 0, 0, 44 ),
	'fg'             => array( 255, 255, 255 ),
	'font'           => $font,
	'write_metadata' => true,
);

// Minimal EXIF segment with Orientation = 6.
function exif_segment(): string {
	$tiff = "II*\x00" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvVV', 0x0112, 3, 1, 6 ) . pack( 'V', 0 );
	$data = "Exif\0\0" . $tiff;
	return "\xFF\xE1" . pack( 'n', strlen( $data ) + 2 ) . $data;
}

function make_white( int $w, int $h ) {
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
	return $im;
}

echo "JPEG\n";
$im = make_white( 1200, 800 );
imagejpeg( $im, "$tmp/a.jpg", 95 );
imagedestroy( $im );
$raw = file_get_contents( "$tmp/a.jpg" );
// Insert EXIF after JFIF APP0.
$n   = unpack( 'n', substr( $raw, 4, 2 ) );
$pos = 4 + $n[1];
file_put_contents( "$tmp/a.jpg", substr( $raw, 0, $pos ) . exif_segment() . substr( $raw, $pos ) );

$res = KI_Kennzeichnung_Image::stamp( "$tmp/a.jpg", 'jpeg', $opts );
t( 'stamp returns array', is_array( $res ), is_string( $res ) ? $res : '' );
$info = getimagesize( "$tmp/a.jpg", $ext );
t( 'dimensions unchanged', 1200 === $info[0] && 800 === $info[1] );
t( 'long label used on large image', 'KI-generiert' === $res['label'] );
$r   = $res['rect'];
$img = imagecreatefromjpeg( "$tmp/a.jpg" );
$bx  = (int) ( ( $r[0] + $r[2] / 2 ) * 1200 );
$by  = (int) ( ( $r[1] + $r[3] / 2 ) * 800 );
$in  = imagecolorsforindex( $img, imagecolorat( $img, (int) ( $r[0] * 1200 ) + 4, $by ) );
t( 'badge area is darker than white background', $in['red'] < 200, (string) $in['red'] );
$out = imagecolorsforindex( $img, imagecolorat( $img, 20, 20 ) );
t( 'far corner untouched (white)', $out['red'] > 250 );
// Overlap artefact check: pill body vs. pill end cap should have equal brightness.
$cap  = imagecolorsforindex( $img, imagecolorat( $img, (int) ( $r[0] * 1200 ) + (int) ( $r[3] * 800 * 0.5 ), (int) ( ( $r[1] + $r[3] * 0.12 ) * 800 ) ) );
$body = imagecolorsforindex( $img, imagecolorat( $img, (int) ( $r[0] * 1200 ) + (int) ( $r[2] * 1200 * 0.5 ), (int) ( ( $r[1] + $r[3] * 0.12 ) * 800 ) ) );
t( 'no double-blend banding (cap vs body)', abs( $cap['red'] - $body['red'] ) < 25, $cap['red'] . ' vs ' . $body['red'] );
imagedestroy( $img );

$exif = function_exists( 'exif_read_data' ) ? @exif_read_data( "$tmp/a.jpg" ) : null;
t( 'EXIF orientation preserved', is_array( $exif ) && 6 === (int) ( $exif['Orientation'] ?? 0 ) );
$bytes = file_get_contents( "$tmp/a.jpg" );
t( 'XMP carries DigitalSourceType', false !== strpos( $bytes, 'trainedAlgorithmicMedia' ) );
t( 'file is still a valid JPEG', false !== @imagecreatefromjpeg( "$tmp/a.jpg" ) );
t( 'no temp file left', ! file_exists( "$tmp/a.jpg.kikz-tmp" ) );

echo "EXIF parser compatibility\n";
$im = make_white( 600, 400 );
imagejpeg( $im, "$tmp/noexif.jpg", 90 );
imagedestroy( $im );
KI_Kennzeichnung_Image::stamp( "$tmp/noexif.jpg", 'jpeg', $opts );
$warned = false;
set_error_handler( function () use ( &$warned ) {
	$warned = true;
	return true;
} );
$ex = function_exists( 'exif_read_data' ) ? exif_read_data( "$tmp/noexif.jpg" ) : array();
restore_error_handler();
t( 'exif_read_data on a stamped JPEG that had no EXIF raises no warning', ! $warned );
t( 'XMP is still present', false !== strpos( file_get_contents( "$tmp/noexif.jpg" ), 'trainedAlgorithmicMedia' ) );

echo "JPEG with existing XMP is merged, not duplicated\n";
$im = make_white( 800, 600 );
imagejpeg( $im, "$tmp/b.jpg", 95 );
imagedestroy( $im );
$raw  = file_get_contents( "$tmp/b.jpg" );
$n    = unpack( 'n', substr( $raw, 4, 2 ) );
$pos  = 4 + $n[1];
$xmp  = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmp:CreatorTool="Firefly Test"/></rdf:RDF></x:xmpmeta>';
$data = "http://ns.adobe.com/xap/1.0/\0" . $xmp;
file_put_contents( "$tmp/b.jpg", substr( $raw, 0, $pos ) . "\xFF\xE1" . pack( 'n', strlen( $data ) + 2 ) . $data . substr( $raw, $pos ) );
$res   = KI_Kennzeichnung_Image::stamp( "$tmp/b.jpg", 'jpeg', $opts );
$bytes = file_get_contents( "$tmp/b.jpg" );
t( 'stamped', is_array( $res ) );
t( 'original creator tool kept', false !== strpos( $bytes, 'Firefly Test' ) );
t( 'DigitalSourceType added once', 1 === substr_count( $bytes, '<Iptc4xmpExt:DigitalSourceType>' ), (string) substr_count( $bytes, 'DigitalSourceType>' ) );
t( 'only one XMP packet', 1 === substr_count( $bytes, "http://ns.adobe.com/xap/1.0/\0" ) );

echo "PNG with transparency\n";
$im = imagecreatetruecolor( 900, 600 );
imagealphablending( $im, false );
imagesavealpha( $im, true );
imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
imagepng( $im, "$tmp/c.png" );
imagedestroy( $im );
$res = KI_Kennzeichnung_Image::stamp( "$tmp/c.png", 'png', $opts );
t( 'stamped', is_array( $res ), is_string( $res ) ? $res : '' );
$img = imagecreatefrompng( "$tmp/c.png" );
imagealphablending( $img, false );
$a   = ( imagecolorat( $img, 10, 10 ) >> 24 ) & 0x7F;
t( 'transparent background stays transparent', 127 === $a, (string) $a );
$r = $res['rect'];
$p = imagecolorat( $img, (int) ( ( $r[0] + $r[2] / 2 ) * 900 ), (int) ( ( $r[1] + $r[3] * 0.1 ) * 600 ) );
t( 'badge pixels are not fully transparent', ( ( $p >> 24 ) & 0x7F ) < 100 );
imagedestroy( $img );
$bytes = file_get_contents( "$tmp/c.png" );
t( 'PNG XMP present', false !== strpos( $bytes, 'XML:com.adobe.xmp' ) && false !== strpos( $bytes, 'trainedAlgorithmicMedia' ) );
t( 'PNG still decodes', false !== @imagecreatefrompng( "$tmp/c.png" ) );

echo "Palette PNG\n";
$im = imagecreate( 700, 400 );
imagecolorallocate( $im, 200, 220, 240 );
imagepng( $im, "$tmp/d.png" );
imagedestroy( $im );
$res = KI_Kennzeichnung_Image::stamp( "$tmp/d.png", 'png', $opts );
t( 'palette PNG stamped', is_array( $res ), is_string( $res ) ? $res : '' );

echo "WebP\n";
if ( KI_Kennzeichnung_Image::supports( 'webp' ) ) {
	$im = make_white( 1000, 700 );
	imagewebp( $im, "$tmp/e.webp", 85 );
	imagedestroy( $im );
	$res = KI_Kennzeichnung_Image::stamp( "$tmp/e.webp", 'webp', $opts );
	t( 'webp stamped', is_array( $res ), is_string( $res ) ? $res : '' );
	t( 'webp decodes', false !== @imagecreatefromwebp( "$tmp/e.webp" ) );
}

echo "Small sizes\n";
$im = make_white( 300, 200 );
imagejpeg( $im, "$tmp/f.jpg" );
imagedestroy( $im );
$res = KI_Kennzeichnung_Image::stamp( "$tmp/f.jpg", 'jpeg', $opts );
t( '300px thumb: long label fits or falls back, always legible', is_array( $res ) && $res['size'] >= 11 );
$im = make_white( 150, 100 );
imagejpeg( $im, "$tmp/g.jpg" );
imagedestroy( $im );
$res = KI_Kennzeichnung_Image::stamp( "$tmp/g.jpg", 'jpeg', $opts );
t( '150px thumb falls back to short label', is_array( $res ) && 'KI' === $res['label'], is_array( $res ) ? $res['label'] : (string) $res );
$im = make_white( 60, 40 );
imagejpeg( $im, "$tmp/h.jpg" );
imagedestroy( $im );
t( 'tiny image is skipped, not mangled', 'too_small' === KI_Kennzeichnung_Image::stamp( "$tmp/h.jpg", 'jpeg', $opts ) );

echo "Positions\n";
foreach ( array( 'top-left', 'top-center', 'top-right', 'middle-left', 'center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right' ) as $pos ) {
	$lay = KI_Kennzeichnung_Image::layout( 1000, 600, array_merge( $opts, array( 'position' => $pos ) ), array( 'KI-generiert' ) );
	$ok  = is_array( $lay ) && $lay['x'] >= 0 && $lay['y'] >= 0 && $lay['x'] + $lay['bw'] <= 1000 && $lay['y'] + $lay['bh'] <= 600;
	t( "layout inside image: $pos", $ok );
}
$bl = KI_Kennzeichnung_Image::layout( 1000, 600, array_merge( $opts, array( 'position' => 'bottom-left' ) ), array( 'X' ) );
$br = KI_Kennzeichnung_Image::layout( 1000, 600, array_merge( $opts, array( 'position' => 'bottom-right' ) ), array( 'X' ) );
t( 'left vs right differ', $bl['x'] < $br['x'] );

echo "Errors\n";
t( 'missing file', 'missing' === KI_Kennzeichnung_Image::stamp( "$tmp/nope.jpg", 'jpeg', $opts ) );
file_put_contents( "$tmp/x.jpg", 'not an image' );
t( 'garbage file', in_array( KI_Kennzeichnung_Image::stamp( "$tmp/x.jpg", 'jpeg', $opts ), array( 'unreadable' ), true ) );
t( 'GIF is unsupported', ! KI_Kennzeichnung_Image::supports( 'gif' ) );
t( 'type map', 'jpeg' === KI_Kennzeichnung_Image::type_from( 'a.JPG', '' ) && 'png' === KI_Kennzeichnung_Image::type_from( 'a', 'image/png' ) && null === KI_Kennzeichnung_Image::type_from( 'a.gif', 'image/gif' ) );

array_map( 'unlink', glob( "$tmp/*" ) );
@rmdir( $tmp );
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
