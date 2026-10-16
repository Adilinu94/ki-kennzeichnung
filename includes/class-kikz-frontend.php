<?php
/**
 * Front end: marks flagged images, prints the badge CSS and the small script
 * that refines the overlay (anchor offsets, crop and legibility guard).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Frontend {

	/** @var array<int,bool> Flagged attachments rendered on this page. */
	private static $used = array();

	public static function init(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_attributes' ), 10, 3 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 10, 3 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_assets' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'print_site_notice' ), 99 );
		add_shortcode( 'ki-badge', array( __CLASS__, 'shortcode_badge' ) );
		add_shortcode( 'ki-hinweis', array( __CLASS__, 'shortcode_notice' ) );
	}

	// ---------------------------------------------------------------------
	// Image decoration.
	// ---------------------------------------------------------------------

	public static function mark_used( int $id ): void {
		self::$used[ $id ] = true;
	}

	/**
	 * Whether the file was stamped so recently that visitors may still hold the
	 * unlabeled version in their browser cache.
	 */
	public static function is_fresh( int $id ): bool {
		$t = (int) get_post_meta( $id, '_ki_stamp_time', true );
		return $t > 0 && ( time() - $t ) < KI_Kennzeichnung_Settings::grace_seconds();
	}

	/**
	 * CSS classes that switch the overlay on for an image ('' = file badge is enough).
	 */
	public static function overlay_classes( int $id ): string {
		$mode = KI_Kennzeichnung_Settings::mode();
		if ( 'overlay' === $mode || ! KI_Kennzeichnung_Flags::is_labeled( $id ) ) {
			return 'ki-flagged-image';
		}
		$fresh = self::is_fresh( $id );
		if ( 'file' === $mode ) {
			return $fresh ? 'ki-flagged-image' : '';
		}
		return $fresh ? 'ki-flagged-image' : 'ki-flagged-image ki-auto';
	}

	/**
	 * Append the alt-text suffix to a non-empty alt text (once).
	 */
	public static function alt_with_suffix( string $alt ): string {
		$suffix = trim( KI_Kennzeichnung_Settings::get( 'alt_suffix' ) );
		$alt    = trim( $alt );
		if ( '' === $suffix || '' === $alt || false !== stripos( $alt, $suffix ) ) {
			return $alt;
		}
		return $alt . ' (' . $suffix . ')';
	}

	/**
	 * wp_get_attachment_image() path (post thumbnails, blocks, Elementor, Woo...).
	 */
	public static function filter_attributes( $attr, $attachment, $size ) {
		if ( ! is_array( $attr ) || ! $attachment instanceof WP_Post ) {
			return $attr;
		}
		$id = (int) $attachment->ID;
		if ( ! KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
			return $attr;
		}
		$classes = self::overlay_classes( $id );
		if ( '' !== $classes ) {
			$attr['class']        = trim( (string) ( $attr['class'] ?? '' ) . ' ' . $classes );
			$attr['data-kikz-id'] = (string) $id;
			self::mark_used( $id ); // The script is only needed where an overlay may be shown.
		}
		if ( isset( $attr['alt'] ) ) {
			$attr['alt'] = self::alt_with_suffix( (string) $attr['alt'] );
		}
		return $attr;
	}

	/**
	 * Images inside post content (wp-image-N class) or without an ID (URL match).
	 */
	public static function filter_content_img( $html, $context = '', $attachment_id = 0 ) {
		if ( ! is_string( $html ) || ! KI_Kennzeichnung_Flags::has_flagged() ) {
			return $html;
		}
		$id = (int) $attachment_id;
		if ( $id <= 0 || ! KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
			$id = 0;
			$p  = new WP_HTML_Tag_Processor( $html );
			if ( $p->next_tag( 'img' ) ) {
				$id = KI_Kennzeichnung_Flags::id_from_url( (string) $p->get_attribute( 'src' ) );
			}
		}
		return $id > 0 ? self::decorate_img_html( $html, $id ) : $html;
	}

	/**
	 * Decorate the first <img> of a markup string for a flagged attachment.
	 */
	public static function decorate_img_html( string $html, int $id ): string {
		$p = new WP_HTML_Tag_Processor( $html );
		if ( ! $p->next_tag( 'img' ) || $p->has_class( 'ki-flagged-image' ) || null !== $p->get_attribute( 'data-kikz-id' ) ) {
			return $html;
		}
		$classes = array_filter( explode( ' ', self::overlay_classes( $id ) ) );
		foreach ( $classes as $class ) {
			$p->add_class( $class );
		}
		if ( $classes ) {
			$p->set_attribute( 'data-kikz-id', (string) $id );
			self::mark_used( $id );
		}
		$alt = $p->get_attribute( 'alt' );
		if ( is_string( $alt ) ) {
			$p->set_attribute( 'alt', self::alt_with_suffix( $alt ) );
		}
		return $p->get_updated_html();
	}

	// ---------------------------------------------------------------------
	// CSS.
	// ---------------------------------------------------------------------

	/**
	 * CSS declarations that place the badge relative to the visible image box
	 * (the --kikz-* insets are set by the script when the image is smaller than
	 * its container).
	 */
	private static function position_css( string $pos, string $m ): string {
		if ( false !== strpos( $pos, 'right' ) ) {
			$hx = 'right';
		} elseif ( in_array( $pos, array( 'top-center', 'center', 'bottom-center' ), true ) ) {
			$hx = 'center';
		} else {
			$hx = 'left';
		}
		if ( 0 === strpos( $pos, 'bottom' ) ) {
			$vy = 'bottom';
		} elseif ( in_array( $pos, array( 'center', 'middle-left', 'middle-right' ), true ) ) {
			$vy = 'middle';
		} else {
			$vy = 'top';
		}

		$css = '';
		$tx  = '0';
		$ty  = '0';
		if ( 'right' === $hx ) {
			$css .= 'right:calc(var(--kikz-r) + ' . $m . ');left:auto;';
		} elseif ( 'center' === $hx ) {
			$css .= 'left:calc(var(--kikz-l) + (100% - var(--kikz-l) - var(--kikz-r)) / 2);right:auto;';
			$tx   = '-50%';
		} else {
			$css .= 'left:calc(var(--kikz-l) + ' . $m . ');right:auto;';
		}
		if ( 'bottom' === $vy ) {
			$css .= 'bottom:calc(var(--kikz-b) + ' . $m . ');top:auto;';
		} elseif ( 'middle' === $vy ) {
			$css .= 'top:calc(var(--kikz-t) + (100% - var(--kikz-t) - var(--kikz-b)) / 2);bottom:auto;';
			$ty   = '-50%';
		} else {
			$css .= 'top:calc(var(--kikz-t) + ' . $m . ');bottom:auto;';
		}
		if ( '0' !== $tx || '0' !== $ty ) {
			$css .= 'transform:translate(' . $tx . ',' . $ty . ');';
		}
		return $css;
	}

	public static function print_styles(): void {
		$col    = KI_Kennzeichnung_Settings::colors();
		$px     = KI_Kennzeichnung_Settings::overlay_font_px();
		$m      = KI_Kennzeichnung_Settings::margin() . 'px';
		$label  = KI_Kennzeichnung_Settings::css_string( KI_Kennzeichnung_Settings::label() );
		$font   = KI_Kennzeichnung_Settings::sanitize_font_family( KI_Kennzeichnung_Settings::get( 'badge_font' ) );
		$pos    = KI_Kennzeichnung_Settings::position();
		$notnest = ':not(.elementor-widget *)';

		$anchors = array(
			'.ki-flagged-bg',
			'.ki-anchor',
			'.ki-badge-anzeigen',
			'.elementor-widget.ki-flagged-widget',
			'.elementor-widget-image:has(img.ki-flagged-image)',
			'.elementor-image-box-img:has(img.ki-flagged-image)',
			'.e-atomic-element:has(> img.ki-flagged-image)', // Elementor 4: e-image sits directly in its e-div-block/e-flexbox.
			':is(figure,.wp-block-cover,.gallery-icon)' . $notnest . ':has(> img.ki-flagged-image,> a > img.ki-flagged-image)',
		);
		$relative = array(
			':where(.ki-flagged-bg.e-atomic-element)', // Zero specificity: never overrides a position the user set.
			'.ki-anchor',
			'.ki-badge-anzeigen',
			'.elementor-widget-image:has(img.ki-flagged-image)',
			'.elementor-image-box-img:has(img.ki-flagged-image)',
			':where(.e-atomic-element):has(> img.ki-flagged-image)',
			':is(figure,.wp-block-cover,.gallery-icon)' . $notnest . ':has(> img.ki-flagged-image,> a > img.ki-flagged-image)',
		);
		// A selector list with one unknown entry is dropped as a whole. Browsers without :has() would
		// lose the .ki-anchor / .ki-flagged-bg rules too, so wrap every :has() entry in the forgiving :is().
		$forgiving = static function ( string $sel ): string {
			return false !== strpos( $sel, ':has(' ) ? ':is(' . $sel . ')' : $sel;
		};
		$anchors  = array_map( $forgiving, $anchors );
		$relative = array_map( $forgiving, $relative );
		$after    = implode( '::after,', $anchors ) . '::after';

		$css  = ':root{--kikz-bg:' . $col['bg'] . ';--kikz-fg:' . $col['fg'] . ';--kikz-fs:' . $px . 'px;--kikz-label:"' . $label . '";--kikz-font:' . ( '' !== $font ? $font : 'inherit' ) . ';--kikz-l:0px;--kikz-t:0px;--kikz-r:0px;--kikz-b:0px}';
		$css .= implode( ',', $relative ) . '{position:relative}';
		$css .= $after . '{content:var(--kikz-label);position:absolute;z-index:6;display:inline-block;box-sizing:border-box;padding:.2em .8em;border-radius:999px;background:var(--kikz-bg);color:var(--kikz-fg);font-family:var(--kikz-font);font-size:var(--kikz-fs);font-weight:600;line-height:1.5;letter-spacing:.02em;white-space:nowrap;pointer-events:none;' . self::position_css( $pos, $m ) . '}';
		$css .= '[data-kikz-ok="1"]::after{content:none!important;display:none!important}';
		$css .= '.ki-badge{position:absolute;bottom:10px;right:10px;z-index:5;display:inline-block;padding:.2em .8em;border-radius:999px;background:var(--kikz-bg);color:var(--kikz-fg);font-size:var(--kikz-fs);line-height:1.5;letter-spacing:.02em}';
		$css .= '.ki-hinweis{text-align:center;font-size:12px;opacity:.75;padding:14px 16px}.ki-hinweis p{margin:0}';

		echo '<style id="ki-kennzeichnung-css">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- every value is validated or escaped above.
	}

	// ---------------------------------------------------------------------
	// Script.
	// ---------------------------------------------------------------------

	public static function register_script(): void {
		wp_register_script( 'kikz-frontend', KIKZ_URL . 'assets/js/frontend.js', array(), KIKZ_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * Enqueue the script when flagged images were rendered, with the rect data.
	 */
	public static function footer_assets(): void {
		if ( empty( self::$used ) ) {
			return;
		}
		$data = array( 'rects' => (object) array(), 'names' => (object) array(), 'minBadge' => 15 );
		$rects = array();
		$names = array();
		foreach ( array_keys( self::$used ) as $id ) {
			$r = get_post_meta( (int) $id, '_ki_badge_rects', true );
			if ( is_array( $r ) ) {
				$rects = array_merge( $rects, $r );
			}
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
				$names[ wp_basename( $meta['file'] ) ] = (int) $id;
				foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$names[ $s['file'] ] = (int) $id;
					}
				}
				if ( ! empty( $meta['original_image'] ) ) {
					$names[ $meta['original_image'] ] = (int) $id;
				}
			}
			if ( count( $names ) > 800 ) {
				break;
			}
		}
		$data['rects'] = (object) $rects;
		$data['names'] = (object) $names;
		if ( ! wp_script_is( 'kikz-frontend', 'registered' ) ) {
			self::register_script(); // A theme or optimiser may have reset the registry.
		}
		wp_enqueue_script( 'kikz-frontend' );
		wp_add_inline_script( 'kikz-frontend', 'window.kikzData=' . wp_json_encode( $data ) . ';', 'before' );
	}

	// ---------------------------------------------------------------------
	// Notice and shortcodes.
	// ---------------------------------------------------------------------

	public static function print_site_notice(): void {
		if ( is_admin() || '1' !== KI_Kennzeichnung_Settings::get( 'footnote_on' ) ) {
			return;
		}
		$text = trim( KI_Kennzeichnung_Settings::get( 'footnote' ) );
		if ( '' === $text ) {
			return;
		}
		echo '<div class="ki-hinweis ki-footer-hinweis"><p>' . esc_html( $text ) . '</p></div>';
	}

	public static function shortcode_badge(): string {
		return '<span class="ki-badge">' . esc_html( KI_Kennzeichnung_Settings::label() ) . '</span>';
	}

	public static function shortcode_notice(): string {
		return '<div class="ki-hinweis"><p>' . esc_html( KI_Kennzeichnung_Settings::get( 'footnote' ) ) . '</p></div>';
	}
}
