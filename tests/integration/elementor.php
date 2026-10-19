<?php
// Included by run.php. Requires Elementor to be active.

use KI_Kennzeichnung_Flags as Flags;
use KI_Kennzeichnung_Frontend as Frontend;

unset( $GLOBALS['current_screen'] ); // Elementor renders nothing in an admin screen context.
section( 'Elementor: rendering with the real Elementor renderer' );

if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
	t( 'Elementor is active', false, 'skipping the Elementor suite' );
	return;
}
t( 'Elementor is active', true );
if ( ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' ) ) {
	// Elementor activates the Container feature only after its very first load (install history).
	t( 'Elementor Container feature is active (run bin/setup-test-env.sh, it warms Elementor up)', false );
	return;
}

/** Find the wrapper of an Elementor element in rendered HTML. */
function kikz_find_el( string $html, string $data_id ): ?array {
	$p = new WP_HTML_Tag_Processor( $html );
	while ( $p->next_tag() ) {
		if ( $data_id === $p->get_attribute( 'data-id' ) ) {
			return array(
				'class' => (string) $p->get_attribute( 'class' ),
				'kikz'  => $p->get_attribute( 'data-kikz-id' ),
			);
		}
	}
	return null;
}

function kikz_has_class( ?array $el, string $class ): bool {
	return null !== $el && in_array( $class, preg_split( '/\s+/', $el['class'] ), true );
}

function kikz_media( int $id ): array {
	return array( 'url' => (string) wp_get_attachment_url( $id ), 'id' => $id, 'size' => '', 'alt' => '', 'source' => 'library' );
}

function kikz_render( array $elements, int $thumb = 0 ): array {
	$pid = wp_insert_post( array( 'post_title' => 'kikz-elementor-test', 'post_type' => 'page', 'post_status' => 'publish' ) );
	update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
	update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
	update_post_meta( $pid, '_elementor_version', '3.35.0' );
	update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
	if ( $thumb ) {
		set_post_thumbnail( $pid, $thumb );
	}
	$GLOBALS['kikz_cleanup'][] = $pid;
	$GLOBALS['post']           = get_post( $pid );
	setup_postdata( $GLOBALS['post'] );
	\Elementor\Plugin::$instance->files_manager->clear_cache();
	$html = \Elementor\Plugin::$instance->frontend->get_builder_content( $pid, false );
	return array( $pid, (string) $html );
}

function kikz_container( string $id, array $settings = array(), array $children = array() ): array {
	return array( 'id' => $id, 'elType' => 'container', 'settings' => $settings, 'elements' => $children, 'isInner' => false );
}

function kikz_widget( string $id, string $type, array $settings ): array {
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}

// Flagged, stamped, past the cache grace period: auto mode may hide the overlay.
update_option( 'kikz_grace_days', '0' );
update_option( 'kikz_display_mode', 'auto' );
$ai    = kikz_upload( kikz_make_image( 2000, 1200 ), 'kikz-el-ai.jpg' );
$ai2   = kikz_upload( kikz_make_image( 1800, 1100 ), 'kikz-el-ai-mobile.jpg' );
$human = kikz_upload( kikz_make_image( 1600, 1000 ), 'kikz-el-human.jpg' );
foreach ( array( $ai, $ai2 ) as $x ) {
	Flags::set( $x, true, 'manual', array(), true );
}
t( 'fixtures stamped', Flags::is_labeled( $ai ) && Flags::is_labeled( $ai2 ) && ! Flags::is_flagged( $human ) );

