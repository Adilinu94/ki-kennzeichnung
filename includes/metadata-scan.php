<?php
/**
 * Metadata-based AI detection for KI-Kennzeichnung.
 * Scans attachment files (EXIF Software, IPTC, XMP) for AI markers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Metadata {

	public static function init(): void {
		add_action( 'wp_generate_attachment_metadata', [ __CLASS__, 'scan_on_upload' ], 10, 2 );
		add_action( 'wp_ajax_kikz_scan_metadata', [ __CLASS__, 'ajax_scan' ] );
	}

	/**
	 * Extract AI markers from an attachment file.
	 */
	public static function detect( int $attachment_id ): bool {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return false;
		}
		$patterns = KI_Kennzeichnung::patterns_public();
		$text     = '';

		// EXIF Software.
		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $file );
			if ( is_array( $exif ) && ! empty( $exif['Software'] ) ) {
				$text .= ' ' . $exif['Software'];
			}
		}
		// IPTC.
		if ( function_exists( 'getimagesize' ) ) {
			$size = @getimagesize( $file, $info );
			if ( is_array( $info ) && ! empty( $info['APP13'] ) ) {
				if ( function_exists( 'iptcparse' ) ) {
					$iptc = @iptcparse( $info['APP13'] );
					if ( is_array( $iptc ) ) {
						foreach ( $iptc as $v ) {
							$text .= ' ' . implode( ' ', (array) $v );
						}
					}
				}
			}
		}

		$haystack = strtolower( $text );
		foreach ( $patterns as $p ) {
			if ( '' !== $p && false !== strpos( $haystack, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Auto-scan on upload.
	 */
	public static function scan_on_upload( $metadata, $attachment_id ) {
		if ( self::detect( (int) $attachment_id ) ) {
			update_post_meta( $attachment_id, '_ki_generated', '1' );
		}
		return $metadata;
	}

	/**
	 * AJAX batch scan.
	 */
	public static function ajax_scan(): void {
		check_ajax_referer( 'kikz_toggle', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
		}
		$batch  = 10;
		$marked = 0;
		$open   = 0;
		$ids    = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => 200,
			'fields'         => 'ids',
		] );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( KI_Kennzeichnung::is_flagged_public( $id ) ) {
				continue;
			}
			if ( self::detect( $id ) ) {
				if ( $marked < $batch ) {
					update_post_meta( $id, '_ki_generated', '1' );
					$marked++;
				} else {
					$open++;
				}
			}
		}
		wp_send_json_success( [ 'marked' => $marked, 'open' => $open ] );
	}
}

add_action( 'init', function () {
	KI_Kennzeichnung_Metadata::init();
}, 12 );
