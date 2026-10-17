<?php
/**
 * Plugin Name:       KI-Kennzeichnung
 * Description:       Labels AI-generated images reliably: media-library checkbox, bulk actions and detection, a badge burned into the file and/or shown as overlay (Elementor containers, flexbox, sections, image widgets, backgrounds), alt-text suffix, machine-readable IPTC metadata, a site notice, a page audit and a CSV evidence export.
 * Version:           2.0.2
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
if ( defined( 'KIKZ_VERSION' ) ) {
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

define( 'KIKZ_VERSION', '2.0.2' );
define( 'KIKZ_FILE', __FILE__ );
define( 'KIKZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'KIKZ_URL', plugin_dir_url( __FILE__ ) );
define( 'KIKZ_BASENAME', plugin_basename( __FILE__ ) );

foreach ( array( 'image', 'settings', 'flags', 'stamper', 'cache', 'detect', 'media', 'frontend', 'elementor', 'woo', 'audit' ) as $kikz_part ) {
	require_once KIKZ_DIR . 'includes/class-kikz-' . $kikz_part . '.php';
}
unset( $kikz_part );

/**
 * Bootstrap and the small public API that 1.x exposed.
 */
final class KI_Kennzeichnung {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		KI_Kennzeichnung_Settings::init();
		KI_Kennzeichnung_Stamper::init();
		KI_Kennzeichnung_Cache::init();
		KI_Kennzeichnung_Detect::init();
		KI_Kennzeichnung_Media::init();
		KI_Kennzeichnung_Frontend::init();
		KI_Kennzeichnung_Elementor::init();
		KI_Kennzeichnung_Woo::init();
		KI_Kennzeichnung_Audit::init();
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'ki-kennzeichnung', false, dirname( KIKZ_BASENAME ) . '/languages' );
	}

	/** @return array<string,string> */
	public static function defaults(): array {
		return KI_Kennzeichnung_Settings::defaults();
	}

	public static function get( string $key ): string {
		return KI_Kennzeichnung_Settings::get( $key );
	}

	/** Whether an attachment is marked as AI-generated (1.x API). */
	public static function is_flagged_public( int $attachment_id ): bool {
		return KI_Kennzeichnung_Flags::is_flagged( $attachment_id );
	}

	/** @return string[] File-name patterns (1.x API). */
	public static function patterns_public(): array {
		return KI_Kennzeichnung_Settings::patterns();
	}

	/**
	 * Mark/unmark an attachment and (un)stamp its file (1.x API; now synchronous).
	 */
	public static function apply_file_label( int $attachment_id, bool $flag ): bool {
		KI_Kennzeichnung_Flags::set( $attachment_id, $flag, 'api', array(), true );
		$labeled = KI_Kennzeichnung_Flags::is_labeled( $attachment_id );
		return $flag ? $labeled : ! $labeled;
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'kikz_process_queue' );
		wp_clear_scheduled_hook( 'kikz_purge' );
	}
}

register_deactivation_hook( __FILE__, array( 'KI_Kennzeichnung', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'KI_Kennzeichnung', 'init' ) );