$tree = array(
	// A: flexbox container, flagged desktop background.
	kikz_container( 'ctrA0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $ai ) ), array(
		// F: nested container without background must NOT be labeled.
		kikz_container( 'ctrF0001', array(), array( kikz_widget( 'hd000001', 'heading', array( 'title' => 'Hallo' ) ) ) ),
	) ),
	// B: only the mobile variant is flagged.
	kikz_container( 'ctrB0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $human ), 'background_image_mobile' => kikz_media( $ai2 ) ) ),
	// C: human photo.
	kikz_container( 'ctrC0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $human ) ) ),
	// D: flagged but switched off / forced on without image.
	kikz_container( 'ctrD0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $ai ), 'kikz_mode' => 'never' ) ),
	kikz_container( 'ctrD0002', array( 'kikz_mode' => 'always' ) ),
	// E: overlay image.
	kikz_container( 'ctrE0001', array( 'background_overlay_background' => 'classic', 'background_overlay_image' => kikz_media( $ai ) ) ),
	// G: slideshow gallery (list of media items).
	kikz_container( 'ctrG0001', array( 'background_background' => 'slideshow', 'background_slideshow_gallery' => array( kikz_media( $human ), kikz_media( $ai2 ) ) ) ),
	// Legacy 1.x switch from the plan documents.
	kikz_container( 'ctrL0001', array( 'ki_flagged_bg' => 'yes' ) ),
	// H: image widget, library size.
	kikz_container( 'ctrH0001', array(), array(
		kikz_widget( 'imgH0001', 'image', array( 'image' => kikz_media( $ai ), 'image_size' => 'medium' ) ),
		// I: custom size -> raw <img> from elementor/thumbs without wp-image class.
		kikz_widget( 'imgI0001', 'image', array( 'image' => kikz_media( $ai ), 'image_size' => 'custom', 'image_custom_dimension' => array( 'width' => '300', 'height' => '200' ) ) ),
		// J: human image.
		kikz_widget( 'imgJ0001', 'image', array( 'image' => kikz_media( $human ), 'image_size' => 'medium' ) ),
		// L: image inside a text editor widget (only wp-image-N class in HTML).
		kikz_widget( 'txtL0001', 'text-editor', array( 'editor' => '<p>Text <img class="wp-image-' . $ai . '" src="' . esc_url( wp_get_attachment_image_url( $ai, 'medium' ) ) . '" alt="Bild"></p>' ) ),
		// M: inline-style background in an HTML widget.
		kikz_widget( 'htmM0001', 'html', array( 'html' => '<div class="slide" style="background-image:url(' . esc_url( wp_get_attachment_image_url( $ai, 'large' ) ) . ')">x</div>' ) ),
	) ),
);
list( $pid, $html ) = kikz_render( $tree );
t( 'Elementor produced markup', strlen( $html ) > 500, (string) strlen( $html ) );

$a = kikz_find_el( $html, 'ctrA0001' );
t( 'A: flexbox container with flagged background gets ki-flagged-bg', kikz_has_class( $a, 'ki-flagged-bg' ), wp_json_encode( $a ) );
t( 'A: auto mode, stamped, not fresh -> ki-auto (script may hide the overlay when the file badge is visible)', kikz_has_class( $a, 'ki-auto' ) );
t( 'A: data-kikz-id points at the attachment', (string) $ai === (string) ( $a['kikz'] ?? '' ) );
t( 'F: nested container without background is not labeled', ! kikz_has_class( kikz_find_el( $html, 'ctrF0001' ), 'ki-flagged-bg' ) );
t( 'B: flagged MOBILE background variant is detected', kikz_has_class( kikz_find_el( $html, 'ctrB0001' ), 'ki-flagged-bg' ) );
t( 'C: human photo is not labeled', ! kikz_has_class( kikz_find_el( $html, 'ctrC0001' ), 'ki-flagged-bg' ) );
t( 'D: "Nie kennzeichnen" wins over a flagged image', ! kikz_has_class( kikz_find_el( $html, 'ctrD0001' ), 'ki-flagged-bg' ) );
$d2 = kikz_find_el( $html, 'ctrD0002' );
t( 'D: "Immer kennzeichnen" forces the label (without ki-auto)', kikz_has_class( $d2, 'ki-flagged-bg' ) && ! kikz_has_class( $d2, 'ki-auto' ) );
t( 'E: flagged overlay image is detected', kikz_has_class( kikz_find_el( $html, 'ctrE0001' ), 'ki-flagged-bg' ) );
$g = kikz_find_el( $html, 'ctrG0001' );
t( 'G: flagged image inside a background slideshow is detected', kikz_has_class( $g, 'ki-flagged-bg' ) && (string) $ai2 === (string) ( $g['kikz'] ?? '' ), wp_json_encode( $g ) );
t( 'L: 1.x switch ki_flagged_bg=yes still works', kikz_has_class( kikz_find_el( $html, 'ctrL0001' ), 'ki-flagged-bg' ) );

$h = kikz_find_el( $html, 'imgH0001' );
t( 'H: image widget wrapper marked', kikz_has_class( $h, 'ki-flagged-widget' ) && (string) $ai === (string) ( $h['kikz'] ?? '' ), wp_json_encode( $h ) );
t( 'H: <img> of the library-size widget carries the class and alt suffix', 1 === preg_match( '/<img[^>]*ki-flagged-image[^>]*>/', $html ) );
$i = kikz_find_el( $html, 'imgI0001' );
t( 'I: custom-size widget (raw <img>, no wp-image class) is still marked via widget settings', kikz_has_class( $i, 'ki-flagged-widget' ), wp_json_encode( $i ) );
t( 'I: its <img> from elementor/thumbs got decorated too', 1 === preg_match( '/<img[^>]*elementor\/thumbs[^>]*>/', $html, $mi ) && false !== strpos( $mi[0], 'ki-flagged-image' ), $mi[0] ?? 'no thumbs img' );
t( 'J: human image widget is not marked', ! kikz_has_class( kikz_find_el( $html, 'imgJ0001' ), 'ki-flagged-widget' ) );
t( 'L: image inside a text-editor widget is decorated through the render_content filter', 1 === preg_match( '/<img[^>]*wp-image-' . $ai . '[^>]*ki-flagged-image|<img[^>]*ki-flagged-image[^>]*wp-image-' . $ai . '/', $html ) );
t( 'M: inline background-image element in an HTML widget is labeled', 1 === preg_match( '/<div[^>]*class="[^"]*ki-flagged-bg[^"]*"[^>]*style="background-image/', $html ) || 1 === preg_match( '/<div[^>]*style="background-image[^"]*"[^>]*class="[^"]*ki-flagged-bg/', $html ), '' );

section( 'Elementor: modes' );
update_option( 'kikz_display_mode', 'file' );
list( , $html_file ) = kikz_render( array( kikz_container( 'ctrA0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $ai ) ) ) ) );
t( 'file mode, labeled, not fresh: container needs no overlay', ! kikz_has_class( kikz_find_el( $html_file, 'ctrA0001' ), 'ki-flagged-bg' ) );
update_option( 'kikz_display_mode', 'overlay' );
list( , $html_ov ) = kikz_render( array( kikz_container( 'ctrA0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $ai ) ) ) ) );
$ov = kikz_find_el( $html_ov, 'ctrA0001' );
t( 'overlay mode: container always gets the overlay, never ki-auto', kikz_has_class( $ov, 'ki-flagged-bg' ) && ! kikz_has_class( $ov, 'ki-auto' ) );
update_option( 'kikz_display_mode', 'auto' );
update_option( 'kikz_grace_days', '7' );
list( , $html_fresh ) = kikz_render( array( kikz_container( 'ctrA0001', array( 'background_background' => 'classic', 'background_image' => kikz_media( $ai ) ) ) ) );
t( 'fresh stamp (browser caches!): overlay stays, script must not hide it', ! kikz_has_class( kikz_find_el( $html_fresh, 'ctrA0001' ), 'ki-auto' ) && kikz_has_class( kikz_find_el( $html_fresh, 'ctrA0001' ), 'ki-flagged-bg' ) );
update_option( 'kikz_grace_days', '0' );

section( 'Elementor: dynamic tag as background (Pro registers e.g. "Featured Image"; here an own tag)' );
class Kikz_Test_Image_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
	public static $attachment = 0;
	public function get_name() {
		return 'kikz-test-image';
	}
	public function get_group() {
		return 'kikz-test';
	}
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY );
	}
	public function get_title() {
		return 'Kikz test image';
	}
	protected function get_value( array $options = array() ) {
		return array( 'id' => self::$attachment, 'url' => (string) wp_get_attachment_url( self::$attachment ) );
	}
}
$dyn = \Elementor\Plugin::$instance->dynamic_tags;
$dyn->register_group( 'kikz-test', array( 'title' => 'Kikz' ) );
$dyn->register( new Kikz_Test_Image_Tag() );
Kikz_Test_Image_Tag::$attachment = $ai;
list( , $html_dyn ) = kikz_render(
	array( kikz_container( 'ctrDYN001', array( 'background_background' => 'classic', '__dynamic__' => array( 'background_image' => '[elementor-tag id="dyn0001" name="kikz-test-image" settings="%7B%7D"]' ) ) ) )
);
t( 'flagged image delivered by a dynamic tag is detected (get_settings_for_display resolves it)', kikz_has_class( kikz_find_el( $html_dyn, 'ctrDYN001' ), 'ki-flagged-bg' ), wp_json_encode( kikz_find_el( $html_dyn, 'ctrDYN001' ) ) );
Kikz_Test_Image_Tag::$attachment = $human;
list( , $html_dyn2 ) = kikz_render(
	array( kikz_container( 'ctrDYN002', array( 'background_background' => 'classic', '__dynamic__' => array( 'background_image' => '[elementor-tag id="dyn0002" name="kikz-test-image" settings="%7B%7D"]' ) ) ) )
);
t( 'the same tag returning a human photo is not labeled', ! kikz_has_class( kikz_find_el( $html_dyn2, 'ctrDYN002' ), 'ki-flagged-bg' ) );

section( 'Elementor 4: atomic containers and the atomic image widget' );

function kikz_atomic_bg( string $id, string $style_id, array $src, string $type = 'e-div-block', array $children = array() ): array {
	return array(
		'id'       => $id,
		'elType'   => $type,
		'settings' => array( 'classes' => array( '$$type' => 'classes', 'value' => array( $style_id ) ) ),
		'elements' => $children,
		'isInner'  => false,
		'styles'   => array(
			$style_id => array(
				'id'       => $style_id,
				'label'    => 'local',
				'type'     => 'class',
				'variants' => array(
					array(
						'meta'  => array( 'breakpoint' => 'desktop', 'state' => null ),
						'props' => array(
							'background' => array(
								'$$type' => 'background',
								'value'  => array(
									'background-overlay' => array(
										'$$type' => 'background-overlay',
										'value'  => array(
											array(
												'$$type' => 'background-image-overlay',
												'value'  => array(
													'image' => array( '$$type' => 'image', 'value' => array( 'src' => array( '$$type' => 'image-src', 'value' => $src ), 'size' => array( '$$type' => 'string', 'value' => 'large' ) ) ),
													'size'  => array( '$$type' => 'string', 'value' => 'cover' ),
												),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		),
	);
}

/** Minimal stand-in for an Elementor element: lets us test our logic without Elementor's Twig renderer. */
class Kikz_Fake_Element {
	public $attrs = array();
	private $name;
	private $data;
	public function __construct( string $name, array $data ) {
		$this->name = $name;
		$this->data = $data;
	}
	public function get_type() {
		return 'element';
	}
	public function get_name() {
		return $this->name;
	}
	public function get_id() {
		return $this->data['id'];
	}
	public function get_data() {
		return $this->data;
	}
	public function get_settings( $k = null ) {
		return $this->data['settings'] ?? array();
	}
	public function get_settings_for_display( $k = null ) {
		return $this->get_settings();
	}
	public function add_render_attribute( $el, $key, $value = null ) {
		$this->attrs[ $el ][ $key ] = array_merge( (array) ( $this->attrs[ $el ][ $key ] ?? array() ), (array) $value );
	}
}

$by_url    = array( 'url' => array( '$$type' => 'url', 'value' => (string) wp_get_attachment_url( $ai ) ) );
$by_id     = array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => $ai2 ) );
$human_url = array( 'url' => array( '$$type' => 'url', 'value' => (string) wp_get_attachment_url( $human ) ) );

$fe = new Kikz_Fake_Element( 'e-div-block', kikz_atomic_bg( 'fakeA001', 'e-aaaaaa-0001', $by_url ) );
KI_Kennzeichnung_Elementor::before_render( $fe );
$cls = $fe->attrs['_wrapper']['class'] ?? array();
t( 'atomic detection: flagged background URL in the typed styles -> ki-flagged-bg + ki-auto on the wrapper', in_array( 'ki-flagged-bg', $cls, true ) && in_array( 'ki-auto', $cls, true ), wp_json_encode( $fe->attrs ) );
t( 'atomic detection: data-kikz-id points at the attachment', array( (string) $ai ) === ( $fe->attrs['_wrapper']['data-kikz-id'] ?? null ) );
$fe = new Kikz_Fake_Element( 'e-flexbox', kikz_atomic_bg( 'fakeB001', 'e-bbbbbb-0002', $by_id, 'e-flexbox' ) );
KI_Kennzeichnung_Elementor::before_render( $fe );
t( 'atomic detection: e-flexbox with an image-attachment-id background', in_array( 'ki-flagged-bg', $fe->attrs['_wrapper']['class'] ?? array(), true ) && array( (string) $ai2 ) === ( $fe->attrs['_wrapper']['data-kikz-id'] ?? null ) );
$fe = new Kikz_Fake_Element( 'e-div-block', kikz_atomic_bg( 'fakeC001', 'e-cccccc-0003', $human_url ) );
KI_Kennzeichnung_Elementor::before_render( $fe );
t( 'atomic detection: a human photo adds nothing', array() === $fe->attrs );
$fe = new Kikz_Fake_Element( 'e-grid', array( 'id' => 'fakeD001', 'elType' => 'e-grid', 'settings' => array(), 'styles' => array() ) );
KI_Kennzeichnung_Elementor::before_render( $fe );
t( 'atomic detection: an element without styles adds nothing and does not fail', array() === $fe->attrs );
update_option( 'kikz_display_mode', 'overlay' );
$fe = new Kikz_Fake_Element( 'e-div-block', kikz_atomic_bg( 'fakeE001', 'e-eeeeee-0005', $by_url ) );
KI_Kennzeichnung_Elementor::before_render( $fe );
t( 'atomic detection in overlay mode: overlay without ki-auto', in_array( 'ki-flagged-bg', $fe->attrs['_wrapper']['class'] ?? array(), true ) && ! in_array( 'ki-auto', $fe->attrs['_wrapper']['class'] ?? array(), true ) );
update_option( 'kikz_display_mode', 'auto' );
t( 'atomic_ids finds typed ids and URLs at any depth and ignores unflagged ones', array( $ai ) === KI_Kennzeichnung_Elementor::atomic_ids( array( 'a' => array( 'b' => array( '$$type' => 'url', 'value' => wp_get_attachment_url( $ai ) ), 'c' => array( '$$type' => 'url', 'value' => wp_get_attachment_url( $human ) ) ) ) ) );
$audit_tree = kikz_atomic_bg( 'fakeA001', 'e-aaaaaa-0001', $by_url );
$pid_a      = wp_insert_post( array( 'post_title' => 'kikz-atomic-audit', 'post_type' => 'page', 'post_status' => 'publish' ) );
$GLOBALS['kikz_cleanup'][] = $pid_a;
update_post_meta( $pid_a, '_elementor_data', wp_slash( wp_json_encode( array( $audit_tree ) ) ) );
$ok = KI_Kennzeichnung_Audit::analyze_html( '<div data-id="fakeA001" class="e-con ki-flagged-bg ki-auto"></div>', $pid_a );
t( 'audit: a labeled atomic background container passes', array() === $ok['issues'], wp_json_encode( $ok ) );
$bad = KI_Kennzeichnung_Audit::analyze_html( '<div data-id="fakeA001" class="e-con"></div>', $pid_a );
t( 'audit: an atomic background container delivered without label is caught', ! empty( array_filter( $bad['issues'], static function ( $m ) {
	return false !== strpos( $m, 'fakeA001' );
} ) ), wp_json_encode( $bad ) );
$css_head = '';
ob_start();
KI_Kennzeichnung_Frontend::print_styles();
$css_head = ob_get_clean();
t( 'CSS anchors the atomic e-image to its container and keeps :has() entries forgiving (:is)', false !== strpos( $css_head, ':is(:where(.e-atomic-element):has(> img.ki-flagged-image))' ) && false !== strpos( $css_head, ':where(.ki-flagged-bg.e-atomic-element)' ), '' );

// Real rendering needs Elementor's scoped Twig (shipped in the release zip, absent from a git checkout).
if ( ! \Elementor\Plugin::$instance->elements_manager->get_element_types( 'e-div-block' ) || ! class_exists( '\\ElementorDeps\\Twig\\Environment' ) ) {
	echo "  skip real atomic rendering (needs an Elementor release build with Twig; verified on the live test site instead)\n";
} else {
	$img_widget = array( 'id' => 'atomI0001', 'elType' => 'widget', 'widgetType' => 'e-image', 'elements' => array(), 'settings' => array( 'image' => array( '$$type' => 'image', 'value' => array( 'src' => array( '$$type' => 'image-src', 'value' => array( 'id' => array( '$$type' => 'image-attachment-id', 'value' => $ai ) ) ), 'size' => array( '$$type' => 'string', 'value' => 'large' ) ) ) ) );
	list( $apid, $ahtml ) = kikz_render( array(
		kikz_atomic_bg( 'atomA0001', 'e-aaaaaa-0001', $by_url ),
		kikz_atomic_bg( 'atomD0001', 'e-dddddd-0004', $human_url, 'e-div-block', array( $img_widget ) ),
	) );
	$aa = kikz_find_el( $ahtml, 'atomA0001' );
	t( 'rendered e-div-block is labeled and keeps Elementor\'s own classes', kikz_has_class( $aa, 'ki-flagged-bg' ) && kikz_has_class( $aa, 'e-con' ) && kikz_has_class( $aa, 'e-aaaaaa-0001' ), wp_json_encode( $aa ) );
	t( 'rendered atomic e-image carries the label class', 1 === preg_match( '/<img[^>]*ki-flagged-image/', $ahtml ) );
}

section( 'Elementor: footer data, audit and panel controls' );
$g = kikz_find_el( $html, 'ctrG0001' );
preg_match( '/<[^>]*data-id="ctrG0001"[^>]*>/', $html, $wrapG );
t( 'container wrapper carries data-kikz-rects naming the stamped background file', false !== strpos( html_entity_decode( $wrapG[0] ?? '' ), wp_basename( get_attached_file( $ai2 ) ) ) && false !== strpos( $wrapG[0] ?? '', 'data-kikz-rects' ), substr( $wrapG[0] ?? '', 0, 200 ) );

// Elementor serves cached elements without running our render hooks: $used stays empty on such requests.
$ref = new ReflectionProperty( 'KI_Kennzeichnung_Frontend', 'used' );
$ref->setAccessible( true );
$ref->setValue( null, array() );
wp_dequeue_script( 'kikz-frontend' );
ob_start();
Frontend::footer_assets();
ob_end_clean();
t( 'the script is still enqueued when nothing was rendered by us but Elementor printed content', wp_script_is( 'kikz-frontend', 'enqueued' ) );

$issues = KI_Kennzeichnung_Audit::analyze_html( $html, $pid );
t( 'audit: a correctly rendered Elementor page has no issues', array() === $issues['issues'], wp_json_encode( $issues ) );
$broken = str_replace( 'ki-flagged-bg', 'x', $html );
$bad    = KI_Kennzeichnung_Audit::analyze_html( $broken, $pid );
t( 'audit: removing the label from a background container is caught', ! empty( array_filter( $bad['issues'], static function ( $m ) {
	return false !== strpos( $m, 'ctrA0001' );
} ) ), wp_json_encode( $bad['issues'] ) );
$broken2 = preg_replace( '/ki-flagged-image/', 'x', $html );
$bad2b   = KI_Kennzeichnung_Audit::analyze_html( preg_replace( '/data-kikz-id="\d+"/', '', $broken2 ), 0 );
t( 'audit: an <img> of a flagged attachment delivered without label is caught', ! empty( $bad2b['issues'] ), wp_json_encode( $bad2b ) );

update_option( 'kikz_display_mode', 'file' );
$file_ok = KI_Kennzeichnung_Audit::analyze_html( '<img src="' . esc_url( wp_get_attachment_image_url( $ai, 'medium' ) ) . '" alt="x">', 0 );
t( 'audit: in file mode a labeled file without overlay is fine', array() === $file_ok['issues'], wp_json_encode( $file_ok ) );
update_option( 'kikz_display_mode', 'overlay' );
$ov_bad = KI_Kennzeichnung_Audit::analyze_html( '<img src="' . esc_url( wp_get_attachment_image_url( $ai, 'medium' ) ) . '" alt="x">', 0 );
t( 'audit: in overlay mode the same markup is a problem', ! empty( $ov_bad['issues'] ) );
update_option( 'kikz_display_mode', 'auto' );

t( 'hooks registered on the verified Elementor names', has_action( 'elementor/frontend/before_render', array( 'KI_Kennzeichnung_Elementor', 'before_render' ) ) && has_filter( 'elementor/widget/render_content', array( 'KI_Kennzeichnung_Elementor', 'filter_widget_content' ) ) && has_action( 'elementor/element/container/section_background/before_section_end' ) && has_action( 'elementor/element/image/section_image/before_section_end' ) );
$cont = \Elementor\Plugin::$instance->elements_manager->create_element_instance( array( 'id' => 'ctrPNL001', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) );
$ctl  = $cont ? $cont->get_controls( 'kikz_mode' ) : null;
t( 'container: kikz_mode control exists (select, default auto)', is_array( $ctl ) && 'select' === $ctl['type'] && 'auto' === $ctl['default'], wp_json_encode( $ctl ) );
$hd = \Elementor\Plugin::$instance->elements_manager->create_element_instance( array( 'id' => 'hdPNL001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array() ) );
t( 'unrelated widgets (heading) do not get the control', $hd && null === $hd->get_controls( 'kikz_mode' ) );
// The option must sit where the image is configured, not in a far-away Advanced section.
$panel = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/panel-check.php' ) . ' 2>/dev/null' ), true );
$expect = array(
	'container' => array( 'section_background', 'style' ),
	'section'   => array( 'section_background', 'style' ),
	'column'    => array( 'section_style', 'style' ),
	'image'     => array( 'section_image', 'content' ),
	'image-box' => array( 'section_image', 'content' ),
);
$got = array();
foreach ( $expect as $name => $_ ) {
	$got[ $name ] = isset( $panel[ $name ] ) ? array( $panel[ $name ]['section'], $panel[ $name ]['tab'] ) : null;
}
t( 'the option sits where the image is chosen (Style > Background; Content > Image)', is_array( $panel ) && $got === $expect, wp_json_encode( $got ) );
t( 'the select offers auto / always / never with a readable label', is_array( $panel ) && array( 'auto', 'always', 'never' ) === $panel['container']['options'] && '' !== $panel['container']['label'] );
t( 'unrelated widgets (heading) do not get the option', is_array( $panel ) && null === $panel['heading'] );
t( 'there is no separate far-away "KI-Kennzeichnung" section anymore', null === $cont->get_controls( 'kikz_section' ) );
t( 'Elementor cache can be cleared (element cache + CSS)', KI_Kennzeichnung_Cache::clear_elementor() );

// The old integration used hooks that do not exist; make sure nobody re-introduces them.
t( 'no listener on the non-existent hooks of the 1.x integration', ! has_action( 'elementor/container/before_render' ) && ! has_action( 'elementor/widget/before_render' ) );
