<?php
/**
 * Cache invalidation. The image URL stays the same when a file is stamped, so
 * page caches, CDNs and Elementor's element cache must be told to let go.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Cache {

	private const FLAG = 'kikz_purge_pending';

	public static function init(): void {
		add_action( 'kikz_purge', array( __CLASS__, 'purge_now' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 10, 1 );
	}

	/**
	 * Visual settings changed: cached HTML contains the old badge CSS.
	 */
	public static function on_option( $name ): void {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'kikz_' ) ) {
			return;
		}
		$visual = array( 'badge_text', 'badge_short', 'badge_size', 'badge_corner', 'badge_theme', 'badge_color', 'badge_bg_color', 'badge_font', 'badge_margin', 'display_mode', 'alt_suffix', 'footnote', 'footnote_on', 'grace_days' );
		if ( in_array( substr( $name, 5 ), $visual, true ) ) {
			self::request_purge();
		}
	}

	/**
	 * Debounced: many stamps in a row cause exactly one purge.
	 */
	public static function request_purge( int $id = 0 ): void {
		set_transient( self::FLAG, 1, HOUR_IN_SECONDS );
		if ( ! wp_next_scheduled( 'kikz_purge' ) ) {
			wp_schedule_single_event( time() + 15, 'kikz_purge' );
		}
	}

	/**
	 * Purge now if a purge was requested.
	 */
	public static function purge_now(): void {
		if ( ! get_transient( self::FLAG ) ) {
			return;
		}
		delete_transient( self::FLAG );
		self::run();
	}

	private static function run(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		self::clear_elementor();
		/**
		 * Let other cache layers (CDN, host cache) hook in.
		 */
		do_action( 'kikz_purge_caches' );
	}

	/**
	 * Regenerates Elementor CSS and drops its element cache (pre-rendered HTML).
	 */
	public static function clear_elementor(): bool {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->files_manager ) ) {
			return false;
		}
		try {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
