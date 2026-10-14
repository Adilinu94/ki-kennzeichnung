<?php
/**
 * Image stamping (burn-in) for KI-Kennzeichnung.
 *
 * Pure image logic without WordPress dependencies (apart from the ABSPATH guard)
 * so it can be tested standalone. Draws the badge into JPEG/PNG/WebP/AVIF files,
 * keeps EXIF/ICC/IPTC where the container allows it and writes the IPTC
 * "DigitalSourceType = trainedAlgorithmicMedia" marker into the XMP packet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Image {

	/** IPTC digital source type for AI-generated media. */
	public const DST_URI = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

	/** Supersampling factor for the anti-aliased pill shape. */
	private const SS = 4;

	/**
	 * Map file and mime type to an image type key.
	 */
	public static function type_from( string $file, string $mime = '' ): ?string {
		$mime = strtolower( $mime );
		$map  = array(
			'image/jpeg' => 'jpeg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( isset( $map[ $mime ] ) ) {
			return $map[ $mime ];
		}
		$ext_map = array(
			'jpg'  => 'jpeg',
			'jpeg' => 'jpeg',
			'jpe'  => 'jpeg',
			'png'  => 'png',
			'webp' => 'webp',
			'avif' => 'avif',
		);
		return $ext_map[ strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ] ?? null;
	}

	/**
	 * Whether GD can read and write the given type here.
	 */
	public static function supports( string $type ): bool {
		if ( ! extension_loaded( 'gd' ) ) {
			return false;
		}
		switch ( $type ) {
			case 'jpeg':
				return function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' );
			case 'png':
				return function_exists( 'imagecreatefrompng' ) && function_exists( 'imagepng' );
			case 'webp':
				return function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' );
			case 'avif':
				return function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' );
		}
		return false;
	}

	/**
	 * Burn the badge into one file, in place.
	 *
	 * Options: label, short_label, position, factor, min_px, margin_factor,
	 * min_margin, max_width, bg (r,g,b,a127), fg (r,g,b), font, write_metadata.
	 *
	 * @param string $path File path.
	 * @param string $type Type key from type_from().
	 * @param array  $o    Options.
	 * @return array|string Result array on success, error code otherwise.
	 */
	public static function stamp( string $path, string $type, array $o ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return 'missing';
		}
		if ( ! self::supports( $type ) ) {
			return 'unsupported';
		}
		$info = @getimagesize( $path );
		if ( ! $info ) {
			return 'unreadable';
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w < 80 || $h < 40 ) {
			return 'too_small';
		}
		if ( ! self::ensure_memory( $w, $h ) ) {
			return 'memory';
		}

		$labels = array_values( array_unique( array_filter( array( (string) ( $o['label'] ?? '' ), (string) ( $o['short_label'] ?? 'KI' ) ) ) ) );
		$lay    = self::layout( $w, $h, $o, $labels );
		if ( null === $lay ) {
			return 'too_small';
		}

		$bytes = in_array( $type, array( 'jpeg', 'png' ), true ) ? (string) @file_get_contents( $path ) : '';

		$im = self::load( $path, $type );
		if ( ! $im ) {
			return 'decode_failed';
		}
		if ( ! imageistruecolor( $im ) ) {
			imagepalettetotruecolor( $im );
		}

		self::draw( $im, $lay, $o );

		$tmp = $path . '.kikz-tmp';
		$ok  = self::save( $im, $tmp, $type );
		self::free( $im );
		if ( ! $ok || ! is_file( $tmp ) || filesize( $tmp ) < 100 ) {
			@unlink( $tmp );
			return 'encode_failed';
		}

		if ( ! empty( $o['write_metadata'] ) && '' !== $bytes ) {
			$out = (string) file_get_contents( $tmp );
			$out = 'jpeg' === $type ? self::jpeg_with_metadata( $out, $bytes ) : self::png_with_metadata( $out, $bytes );
			if ( '' !== $out ) {
				file_put_contents( $tmp, $out );
			}
		}

		$perms = @fileperms( $path );
		if ( false !== $perms ) {
			@chmod( $tmp, $perms & 0777 );
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $path );
			if ( ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return 'write_failed';
			}
		}

		return array(
			'rect'  => array(
				round( $lay['x'] / $w, 4 ),
				round( $lay['y'] / $h, 4 ),
				round( $lay['bw'] / $w, 4 ),
				round( $lay['bh'] / $h, 4 ),
			),
			'label' => $lay['label'],
			'size'  => $lay['size'],
			'w'     => $w,
			'h'     => $h,
		);
	}

	/**
	 * Compute badge size and position. Falls back to the short label when the
	 * badge would cover too much of a small image.
	 *
	 * @return array|null
	 */
	public static function layout( int $w, int $h, array $o, array $labels ): ?array {
		$font    = (string) ( $o['font'] ?? '' );
		$ttf     = '' !== $font && is_file( $font ) && function_exists( 'imagettfbbox' );
		$factor  = (float) ( $o['factor'] ?? 0.01 );
		$min_px  = (float) ( $o['min_px'] ?? 11 );
		$max_w   = (float) ( $o['max_width'] ?? 0.45 );
		$size    = max( $min_px, round( $w * $factor, 1 ) );
		$margin  = (int) max( (float) ( $o['min_margin'] ?? 10 ), round( $w * (float) ( $o['margin_factor'] ?? 0.012 ) ) );
		$pos     = (string) ( $o['position'] ?? 'bottom-right' );

		foreach ( $labels as $label ) {
			if ( $ttf ) {
				$box = imagettfbbox( $size, 0, $font, $label );
				if ( ! $box ) {
					continue;
				}
				$tw  = $box[2] - $box[0];
				$th  = $box[1] - $box[7];
				$top = $box[7];
			} else {
				$tw  = imagefontwidth( 5 ) * strlen( $label );
				$th  = imagefontheight( 5 );
				$top = 0;
			}
			$pad_x = (int) round( $th * 0.85 );
			$pad_y = (int) round( $th * 0.5 );
			$bw    = (int) ( $tw + $pad_x * 2 );
			$bh    = (int) ( $th + $pad_y * 2 );
			if ( $bw > $w * $max_w || $bh > $h * 0.5 ) {
				continue;
			}

			$x = $margin;
			if ( false !== strpos( $pos, 'right' ) ) {
				$x = $w - $margin - $bw;
			} elseif ( 'top-center' === $pos || 'center' === $pos || 'bottom-center' === $pos ) {
				$x = (int) round( ( $w - $bw ) / 2 );
			}
			$y = $margin;
			if ( 0 === strpos( $pos, 'bottom' ) ) {
				$y = $h - $margin - $bh;
			} elseif ( 'center' === $pos || 'middle-left' === $pos || 'middle-right' === $pos ) {
				$y = (int) round( ( $h - $bh ) / 2 );
			}
			if ( $x < 0 || $y < 0 || $x + $bw > $w || $y + $bh > $h ) {
				continue;
			}

			return array(
				'label'    => $label,
				'size'     => $size,
				'ttf'      => $ttf,
				'font'     => $font,
				'x'        => $x,
				'y'        => $y,
				'bw'       => $bw,
				'bh'       => $bh,
				'pad_x'    => $pad_x,
				'pad_y'    => $pad_y,
				'baseline' => (int) ( $pad_y - $top ),
			);
		}
		return null;
	}

	/**
	 * Draw the badge: the translucent pill is rendered on its own supersampled
	 * layer (no double blending in overlapping shapes), then composited.
	 */
	private static function draw( $im, array $lay, array $o ): void {
		$bg = $o['bg'] ?? array( 0, 0, 0, 44 );
		$fg = $o['fg'] ?? array( 255, 255, 255 );
		$ss = self::SS;
		$bw = $lay['bw'];
		$bh = $lay['bh'];

		$big = imagecreatetruecolor( $bw * $ss, $bh * $ss );
		imagealphablending( $big, false );
		imagesavealpha( $big, true );
		imagefill( $big, 0, 0, imagecolorallocatealpha( $big, 0, 0, 0, 127 ) );
		$col = imagecolorallocatealpha( $big, (int) $bg[0], (int) $bg[1], (int) $bg[2], (int) $bg[3] );
		self::pill( $big, $bw * $ss, $bh * $ss, $col );

		$pill = imagecreatetruecolor( $bw, $bh );
		imagealphablending( $pill, false );
		imagesavealpha( $pill, true );
		imagefill( $pill, 0, 0, imagecolorallocatealpha( $pill, 0, 0, 0, 127 ) );
		imagecopyresampled( $pill, $big, 0, 0, 0, 0, $bw, $bh, $bw * $ss, $bh * $ss );
		self::free( $big );

		imagealphablending( $im, true );
		imagecopy( $im, $pill, $lay['x'], $lay['y'], 0, 0, $bw, $bh );
		self::free( $pill );

		$text = imagecolorallocate( $im, (int) $fg[0], (int) $fg[1], (int) $fg[2] );
		if ( $lay['ttf'] ) {
			imagettftext( $im, $lay['size'], 0, $lay['x'] + $lay['pad_x'], $lay['y'] + $lay['baseline'], $text, $lay['font'], $lay['label'] );
		} else {
			imagestring( $im, 5, $lay['x'] + $lay['pad_x'], $lay['y'] + $lay['pad_y'], $lay['label'], $text );
		}
	}

	/**
	 * Fill a pill (fully rounded rectangle) without blending.
	 */
	private static function pill( $im, int $w, int $h, int $color ): void {
		$r = (int) floor( min( $w, $h ) / 2 );
		imagefilledrectangle( $im, $r, 0, $w - $r - 1, $h - 1, $color );
		imagefilledellipse( $im, $r, (int) ( $h / 2 ), $r * 2, $h, $color );
		imagefilledellipse( $im, $w - $r - 1, (int) ( $h / 2 ), $r * 2, $h, $color );
	}

	/**
	 * Release a GD image. Since PHP 8.0 images are objects that free themselves;
	 * imagedestroy() is a no-op there (and deprecated in newer versions).
	 *
	 * @param \GdImage|resource $im Image.
	 */
	private static function free( $im ): void {
		if ( PHP_VERSION_ID < 80000 && is_resource( $im ) ) {
			imagedestroy( $im ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
		}
	}

	/**
	 * Create a GD image from a file.
	 *
	 * @return \GdImage|false
	 */
	private static function load( string $file, string $type ) {
		switch ( $type ) {
			case 'jpeg':
				return @imagecreatefromjpeg( $file );
			case 'png':
				return @imagecreatefrompng( $file );
			case 'webp':
				return @imagecreatefromwebp( $file );
			case 'avif':
				return @imagecreatefromavif( $file );
		}
		return false;
	}

	/**
	 * Encode the image to a file in its original format.
	 */
	private static function save( $im, string $file, string $type ): bool {
		switch ( $type ) {
			case 'jpeg':
				return (bool) @imagejpeg( $im, $file, 90 );
			case 'png':
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
				return (bool) @imagepng( $im, $file, 6 );
			case 'webp':
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
				return (bool) @imagewebp( $im, $file, 88 );
			case 'avif':
				return (bool) @imageavif( $im, $file, 70 );
		}
		return false;
	}

	/**
	 * Make sure the image fits into the memory limit (GD needs ~5 bytes/pixel).
	 */
	private static function ensure_memory( int $w, int $h ): bool {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		$need = (int) ( $w * $h * 5.2 ) + 6 * 1024 * 1024;
		return memory_get_usage() + $need < $limit;
	}

	private static function bytes( string $val ): int {
		$val = trim( $val );
		if ( '' === $val || '-1' === $val ) {
			return -1;
		}
		$n    = (int) $val;
		$unit = strtolower( substr( $val, -1 ) );
		if ( 'g' === $unit ) {
			return $n * 1024 * 1024 * 1024;
		}
		if ( 'm' === $unit ) {
			return $n * 1024 * 1024;
		}
		if ( 'k' === $unit ) {
			return $n * 1024;
		}
		return $n;
	}

	// ---------------------------------------------------------------------
	// Metadata (JPEG and PNG).
	// ---------------------------------------------------------------------

	/**
	 * Re-attach EXIF, ICC, IPTC and a merged XMP packet to a freshly encoded JPEG.
	 * Returns an empty string on failure (caller keeps the plain encode).
	 */
	public static function jpeg_with_metadata( string $encoded, string $original ): string {
		$src = self::jpeg_segments( $original );
		if ( null === $src ) {
			return '';
		}
		$keep = array();
		$xmp  = null;
		foreach ( $src as $seg ) {
			list( $marker, $data ) = $seg;
			if ( 0xE1 === $marker && 0 === strpos( $data, "Exif\0\0" ) ) {
				$keep[] = $seg;
			} elseif ( 0xE2 === $marker && 0 === strpos( $data, "ICC_PROFILE\0" ) ) {
				$keep[] = $seg;
			} elseif ( 0xED === $marker && 0 === strpos( $data, 'Photoshop 3.0' ) ) {
				$keep[] = $seg;
			} elseif ( 0xE1 === $marker && 0 === strpos( $data, "http://ns.adobe.com/xap/1.0/\0" ) && null === $xmp ) {
				$xmp = substr( $data, 29 );
			}
		}
		$has_exif = false;
		foreach ( $keep as $seg ) {
			if ( 0xE1 === $seg[0] && 0 === strpos( $seg[1], "Exif\0\0" ) ) {
				$has_exif = true;
				break;
			}
		}
		if ( ! $has_exif ) {
			// PHP's exif parser warns when the first APP1 segment is not Exif; an empty one prevents that.
			array_unshift( $keep, array( 0xE1, "Exif\0\0II" . pack( 'v', 42 ) . pack( 'V', 8 ) . pack( 'v', 0 ) . pack( 'V', 0 ) ) );
		}
		$keep[] = array( 0xE1, "http://ns.adobe.com/xap/1.0/\0" . self::xmp_with_dst( $xmp ) );

		$blob = '';
		foreach ( $keep as $seg ) {
			if ( strlen( $seg[1] ) > 65533 ) {
				continue;
			}
			$blob .= "\xFF" . chr( $seg[0] ) . pack( 'n', strlen( $seg[1] ) + 2 ) . $seg[1];
		}

		if ( "\xFF\xD8" !== substr( $encoded, 0, 2 ) ) {
			return '';
		}
		$pos = 2;
		if ( "\xFF\xE0" === substr( $encoded, 2, 2 ) ) {
			$len = unpack( 'n', substr( $encoded, 4, 2 ) );
			$pos = 2 + 2 + (int) $len[1];
		}
		return substr( $encoded, 0, $pos ) . $blob . substr( $encoded, $pos );
	}

	/**
	 * Parse the header segments of a JPEG up to the first scan.
	 *
	 * @return array<int, array{0:int,1:string}>|null
	 */
	public static function jpeg_segments( string $bytes ): ?array {
		if ( "\xFF\xD8" !== substr( $bytes, 0, 2 ) ) {
			return null;
		}
		$len  = strlen( $bytes );
		$pos  = 2;
		$segs = array();
		while ( $pos + 4 <= $len ) {
			if ( "\xFF" !== $bytes[ $pos ] ) {
				break;
			}
			$marker = ord( $bytes[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				$pos++;
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break;
			}
			if ( ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0x01 === $marker ) {
				$pos += 2;
				continue;
			}
			$n = unpack( 'n', substr( $bytes, $pos + 2, 2 ) );
			$n = (int) $n[1];
			if ( $n < 2 ) {
				break;
			}
			$segs[] = array( $marker, substr( $bytes, $pos + 4, $n - 2 ) );
			$pos   += 2 + $n;
		}
		return $segs;
	}

	/**
	 * Re-attach colour/EXIF chunks and the merged XMP iTXt chunk to a fresh PNG.
	 */
	public static function png_with_metadata( string $encoded, string $original ): string {
		$sig = "\x89PNG\r\n\x1a\n";
		if ( 0 !== strpos( $encoded, $sig ) || 0 !== strpos( $original, $sig ) ) {
			return '';
		}
		$keep_types = array( 'iCCP', 'sRGB', 'gAMA', 'cHRM', 'pHYs', 'eXIf' );
		$extra      = '';
		$xmp        = null;
		$has        = array();
		foreach ( self::png_chunks( $encoded ) as $c ) {
			$has[ $c[0] ] = true;
		}
		foreach ( self::png_chunks( $original ) as $c ) {
			list( $type, $data ) = $c;
			if ( in_array( $type, $keep_types, true ) && empty( $has[ $type ] ) ) {
				if ( 'iCCP' === $type && ! empty( $has['sRGB'] ) ) {
					continue;
				}
				$extra .= self::png_chunk( $type, $data );
			} elseif ( 'iTXt' === $type && 0 === strpos( $data, "XML:com.adobe.xmp\0" ) && null === $xmp ) {
				$xmp = substr( $data, strlen( "XML:com.adobe.xmp\0" ) + 4 );
				$xmp = preg_replace( '/^[\x00]*/', '', (string) $xmp );
			}
		}
		$extra .= self::png_chunk( 'iTXt', "XML:com.adobe.xmp\0\0\0\0\0" . self::xmp_with_dst( $xmp ) );

		// Insert right after IHDR (8 signature + 25 IHDR bytes).
		return substr( $encoded, 0, 33 ) . $extra . substr( $encoded, 33 );
	}

	/**
	 * @return array<int, array{0:string,1:string}>
	 */
	public static function png_chunks( string $bytes ): array {
		$out = array();
		$pos = 8;
		$len = strlen( $bytes );
		while ( $pos + 12 <= $len ) {
			$n    = unpack( 'N', substr( $bytes, $pos, 4 ) );
			$n    = (int) $n[1];
			$type = substr( $bytes, $pos + 4, 4 );
			if ( $pos + 12 + $n > $len ) {
				break;
			}
			$out[] = array( $type, substr( $bytes, $pos + 8, $n ) );
			if ( 'IEND' === $type ) {
				break;
			}
			$pos += 12 + $n;
		}
		return $out;
	}

	private static function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * Build an XMP packet that carries the IPTC DigitalSourceType. An existing
	 * packet is kept and extended; a broken or oversized one is replaced.
	 */
	public static function xmp_with_dst( ?string $existing ): string {
		$ns = 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/';
		if ( is_string( $existing ) && '' !== $existing && strlen( $existing ) < 60000 && class_exists( 'DOMDocument' ) ) {
			$prev = libxml_use_internal_errors( true );
			$dom  = new DOMDocument();
			if ( @$dom->loadXML( $existing, LIBXML_NONET ) ) {
				$xp = new DOMXPath( $dom );
				$xp->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );
				$desc = $xp->query( '//rdf:Description' )->item( 0 );
				if ( $desc instanceof DOMElement ) {
					foreach ( iterator_to_array( $xp->query( './/*[local-name()="DigitalSourceType"]' ) ) as $old ) {
						$old->parentNode->removeChild( $old );
					}
					if ( $desc->hasAttributeNS( $ns, 'DigitalSourceType' ) ) {
						$desc->removeAttributeNS( $ns, 'DigitalSourceType' );
					}
					$desc->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:Iptc4xmpExt', $ns );
					$desc->appendChild( $dom->createElementNS( $ns, 'Iptc4xmpExt:DigitalSourceType', self::DST_URI ) );
					$xml = (string) $dom->saveXML();
					$xml = (string) preg_replace( '/^<\?xml[^>]*\?>\s*/', '', $xml );
					libxml_clear_errors();
					libxml_use_internal_errors( $prev );
					if ( '' !== $xml && strlen( $xml ) < 64000 ) {
						return $xml;
					}
				}
			}
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}

		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="' . $ns . '">'
			. '<Iptc4xmpExt:DigitalSourceType>' . self::DST_URI . '</Iptc4xmpExt:DigitalSourceType>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>' . "\n" . '<?xpacket end="w"?>';
	}
}
