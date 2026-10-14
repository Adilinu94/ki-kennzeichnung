<?php
// Standalone helper for elementor.php: prints the tab/label our panel section is registered with.
require getenv( 'KIKZ_WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';
wp_set_current_user( 1 );
$seen = array();
foreach ( array( 'container', 'section', 'column', 'image', 'image-box' ) as $stack ) {
	add_action( "elementor/element/$stack/kikz_section/before_section_start", static function ( $el, $args ) use ( &$seen, $stack ) {
		$seen[ $stack ] = $args['tab'] . ' / ' . $args['label'];
	}, 10, 2 );
}
$p = \Elementor\Plugin::$instance;
$p->editor->set_edit_mode( true );
foreach ( array( array( 'container' ), array( 'section' ), array( 'column' ), array( 'widget', 'image' ), array( 'widget', 'image-box' ) ) as $d ) {
	$p->elements_manager->create_element_instance( array( 'id' => 'z' . md5( implode( '', $d ) ), 'elType' => $d[0], 'widgetType' => $d[1] ?? null, 'settings' => array(), 'elements' => array() ) )->get_stack();
}
echo wp_json_encode( $seen );
