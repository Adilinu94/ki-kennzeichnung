<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ki_generated','_ki_file_labeled','_ki_original_file')" );
delete_option( 'kikz_badge_text' );
delete_option( 'kikz_alt_suffix' );
delete_option( 'kikz_footnote' );
delete_option( 'kikz_footnote_on' );
delete_option( 'kikz_burn_in' );
delete_option( 'kikz_badge_size' );
delete_option( 'kikz_badge_corner' );
delete_option( 'kikz_badge_theme' );
delete_option( 'kikz_badge_short' );
delete_option( 'kikz_badge_color' );
delete_option( 'kikz_badge_bg_color' );
delete_option( 'kikz_badge_font' );
delete_option( 'kikz_badge_google_font_url' );
delete_option( 'kikz_patterns' );
delete_option( 'kikz_preview_image' );
