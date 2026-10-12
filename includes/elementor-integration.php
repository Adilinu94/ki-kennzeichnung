<?php
/**
 * Elementor integration for KI-Kennzeichnung.
 *
 * Registers a "KI-generiert" toggle on the Elementor Container, Flexbox
 * widget and the Image widget, and applies the matching CSS classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Elementor {

	/**
	 * Register all hooks. Called only when Elementor is active.
	 */
	public static function init(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		// Control registration (containers + flexbox + image widget).
		add_action( 'elementor/element/container/section_layout/after_section_end', [ __CLASS__, 'add_container_control' ], 10, 2 );
		add_action( 'elementor/element/flexbox/section_layout/after_section_end', [ __CLASS__, 'add_flexbox_control' ], 10, 2 );
		add_action( 'elementor/element/image/section_image/after_section_end', [ __CLASS__, 'add_image_control' ], 10, 2 );

		// Render-time class injection.
		add_action( 'elementor/container/before_render', [ __CLASS__, 'render_container' ] );
		add_action( 'elementor/widget/before_render', [ __CLASS__, 'render_widget' ] );
	}

	/**
	 * Add the toggle to Elementor containers (>= 3.10).
	 */
	public static function add_container_control( $element, $args ): void {
		self::add_flagged_toggle( $element, '_ki_container_flagged', __( 'Als KI-generiert kennzeichnen', 'ki-kennzeichnung' ) );
	}

	/**
	 * Add the toggle to Elementor Flexbox widgets (>= 3.18).
	 */
	public static function add_flexbox_control( $element, $args ): void {
		self::add_flagged_toggle( $element, '_ki_flexbox_flagged', __( 'Als KI-generiert kennzeichnen', 'ki-kennzeichnung' ) );
	}

	/**
	 * Shared toggle helper.
	 */
	private static function add_flagged_toggle( $element, string $key, string $label ): void {
		$element->add_control(
			$key,
			[
				'label'        => $label,
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Ja', 'ki-kennzeichnung' ),
				'label_off'   => __( 'Nein', 'ki-kennzeichnung' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Hängt ein Badge an das Hintergrundbild/Container-Bild.', 'ki-kennzeichnung' ),
			]
		);
	}

	/**
	 * Add an attachment-picker to the image widget.
	 */
	public static function add_image_control( $element, $args ): void {
		$element->add_control(
			'ki_image_override',
			[
				'label'       => __( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
				'type'        => \Elementor\Controls_Manager::SWITCHER,
				'label_on'    => __( 'aktiv', 'ki-kennzeichnung' ),
				'label_off'   => __( 'inaktiv', 'ki-kennzeichnung' ),
				'return_value' => 'yes',
				'default'     => '',
			]
		);
	}

	/**
	 * Inject ki-flagged-bg class on container render.
	 */
	public static function render_container( $element ): void {
		$settings = $element->get_settings_for_display();
		if ( isset( $settings['_ki_container_flagged'] ) && 'yes' === $settings['_ki_container_flagged'] ) {
			$element->add_render_attribute( 'wrapper', 'class', 'ki-flagged-bg' );
		}
	}

	/**
	 * Inject ki-flagged-image class on image-widget render.
	 */
	public static function render_widget( $element ): void {
		$widget_type = $element->get_name();
		if ( 'image' !== $widget_type ) {
			return;
		}
		$settings = $element->get_settings_for_display();
		if ( isset( $settings['ki_image_override'] ) && 'yes' === $settings['ki_image_override'] ) {
			$element->add_render_attribute( 'wrapper', 'class', 'ki-flagged-image-wrapper' );
			$element->add_render_attribute( 'image', 'class', 'ki-flagged-image' );
		}

		// Attachment-based auto-flagging.
		if ( isset( $settings['image'] ) && is_array( $settings['image'] ) && ! empty( $settings['image']['id'] ) ) {
			$attachment_id = (int) $settings['image']['id'];
			if ( $attachment_id > 0 && class_exists( 'KI_Kennzeichnung' ) ) {
				if ( KI_Kennzeichnung::is_flagged_public( $attachment_id ) ) {
					$element->add_render_attribute( 'wrapper', 'class', 'ki-flagged-image-wrapper' );
					$element->add_render_attribute( 'image', 'class', 'ki-flagged-image' );
				}
			}
		}
	}
}

add_action( 'init', function () {
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		KI_Kennzeichnung_Elementor::init();
	}
}, 11 );
