<?php
/**
 * File-level labeling pipeline.
 *
 * - Originals are backed up into a protected folder.
 * - Sizes are always regenerated from the clean original and every size is
 *   stamped individually (so thumbnails get a readable badge, not a shrunken one).
 * - Stamping runs inside wp_generate_attachment_metadata at priority 1, i.e.
 *   before image optimisers create WebP/AVIF copies from the files.
 * - Work is queued (cron + admin runner) instead of running in one request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Stamper {

	private const QUEUE    = 'kikz_queue';
	private const LOCK     = 'kikz_queue_lock';
	private const DIR_NAME = 'ki-kennzeichnung-originals';

	/** @var array{id:int,stamp:bool,result:?array}|null */
	private static $ctx = null;

	public static function init(): void {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 1, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
		add_action( 'kikz_process_queue', array( __CLASS__, 'cron_run' ) );
	}

	// ---------------------------------------------------------------------
	// Queue.
	// ---------------------------------------------------------------------

	/**
	 * @return array<int, array{id:int,op:string}>
	 */
	public static function queue(): array {
		$q = get_option( self::QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	public static function queue_length(): int {
		return count( self::queue() );
	}

	public static function enqueue( int $id, string $op ): void {
		$q   = array_values( array_filter( self::queue(), static function ( $item ) use ( $id ) {
			return (int) $item['id'] !== $id;
		} ) );
		$q[] = array( 'id' => $id, 'op' => 'unstamp' === $op ? 'unstamp' : 'stamp' );
		update_option( self::QUEUE, $q, false );
		if ( 'stamp' === $op ) {
			update_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS, 'pending' );
			delete_post_meta( $id, '_ki_stamp_error' );
		}
		self::schedule();
	}

	public static function dequeue( int $id ): void {
		$q = self::queue();
		$n = array_values( array_filter( $q, static function ( $item ) use ( $id ) {
			return (int) $item['id'] !== $id;
		} ) );
		if ( count( $n ) !== count( $q ) ) {
			update_option( self::QUEUE, $n, false );
		}
	}

	private static function schedule(): void {
		if ( ! wp_next_scheduled( 'kikz_process_queue' ) ) {
			wp_schedule_single_event( time() + 20, 'kikz_process_queue' );
		}
	}

	public static function cron_run(): void {
		self::process( 3, 25.0 );
		if ( self::queue_length() > 0 ) {
			self::schedule();
		}
	}

	/**
	 * Work through the queue within a time budget.
	 *
	 * @return array{done:int,left:int,errors:array<int,string>,busy:bool}
	 */
	public static function process( int $max_items = 3, float $budget = 20.0 ): array {
		if ( get_transient( self::LOCK ) ) {
			return array( 'done' => 0, 'left' => self::queue_length(), 'errors' => array(), 'busy' => true );
		}
		set_transient( self::LOCK, 1, 120 );
		$start  = microtime( true );
		$done   = 0;
		$errors = array();
		while ( $done < $max_items && ( microtime( true ) - $start ) < $budget ) {
			$q = self::queue();
			if ( empty( $q ) ) {
				break;
			}
			$item = array_shift( $q );
			update_option( self::QUEUE, $q, false );
			$id = (int) $item['id'];
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$res = 'unstamp' === $item['op'] ? self::unstamp_attachment( $id, false ) : self::stamp_attachment( $id );
			if ( ! $res['ok'] ) {
				$errors[ $id ] = self::error_message( $res['code'] );
			}
			$done++;
		}
		delete_transient( self::LOCK );
		if ( $done > 0 ) {
			KI_Kennzeichnung_Cache::purge_now();
		}
		return array( 'done' => $done, 'left' => self::queue_length(), 'errors' => $errors, 'busy' => false );
	}

	/**
	 * Queue (re-)stamping of every flagged image: labeled ones are redone from
	 * the original, unlabeled ones (e.g. flagged while in overlay mode) are stamped.
	 */
	public static function enqueue_restamp_all(): int {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_type = 'attachment'", KI_Kennzeichnung_Flags::META ) );
		foreach ( (array) $ids as $id ) {
			self::enqueue( (int) $id, 'stamp' );
		}
		return count( (array) $ids );
	}

	/**
	 * Queue removal of every burned-in badge. The images stay flagged (overlay only).
	 */
	public static function enqueue_unstamp_all(): int {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", KI_Kennzeichnung_Flags::META_LABEL ) );
		foreach ( (array) $ids as $id ) {
			self::enqueue( (int) $id, 'unstamp' );
		}
		return count( (array) $ids );
	}

	// ---------------------------------------------------------------------
	// Status.
	// ---------------------------------------------------------------------

	public static function set_status( int $id, string $status, string $code = '' ): void {
		update_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS, $status );
		if ( '' !== $code ) {
			update_post_meta( $id, '_ki_stamp_error', $code );
		} else {
			delete_post_meta( $id, '_ki_stamp_error' );
		}
	}

	public static function clear_status( int $id ): void {
		delete_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS );
		delete_post_meta( $id, '_ki_stamp_error' );
	}

	public static function error_message( string $code ): string {
		$map = array(
			'missing'        => __( 'Datei nicht gefunden.', 'ki-kennzeichnung' ),
			'unsupported'    => __( 'Dateityp wird nicht eingebrannt (z. B. GIF, SVG) – das Overlay übernimmt.', 'ki-kennzeichnung' ),
			'unreadable'     => __( 'Bild konnte nicht gelesen werden.', 'ki-kennzeichnung' ),
			'too_small'      => __( 'Bild ist für ein Badge zu klein – das Overlay übernimmt.', 'ki-kennzeichnung' ),
			'memory'         => __( 'Zu wenig Arbeitsspeicher für dieses Bild (PHP memory_limit).', 'ki-kennzeichnung' ),
			'decode_failed'  => __( 'Bild konnte nicht dekodiert werden.', 'ki-kennzeichnung' ),
			'encode_failed'  => __( 'Bild konnte nicht gespeichert werden.', 'ki-kennzeichnung' ),
			'write_failed'   => __( 'Datei ist nicht beschreibbar.', 'ki-kennzeichnung' ),
			'backup_failed'  => __( 'Original konnte nicht gesichert werden.', 'ki-kennzeichnung' ),
			'backup_missing' => __( 'Gesichertes Original fehlt.', 'ki-kennzeichnung' ),
			'regen_failed'   => __( 'Bildgrößen konnten nicht erzeugt werden.', 'ki-kennzeichnung' ),
			'modified'       => __( 'Die Datei wurde nach dem Stempeln bearbeitet.', 'ki-kennzeichnung' ),
			'no_gd'          => __( 'Auf diesem Server fehlt die PHP-Erweiterung GD.', 'ki-kennzeichnung' ),
		);
		return $map[ $code ] ?? $code;
	}

	// ---------------------------------------------------------------------
	// Backup folder.
	// ---------------------------------------------------------------------

	private static function base_dir(): string {
		$u = wp_get_upload_dir();
		return untrailingslashit( $u['basedir'] );
	}

	public static function backup_dir(): string {
		$dir = self::base_dir() . '/' . self::DIR_NAME;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( is_dir( $dir ) && ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "# Originals are private.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" );
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Absolute path of the backup for an attachment (may not exist yet).
	 */
	public static function backup_path( int $id ): string {
		$stored = (string) get_post_meta( $id, '_ki_original_file', true );
		if ( '' !== $stored ) {
			if ( '/' === $stored[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $stored ) ) {
				return $stored; // Legacy 1.x absolute path.
			}
			return self::base_dir() . '/' . $stored;
		}
		$file = (string) get_attached_file( $id );
		return self::backup_dir() . '/' . $id . '-' . wp_basename( $file );
	}

	private static function store_backup_meta( int $id, string $abs ): void {
		$base = self::base_dir() . '/';
		update_post_meta( $id, '_ki_original_file', 0 === strpos( $abs, $base ) ? substr( $abs, strlen( $base ) ) : $abs );
	}

	/**
	 * Move 1.x backups (public, next to the image) into the protected folder.
	 */
	public static function migrate_legacy_backups(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ki_original_file' LIMIT 500" );
		$dir  = self::backup_dir();
		$t0   = microtime( true );
		foreach ( (array) $rows as $row ) {
			if ( microtime( true ) - $t0 > 15 ) {
				break;
			}
			$old = (string) $row->meta_value;
			if ( '' === $old || ! ( '/' === $old[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $old ) ) || ! is_file( $old ) ) {
				continue;
			}
			$new = $dir . '/' . (int) $row->post_id . '-' . wp_basename( $old );
			if ( @rename( $old, $new ) || ( @copy( $old, $new ) && @unlink( $old ) ) ) {
				self::store_backup_meta( (int) $row->post_id, $new );
			}
		}
	}

	public static function on_delete( $id ): void {
		$id     = (int) $id;
		$backup = self::backup_path( $id );
		if ( is_file( $backup ) ) {
			@unlink( $backup );
		}
		KI_Kennzeichnung_Flags::forget( $id );
	}

	// ---------------------------------------------------------------------
	// Stamping.
	// ---------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private static function stamp_options(): array {
		$size = KI_Kennzeichnung_Settings::stamp_size();
		$col  = KI_Kennzeichnung_Settings::colors();
		return array(
			'label'          => KI_Kennzeichnung_Settings::label(),
			'short_label'    => 'KI',
			'position'       => KI_Kennzeichnung_Settings::position(),
			'factor'         => $size['factor'],
			'min_px'         => $size['min_px'],
			'min_margin'     => KI_Kennzeichnung_Settings::margin(),
			'margin_factor'  => 0.008,
			'max_width'      => 0.45,
			'bg'             => KI_Kennzeichnung_Settings::gd_color( $col['bg'], array( 0, 0, 0, 44 ) ),
			'fg'             => array_slice( KI_Kennzeichnung_Settings::gd_color( $col['fg'], array( 255, 255, 255, 0 ) ), 0, 3 ),
			'font'           => KIKZ_DIR . 'fonts/DejaVuSans.ttf',
			'write_metadata' => true,
		);
	}

	/**
	 * Stamp the main file and all generated sizes of freshly generated metadata.
	 *
	 * @return array{main_ok:bool,code:string,rects:array<string,array>}
	 */
	private static function stamp_files( int $id, array $metadata ): array {
		$base = self::base_dir();
		$main = $base . '/' . ltrim( (string) $metadata['file'], '/' );
		$dir  = dirname( $main );
		$mime = (string) get_post_mime_type( $id );
		$opts = self::stamp_options();

		$type = KI_Kennzeichnung_Image::resolve_type( $main, $mime );
		if ( null === $type ) {
			return array( 'main_ok' => false, 'code' => 'unsupported', 'rects' => array() );
		}
		$res = KI_Kennzeichnung_Image::stamp( $main, $type, $opts );
		if ( ! is_array( $res ) ) {
			return array( 'main_ok' => false, 'code' => $res, 'rects' => array() );
		}
		$rects = array( wp_basename( $main ) => $res['rect'] );

		$files = array();
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $size['file'];
			}
			foreach ( (array) ( $size['sources'] ?? array() ) as $src ) {
				if ( ! empty( $src['file'] ) ) {
					$files[] = $src['file'];
				}
			}
		}
		foreach ( (array) ( $metadata['sources'] ?? array() ) as $src ) {
			if ( ! empty( $src['file'] ) ) {
				$files[] = $src['file'];
			}
		}
		foreach ( array_unique( $files ) as $name ) {
			$path = $dir . '/' . $name;
			if ( $path === $main || ! is_file( $path ) ) {
				continue;
			}
			$t = KI_Kennzeichnung_Image::resolve_type( $path );
			if ( null === $t ) {
				continue;
			}
			$r = KI_Kennzeichnung_Image::stamp( $path, $t, $opts );
			if ( is_array( $r ) ) {
				$rects[ wp_basename( $path ) ] = $r['rect'];
			}
		}
		return array( 'main_ok' => true, 'code' => '', 'rects' => $rects );
	}

	/**
	 * Hook: runs for every metadata generation (upload, regeneration, our own).
	 */
	public static function on_generate_metadata( $metadata, $attachment_id, $context = '' ) {
		$id = (int) $attachment_id;
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || $id <= 0 ) {
			return $metadata;
		}

		// Our own pipeline: stamp what was just generated from the clean original.
		if ( null !== self::$ctx && self::$ctx['id'] === $id ) {
			if ( self::$ctx['stamp'] ) {
				self::$ctx['result'] = self::stamp_files( $id, $metadata );
			}
			return $metadata;
		}

		// Someone else regenerates (upload, "Regenerate Thumbnails", theme change).
		if ( ! KI_Kennzeichnung_Flags::is_flagged( $id ) || ! KI_Kennzeichnung_Settings::stamping_enabled() ) {
			return $metadata;
		}
		if ( KI_Kennzeichnung_Flags::is_labeled( $id ) ) {
			$main_path = self::base_dir() . '/' . ltrim( (string) $metadata['file'], '/' );
			$hash      = (string) get_post_meta( $id, '_ki_stamped_hash', true );
			if ( '' !== $hash && is_file( $main_path ) && md5_file( $main_path ) !== $hash ) {
				// The file itself was replaced (e.g. "Enable Media Replace"): the old backup is
				// obsolete and must never be copied over the new image. Treat the new file as clean.
				$old_backup = self::backup_path( $id );
				if ( is_file( $old_backup ) ) {
					@unlink( $old_backup );
				}
				self::drop_stamp_meta( $id );
				delete_post_meta( $id, '_ki_original_file' );
				// Falls through to the "fresh files" branch below.
			} else {
				// Their sizes were cut from an already stamped file: redo from the original.
				if ( is_file( self::backup_path( $id ) ) ) {
					$orig = (string) ( $metadata['original_image'] ?? '' );
					self::stamp_attachment( $id );
					$fresh = wp_get_attachment_metadata( $id );
					if ( ! is_array( $fresh ) || empty( $fresh ) ) {
						return $metadata;
					}
					if ( '' !== $orig && empty( $fresh['original_image'] ) ) {
						$fresh['original_image'] = $orig;
					}
					$fresh = self::recover_original_image( $fresh );
					wp_update_attachment_metadata( $id, $fresh );
					return $fresh;
				}
				return $metadata;
			}
		}

		// Fresh upload that was flagged (e.g. by metadata detection): files are clean.
		$main = self::base_dir() . '/' . ltrim( (string) $metadata['file'], '/' );
		if ( ! is_file( self::backup_path( $id ) ) ) {
			$backup = self::backup_dir() . '/' . $id . '-' . wp_basename( $main );
			if ( ! @copy( $main, $backup ) ) {
				self::set_status( $id, 'failed', 'backup_failed' );
				return $metadata;
			}
			self::store_backup_meta( $id, $backup );
		}
		self::finish_stamp( $id, self::stamp_files( $id, $metadata ), $main );
		return $metadata;
	}

	private static function finish_stamp( int $id, array $res, string $main ): void {
		if ( ! $res['main_ok'] ) {
			$soft = in_array( $res['code'], array( 'unsupported', 'too_small' ), true );
			self::set_status( $id, $soft ? 'unsupported' : 'failed', $res['code'] );
			return;
		}
		update_post_meta( $id, KI_Kennzeichnung_Flags::META_LABEL, '1' );
		update_post_meta( $id, '_ki_badge_rects', $res['rects'] );
		update_post_meta( $id, '_ki_stamped_hash', (string) md5_file( $main ) );
		update_post_meta( $id, '_ki_stamp_time', time() );
		self::set_status( $id, 'ok' );
	}

	/**
	 * Merge freshly generated metadata into the old one: keep keys that only
	 * the old metadata knows (e.g. original_image) and remove obsolete files.
	 */
	private static function merge_metadata( array $old, array $new ): array {
		foreach ( $old as $key => $value ) {
			if ( ! array_key_exists( $key, $new ) ) {
				$new[ $key ] = $value;
			}
		}
		$new = self::recover_original_image( $new );
		$dir = dirname( self::base_dir() . '/' . ltrim( (string) ( $new['file'] ?? '' ), '/' ) );
		$keep = array();
		foreach ( (array) ( $new['sizes'] ?? array() ) as $s ) {
			$keep[ $s['file'] ?? '' ] = true;
		}
		foreach ( (array) ( $old['sizes'] ?? array() ) as $name => $s ) {
			if ( ! empty( $s['file'] ) && empty( $keep[ $s['file'] ] ) && is_file( $dir . '/' . $s['file'] ) ) {
				@unlink( $dir . '/' . $s['file'] );
			}
		}
		return $new;
	}

	/**
	 * WordPress drops "original_image" whenever metadata is regenerated from the
	 * already scaled file. Rebuild it from the "-scaled" naming convention.
	 */
	public static function recover_original_image( array $meta ): array {
		if ( ! empty( $meta['original_image'] ) || empty( $meta['file'] ) ) {
			return $meta;
		}
		if ( preg_match( '/^(.*)-scaled(\.[^.]+)$/', wp_basename( (string) $meta['file'] ), $m ) ) {
			$dir = dirname( self::base_dir() . '/' . ltrim( (string) $meta['file'], '/' ) );
			if ( is_file( $dir . '/' . $m[1] . $m[2] ) ) {
				$meta['original_image'] = $m[1] . $m[2];
			}
		}
		return $meta;
	}

	/**
	 * Regenerate all sizes from the clean file; optionally stamping them.
	 *
	 * @return array{ok:bool,result:?array}
	 */
	private static function regenerate( int $id, string $file, bool $stamp ): array {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$old       = wp_get_attachment_metadata( $id );
		self::$ctx = array( 'id' => $id, 'stamp' => $stamp, 'result' => null );
		$new       = wp_generate_attachment_metadata( $id, $file );
		$result    = self::$ctx['result'];
		self::$ctx = null;
		if ( ! is_array( $new ) || empty( $new['file'] ) ) {
			return array( 'ok' => false, 'result' => null );
		}
		wp_update_attachment_metadata( $id, self::merge_metadata( is_array( $old ) ? $old : array(), $new ) );
		return array( 'ok' => true, 'result' => $result );
	}

	/**
	 * Stamp (or re-stamp) one attachment.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function stamp_attachment( int $id ): array {
		if ( ! KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
			self::clear_status( $id );
			return array( 'ok' => true, 'code' => 'not_flagged' );
		}
		if ( ! KI_Kennzeichnung_Settings::stamping_enabled() ) {
			self::clear_status( $id );
			return array( 'ok' => true, 'code' => 'overlay_mode' );
		}
		if ( ! extension_loaded( 'gd' ) ) {
			self::set_status( $id, 'failed', 'no_gd' );
			return array( 'ok' => false, 'code' => 'no_gd' );
		}
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_file( $file ) ) {
			self::set_status( $id, 'failed', 'missing' );
			return array( 'ok' => false, 'code' => 'missing' );
		}
		$type = KI_Kennzeichnung_Image::resolve_type( $file, (string) get_post_mime_type( $id ) );
		if ( null === $type || ! KI_Kennzeichnung_Image::supports( $type ) ) {
			self::set_status( $id, 'unsupported', 'unsupported' );
			return array( 'ok' => true, 'code' => 'unsupported' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$backup = self::backup_path( $id );
		if ( KI_Kennzeichnung_Flags::is_labeled( $id ) ) {
			if ( self::is_modified( $id ) ) {
				// Edited or replaced after stamping: restoring the backup would destroy that work.
				self::set_status( $id, 'failed', 'modified' );
				return array( 'ok' => false, 'code' => 'modified' );
			}
			if ( ! is_file( $backup ) || ! @copy( $backup, $file ) ) {
				self::set_status( $id, 'failed', 'backup_missing' );
				return array( 'ok' => false, 'code' => 'backup_missing' );
			}
		} else {
			if ( ! is_file( $backup ) ) {
				$backup = self::backup_dir() . '/' . $id . '-' . wp_basename( $file );
				if ( ! @copy( $file, $backup ) ) {
					self::set_status( $id, 'failed', 'backup_failed' );
					return array( 'ok' => false, 'code' => 'backup_failed' );
				}
			}
			self::store_backup_meta( $id, $backup );
		}
		delete_post_meta( $id, KI_Kennzeichnung_Flags::META_LABEL );

		$run = self::regenerate( $id, $file, true );
		if ( ! $run['ok'] ) {
			@copy( $backup, $file );
			self::set_status( $id, 'failed', 'regen_failed' );
			return array( 'ok' => false, 'code' => 'regen_failed' );
		}
		$res = $run['result'];
		if ( ! is_array( $res ) || ! $res['main_ok'] ) {
			// Main file could not be stamped: put the clean state back.
			@copy( $backup, $file );
			self::regenerate( $id, $file, false );
			$code = is_array( $res ) ? $res['code'] : 'regen_failed';
			self::finish_stamp( $id, array( 'main_ok' => false, 'code' => $code, 'rects' => array() ), $file );
			return array( 'ok' => in_array( $code, array( 'unsupported', 'too_small' ), true ), 'code' => $code );
		}
		self::finish_stamp( $id, $res, (string) get_attached_file( $id ) );
		KI_Kennzeichnung_Cache::request_purge( $id );
		return array( 'ok' => true, 'code' => 'ok' );
	}

	/**
	 * Restore the clean original and regenerate sizes without badge.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function unstamp_attachment( int $id, bool $force = false ): array {
		if ( ! KI_Kennzeichnung_Flags::is_labeled( $id ) ) {
			self::clear_status( $id );
			return array( 'ok' => true, 'code' => 'not_labeled' );
		}
		$file   = (string) get_attached_file( $id );
		$backup = self::backup_path( $id );
		if ( ! is_file( $backup ) || '' === $file ) {
			self::drop_stamp_meta( $id );
			return array( 'ok' => false, 'code' => 'backup_missing' );
		}
		if ( ! $force && self::is_modified( $id ) ) {
			return array( 'ok' => false, 'code' => 'modified' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}
		if ( ! @copy( $backup, $file ) ) {
			return array( 'ok' => false, 'code' => 'write_failed' );
		}
		$run = self::regenerate( $id, $file, false );
		@unlink( $backup );
		self::drop_stamp_meta( $id );
		KI_Kennzeichnung_Cache::request_purge( $id );
		return array( 'ok' => $run['ok'], 'code' => $run['ok'] ? 'ok' : 'regen_failed' );
	}

	/**
	 * Whether the stamped file was changed after stamping (e.g. WP image editor).
	 */
	public static function is_modified( int $id ): bool {
		$hash = (string) get_post_meta( $id, '_ki_stamped_hash', true );
		$file = (string) get_attached_file( $id );
		return '' !== $hash && is_file( $file ) && md5_file( $file ) !== $hash;
	}

	private static function drop_stamp_meta( int $id ): void {
		foreach ( array( KI_Kennzeichnung_Flags::META_LABEL, '_ki_original_file', '_ki_badge_rects', '_ki_stamped_hash', '_ki_stamp_time' ) as $key ) {
			delete_post_meta( $id, $key );
		}
		self::clear_status( $id );
	}
}
