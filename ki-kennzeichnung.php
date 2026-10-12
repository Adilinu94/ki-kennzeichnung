<?php
/**
 * Plugin Name:       KI-Kennzeichnung
 * Description:       Labels AI-generated images across the site: media-library checkbox and bulk actions, an automatic badge on the image, an alt-text suffix, and a configurable site notice. Provides the [ki-badge] and [ki-hinweis] shortcodes.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nick Webdesign
 * License:           GPL-2.0-or-later
 * Text Domain:       ki-kennzeichnung
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung {

	/** Attachment meta flag. */
	private const META_KEY = '_ki_generated';

	/** Plugin version. */
	private const VERSION = '1.3.0';

	/**
	 * Register all hooks.
	 */
	public static function init(): void {
		// Media library: checkbox on the attachment edit screen.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'render_media_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_media_field' ), 10, 2 );

		// Media library: list column and bulk actions.
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );

		// Admin UI: styles, scripts, AJAX toggle, media filter, plugin links.
		add_action( 'admin_head', array( __CLASS__, 'print_admin_styles' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_admin_scripts' ) );
		add_action( 'wp_ajax_kikz_toggle', array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_kikz_scan_names', array( __CLASS__, 'ajax_scan_names' ) );
		add_action( 'wp_ajax_kikz_mark_matches', array( __CLASS__, 'ajax_mark_matches' ) );
		add_action( 'wp_ajax_kikz_clear_elementor_cache', array( __CLASS__, 'ajax_clear_elementor_cache' ) );
		add_filter( 'restrict_manage_posts', array( __CLASS__, 'media_filter_dropdown' ) );
		add_filter( 'pre_get_posts', array( __CLASS__, 'filter_media_query' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'plugin_action_links' ) );

		// Front end: flag rendered images and extend their alt text.
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 10, 3 );

		// Front end: badge styles, JS fallback and the optional site notice.
		add_action( 'wp_head', array( __CLASS__, 'print_google_font' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_assets' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'print_site_notice' ), 99 );

		// Shortcodes for manual placement (e.g. background images).
		add_shortcode( 'ki-badge', array( __CLASS__, 'shortcode_badge' ) );
		add_shortcode( 'ki-hinweis', array( __CLASS__, 'shortcode_notice' ) );

		// Settings.
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
	}

	/**
	 * Option defaults.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'badge_text'  => 'KI-generiert',
			'alt_suffix'  => 'KI-generiert',
			'footnote'    => 'Hinweis: Einzelne Bilder auf dieser Website wurden mit KI erstellt.',
			'footnote_on' => '1',
			'burn_in'     => '1',
			'badge_size'  => 'medium',
					'badge_corner' => 'bottom-right',
					'badge_theme'  => 'dark',
					'badge_short'  => '0',
					'badge_color'  => '#ffffff',
					'badge_bg_color'=> 'rgba(0,0,0,.65)',
					'badge_font'   => 'system',
					'badge_google_font_url' => '',
					'patterns'     => 'chatgpt, dall-e, dalle, midjourney, stable-diffusion, firefly, imagen, flux-dev, ai-generated, ki-generiert',
			'preview_image' => '0',
		);
	}

	/**
	 * Read one plugin option with its default.
	 */
	public static function get( string $key ): string {
		$defaults = self::defaults();
		$value    = get_option( 'kikz_' . $key, $defaults[ $key ] ?? '' );
		if ( false === $value || null === $value ) {
			$value = $defaults[ $key ] ?? '';
		}
		return (string) $value;
	}

	/**
	 * Whether an attachment is flagged as AI-generated.
	 */
	private static function parse_rgb( string $hex, array $fallback ): array {
		$hex = trim( ltrim( $hex, '#' ) );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
	}

	private static function parse_color( string $value, array $fallback ): array {
		// Supports hex or rgba(); falls back.
		if ( 0 === strpos( $value, 'rgba' ) || 0 === strpos( $value, 'rgb' ) ) {
			preg_match_all( '/[\d.]+/', $value, $m );
			$nums = $m[0] ?? [];
			if ( count( $nums ) >= 3 ) {
				$r = (int) $nums[0]; $g = (int) $nums[1]; $b = (int) $nums[2];
				$a = isset( $nums[3] ) ? (int) round( ( 1 - (float) $nums[3] ) * 127 ) : $fallback[3];
				return [ $r, $g, $b, max( 0, min( 127, $a ) ) ];
			}
		}
		$rgb = self::parse_rgb( $value, [ $fallback[0], $fallback[1], $fallback[2] ] );
		return [ $rgb[0], $rgb[1], $rgb[2], $fallback[3] ];
	}

	private static function is_flagged( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, self::META_KEY, true );
	}

	/**
	 * Public wrapper.
	 */
	public static function is_flagged_public( int $attachment_id ): bool {
		return self::is_flagged( $attachment_id );
	}

	/**
	 * Whether the visible badge is burned into the image file itself.
	 */
	private static function burn_in_enabled(): bool {
		return '1' === self::get( 'burn_in' );
	}

	/**
	 * Visible badge label (short form when enabled).
	 */
	private static function badge_label(): string {
		return '1' === self::get( 'badge_short' ) ? 'KI' : self::get( 'badge_text' );
	}

	/**
	 * Inline CSS for the badge, honouring size, corner and theme options.
	 */
	private static function badge_css(): string {
		$sizes  = array( 'small' => 10, 'medium' => 11, 'large' => 13 );
		$size   = self::get( 'badge_size' );
		$px     = $sizes[ $size ] ?? 11;
		$corner = 'bottom-left' === self::get( 'badge_corner' ) ? 'left:10px;' : 'right:10px;';
		$theme  = 'light' === self::get( 'badge_theme' )
			? 'background:rgba(255,255,255,.88);color:#1d2327;'
			: 'background:rgba(0,0,0,.65);color:#fff;';

		return 'position:absolute;bottom:10px;' . $corner . 'z-index:6;display:inline-block;padding:2px 9px;border-radius:999px;' . $theme . 'font-size:' . $px . 'px;line-height:1.7;letter-spacing:.02em;pointer-events:none;';
	}

	/**
	 * Search patterns for the file-name scan.
	 *
	 * @return string[]
	 */
	private static function patterns(): array {
		$raw   = self::get( 'patterns' );
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( empty( $parts ) ) {
			$parts = array( 'chatgpt', 'dall-e', 'midjourney' );
		}
		return array_values( array_map( 'strtolower', $parts ) );
	}

	/**
	 * Public accessor for patterns.
	 */
	public static function patterns_public(): array {
		return self::patterns();
	}

	/**
	 * Find image attachments whose file name matches a pattern.
	 *
	 * @return array<int, array{id: int, file: string}>
	 */
	public static function find_name_matches(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID, m.meta_value AS file FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' ORDER BY p.ID DESC LIMIT 1000"
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$patterns = self::patterns();
		$out      = array();
		foreach ( $rows as $row ) {
			$file = (string) $row->file;
			if ( '' === $file ) {
				continue;
			}
			$haystack = strtolower( $file );
			foreach ( $patterns as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $haystack, $pattern ) ) {
					$out[] = array(
						'id'   => (int) $row->ID,
						'file' => wp_basename( $file ),
					);
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the attachment file already carries the burned-in badge.
	 */
	private static function is_file_labeled( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, '_ki_file_labeled', true );
	}

	/**
	 * Burn the badge into the attachment file, or restore the original file.
	 */
	public static function apply_file_label( int $attachment_id, bool $flag ): bool {
		if ( ! self::burn_in_enabled() ) {
			return false;
		}
		if ( $flag ) {
			return self::stamp_file( $attachment_id );
		}
		self::restore_original_file( $attachment_id );
		return true;
	}

	/**
	 * Draw the badge into the file and regenerate the intermediate sizes.
	 */
	private static function stamp_file( int $attachment_id ): bool {
		if ( self::is_file_labeled( $attachment_id ) ) {
			return true;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return false;
		}
		$type = self::image_type_from( $file, (string) get_post_mime_type( $attachment_id ) );
		if ( null === $type ) {
			return false;
		}
		$image = self::load_image( $file, $type );
		if ( ! $image ) {
			return false;
		}

		self::draw_badge( $image, imagesx( $image ), imagesy( $image ) );

		$path   = pathinfo( $file );
		$backup = $path['dirname'] . '/' . $path['filename'] . '-ki-original.' . $path['extension'];
		if ( ! file_exists( $backup ) ) {
			@copy( $file, $backup );
		}

		$saved = self::save_image( $image, $file, $type );
		imagedestroy( $image );
		if ( ! $saved ) {
			return false;
		}

		update_post_meta( $attachment_id, '_ki_file_labeled', '1' );
		update_post_meta( $attachment_id, '_ki_original_file', $backup );
		self::regenerate_sizes( $attachment_id, $file );
		return true;
	}

	/**
	 * Restore the unlabeled original file.
	 */
	private static function restore_original_file( int $attachment_id ): void {
		if ( ! self::is_file_labeled( $attachment_id ) ) {
			return;
		}
		$file   = get_attached_file( $attachment_id );
		$backup = (string) get_post_meta( $attachment_id, '_ki_original_file', true );
		if ( $file && $backup && is_file( $backup ) ) {
			@copy( $backup, $file );
			@unlink( $backup );
			delete_post_meta( $attachment_id, '_ki_file_labeled' );
			delete_post_meta( $attachment_id, '_ki_original_file' );
			self::regenerate_sizes( $attachment_id, $file );
		}
	}

	/**
	 * Render the rounded badge into the image resource.
	 */
	private static function draw_badge( $image, int $width, int $height ): void {
		$text    = self::badge_label();
		$font    = __DIR__ . '/fonts/DejaVuSans.ttf';
		$ttf     = is_file( $font ) && function_exists( 'imagettftext' );
		$factors = array( 'small' => 0.006, 'medium' => 0.0085, 'large' => 0.012 );
		$factor  = $factors[ self::get( 'badge_size' ) ] ?? 0.0085;
		$size    = max( 9, (int) round( $width * $factor ) );

		if ( $ttf ) {
			$box = imagettfbbox( $size, 0, $font, $text );
			$tw  = abs( $box[2] - $box[0] );
			$th  = abs( $box[7] - $box[1] );
		} else {
			$tw = imagefontwidth( 5 ) * strlen( $text );
			$th = imagefontheight( 5 );
		}

		$pad_x  = (int) round( $th * 0.85 );
		$pad_y  = (int) round( $th * 0.5 );
		$bw     = $tw + $pad_x * 2;
		$bh     = $th + $pad_y * 2;
		$margin = max( 10, (int) round( $width * 0.012 ) );
		$x0     = 'bottom-left' === self::get( 'badge_corner' ) ? $margin : $width - $margin - $bw;
		$y0     = $height - $margin - $bh;
		if ( $x0 < 4 || $y0 < 4 ) {
			return;
		}

		$bg_color = self::get( 'badge_bg_color' );
		$fg_color = self::get( 'badge_color' );

		imagealphablending( $image, true );
		$bg = imagecolorallocatealpha( $image, ...self::parse_color( $bg_color, array( 15, 20, 26, 70 ) ) );
		$fg = imagecolorallocate( $image, ...self::parse_rgb( $fg_color, array( 255, 255, 255 ) ) );
		self::filled_round_rect( $image, $x0, $y0, $x0 + $bw, $y0 + $bh, (int) round( $bh / 2 ), $bg );
		if ( $ttf ) {
			imagettftext( $image, $size, 0, $x0 + $pad_x, $y0 + $pad_y + $th, $fg, $font, $text );
		} else {
			imagestring( $image, 5, $x0 + $pad_x, $y0 + $pad_y, $text, $fg );
		}
	}

	/**
	 * Fill a rounded rectangle.
	 */
	private static function filled_round_rect( $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color ): void {
		$radius = max( 1, $radius );
		imagefilledrectangle( $image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color );
		imagefilledrectangle( $image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color );
		$d = $radius * 2;
		imagefilledellipse( $image, $x1 + $radius, $y1 + $radius, $d, $d, $color );
		imagefilledellipse( $image, $x2 - $radius, $y1 + $radius, $d, $d, $color );
		imagefilledellipse( $image, $x1 + $radius, $y2 - $radius, $d, $d, $color );
		imagefilledellipse( $image, $x2 - $radius, $y2 - $radius, $d, $d, $color );
	}

	/**
	 * Create an image resource from a file.
	 */
	private static function load_image( string $file, string $type ) {
		switch ( $type ) {
			case 'webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file ) : null;
			case 'jpeg':
				return @imagecreatefromjpeg( $file );
			case 'png':
				return @imagecreatefrompng( $file );
		}
		return null;
	}

	/**
	 * Write the image resource back to the file in its original format.
	 */
	private static function save_image( $image, string $file, string $type ): bool {
		switch ( $type ) {
			case 'webp':
				return function_exists( 'imagewebp' ) && @imagewebp( $image, $file, 88 );
			case 'jpeg':
				return (bool) @imagejpeg( $image, $file, 90 );
			case 'png':
				return (bool) @imagepng( $image, $file, 6 );
		}
		return false;
	}

	/**
	 * Map file and mime type to an image type key.
	 */
	private static function image_type_from( string $file, string $mime ): ?string {
		$mime = strtolower( $mime );
		$ext  = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'image/webp' === $mime || 'webp' === $ext ) {
			return 'webp';
		}
		if ( 'image/jpeg' === $mime || in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) {
			return 'jpeg';
		}
		if ( 'image/png' === $mime || 'png' === $ext ) {
			return 'png';
		}
		return null;
	}

	/**
	 * Regenerate the intermediate image sizes from the updated file.
	 */
	private static function regenerate_sizes( int $attachment_id, string $file ): void {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}
	}

	/**
	 * Number of flagged attachments.
	 */
	private static function flagged_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type = 'attachment'",
				self::META_KEY,
				'1'
			)
		);
	}

	// ---------------------------------------------------------------------
	// Media library.
	// ---------------------------------------------------------------------

	/**
	 * Add the checkbox to the attachment edit screen.
	 *
	 * @param array   $form_fields Existing fields.
	 * @param WP_Post $post        Attachment post.
	 * @return array
	 */
	public static function render_media_field( array $form_fields, $post ): array {
		$field_id = 'attachments-' . (int) $post->ID . '-ki_generated';
		$checked  = self::is_flagged( (int) $post->ID );

		$form_fields['ki_generated'] = array(
			'label' => 'KI-Kennzeichnung',
			'input' => 'html',
			'html'  => '<label for="' . esc_attr( $field_id ) . '">'
				. '<input type="checkbox" id="' . esc_attr( $field_id ) . '"'
				. ' name="attachments[' . (int) $post->ID . '][ki_generated]" value="1"' . checked( $checked, true, false ) . '> '
				. 'KI-generiertes Bild</label>',
			'helps' => 'Bild wird auf der Website automatisch gekennzeichnet (Badge und Alt-Text-Zusatz).',
		);

		return $form_fields;
	}

	/**
	 * Persist the checkbox.
	 *
	 * @param array $post       Sanitized post data.
	 * @param array $attachment Submitted attachment fields.
	 * @return array
	 */
	public static function save_media_field( array $post, array $attachment ): array {
		$id      = (int) $post['ID'];
		$flagged = ! empty( $attachment['ki_generated'] );

		if ( $flagged ) {
			update_post_meta( $id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $id, self::META_KEY );
		}

		self::apply_file_label( $id, $flagged );

		return $post;
	}

	/**
	 * Add the list column header.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_media_column( array $columns ): array {
		$columns['ki_kennzeichnung'] = 'KI';
		return $columns;
	}

	/**
	 * Render the list column content: a clickable toggle chip.
	 */
	public static function render_media_column( string $column, int $post_id ): void {
		if ( 'ki_kennzeichnung' !== $column ) {
			return;
		}
		$flagged = self::is_flagged( $post_id );

		printf(
			'<button type="button" class="kikz-chip%1$s" data-id="%2$d" data-flagged="%3$d" aria-pressed="%3$d" title="KI-Kennzeichnung umschalten">%4$s</button>',
			$flagged ? ' is-on' : '',
			$post_id,
			$flagged ? 1 : 0,
			$flagged ? 'KI' : '&ndash;'
		);
	}

	/**
	 * Register the bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public static function register_bulk_actions( array $actions ): array {
		$actions['kikz_mark']   = 'Als KI-generiert markieren';
		$actions['kikz_unmark'] = 'KI-Kennzeichnung entfernen';
		return $actions;
	}

	/**
	 * Handle the bulk actions.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Selected action.
	 * @param int[]  $ids      Selected attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( string $redirect, string $action, array $ids ): string {
		if ( 'kikz_mark' !== $action && 'kikz_unmark' !== $action ) {
			return $redirect;
		}

		$count = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'kikz_mark' === $action ) {
				update_post_meta( $id, self::META_KEY, '1' );
				self::apply_file_label( $id, true );
			} else {
				delete_post_meta( $id, self::META_KEY );
				self::apply_file_label( $id, false );
			}
			$count++;
		}

		return add_query_arg(
			'kikz_done',
			( 'kikz_mark' === $action ? 'marked-' : 'unmarked-' ) . $count,
			$redirect
		);
	}

	/**
	 * AJAX: toggle the flag of one attachment.
	 */
	public static function ajax_clear_elementor_cache(): void {
		check_ajax_referer( 'kikz_toggle', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				// ignore
			}
		}
		wp_send_json_success( array( 'cleared' => true ) );
	}

	/**
	 * AJAX: toggle the flag of one attachment.
	 */
	public static function ajax_toggle(): void {
		check_ajax_referer( 'kikz_toggle', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => 'Ungueltige ID.' ) );
		}

		$state = isset( $_POST['state'] ) && '1' === (string) $_POST['state'];
		if ( $state ) {
			update_post_meta( $id, self::META_KEY, '1' );
		} else {
			delete_post_meta( $id, self::META_KEY );
		}
		self::apply_file_label( $id, $state );

		wp_send_json_success(
			array(
				'id'      => $id,
				'flagged' => $state,
				'count'   => self::flagged_count(),
			)
		);
	}

	/**
	 * AJAX: scan the media library for file names that indicate AI sources.
	 */
	public static function ajax_scan_names(): void {
		check_ajax_referer( 'kikz_toggle', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}

		$items = array();
		foreach ( self::find_name_matches() as $match ) {
			$items[] = array(
				'id'      => $match['id'],
				'file'    => $match['file'],
				'flagged' => self::is_flagged( $match['id'] ),
			);
		}

		wp_send_json_success(
			array(
				'count' => count( $items ),
				'items' => array_slice( $items, 0, 100 ),
			)
		);
	}

	/**
	 * AJAX: mark and stamp every attachment whose file name matches the patterns.
	 */
	public static function ajax_mark_matches(): void {
		check_ajax_referer( 'kikz_toggle', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}

		$batch  = 5;
		$marked = 0;
		$open   = 0;
		foreach ( self::find_name_matches() as $match ) {
			if ( self::is_flagged( $match['id'] ) ) {
				continue;
			}
			if ( $marked < $batch ) {
				update_post_meta( $match['id'], self::META_KEY, '1' );
				self::apply_file_label( $match['id'], true );
				$marked++;
			} else {
				$open++;
			}
		}

		wp_send_json_success(
			array(
				'marked' => $marked,
				'open'   => $open,
				'count'  => self::flagged_count(),
			)
		);
	}

	/**
	 * Add the media list filter dropdown.
	 */
	public static function media_filter_dropdown( string $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['kikz_filter'] ) ? sanitize_key( wp_unslash( $_GET['kikz_filter'] ) ) : '';

		printf( '<label for="kikz_filter" class="screen-reader-text">KI-Kennzeichnung</label>' );
		printf( '<select name="kikz_filter" id="kikz_filter">' );
		printf( '<option value=""%s>KI-Kennzeichnung: alle</option>', selected( $current, '', false ) );
		printf( '<option value="marked"%s>Nur KI-markierte</option>', selected( $current, 'marked', false ) );
		printf( '<option value="unmarked"%s>Nur nicht markierte</option>', selected( $current, 'unmarked', false ) );
		printf( '</select>' );
	}

	/**
	 * Apply the media list filter.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function filter_media_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$mode = isset( $_GET['kikz_filter'] ) ? sanitize_key( wp_unslash( $_GET['kikz_filter'] ) ) : '';
		if ( 'marked' === $mode ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => self::META_KEY,
						'value'   => '1',
						'compare' => '=',
					),
				)
			);
		} elseif ( 'unmarked' === $mode ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				)
			);
		}
	}

	/**
	 * Add the settings link to the plugins list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=ki-kennzeichnung' ) ) . '">Einstellungen</a>'
		);
		return $links;
	}

	// ---------------------------------------------------------------------
	// Admin UI: styles and scripts.
	// ---------------------------------------------------------------------

	/**
	 * Whether the current admin screen belongs to the plugin.
	 */
	private static function is_plugin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'upload', 'settings_page_ki-kennzeichnung' ), true );
	}

	/**
	 * Print the admin styles.
	 */
	public static function print_admin_styles(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		?>
<style id="kikz-admin-css">
.kikz-chip{display:inline-flex;align-items:center;gap:4px;padding:1px 10px;border-radius:999px;border:1px solid #c3c4c7;background:#f6f7f7;color:#50575e;font-size:11px;line-height:1.8;cursor:pointer;transition:background .15s ease,color .15s ease,border-color .15s ease}
.kikz-chip:hover{border-color:var(--wp-admin-theme-color,#2271b1);color:var(--wp-admin-theme-color,#2271b1)}
.kikz-chip.is-on{background:var(--wp-admin-theme-color,#2271b1);border-color:var(--wp-admin-theme-color,#2271b1);color:#fff}
.kikz-chip[disabled]{opacity:.5;cursor:wait}
.attachment{position:relative}
.kikz-grid-badge{position:absolute;top:8px;left:8px;z-index:3;background:rgba(0,0,0,.7);color:#fff;border-radius:999px;padding:1px 8px;font-size:11px;line-height:1.7;pointer-events:none}
.kikz-wrap{max-width:1180px}
.kikz-header{display:flex;align-items:center;gap:14px;margin:16px 0 22px}
.kikz-logo{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--wp-admin-theme-color,#2271b1),#0a4b78);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;letter-spacing:.5px;box-shadow:0 6px 14px -6px rgba(0,0,0,.45)}
.kikz-header h1{margin:0;font-size:23px;line-height:1.2;font-weight:600}
.kikz-header p{margin:3px 0 0;color:#646970;font-size:13px}
.kikz-version{display:inline-block;vertical-align:middle;margin-left:8px;padding:2px 9px;border-radius:999px;background:#f0f0f1;color:#646970;font-size:11px;font-weight:500;letter-spacing:.02em}
.kikz-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:22px;align-items:start}
@media(max-width:960px){.kikz-grid{grid-template-columns:1fr}}
.kikz-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:22px 24px;box-shadow:0 1px 2px rgba(0,0,0,.04),0 14px 30px -24px rgba(0,0,0,.35)}
.kikz-card + .kikz-card{margin-top:22px}
.kikz-card h2{margin:0 0 4px;font-size:15px;font-weight:600}
.kikz-card .kikz-hint{margin:0 0 18px;color:#646970;font-size:12.5px;line-height:1.5}
.kikz-field{margin:0 0 18px}
.kikz-field:last-child{margin-bottom:0}
.kikz-field > label{display:block;font-weight:600;margin-bottom:6px}
.kikz-input{width:100%;max-width:440px;border-radius:10px;border:1px solid #c3c4c7;padding:8px 12px;font-size:13px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.kikz-input:focus{border-color:var(--wp-admin-theme-color,#2271b1);box-shadow:0 0 0 2px rgba(34,113,177,.22);outline:none}
textarea.kikz-input{min-height:74px;resize:vertical}
.kikz-wrap label.kikz-switch{display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.kikz-switch input{position:absolute;opacity:0;pointer-events:none}
.kikz-wrap .kikz-switch .kikz-track{display:inline-block;vertical-align:middle;width:44px;height:25px;border-radius:999px;background:#c3c4c7;position:relative;transition:background .15s ease;flex:0 0 auto}
.kikz-switch .kikz-track::after{content:"";position:absolute;top:3px;left:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s ease}
.kikz-switch input:checked + .kikz-track{background:var(--wp-admin-theme-color,#2271b1)}
.kikz-switch input:checked + .kikz-track::after{transform:translateX(19px)}
.kikz-switch input:focus-visible + .kikz-track{box-shadow:0 0 0 2px rgba(34,113,177,.35)}
.kikz-preview{position:relative;border-radius:14px;overflow:hidden;min-height:220px;background:linear-gradient(135deg,#e8edf3,#ccd6e2);display:flex;align-items:center;justify-content:center;color:#8794a5;font-size:12px;letter-spacing:.04em;text-transform:uppercase}
.kikz-preview .kikz-badge-demo{text-transform:none;z-index:2;position:absolute;bottom:12px;right:12px;background:rgba(0,0,0,.65);color:#fff;border-radius:999px;padding:3px 11px;font-size:12px;line-height:1.7}
.kikz-preview .kikz-notice-demo{text-transform:none;position:absolute;left:0;right:0;bottom:0;background:rgba(255,255,255,.9);text-align:center;font-size:11.5px;color:#646970;padding:8px 12px;display:none}
.kikz-preview.show-notice .kikz-notice-demo{display:block}
.kikz-preview.show-notice .kikz-badge-demo{bottom:48px;}
@font-face{font-family:'Kikz Badge';src:url('<?php echo esc_url( plugins_url( 'fonts/DejaVuSans.ttf', __FILE__ ) ); ?>') format('truetype');font-display:swap;}
.kikz-preview .kikz-badge-demo{font-family:'Kikz Badge','DejaVu Sans',sans-serif;}
.kikz-preview img{display:block;width:100%;height:auto;max-height:440px;object-fit:contain;border-radius:12px;}
.kikz-preview.has-image{min-height:0;}
.kikz-stat{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;border-radius:12px;background:#f6f7f7;border:1px solid #f0f0f1}
.kikz-stat strong{font-size:24px;line-height:1;display:block}
.kikz-stat span{color:#646970;font-size:12px}
.kikz-actions{margin:22px 0 8px;display:flex;gap:12px;align-items:center}
.kikz-actions .button-primary{border-radius:10px;padding:4px 18px;height:auto;line-height:2.2}
.kikz-code{background:#f0f0f1;border-radius:6px;padding:1px 7px;font-size:12px}
.kikz-footer-note{margin-top:24px;color:#646970;font-size:12px}
</style>
		<?php
	}

	/**
	 * Print the admin scripts: chip toggle on the media list, grid badge marker,
	 * and the live preview on the settings page.
	 */
	public static function print_admin_scripts(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		$screen     = get_current_screen();
		$is_settings = ( $screen && 'settings_page_ki-kennzeichnung' === $screen->id );
		?>
<script id="kikz-admin-js">
(function(){
	var nonce = <?php echo wp_json_encode( wp_create_nonce( 'kikz_toggle' ) ); ?>;
	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

	// Clickable KI chip in the media list.
	document.addEventListener('click', function(e){
		var btn = e.target.closest ? e.target.closest('.kikz-chip') : null;
		if(!btn || btn.disabled){ return; }
		e.preventDefault();
		var next = btn.getAttribute('data-flagged') === '1' ? '0' : '1';
		var body = 'action=kikz_toggle&nonce=' + encodeURIComponent(nonce) + '&id=' + encodeURIComponent(btn.getAttribute('data-id')) + '&state=' + next;
		btn.disabled = true;
		fetch(ajaxUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: body })
			.then(function(r){ return r.json(); })
			.then(function(res){
				if(res && res.success){
					btn.setAttribute('data-flagged', next);
					btn.setAttribute('aria-pressed', next);
					btn.classList.toggle('is-on', next === '1');
					btn.textContent = next === '1' ? 'KI' : '\u2013';
					var counter = document.getElementById('kikz-count');
					if(counter && res.data && typeof res.data.count === 'number'){ counter.textContent = res.data.count; }
				}
			})
			.catch(function(){})
			.finally(function(){ btn.disabled = false; });
	});
<?php if ( ! $is_settings ) : ?>
	// Grid view: mark flagged attachments with a small corner badge.
	var flagged = <?php
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'meta_key'       => self::META_KEY,
			'meta_value'     => '1',
		)
	);
	echo wp_json_encode( array_map( 'intval', $ids ) );
	?>;
	function decorateGrid(){
		if(!window.wp || !wp.media || !wp.media.view || !wp.media.view.Attachment){ return; }
		var proto = wp.media.view.Attachment.prototype;
		if(proto.__kikzPatched){ return; }
		proto.__kikzPatched = true;
		var orig = proto.render;
		proto.render = function(){
			var out = orig.apply(this, arguments);
			try {
				var id = this.model && this.model.get('id');
				if(id && flagged.indexOf(id) !== -1 && this.$el && this.$el.length && !this.$el.find('.kikz-grid-badge').length){
					this.$el.append('<span class="kikz-grid-badge">KI</span>');
				}
			} catch(e) {}
			return out;
		};
	}
	if(document.readyState !== 'loading'){ decorateGrid(); } else { document.addEventListener('DOMContentLoaded', decorateGrid); }
<?php endif; ?>
<?php if ( $is_settings ) : ?>
	// Settings page: live preview.
	function bindPreview(){
		var badgeInput  = document.getElementById('kikz_badge_text');
		var shortChk    = document.getElementById('kikz_badge_short');
		var sizeSel     = document.getElementById('kikz_badge_size');
		var cornerSel   = document.getElementById('kikz_badge_corner');
		var themeSel    = document.getElementById('kikz_badge_theme');
		var noticeInput = document.getElementById('kikz_footnote');
		var noticeOn    = document.getElementById('kikz_footnote_on');
		var badgeDemo   = document.getElementById('kikz-badge-demo');
		var noticeDemo  = document.getElementById('kikz-notice-demo');
		var preview     = document.getElementById('kikz-preview');
		function sync(){
			if(badgeDemo){
				badgeDemo.textContent = (shortChk && shortChk.checked) ? 'KI' : (badgeInput ? (badgeInput.value || 'KI-generiert') : '');
				var sizes = { small: '10px', medium: '11px', large: '13px' };
				badgeDemo.style.fontSize = sizeSel ? (sizes[sizeSel.value] || '11px') : '';
				var corner = cornerSel ? cornerSel.value : 'bottom-right';
				badgeDemo.style.left = corner === 'bottom-left' ? '12px' : '';
				badgeDemo.style.right = corner === 'bottom-left' ? '' : '12px';
				var light = themeSel && themeSel.value === 'light';
				badgeDemo.style.background = light ? 'rgba(255,255,255,.88)' : 'rgba(0,0,0,.65)';
				badgeDemo.style.color = light ? '#1d2327' : '#fff';
			}
			if(noticeInput && noticeDemo){ noticeDemo.textContent = noticeInput.value || ''; }
			if(noticeOn && preview){ preview.classList.toggle('show-notice', noticeOn.checked); }
		}
		[badgeInput, noticeInput].forEach(function(el){ if(el){ el.addEventListener('input', sync); } });
		[shortChk, sizeSel, cornerSel, themeSel].forEach(function(el){ if(el){ el.addEventListener('change', sync); } });
		if(noticeOn){ noticeOn.addEventListener('change', sync); }
		sync();
	}
	// Settings page: file-name scan.
	var scanBtn = document.getElementById('kikz-scan');
	var markBtn = document.getElementById('kikz-mark-matches');
	var scanOut = document.getElementById('kikz-scan-result');
	function escHtml(value){ return String(value).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
	function callAjax(action, onDone){
		fetch(ajaxUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: 'action=' + action + '&nonce=' + encodeURIComponent(nonce) })
			.then(function(r){ return r.json(); })
			.then(function(res){ if(res && res.success){ onDone(res.data); } })
			.catch(function(){});
	}
	if(scanBtn && scanOut){
		scanBtn.addEventListener('click', function(){
			scanOut.textContent = 'Suche l\u00e4uft ...';
			callAjax('kikz_scan_names', function(data){
				var open = data.items.filter(function(it){ return !it.flagged; }).length;
				var html = '<p><strong>' + data.count + '</strong> Treffer, davon <strong>' + open + '</strong> noch nicht markiert.</p>';
				if(data.items.length){
					html += '<ul style="margin:6px 0 0;padding-left:18px;list-style:disc;max-height:180px;overflow:auto;">';
					data.items.forEach(function(it){ html += '<li style="margin:0 0 2px;">' + escHtml(it.file) + (it.flagged ? ' <em>(bereits markiert)</em>' : '') + '</li>'; });
					html += '</ul>';
				}
				scanOut.innerHTML = html;
				if(markBtn){ markBtn.style.display = open > 0 ? 'inline-block' : 'none'; }
			});
		});
	}
	if(markBtn){
		markBtn.addEventListener('click', function(){
			var totalMarked = 0;
			markBtn.disabled = true;
			scanOut.innerHTML = '<p>Markiere und stemple Bilder ...</p>';
			function step(){
				callAjax('kikz_mark_matches', function(data){
					totalMarked += data.marked;
					var counter = document.getElementById('kikz-count');
					if(counter){ counter.textContent = data.count; }
					if(data.open > 0){
						scanOut.innerHTML = '<p><strong>' + totalMarked + '</strong> markiert, <strong>' + data.open + '</strong> verbleiben ...</p>';
						step();
						return;
					}
					scanOut.innerHTML = '<p><strong>' + totalMarked + '</strong> Bilder markiert und gestempelt. Insgesamt markiert: <strong>' + data.count + '</strong>.</p>';
					markBtn.style.display = 'none';
					markBtn.disabled = false;
				});
			}
			step();
		});
	}
	// Settings page: preview image picker.
	var pickBtn = document.getElementById('kikz-pick-image');
	var clearBtn = document.getElementById('kikz-clear-image');
	var imageInput = document.getElementById('kikz_preview_image');
	var previewImg = document.getElementById('kikz-preview-img');
	var placeholder = document.getElementById('kikz-preview-placeholder');
	function applyPreviewImage(url, id){
		if(!previewImg){ return; }
		if(url){
			previewImg.src = url;
			previewImg.style.display = 'block';
			var box = document.getElementById('kikz-preview');
			if(box){ box.classList.add('has-image'); }
			if(placeholder){ placeholder.style.display = 'none'; }
			if(imageInput){ imageInput.value = id || ''; }
			if(pickBtn){ pickBtn.textContent = 'Bild \u00e4ndern'; }
			if(clearBtn){ clearBtn.style.display = 'inline-block'; }
		} else {
			previewImg.removeAttribute('src');
			previewImg.style.display = 'none';
			var boxEmpty = document.getElementById('kikz-preview');
			if(boxEmpty){ boxEmpty.classList.remove('has-image'); }
			if(placeholder){ placeholder.style.display = ''; }
			if(imageInput){ imageInput.value = '0'; }
			if(pickBtn){ pickBtn.textContent = 'Bild ausw\u00e4hlen'; }
			if(clearBtn){ clearBtn.style.display = 'none'; }
		}
	}
	if(pickBtn){
		var frame = null;
		pickBtn.addEventListener('click', function(e){
			e.preventDefault();
			if(!window.wp || !wp.media){ return; }
			if(!frame){
				frame = wp.media({ title: 'Vorschaubild ausw\u00e4hlen', button: { text: 'Dieses Bild verwenden' }, library: { type: 'image' }, multiple: false });
				frame.on('select', function(){
					var att = frame.state().get('selection').first().toJSON();
					var url = (att.sizes && att.sizes.large) ? att.sizes.large.url : ((att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url);
					applyPreviewImage(url, att.id);
				});
			}
			frame.open();
		});
	}
	if(clearBtn){
		clearBtn.addEventListener('click', function(e){
			e.preventDefault();
			applyPreviewImage('', 0);
		});
	}
	if(document.readyState !== 'loading'){ bindPreview(); } else { document.addEventListener('DOMContentLoaded', bindPreview); }
<?php endif; ?>
})();
</script>
		<?php
	}

	// ---------------------------------------------------------------------
	// Front end.
	// ---------------------------------------------------------------------

	/**
	 * Add the marker class and the alt-text suffix to rendered images.
	 *
	 * @param array   $attr       Image attributes.
	 * @param WP_Post $attachment Attachment post.
	 * @param mixed   $size       Requested size.
	 * @return array
	 */
	public static function filter_image_attributes( array $attr, $attachment, $size ): array {
		if ( ! $attachment instanceof WP_Post || ! self::is_flagged( (int) $attachment->ID ) || self::is_file_labeled( (int) $attachment->ID ) ) {
			return $attr;
		}

		$attr['class'] = trim( (string) ( $attr['class'] ?? '' ) . ' ki-flagged-image' );

		$suffix = trim( self::get( 'alt_suffix' ) );
		$alt    = trim( (string) ( $attr['alt'] ?? '' ) );
		if ( '' !== $suffix && '' !== $alt ) {
			$attr['alt'] = $alt . ' (' . $suffix . ')';
		}

		return $attr;
	}

public static function print_google_font(): void {
		$url = trim( self::get( 'badge_google_font_url' ) );
		if ( '' !== $url ) {
			echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
			echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
			echo '<link rel="stylesheet" href="' . esc_url( $url ) . '">' . "\n";
		}
	}

	/**
	 * Print the badge CSS.
	 */
	public static function print_styles(): void {
		$label = esc_html( self::get( 'badge_text' ) );
		?>
<style id="ki-kennzeichnung-css">
.ki-badge{position:absolute;bottom:10px;right:10px;z-index:5;display:inline-block;padding:2px 9px;border-radius:999px;background:rgba(0,0,0,.65);color:#fff;font-size:11px;line-height:1.7;letter-spacing:.02em;}
:is(.elementor-widget-image,.wp-block-image,figure,.gallery-item):has(img.ki-flagged-image){position:relative;}
:is(.elementor-widget-image,.wp-block-image,figure,.gallery-item):has(img.ki-flagged-image)::after{content:"<?php echo esc_html( $label ); ?>";position:absolute;bottom:10px;right:10px;z-index:5;display:inline-block;padding:2px 9px;border-radius:999px;background:rgba(0,0,0,.65);color:#fff;font-size:11px;line-height:1.7;letter-spacing:.02em;pointer-events:none;}
:is(.elementor-section,.elementor-container,.e-con).ki-badge-anzeigen{position:relative;}
.ki-badge-anzeigen::after{content:"<?php echo esc_html( $label ); ?>";position:absolute;bottom:10px;right:10px;z-index:5;display:inline-block;padding:2px 9px;border-radius:999px;background:rgba(0,0,0,.65);color:#fff;font-size:11px;line-height:1.7;letter-spacing:.02em;pointer-events:none;}
.ki-hinweis{text-align:center;font-size:12px;opacity:.75;padding:14px 16px;}
.ki-hinweis p{margin:0;}
.ki-flagged-bg-static{position:relative;}
.ki-flagged-bg::after{content:"<?php echo esc_html( $label ); ?>";position:absolute;bottom:10px;right:10px;z-index:6;display:inline-block;padding:2px 9px;border-radius:999px;background:rgba(0,0,0,.65);color:#fff;font-size:11px;line-height:1.7;letter-spacing:.02em;pointer-events:none;}
</style>
		<?php
	}

	/**
	 * Print the JS fallback: mark images by their file name when a builder did
	 * not render them through wp_get_attachment_image().
	 */
	public static function print_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => self::META_KEY,
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => '_ki_file_labeled',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$stems = array();
		foreach ( $ids as $id ) {
			$file = wp_basename( (string) get_attached_file( (int) $id ) );
			if ( '' === $file ) {
				continue;
			}
			$stems[] = (string) pathinfo( $file, PATHINFO_FILENAME );
		}
		if ( empty( $stems ) ) {
			return;
		}
		?>
<script id="ki-kennzeichnung-js">
(function(){
	var stems = <?php echo wp_json_encode( array_values( array_unique( $stems ) ) ); ?>;
	function matches(src){
		if(!src){return false;}
		var name = src.split('?')[0].split('/').pop();
		for(var i=0;i<stems.length;i++){
			var s = stems[i];
			if(name === s || name.indexOf(s + '.') === 0 || name.indexOf(s + '-') === 0){return true;}
		}
		return false;
	}
	function mark(){
		var imgs = document.querySelectorAll('img:not(.ki-flagged-image)');
		for(var i=0;i<imgs.length;i++){
			if(matches(imgs[i].getAttribute('src')) || matches(imgs[i].getAttribute('data-src'))){
				imgs[i].classList.add('ki-flagged-image');
			}
		}
	}
	function urlsFrom(value){
		var out = [], re = /url\((['"]?)(.*?)\1\)/g, m;
		while((m = re.exec(value || '')) !== null){ out.push(m[2]); }
		return out;
	}
	function markBackgrounds(){
		var els = document.querySelectorAll('[style*="background-image"], .elementor-background-slideshow__slide__image');
		for(var i=0;i<els.length;i++){
			var el = els[i];
			if(el.classList.contains('ki-flagged-bg')){ continue; }
			var urls = urlsFrom(el.getAttribute('style') || '');
			if(!urls.length){ try { urls = urlsFrom(getComputedStyle(el).backgroundImage || ''); } catch(e){} }
			for(var j=0;j<urls.length;j++){
				if(matches(urls[j])){
					el.classList.add('ki-flagged-bg');
					try {
						if(getComputedStyle(el).position === 'static'){ el.classList.add('ki-flagged-bg-static'); }
					} catch(e) {}
					break;
				}
			}
		}
	}
	function run(){ mark(); markBackgrounds(); }
	if(document.readyState !== 'loading'){run();}else{document.addEventListener('DOMContentLoaded', run);}
	window.addEventListener('load', run);
	setTimeout(run, 2000);
	setTimeout(run, 6000);
	try {
	  var obs = new MutationObserver(function(){ run(); });
	  obs.observe(document.body, {subtree:true, childList:true, attributes:true, attributeFilter:['style','class']});
	} catch(e) {}
	})();
</script>
		<?php
	}

	/**
	 * Print the optional site notice.
	 */
	public static function print_site_notice(): void {
		if ( is_admin() || '1' !== self::get( 'footnote_on' ) ) {
			return;
		}
		$text = trim( self::get( 'footnote' ) );
		if ( '' === $text ) {
			return;
		}
		echo '<div class="ki-hinweis ki-footer-hinweis"><p>' . esc_html( $text ) . '</p></div>';
	}

	// ---------------------------------------------------------------------
	// Shortcodes.
	// ---------------------------------------------------------------------

	/**
	 * [ki-badge] prints a badge at the current position.
	 */
	public static function shortcode_badge(): string {
		return '<span class="ki-badge">' . esc_html( self::badge_label() ) . '</span>';
	}

	/**
	 * [ki-hinweis] prints the notice text at the current position.
	 */
	public static function shortcode_notice(): string {
		return '<div class="ki-hinweis"><p>' . esc_html( self::get( 'footnote' ) ) . '</p></div>';
	}

	// ---------------------------------------------------------------------
	// Settings.
	// ---------------------------------------------------------------------

	/**
	 * Register the settings.
	 */
	public static function register_settings(): void {
		register_setting( 'kikz', 'kikz_badge_text', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_alt_suffix', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_footnote', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_footnote_on', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_burn_in', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_size', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_corner', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_theme', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_short', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_color', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ) );
		register_setting( 'kikz', 'kikz_badge_bg_color', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_font', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_badge_google_font_url', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'kikz', 'kikz_patterns', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'kikz', 'kikz_preview_image', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
	}

	/**
	 * Add the settings page.
	 */
	public static function add_settings_page(): void {
		add_options_page( 'KI-Kennzeichnung', 'KI-Kennzeichnung', 'manage_options', 'ki-kennzeichnung', array( __CLASS__, 'render_settings_page' ) );
	}

	/**
	 * Render the modern settings page.
	 */
	public static function render_settings_page(): void {
		wp_enqueue_media();

		$count      = self::flagged_count();
		$preview_id = (int) get_option( 'kikz_preview_image', 0 );
		?>
		<div class="wrap kikz-wrap">
			<div class="kikz-header">
				<div class="kikz-logo">KI</div>
				<div>
					<h1>KI-Kennzeichnung <span class="kikz-version">v<?php echo esc_html( self::VERSION ); ?></span></h1>
					<p>KI-Bilder automatisch kennzeichnen: Badge am Bild, Alt-Text-Zusatz und Hinweis auf der Website.</p>
				</div>
			</div>
			<?php // This marker keeps WordPress from moving admin notices into the custom header markup. ?>
			<hr class="wp-header-end">

			<form method="post" action="options.php">
				<?php settings_fields( 'kikz' ); ?>

				<div class="kikz-grid">
					<div>
						<div class="kikz-card">
							<h2>Darstellung</h2>
							<p class="kikz-hint">Diese Texte werden auf der Website angezeigt.</p>

							<div class="kikz-field">
								<label for="kikz_badge_text">Badge-Text</label>
								<input class="kikz-input" name="kikz_badge_text" id="kikz_badge_text" type="text" value="<?php echo esc_attr( self::get( 'badge_text' ) ); ?>">
							</div>

							<div class="kikz-field">
								<label for="kikz_alt_suffix">Alt-Text-Zusatz</label>
								<input class="kikz-input" name="kikz_alt_suffix" id="kikz_alt_suffix" type="text" value="<?php echo esc_attr( self::get( 'alt_suffix' ) ); ?>">
								<p class="kikz-hint" style="margin:8px 0 0;">Wird in Klammern an vorhandene Alt-Texte gekennzeichneter Bilder angehängt.</p>
							</div>

							<div class="kikz-field">
								<span style="display:block;font-weight:600;margin-bottom:6px;">Datei-Kennzeichnung</span>
								<label class="kikz-switch">
									<input type="hidden" name="kikz_burn_in" value="0">
									<input type="checkbox" name="kikz_burn_in" id="kikz_burn_in" value="1" <?php checked( self::get( 'burn_in' ), '1' ); ?>>
									<span class="kikz-track" aria-hidden="true"></span>
									<span>Badge fest in die Bilddatei schreiben</span>
								</label>
								<p class="kikz-hint" style="margin:8px 0 0;">Beim Markieren wird das Badge fest ins Bild gerechnet (Ecke und Stil wie oben gewählt). Das Original wird automatisch gesichert und beim Entfernen der Markierung wiederhergestellt.</p>
							</div>

							<div class="kikz-field">
								<span style="display:block;font-weight:600;margin-bottom:6px;">Badge-Stil</span>
								<div style="display:flex;gap:18px;flex-wrap:wrap;">
									<label style="display:block;">
										<span style="display:block;font-size:12px;color:#646970;margin-bottom:4px;">Größe</span>
										<select class="kikz-input" name="kikz_badge_size" id="kikz_badge_size" style="max-width:150px;">
											<option value="small" <?php selected( self::get( 'badge_size' ), 'small' ); ?>>Klein</option>
											<option value="medium" <?php selected( self::get( 'badge_size' ), 'medium' ); ?>>Mittel</option>
											<option value="large" <?php selected( self::get( 'badge_size' ), 'large' ); ?>>Groß</option>
										</select>
									</label>
									<label style="display:block;">
										<span style="display:block;font-size:12px;color:#646970;margin-bottom:4px;">Ecke</span>
										<select class="kikz-input" name="kikz_badge_corner" id="kikz_badge_corner" style="max-width:170px;">
											<option value="bottom-right" <?php selected( self::get( 'badge_corner' ), 'bottom-right' ); ?>>Unten rechts</option>
											<option value="bottom-left" <?php selected( self::get( 'badge_corner' ), 'bottom-left' ); ?>>Unten links</option>
										</select>
									</label>
									<label style="display:block;">
										<span style="display:block;font-size:12px;color:#646970;margin-bottom:4px;">Stil</span>
										<select class="kikz-input" name="kikz_badge_theme" id="kikz_badge_theme" style="max-width:150px;">
											<option value="dark" <?php selected( self::get( 'badge_theme' ), 'dark' ); ?>>Dunkel</option>
											<option value="light" <?php selected( self::get( 'badge_theme' ), 'light' ); ?>>Hell</option>
										</select>
									</label>
								</div>
								<label class="kikz-switch" style="margin-top:12px;">
									<input type="hidden" name="kikz_badge_short" value="0">
									<input type="checkbox" name="kikz_badge_short" id="kikz_badge_short" value="1" <?php checked( self::get( 'badge_short' ), '1' ); ?>>
									<span class="kikz-track" aria-hidden="true"></span>
									<span>Nur Kurzform "KI" anzeigen</span>
								</label>
							</div>
						</div>

						<div class="kikz-card">
							<h2>Badge-Farben & Schriftart</h2>
							<p class="kikz-hint">Eigene Farben und optionale Google-Schriftart fuer den Badge (Burn-in + Frontend).</p>
							<div class="kikz-field">
								<label for="kikz_badge_color">Badge-Schriftfarbe (Hex)</label>
								<input class="kikz-input" name="kikz_badge_color" id="kikz_badge_color" type="text" value="<?php echo esc_attr( self::get( 'badge_color' ) ); ?>">
							</div>
							<div class="kikz-field">
								<label for="kikz_badge_bg_color">Badge-Hintergrund (CSS/rgba)</label>
								<input class="kikz-input" name="kikz_badge_bg_color" id="kikz_badge_bg_color" type="text" value="<?php echo esc_attr( self::get( 'badge_bg_color' ) ); ?>">
							</div>
							<div class="kikz-field">
								<label for="kikz_badge_google_font_url">Google Fonts CSS-URL (optional)</label>
								<input class="kikz-input" name="kikz_badge_google_font_url" id="kikz_badge_google_font_url" type="text" value="<?php echo esc_attr( self::get( 'badge_google_font_url' ) ); ?>">
								<p class="kikz-hint" style="margin:8px 0 0;">Beispiel: https://fonts.googleapis.com/css2?family=Inter:wght@600&display=swap</p>
							</div>
						</div>

						<div class="kikz-card">
							<h2>Footer-Hinweis</h2>
							<p class="kikz-hint">Ergänzend zur Kennzeichnung am Bild, abschaltbar.</p>

							<div class="kikz-field">
								<label class="kikz-switch">
									<input type="hidden" name="kikz_footnote_on" value="0">
									<input type="checkbox" name="kikz_footnote_on" id="kikz_footnote_on" value="1" <?php checked( self::get( 'footnote_on' ), '1' ); ?>>
									<span class="kikz-track" aria-hidden="true"></span>
									<span>Hinweis automatisch im Footer anzeigen</span>
								</label>
							</div>

							<div class="kikz-field">
								<label for="kikz_footnote">Hinweis-Text</label>
								<textarea class="kikz-input" name="kikz_footnote" id="kikz_footnote" rows="2"><?php echo esc_textarea( self::get( 'footnote' ) ); ?></textarea>
							</div>
						</div>

						<div class="kikz-card">
							<h2>Hintergrundbilder</h2>
							<p class="kikz-hint" style="margin-bottom:0;">Bei Hintergrundbildern (zum Beispiel im Hero-Bereich) greift das automatische Badge nicht. Dort den Shortcode <span class="kikz-code">[ki-badge]</span> einsetzen oder dem Container unter <em>Erweitert &rarr; CSS-Klassen</em> die Klasse <span class="kikz-code">ki-badge-anzeigen</span> geben.</p>
						</div>

						<div class="kikz-card">
							<h2>Stapel-Erkennung nach Dateinamen</h2>
							<p class="kikz-hint">Findet Bilder, deren Dateiname auf eine KI-Quelle hindeutet, zum Beispiel "ChatGPT-Image". Gefundene Bilder lassen sich mit einem Klick markieren und werden dabei auch in der Datei gestempelt.</p>
							<div class="kikz-field">
								<label for="kikz_patterns">Suchbegriffe (durch Komma getrennt)</label>
								<input class="kikz-input" name="kikz_patterns" id="kikz_patterns" type="text" value="<?php echo esc_attr( self::get( 'patterns' ) ); ?>">
							</div>
							<div class="kikz-actions" style="margin:0;">
								<button type="button" class="button" id="kikz-scan">Nach Dateinamen suchen</button>
								<button type="button" class="button button-primary" id="kikz-mark-matches" style="display:none;">Alle gefundenen markieren</button>
							</div>
							<div id="kikz-scan-result" class="kikz-hint" style="margin-top:12px;"></div>
						</div>
					</div>

					<div>
						<div class="kikz-card">
							<h2>Live-Vorschau</h2>
							<p class="kikz-hint">Wähle ein Bild aus der Mediathek, um die Kennzeichnung darauf live zu sehen.</p>
							<div class="kikz-actions" style="margin:0 0 14px;">
								<button type="button" class="button" id="kikz-pick-image">Bild auswählen</button>
								<button type="button" class="button" id="kikz-clear-image"<?php echo $preview_id > 0 ? '' : ' style="display:none;"'; ?>>Entfernen</button>
								<input type="hidden" name="kikz_preview_image" id="kikz_preview_image" value="<?php echo esc_attr( (string) $preview_id ); ?>">
							</div>
							<div class="kikz-preview<?php echo '1' === self::get( 'footnote_on' ) ? ' show-notice' : ''; ?><?php echo $preview_id > 0 ? ' has-image' : ''; ?>" id="kikz-preview">
								<?php if ( $preview_id > 0 ) : ?>
									<img id="kikz-preview-img" src="<?php echo esc_url( (string) wp_get_attachment_image_url( $preview_id, 'large' ) ); ?>" alt="">
								<?php else : ?>
									<span id="kikz-preview-placeholder">Bildbereich</span>
									<img id="kikz-preview-img" src="" alt="" style="display:none;">
								<?php endif; ?>
								<span class="kikz-badge-demo" id="kikz-badge-demo"><?php echo esc_html( self::badge_label() ); ?></span>
								<div class="kikz-notice-demo" id="kikz-notice-demo"><?php echo esc_html( self::get( 'footnote' ) ); ?></div>
							</div>
							<?php
							$example_ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_ki_file_labeled', 'meta_value' => '1' ) );
							$example_id  = ! empty( $example_ids ) ? (int) $example_ids[0] : 0;
							?>
							<p class="kikz-hint" style="margin:14px 0 0;">Die Vorschau zeigt Stil, Größe und Ecke. Im fertigen Bild skaliert das Badge mit der Bildgröße.<?php if ( $example_id > 0 ) : ?> <a href="<?php echo esc_url( (string) wp_get_attachment_url( $example_id ) ); ?>" target="_blank" rel="noopener">Beispielbild mit eingerechnetem Badge öffnen</a><?php endif; ?></p>
						</div>

						<div class="kikz-card">
							<h2>Status</h2>
							<div class="kikz-stat">
								<div>
									<strong id="kikz-count"><?php echo (int) $count; ?></strong>
									<span>markierte Bilder</span>
								</div>
								<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&kikz_filter=marked' ) ); ?>">In der Mediathek anzeigen</a>
							</div>
							<p class="kikz-hint" style="margin:14px 0 0;">Markieren kannst du Bilder in der Mediathek: in der Listenansicht per Sammelaktion, direkt per Klick auf den KI-Chip in der Spalte, oder einzeln über das H&auml;kchen im Bild.</p>
						</div>
					</div>
				</div>

				<div class="kikz-actions">
					<?php submit_button( 'Änderungen speichern', 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}
}

if ( file_exists( __DIR__ . '/includes/elementor-integration.php' ) ) {
	require_once __DIR__ . '/includes/elementor-integration.php';
}
if ( file_exists( __DIR__ . '/includes/metadata-scan.php' ) ) {
	require_once __DIR__ . '/includes/metadata-scan.php';
}
if ( file_exists( __DIR__ . '/includes/woocommerce-integration.php' ) ) {
	require_once __DIR__ . '/includes/woocommerce-integration.php';
}

KI_Kennzeichnung::init();
