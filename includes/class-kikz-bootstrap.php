<?php
/**
 * Bootstrap class and the small public API that 1.x exposed.
 *
 * Lives in its own file on purpose: PHP declares a class that sits at the top level of a file as soon as
 * the file is compiled, i.e. before any guard in that file could run. Keeping it out of the main file lets
 * the main file check for an already loaded copy (or a 1.x class of the same name) first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
