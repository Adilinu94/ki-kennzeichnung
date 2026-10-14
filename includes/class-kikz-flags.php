<?php
/**
 * Flag state of attachments: single entry point for marking and unmarking,
 * plus a lookup map (file stem -> attachment ID) used for server-side
 * detection of images that were not rendered through WordPress image APIs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Flags {

	public const META        = '_ki_generated';
	public const META_LABEL  = '_ki_file_labeled';
	public const META_STATUS = '_ki_stamp_status';
	public const STEM_OPTION = 'kikz_stem_map';

	/** @var array<string,int>|null */
	private static $stem_cache = null;

	public static function is_flagged( int $id ): bool {
		return (bool) get_post_meta( $id, self::META, true );
	}

	public static function is_labeled( int $id ): bool {
		return (bool) get_post_meta( $id, self::META_LABEL, true );
	}

	/**
	 * Mark or unmark an attachment.
	 *
	 * @param int    $id     Attachment ID.
	 * @param bool   $flag   Target state.
	 * @param string $source manual | bulk | metadata | name | api.
	 * @param array  $detect Detection details (score, signals).
	 * @param bool   $sync   Process the file work immediately instead of queueing.
	 * @return array{flagged: bool, code: string}
	 */
	public static function set( int $id, bool $flag, string $source = 'manual', array $detect = array(), bool $sync = false ): array {
		$code = '';
		if ( $flag ) {
			$was = self::is_flagged( $id );
			update_post_meta( $id, self::META, '1' );
			if ( ! $was ) {
				update_post_meta( $id, '_ki_flag_source', sanitize_key( $source ) );
				update_post_meta( $id, '_ki_flag_time', time() );
			}
			if ( $detect ) {
				update_post_meta( $id, '_ki_detect', $detect );
			}
			self::stem_add( $id );

			if ( KI_Kennzeichnung_Settings::stamping_enabled() && ( ! self::is_labeled( $id ) || ! $was ) ) {
				if ( $sync ) {
					$res  = KI_Kennzeichnung_Stamper::stamp_attachment( $id );
					$code = $res['code'];
					KI_Kennzeichnung_Cache::purge_now();
				} else {
					KI_Kennzeichnung_Stamper::enqueue( $id, 'stamp' );
				}
			}
		} else {
			delete_post_meta( $id, self::META );
			delete_post_meta( $id, '_ki_flag_source' );
			delete_post_meta( $id, '_ki_flag_time' );
			delete_post_meta( $id, '_ki_detect' );
			self::stem_remove( $id );
			KI_Kennzeichnung_Stamper::dequeue( $id );

			if ( self::is_labeled( $id ) ) {
				if ( $sync ) {
					$res  = KI_Kennzeichnung_Stamper::unstamp_attachment( $id, ! empty( $detect['force'] ) );
					$code = $res['code'];
					KI_Kennzeichnung_Cache::purge_now();
				} else {
					KI_Kennzeichnung_Stamper::enqueue( $id, 'unstamp' );
				}
			} else {
				KI_Kennzeichnung_Stamper::clear_status( $id );
			}
		}
		return array( 'flagged' => self::is_flagged( $id ), 'code' => $code );
	}

	/**
	 * Drop bookkeeping for an attachment that is being deleted (no file work).
	 */
	public static function forget( int $id ): void {
		self::stem_remove( $id );
		KI_Kennzeichnung_Stamper::dequeue( $id );
	}

	/**
	 * Counters for the status card.
	 *
	 * @return array{flagged:int,stamped:int,pending:int,failed:int,legacy:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$count = static function ( string $key, string $where = '' ) use ( $wpdb ): int {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment' " . $where, // phpcs:ignore WordPress.DB.PreparedSQL
					$key
				)
			);
		};
		$stamped = $count( self::META_LABEL, "AND m.meta_value = '1'" );
		$with    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ki_badge_rects'" );
		return array(
			'flagged' => $count( self::META, "AND m.meta_value = '1'" ),
			'stamped' => $stamped,
			'pending' => $count( self::META_STATUS, "AND m.meta_value = 'pending'" ),
			'failed'  => $count( self::META_STATUS, "AND m.meta_value IN ('failed','unsupported')" ),
			'legacy'  => max( 0, $stamped - $with ),
		);
	}

	// ---------------------------------------------------------------------
	// Stem map.
	// ---------------------------------------------------------------------

	/**
	 * Normalise a relative upload path to a size-independent key
	 * ("2025/10/photo-scaled-300x200.jpg" -> "2025/10/photo").
	 */
	public static function normalize_stem( string $rel ): string {
		$rel  = ltrim( str_replace( '\\', '/', $rel ), '/' );
		$info = pathinfo( $rel );
		$dir  = ( isset( $info['dirname'] ) && '.' !== $info['dirname'] ) ? $info['dirname'] . '/' : '';
		$name = (string) ( $info['filename'] ?? '' );
		do {
			$before = $name;
			$name   = (string) preg_replace( '/-(\d+x\d+|scaled|rotated|e\d{10,})$/', '', $name );
		} while ( $before !== $name );
		return $dir . $name;
	}

	/**
	 * @return array<string,int>
	 */
	public static function stem_map(): array {
		if ( null === self::$stem_cache ) {
			$map = get_option( self::STEM_OPTION, null );
			if ( ! is_array( $map ) ) {
				self::rebuild_stem_map();
				$map = self::$stem_cache;
			} else {
				self::$stem_cache = $map;
			}
		}
		return self::$stem_cache ?? array();
	}

	public static function has_flagged(): bool {
		return ! empty( self::stem_map() );
	}

	public static function rebuild_stem_map(): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_type = 'attachment'",
				self::META
			)
		);
		$map = array();
		foreach ( (array) $ids as $id ) {
			foreach ( self::stems_for( (int) $id ) as $stem ) {
				$map[ $stem ] = (int) $id;
			}
		}
		self::$stem_cache = $map;
		update_option( self::STEM_OPTION, $map, false );
	}

	/**
	 * @return string[]
	 */
	private static function stems_for( int $id ): array {
		$stems = array();
		$file  = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' !== $file ) {
			$stems[] = self::normalize_stem( $file );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['original_image'] ) && '' !== $file ) {
			$stems[] = self::normalize_stem( trailingslashit( dirname( $file ) ) . $meta['original_image'] );
		}
		return array_values( array_unique( $stems ) );
	}

	private static function stem_add( int $id ): void {
		$map = self::stem_map();
		foreach ( self::stems_for( $id ) as $stem ) {
			$map[ $stem ] = $id;
		}
		self::$stem_cache = $map;
		update_option( self::STEM_OPTION, $map, false );
	}

	private static function stem_remove( int $id ): void {
		$map = array_filter(
			self::stem_map(),
			static function ( $v ) use ( $id ) {
				return (int) $v !== $id;
			}
		);
		self::$stem_cache = $map;
		update_option( self::STEM_OPTION, $map, false );
	}

	/**
	 * Resolve an image URL in the uploads folder to a flagged attachment ID.
	 */
	public static function id_from_url( string $url ): int {
		if ( '' === $url || ! self::has_flagged() ) {
			return 0;
		}
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$uploads = wp_get_upload_dir();
		$prefix = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' === $path || '' === $prefix || 0 !== strpos( $path, $prefix . '/' ) ) {
			return 0;
		}
		$rel  = rawurldecode( substr( $path, strlen( $prefix ) + 1 ) );
		$stem = self::normalize_stem( $rel );
		$map  = self::stem_map();
		return (int) ( $map[ $stem ] ?? 0 );
	}
}
