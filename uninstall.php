<?php
/**
 * Removes plugin data. Stamped image files and the folder with the original
 * images are intentionally kept: the originals live in
 * uploads/ki-kennzeichnung-originals/ as "{attachment-id}-{file name}" and can be
 * copied back manually. A README.txt in that folder explains how.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function kikz_uninstall_site() {
	global $wpdb;

	$meta = array(
		'_ki_generated', '_ki_file_labeled', '_ki_original_file', '_ki_badge_rects', '_ki_stamped_hash',
		'_ki_stamp_time', '_ki_stamp_status', '_ki_stamp_error', '_ki_flag_source', '_ki_flag_time', '_ki_detect',
	);
	$in = "'" . implode( "','", array_map( 'esc_sql', $meta ) ) . "'";
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($in)" ); // phpcs:ignore WordPress.DB

	$options = array(
		'badge_text', 'alt_suffix', 'footnote', 'footnote_on', 'burn_in', 'display_mode', 'badge_size', 'badge_corner',
		'badge_theme', 'badge_short', 'badge_color', 'badge_bg_color', 'badge_font', 'badge_google_font_url',
		'badge_margin', 'grace_days', 'auto_detect', 'patterns', 'preview_image', 'queue', 'stem_map', 'db_version',
	);
	foreach ( $options as $name ) {
		delete_option( 'kikz_' . $name );
	}
	delete_transient( 'kikz_queue_lock' );
	delete_transient( 'kikz_purge_pending' );
	delete_transient( 'kikz_audit_urls' );
	wp_clear_scheduled_hook( 'kikz_process_queue' );
	wp_clear_scheduled_hook( 'kikz_purge' );

	$upload = wp_get_upload_dir();
	$dir    = $upload['basedir'] . '/ki-kennzeichnung-originals';
	if ( is_dir( $dir ) ) {
		@file_put_contents(
			$dir . '/README.txt',
			"These are the original (unlabeled) images saved by the KI-Kennzeichnung plugin.\n" .
			"File names are {attachment-id}-{original file name}. To restore an image, copy it over the\n" .
			"file with the same name in the matching uploads folder (e.g. uploads/2025/10/) and\n" .
			"regenerate thumbnails. You can delete this folder if you no longer need the originals.\n"
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $kikz_site_id ) {
		switch_to_blog( $kikz_site_id );
		kikz_uninstall_site();
		restore_current_blog();
	}
} else {
	kikz_uninstall_site();
}
