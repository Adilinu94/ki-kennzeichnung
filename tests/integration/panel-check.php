<?php
// Standalone helper for elementor.php. Elementor strips control data (tab, label, options) from
// get_controls(); what the editor client really receives is the editor config, so read that.
require getenv( 'KIKZ_WP_LOAD' );
wp_set_current_user( 1 );
// The editor runs inside wp-admin; outside of it Elementor hands out reduced control data.
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'post' );
$p = \Elementor\Plugin::$instance;
$p->editor->set_edit_mode( true );
$elements = $p->elements_manager->get_element_types_config();
$widgets  = $p->widgets_manager->get_widget_types_config();
$out      = array();
foreach ( array( 'container', 'section', 'column', 'image', 'image-box', 'heading' ) as $name ) {
	if ( in_array( $name, array( 'container', 'section', 'column' ), true ) ) {
		$c = $elements[ $name ]['controls']['kikz_mode'] ?? null;
	} else {
		$w = $p->widgets_manager->get_widget_types( $name );
		$c = $w ? ( $w->get_stack()['controls']['kikz_mode'] ?? null ) : null;
	}
	$out[ $name ] = is_array( $c ) ? array( 'section' => $c['section'], 'tab' => $c['tab'], 'default' => $c['default'], 'options' => array_keys( (array) ( $c['options'] ?? array() ) ), 'label' => $c['label'] ?? '' ) : null;
}
echo wp_json_encode( $out );
