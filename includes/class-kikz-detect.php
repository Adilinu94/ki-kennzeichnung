<?php
/**
 * Detection of AI-generated images.
 *
 * Metadata is the reliable signal (IPTC/C2PA DigitalSourceType, generator
 * software, A1111/ComfyUI PNG text chunks) and is scored. File names are only
 * a hint: they never mark anything without explicit confirmation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Detect {

	/** Score from which an image counts as "very likely AI" and is marked automatically. */
	public const AUTO_SCORE = 80;

	/** Score from which an image is offered as candidate for manual review. */
	public const CANDIDATE_SCORE = 40;

	public static function init(): void {
		// Priority 0: run before the stamper (priority 1) so a fresh upload is stamped in the same request.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 0, 2 );
		add_action( 'wp_ajax_kikz_scan_names', array( __CLASS__, 'ajax_scan_names' ) );
		add_action( 'wp_ajax_kikz_mark_names', array( __CLASS__, 'ajax_mark_names' ) );
		add_action( 'wp_ajax_kikz_meta_scan', array( __CLASS__, 'ajax_meta_scan' ) );
	}

	// ---------------------------------------------------------------------
	// Metadata analysis.
	// ---------------------------------------------------------------------

	/**
	 * Score the metadata of an image file.
	 *
	 * @return array{score:int,signals:string[]}
	 */
	public static function analyze( string $path ): array {
		$out = array( 'score' => 0, 'signals' => array() );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $out;
		}
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return $out;
		}
		$head = (string) fread( $fh, 2 * 1024 * 1024 );
		fclose( $fh );

		$text   = '';
		$labels = array();
		if ( "\xFF\xD8" === substr( $head, 0, 2 ) ) {
			foreach ( (array) KI_Kennzeichnung_Image::jpeg_segments( $head ) as $seg ) {
				if ( in_array( $seg[0], array( 0xE1, 0xEB, 0xED, 0xFE ), true ) ) {
					$text .= "\n" . $seg[1];
				}
			}
		} elseif ( 0 === strpos( $head, "\x89PNG\r\n\x1a\n" ) ) {
			foreach ( KI_Kennzeichnung_Image::png_chunks( $head ) as $c ) {
				$type = $c[0];
				if ( 'tEXt' === $type || 'iTXt' === $type || 'zTXt' === $type ) {
					list( $key, $val ) = self::png_text( $type, $c[1] );
					$labels[ $key ]    = $val;
					$text             .= "\n" . $key . "\n" . $val;
				} elseif ( 'eXIf' === $type ) {
					$text .= "\n" . $c[1];
				}
			}
		} elseif ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			$pos = 12;
			$len = strlen( $head );
			while ( $pos + 8 <= $len ) {
				$tag = substr( $head, $pos, 4 );
				$n   = unpack( 'V', substr( $head, $pos + 4, 4 ) );
				$n   = (int) $n[1];
				if ( 'XMP ' === $tag || 'EXIF' === $tag ) {
					$text .= "\n" . substr( $head, $pos + 8, $n );
				}
				$pos += 8 + $n + ( $n & 1 );
			}
		} else {
			$text = $head; // AVIF/HEIF boxes: scan the header region.
		}

		$signals = array();
		$score   = 0;

		if ( false !== stripos( $text, 'trainedAlgorithmicMedia' ) ) {
			if ( false !== stripos( $text, 'compositeWithTrainedAlgorithmicMedia' ) ) {
				$signals[] = 'iptc:compositeWithTrainedAlgorithmicMedia';
				$score     = max( $score, 82 );
			} else {
				$signals[] = 'iptc:trainedAlgorithmicMedia';
				$score     = max( $score, 95 );
			}
		}
		if ( false !== strpos( $text, 'jumb' ) && false !== strpos( $text, 'c2pa' ) ) {
			$signals[] = 'c2pa';
			$score     = max( $score, 20 );
		}
		if ( preg_match( '/\b(midjourney|dall[\s·\-]?e|stable[\s\-]?diffusion|adobe firefly|firefly|imagen|openai|chatgpt|gemini|made with google ai|leonardo\.ai|ideogram|flux\.1|black forest labs|sdxl|comfyui|automatic1111|novelai|nano banana|sora)\b/i', $text, $m ) ) {
			$signals[] = 'software:' . strtolower( $m[1] );
			$score     = max( $score, in_array( 'c2pa', $signals, true ) ? 85 : 62 );
		}
		if ( isset( $labels['parameters'] ) && false !== strpos( $labels['parameters'], 'Steps:' ) && false !== strpos( $labels['parameters'], 'Sampler' ) ) {
			$signals[] = 'png:stable-diffusion-parameters';
			$score     = max( $score, 90 );
		}
		if ( isset( $labels['prompt'] ) && ( isset( $labels['workflow'] ) || false !== strpos( $labels['prompt'], 'class_type' ) ) ) {
			$signals[] = 'png:comfyui-workflow';
			$score     = max( $score, 90 );
		}
		if ( count( $signals ) > 1 && $score < 100 ) {
			$score = min( 100, $score + 5 );
		}

		/**
		 * Filter the detection result (add vendor-specific signals).
		 */
		return apply_filters( 'kikz_detect_result', array( 'score' => $score, 'signals' => $signals ), $path );
	}

	/**
	 * @return array{0:string,1:string} keyword, text
	 */
	private static function png_text( string $type, string $data ): array {
		$nul = strpos( $data, "\0" );
		if ( false === $nul ) {
			return array( '', '' );
		}
		$key  = substr( $data, 0, $nul );
		$rest = substr( $data, $nul + 1 );
		if ( 'tEXt' === $type ) {
			return array( $key, $rest );
		}
		if ( 'zTXt' === $type ) {
			$inflated = @gzuncompress( substr( $rest, 1 ), 4 * 1024 * 1024 );
			return array( $key, false === $inflated ? '' : $inflated );
		}
		// iTXt: compression flag, method, language\0, translated keyword\0, text.
		$flag = ord( $rest[0] ?? "\0" );
		$rest = substr( $rest, 2 );
		$p1   = strpos( $rest, "\0" );
		$p2   = false === $p1 ? false : strpos( $rest, "\0", $p1 + 1 );
		if ( false === $p2 ) {
			return array( $key, '' );
		}
		$text = substr( $rest, $p2 + 1 );
		if ( 1 === $flag ) {
			$inflated = @gzuncompress( $text, 4 * 1024 * 1024 );
			$text     = false === $inflated ? '' : $inflated;
		}
		return array( $key, $text );
	}

	/**
	 * Upload hook: mark clear cases automatically.
	 */
	public static function on_upload( $metadata, $attachment_id = 0 ) {
		if ( '1' !== KI_Kennzeichnung_Settings::get( 'auto_detect' ) || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$id = (int) $attachment_id;
		if ( $id <= 0 || KI_Kennzeichnung_Flags::is_flagged( $id ) || ! wp_attachment_is_image( $id ) ) {
			return $metadata;
		}
		$u    = wp_get_upload_dir();
		$path = trailingslashit( $u['basedir'] ) . $metadata['file'];
		$res  = self::analyze( $path );
		if ( $res['score'] >= self::AUTO_SCORE ) {
			KI_Kennzeichnung_Flags::set( $id, true, 'metadata', array( 'score' => $res['score'], 'signals' => $res['signals'], 'time' => time() ) );
			// The stamper runs right after this filter (priority 1) on the fresh files.
			KI_Kennzeichnung_Stamper::dequeue( $id );
		}
		return $metadata;
	}

	// ---------------------------------------------------------------------
	// File-name matching.
	// ---------------------------------------------------------------------

	private static function norm( string $value ): string {
		$value = strtolower( remove_accents( $value ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}

	/**
	 * Match a file name against the patterns on word boundaries
	 * ("dalle" matches "dalle-3.png" but not "dallemagne.jpg").
	 *
	 * @param string[] $patterns Patterns.
	 */
	public static function name_matches( string $filename, array $patterns ): ?string {
		$stem = self::norm( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
		if ( '' === $stem ) {
			return null;
		}
		foreach ( $patterns as $pattern ) {
			$p = self::norm( (string) $pattern );
			if ( '' !== $p && preg_match( '/(?:^|-)' . preg_quote( $p, '/' ) . '(?:-|$)/', $stem ) ) {
				return (string) $pattern;
			}
		}
		return null;
	}

	/**
	 * Scan the whole library in pages; returns matches that are not flagged yet.
	 *
	 * @return array<int, array{id:int,file:string,pattern:string,flagged:bool}>
	 */
	public static function find_name_matches( int $cap = 5000 ): array {
		global $wpdb;
		$patterns = KI_Kennzeichnung_Settings::patterns();
		$last     = PHP_INT_MAX;
		$out      = array();
		$t0       = microtime( true );
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, m.meta_value AS file FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID < %d ORDER BY p.ID DESC LIMIT 500",
					$last
				)
			);
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->ID;
				$hit  = self::name_matches( wp_basename( (string) $row->file ), $patterns );
				if ( null !== $hit ) {
					$out[] = array(
						'id'      => (int) $row->ID,
						'file'    => wp_basename( (string) $row->file ),
						'pattern' => $hit,
						'flagged' => KI_Kennzeichnung_Flags::is_flagged( (int) $row->ID ),
					);
				}
			}
		} while ( count( (array) $rows ) === 500 && count( $out ) < $cap && ( microtime( true ) - $t0 ) < 20 );
		return $out;
	}

	// ---------------------------------------------------------------------
	// AJAX.
	// ---------------------------------------------------------------------

	private static function guard( string $cap = 'upload_files' ): void {
		check_ajax_referer( 'kikz_admin', 'nonce' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'ki-kennzeichnung' ) ), 403 );
		}
	}

	public static function ajax_scan_names(): void {
		self::guard( 'manage_options' );
		$matches = self::find_name_matches();
		$open    = array_values( array_filter( $matches, static function ( $m ) {
			return ! $m['flagged'];
		} ) );
		wp_send_json_success(
			array(
				'count'  => count( $matches ),
				'open'   => count( $open ),
				'items'  => array_slice( $matches, 0, 200 ),
			)
		);
	}

	/**
	 * Mark confirmed file-name matches (explicit IDs, or all current matches).
	 */
	public static function ajax_mark_names(): void {
		self::guard( 'manage_options' );
		$ids = array();
		if ( ! empty( $_POST['all'] ) ) {
			foreach ( self::find_name_matches() as $m ) {
				if ( ! $m['flagged'] ) {
					$ids[] = $m['id'];
				}
			}
		} else {
			$ids = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
		}
		$marked = 0;
		foreach ( $ids as $id ) {
			if ( $id > 0 && 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) && ! KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
				KI_Kennzeichnung_Flags::set( $id, true, 'name' );
				$marked++;
			}
		}
		wp_send_json_success(
			array(
				'marked'  => $marked,
				'pending' => KI_Kennzeichnung_Stamper::queue_length(),
				'count'   => KI_Kennzeichnung_Flags::stats()['flagged'],
			)
		);
	}

	/**
	 * Batch metadata scan over the library (cursor = last handled attachment ID).
	 */
	public static function ajax_meta_scan(): void {
		self::guard( 'manage_options' );
		global $wpdb;
		$cursor = isset( $_POST['cursor'] ) ? (int) $_POST['cursor'] : PHP_INT_MAX;
		if ( $cursor <= 0 ) {
			$cursor = PHP_INT_MAX;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND ID < %d ORDER BY ID DESC LIMIT 25",
				$cursor
			)
		);
		$marked     = 0;
		$candidates = array();
		$t0         = microtime( true );
		$last       = $cursor;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( microtime( true ) - $t0 > 12 ) {
				break; // Continue from the last handled ID in the next request.
			}
			$last = $id;
			if ( KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
				continue;
			}
			$file = get_attached_file( $id );
			if ( ! $file ) {
				continue;
			}
			$res = self::analyze( $file );
			if ( $res['score'] >= self::AUTO_SCORE ) {
				KI_Kennzeichnung_Flags::set( $id, true, 'metadata', array( 'score' => $res['score'], 'signals' => $res['signals'], 'time' => time() ) );
				$marked++;
			} elseif ( $res['score'] >= self::CANDIDATE_SCORE ) {
				$candidates[] = array(
					'id'      => $id,
					'file'    => wp_basename( $file ),
					'score'   => $res['score'],
					'signals' => $res['signals'],
				);
			}
		}
		wp_send_json_success(
			array(
				'marked'     => $marked,
				'candidates' => $candidates,
				'cursor'     => $last,
				'done'       => count( (array) $ids ) < 25,
				'checked'    => count( (array) $ids ),
				'pending'    => KI_Kennzeichnung_Stamper::queue_length(),
				'count'      => KI_Kennzeichnung_Flags::stats()['flagged'],
			)
		);
	}
}
