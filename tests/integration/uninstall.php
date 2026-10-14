<?php
// Included last by run.php: runs the real uninstall.php against the test site.

use KI_Kennzeichnung_Flags as Flags;
use KI_Kennzeichnung_Stamper as Stamper;

section( 'Uninstall' );
update_option( 'kikz_display_mode', 'auto' );
$un  = kikz_upload( kikz_make_image( 1500, 1000 ), 'kikz-uninstall.jpg' );
$unf = get_attached_file( $un );
Flags::set( $un, true, 'manual', array(), true );
$stamped_hash = md5_file( $unf );
$backup       = Stamper::backup_path( $un );
update_option( 'kikz_queue', array( array( 'id' => $un, 'op' => 'stamp' ) ) );
t( 'precondition: stamped image with a backup', Flags::is_labeled( $un ) && is_file( $backup ) );

define( 'WP_UNINSTALL_PLUGIN', 'ki-kennzeichnung/ki-kennzeichnung.php' );
require KIKZ_DIR . 'uninstall.php';

global $wpdb;
t( 'all _ki_* metadata removed', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_ki\\_%'" ) );
t( 'plugin options removed', false === get_option( 'kikz_display_mode', false ) && false === get_option( 'kikz_queue', false ) && false === get_option( 'kikz_stem_map', false ) );
t( 'stamped image is untouched (nothing silently reverted)', md5_file( $unf ) === $stamped_hash );
t( 'originals are kept so they stay recoverable', is_file( $backup ) );
t( 'a README.txt explains how to restore them', is_file( dirname( $backup ) . '/README.txt' ) && false !== strpos( (string) file_get_contents( dirname( $backup ) . '/README.txt' ), 'attachment-id' ) );
