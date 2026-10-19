<?php
/**
 * Elementor integration.
 *
 * Detection is ID-based: the attachment ID is read from the element settings
 * (including tablet/mobile variants, hover, overlay, slideshow and dynamic tags
 * such as "Featured Image"), not guessed from URLs.
 *
 * Hook names were verified against Elementor 3.35:
 * - elementor/frontend/before_render           (all elements, before the opening tag is printed)
 * - elementor/widget/render_content            (filter on the rendered widget HTML)
 * - elementor/element/{stack}/{section}/before_section_end (panel control inside the existing section)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Elementor {

	/** Widgets whose main image is not always rendered through wp_get_attachment_image(). */
	private const IMAGE_WIDGETS = array( 'image', 'image-box', 'call-to-action', 'testimonial', 'flip-box' );

	/** Elementor 4 atomic containers (backgrounds are stored in their "styles"). */
	private const ATOMIC_CONTAINERS = array( 'e-div-block', 'e-flexbox', 'e-grid' );

	/** @var array<string,int> widget ID => flagged attachment ID */
	private static $widget_ids = array();

	public static function init(): void {
		add_action( 'elementor/frontend/before_render', array( __CLASS__, 'before_render' ) );
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'filter_widget_content' ), 10, 2 );

		// The option sits right where the image is chosen (not in a far-away Advanced section).
		$places = array(
			'container' => 'section_background', // Style > Background.
			'section'   => 'section_background',
			'column'    => 'section_style',
			'image'     => 'section_image',      // Content > Image.
			'image-box' => 'section_image',
		);
		foreach ( $places as $stack => $section ) {
			add_action( "elementor/element/$stack/$section/before_section_end", array( __CLASS__, 'add_controls' ) );
		}
	}

	// ---------------------------------------------------------------------
	// Panel controls.
	// ---------------------------------------------------------------------

	/**
	 * Adds the option at the end of an existing section ("before_section_end").
	 */
	public static function add_controls( $element ): void {
		if ( ! is_object( $element ) || ! class_exists( '\\Elementor\\Controls_Manager' ) ) {
			return;
		}
		$element->add_control(
			'kikz_heading',
			array(
				'label'     => esc_html__( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
				'type'      => \Elementor\Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$element->add_control(
			'kikz_mode',
			array(
				'label'       => esc_html__( 'Als KI-Bild kennzeichnen', 'ki-kennzeichnung' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'auto',
				'options'     => array(
					'auto'   => esc_html__( 'Automatisch (Bild in der Mediathek als KI markiert)', 'ki-kennzeichnung' ),
					'always' => esc_html__( 'Ja, immer kennzeichnen', 'ki-kennzeichnung' ),
					'never'  => esc_html__( 'Nein, nie kennzeichnen', 'ki-kennzeichnung' ),
				),
				'description' => esc_html__( 'Automatisch: Ist das Bild in der Mediathek als KI-generiert markiert, zeigt dieses Element das Badge. „Ja“ erzwingt das Badge auch ohne Markierung (z. B. bei Farbverlauf oder externen Bildern).', 'ki-kennzeichnung' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Detection helpers.
	// ---------------------------------------------------------------------

	/**
	 * Resolve a media-control value ({id,url}) to a flagged attachment ID, or 0.
	 */
	private static function flagged_id( $media ): int {
		if ( ! is_array( $media ) ) {
			return 0;
		}
		$id = isset( $media['id'] ) ? (int) $media['id'] : 0;
		if ( $id <= 0 && ! empty( $media['url'] ) ) {
			$id = KI_Kennzeichnung_Flags::id_from_url( (string) $media['url'] );
		}
		return ( $id > 0 && KI_Kennzeichnung_Flags::is_flagged( $id ) ) ? $id : 0;
	}

	/**
	 * Flagged attachment IDs used as background by a container/section/column.
	 *
	 * @return int[]
	 */
	public static function background_ids( array $settings ): array {
		$ids = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'background' ) || ! is_array( $value ) ) {
				continue;
			}
			if ( isset( $value['id'] ) || isset( $value['url'] ) ) {
				$ids[] = self::flagged_id( $value );
			} else {
				foreach ( $value as $item ) { // Slideshow galleries are lists of media items.
					$ids[] = self::flagged_id( $item );
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Flagged main image of an image-type widget.
	 */
	private static function widget_image_id( array $settings ): int {
		foreach ( array( 'image', 'bg_image', 'graphic_image', 'testimonial_image', 'image_front', 'image_back' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$id = self::flagged_id( $settings[ $key ] );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Decide the overlay classes for a set of attachment IDs.
	 * '' means: the badge in the file is enough for all of them.
	 *
	 * @param int[] $ids IDs.
	 */
	private static function classes_for( array $ids ): string {
		$classes = array();
		foreach ( $ids as $id ) {
			$classes[] = KI_Kennzeichnung_Frontend::overlay_classes( $id );
		}
		if ( in_array( '', $classes, true ) && count( array_unique( $classes ) ) === 1 ) {
			return '';
		}
		// Only let the script hide the overlay when it may hide it for every image.
		foreach ( $classes as $c ) {
			if ( false === strpos( $c, 'ki-auto' ) ) {
				return 'ki-flagged-bg';
			}
		}
		return 'ki-flagged-bg ki-auto';
	}

	// ---------------------------------------------------------------------
	// Render hooks.
	// ---------------------------------------------------------------------

	/**
	 * Flagged attachment IDs inside the data of an Elementor 4 atomic element.
	 * Backgrounds live in the element's "styles" as typed props, e.g.
	 * {"$$type":"image-attachment-id","value":123} or {"$$type":"url","value":"https://…/uploads/…"}.
	 *
	 * @param mixed $node  Data (array) to walk.
	 * @return int[]
	 */
	public static function atomic_ids( $node, int $depth = 0 ): array {
		if ( $depth > 16 || ! is_array( $node ) ) {
			return array();
		}
		$type = isset( $node['$$type'] ) ? (string) $node['$$type'] : '';
		if ( 'image-attachment-id' === $type && isset( $node['value'] ) ) {
			$id = (int) $node['value'];
			return ( $id > 0 && KI_Kennzeichnung_Flags::is_flagged( $id ) ) ? array( $id ) : array();
		}
		if ( 'url' === $type && isset( $node['value'] ) && is_string( $node['value'] ) ) {
			$id = KI_Kennzeichnung_Flags::id_from_url( $node['value'] );
			return $id > 0 ? array( $id ) : array();
		}
		$ids = array();
		foreach ( $node as $child ) {
			if ( is_array( $child ) ) {
				$ids = array_merge( $ids, self::atomic_ids( $child, $depth + 1 ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function manual_mode( $element ): string {
		$raw = (array) $element->get_settings();
		if ( 'yes' === ( $raw['ki_flagged_bg'] ?? '' ) || 'yes' === ( $raw['_ki_container_flagged'] ?? '' ) ) {
			return 'always'; // Switches from the 1.x plan documents.
		}
		$mode = (string) ( $raw['kikz_mode'] ?? 'auto' );
		return in_array( $mode, array( 'always', 'never' ), true ) ? $mode : 'auto';
	}

	/**
	 * Runs before Elementor prints the element's opening tag, so render
	 * attributes added here end up on the wrapper.
	 */
	public static function before_render( $element ): void {
		if ( ! is_object( $element ) || ! method_exists( $element, 'get_type' ) ) {
			return;
		}
		$mode = self::manual_mode( $element );
		if ( 'never' === $mode || ( 'auto' === $mode && ! KI_Kennzeichnung_Flags::has_flagged() ) ) {
			return;
		}
		$type = $element->get_type();

		if ( in_array( $type, array( 'container', 'section', 'column' ), true ) ) {
			$ids     = 'auto' === $mode || 'always' === $mode ? self::background_ids( (array) $element->get_settings_for_display() ) : array();
			$classes = $ids ? self::classes_for( $ids ) : '';
			if ( 'always' === $mode ) {
				$classes = 'ki-flagged-bg';
			}
			if ( '' === $classes ) {
				return; // The badge in the file is enough: no overlay, no script.
			}
			$element->add_render_attribute( '_wrapper', 'class', explode( ' ', $classes ) );
			if ( $ids ) {
				$element->add_render_attribute( '_wrapper', 'data-kikz-id', (string) $ids[0] );
				$element->add_render_attribute( '_wrapper', 'data-kikz-rects', KI_Kennzeichnung_Frontend::rects_json( $ids ) );
				foreach ( $ids as $id ) {
					KI_Kennzeichnung_Frontend::mark_used( $id );
				}
			}
			return;
		}

		if ( in_array( $element->get_name(), self::ATOMIC_CONTAINERS, true ) ) {
			$data    = method_exists( $element, 'get_data' ) ? (array) $element->get_data() : array();
			$ids     = self::atomic_ids( $data['styles'] ?? array() );
			$classes = $ids ? self::classes_for( $ids ) : '';
			if ( '' === $classes ) {
				return;
			}
			$element->add_render_attribute( '_wrapper', 'class', explode( ' ', $classes ) );
			$element->add_render_attribute( '_wrapper', 'data-kikz-id', (string) $ids[0] );
			$element->add_render_attribute( '_wrapper', 'data-kikz-rects', KI_Kennzeichnung_Frontend::rects_json( $ids ) );
			foreach ( $ids as $id ) {
				KI_Kennzeichnung_Frontend::mark_used( $id );
			}
			return;
		}

		if ( 'widget' === $type && in_array( $element->get_name(), self::IMAGE_WIDGETS, true ) ) {
			$id = self::widget_image_id( (array) $element->get_settings_for_display() );
			if ( $id > 0 ) {
				self::$widget_ids[ (string) $element->get_id() ] = $id;
				if ( '' !== KI_Kennzeichnung_Frontend::overlay_classes( $id ) || 'always' === $mode ) {
					$element->add_render_attribute( '_wrapper', 'class', 'ki-flagged-widget' );
					$element->add_render_attribute( '_wrapper', 'data-kikz-id', (string) $id );
					$element->add_render_attribute( '_wrapper', 'data-kikz-rects', KI_Kennzeichnung_Frontend::rects_json( array( $id ) ) );
					KI_Kennzeichnung_Frontend::mark_used( $id );
				}
			} elseif ( 'always' === $mode ) {
				$element->add_render_attribute( '_wrapper', 'class', 'ki-flagged-widget' );
			}
		}
	}

	/**
	 * Post-process the HTML of every widget: images with wp-image-N or a known
	 * upload URL, inline style backgrounds, and the widget's main image when
	 * Elementor rendered it from a custom size (no ID in the markup).
	 */
	public static function filter_widget_content( $content, $widget ) {
		if ( ! is_string( $content ) || '' === $content || ! KI_Kennzeichnung_Flags::has_flagged() ) {
			return $content;
		}
		if ( false === stripos( $content, '<img' ) && false === stripos( $content, 'background' ) ) {
			return $content;
		}
		if ( is_object( $widget ) && method_exists( $widget, 'get_settings' ) && 'never' === self::manual_mode( $widget ) ) {
			return $content;
		}
		$fallback = 0;
		if ( is_object( $widget ) && method_exists( $widget, 'get_id' ) ) {
			$fallback = (int) ( self::$widget_ids[ (string) $widget->get_id() ] ?? 0 );
		}

		$p       = new WP_HTML_Tag_Processor( $content );
		$changed = false;
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'IMG' === $tag ) {
				if ( $p->has_class( 'ki-flagged-image' ) || null !== $p->get_attribute( 'data-kikz-id' ) ) {
					continue;
				}
				$id = 0;
				if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', (string) $p->get_attribute( 'class' ), $m ) ) {
					$id = (int) $m[1];
				}
				if ( $id <= 0 || ! KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
					$id = 0;
					foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
						$id = KI_Kennzeichnung_Flags::id_from_url( (string) $p->get_attribute( $attr ) );
						if ( $id > 0 ) {
							break;
						}
					}
				}
				if ( $id <= 0 && $fallback > 0 ) {
					$id       = $fallback;
					$fallback = 0; // Only the first undecorated image.
				}
				if ( $id > 0 ) {
					$classes = array_filter( explode( ' ', KI_Kennzeichnung_Frontend::overlay_classes( $id ) ) );
					foreach ( $classes as $class ) {
						$p->add_class( $class );
					}
					if ( $classes ) {
						$p->set_attribute( 'data-kikz-id', (string) $id );
						$p->set_attribute( 'data-kikz-rects', KI_Kennzeichnung_Frontend::rects_json( array( $id ) ) );
						KI_Kennzeichnung_Frontend::mark_used( $id );
					}
					$alt = $p->get_attribute( 'alt' );
					if ( is_string( $alt ) ) {
						$p->set_attribute( 'alt', KI_Kennzeichnung_Frontend::alt_with_suffix( $alt ) );
					}
					$changed = true;
				}
			} else {
				$style = $p->get_attribute( 'style' );
				if ( is_string( $style ) && false !== stripos( $style, 'background' ) && preg_match( '/url\(\s*[\'"]?([^\'")]+)/i', $style, $m ) ) {
					$id = KI_Kennzeichnung_Flags::id_from_url( html_entity_decode( $m[1] ) );
					if ( $id > 0 && ! $p->has_class( 'ki-flagged-bg' ) ) {
						$classes = KI_Kennzeichnung_Frontend::overlay_classes( $id );
						if ( '' !== $classes ) {
							$p->add_class( 'ki-flagged-bg' );
							if ( false !== strpos( $classes, 'ki-auto' ) ) {
								$p->add_class( 'ki-auto' );
							}
							$p->set_attribute( 'data-kikz-id', (string) $id );
							$p->set_attribute( 'data-kikz-rects', KI_Kennzeichnung_Frontend::rects_json( array( $id ) ) );
							KI_Kennzeichnung_Frontend::mark_used( $id );
							$changed = true;
						}
					}
				}
			}
		}
		return $changed ? $p->get_updated_html() : $content;
	}
}
