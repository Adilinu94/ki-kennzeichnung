<?php
/**
 * Plugin Name:       KI-Kennzeichnung
 * Description:       Labels AI-generated images reliably: media-library checkbox, bulk actions and detection, a badge burned into the file and/or shown as overlay (Elementor containers, flexbox, sections, image widgets, backgrounds), alt-text suffix, machine-readable IPTC metadata, a site notice, a page audit and a CSV evidence export.
 * Version:           2.0.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nick Webdesign
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ki-kennzeichnung
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Guard against a second active copy of the plugin (an older folder that was
 * left next to the current one, for example). Both copies declare the same
 * constants and classes, and PHP stops with a fatal error when they collide,
 * which takes the whole site down. The copy that loads first wins, the other
 * one steps aside and warns the administrator.
 */
if ( defined( 'KIKZ_VERSION' ) || class_exists( 'KI_Kennzeichnung', false ) ) {
	// Note: 1.x never defined KIKZ_VERSION, it only declared the class KI_Kennzeichnung. Checking the
	// class as well makes this copy step aside when an old 1.x folder was loaded before it.
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>KI-Kennzeichnung:</strong> ' . esc_html__( 'Es ist eine zweite Kopie dieses Plugins aktiv. Diese Kopie wurde nicht geladen, damit die Website nicht mit einem schweren Fehler ausfällt. Bitte deaktiviere und entferne die doppelte Kopie.', 'ki-kennzeichnung' ) . '</p></div>';
		}
	);

	return;
}

define( 'KIKZ_VERSION', '2.0.3' );
define( 'KIKZ_FILE', __FILE__ );
define( 'KIKZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'KIKZ_URL', plugin_dir_url( __FILE__ ) );
define( 'KIKZ_BASENAME', plugin_basename( __FILE__ ) );

foreach ( array( 'image', 'settings', 'flags', 'stamper', 'cache', 'detect', 'media', 'frontend', 'elementor', 'woo', 'audit', 'bootstrap' ) as $kikz_part ) {
	require_once KIKZ_DIR . 'includes/class-kikz-' . $kikz_part . '.php';
}
unset( $kikz_part );

register_deactivation_hook( __FILE__, array( 'KI_Kennzeichnung', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'KI_Kennzeichnung', 'init' ) );
