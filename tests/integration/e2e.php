<?php
// Included by run.php (suite "e2e"). WordPress must be reachable at home_url() over HTTP.

use KI_Kennzeichnung_Flags as Flags;

section( 'End-to-end over HTTP (real theme, real front end)' );

$probe = wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'sslverify' => false ) );
if ( is_wp_error( $probe ) || 200 !== (int) wp_remote_retrieve_response_code( $probe ) ) {
	t( 'site reachable over HTTP', false, is_wp_error( $probe ) ? $probe->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $probe ) );
	return;
}
t( 'site reachable over HTTP (theme: ' . get_option( 'stylesheet' ) . ')', true );

update_option( 'kikz_grace_days', '0' );
update_option( 'kikz_display_mode', 'auto' );
$ai    = kikz_upload( kikz_make_image( 2400, 1500 ), 'kikz-e2e-ai.jpg' );
$ai_b  = kikz_upload( kikz_make_image( 1800, 1200 ), 'kikz-e2e-block.jpg' );
$human = kikz_upload( kikz_make_image( 1800, 1200 ), 'kikz-e2e-human.jpg' );
Flags::set( $ai, true, 'manual', array(), true );
Flags::set( $ai_b, true, 'manual', array(), true );

$classic = '<p>Classic</p><img class="alignnone size-large wp-image-' . $ai . '" src="' . esc_url( wp_get_attachment_image_url( $ai, 'large' ) ) . '" alt="Strand">';
$block   = '<!-- wp:image {"id":' . $ai_b . ',"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_image_url( $ai_b, 'large' ) ) . '" alt="Wald" class="wp-image-' . $ai_b . '"/></figure><!-- /wp:image -->';
$other   = '<!-- wp:image {"id":' . $human . '} --><figure class="wp-block-image"><img src="' . esc_url( wp_get_attachment_image_url( $human, 'large' ) ) . '" alt="Mensch" class="wp-image-' . $human . '"/></figure><!-- /wp:image -->';
$pid     = wp_insert_post( array( 'post_title' => 'kikz-e2e', 'post_status' => 'publish', 'post_content' => $classic . $block . $other, 'post_type' => 'post' ) );
set_post_thumbnail( $pid, $ai );
$GLOBALS['kikz_cleanup'][] = $pid;
KI_Kennzeichnung_Cache::purge_now();

function kikz_fetch( int $pid ): string {
	$r = wp_remote_get( add_query_arg( 'nocache', wp_rand(), get_permalink( $pid ) ), array( 'timeout' => 30, 'sslverify' => false ) );
	return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
}

$html = kikz_fetch( $pid );
t( 'post renders', strlen( $html ) > 2000, (string) strlen( $html ) );
preg_match_all( '/<img\b[^>]*>/i', $html, $imgs );
$flagged = array_filter( $imgs[0], static function ( $i ) {
	return false !== strpos( $i, 'ki-flagged-image' );
} );
$ids = array();
foreach ( $flagged as $i ) {
	if ( preg_match( '/data-kikz-id="(\d+)"/', $i, $m ) ) {
		$ids[] = (int) $m[1];
	}
}
t( 'classic content image is labeled (wp_content_img_tag path)', in_array( $ai, $ids, true ), implode( ',', $ids ) );
t( 'block-editor image is labeled', in_array( $ai_b, $ids, true ) );
t( 'the human photo is NOT labeled', ! in_array( $human, $ids, true ) );
t( 'alt suffix delivered', false !== strpos( $html, 'alt="Strand (KI-generiert)"' ) && false !== strpos( $html, 'alt="Wald (KI-generiert)"' ) && false !== strpos( $html, 'alt="Mensch"' ) );
t( 'badge CSS is in the head', 1 === preg_match( '/<head.*<style id="ki-kennzeichnung-css">.*<\/head>/s', $html ) );
t( 'front-end script is loaded exactly once', 1 === substr_count( $html, 'assets/js/frontend.js' ) );
t( 'site notice rendered in the footer', false !== strpos( $html, 'ki-footer-hinweis' ) );
t( 'every labeled <img> carries its badge geometry (data-kikz-rects) naming the stamped file', preg_match_all( '/<img\b[^>]*data-kikz-rects="[^"]*' . preg_quote( wp_basename( get_attached_file( $ai ) ), '/' ) . '[^"]*"/', $html ) >= 1 );

// The script asset is reachable and syntactically what we ship.
$js = wp_remote_get( KIKZ_URL . 'assets/js/frontend.js', array( 'sslverify' => false ) );
t( 'frontend.js is served', 200 === (int) wp_remote_retrieve_response_code( $js ) && false !== strpos( (string) wp_remote_retrieve_body( $js ), 'badgeVisible' ) );

$urls = array_column( KI_Kennzeichnung_Audit::collect_urls(), 'url' );
t( 'audit collects the front page and every post that uses a flagged image', in_array( home_url( '/' ), $urls, true ) && in_array( get_permalink( $pid ), $urls, true ), wp_json_encode( $urls ) );
$audit = KI_Kennzeichnung_Audit::check_url( get_permalink( $pid ), $pid );
t( 'audit over real HTTP: no issues, images were found', array() === $audit['issues'] && '' === $audit['error'] && $audit['images'] >= 2, wp_json_encode( $audit ) );

update_option( 'kikz_display_mode', 'file' );
KI_Kennzeichnung_Cache::purge_now();
$html_file = kikz_fetch( $pid );
preg_match( '/<img\b[^>]*\bki-flagged-image[^>]*>/i', $html_file, $leak );
t( 'file mode (stamped, not fresh): no overlay classes on any <img>', array() === $leak, $leak[0] ?? '' );
t( 'file mode: the front-end script is not even loaded', false === strpos( $html_file, 'assets/js/frontend.js' ) );
t( 'file mode: alt suffix is still delivered', false !== strpos( $html_file, 'alt="Strand (KI-generiert)"' ) );
$audit = KI_Kennzeichnung_Audit::check_url( get_permalink( $pid ), $pid );
t( 'audit agrees that file-labeled images are fine', array() === $audit['issues'], wp_json_encode( $audit ) );
// The file itself carries the label even though no overlay is emitted.
$thumb_file = dirname( get_attached_file( $ai ) ) . '/' . wp_get_attachment_metadata( $ai )['sizes']['large']['file'];
$rects      = get_post_meta( $ai, '_ki_badge_rects', true );
t( 'the served "large" file contains the badge', kikz_pill_luma( $thumb_file, $rects[ wp_basename( $thumb_file ) ] ) < 110 );

update_option( 'kikz_display_mode', 'overlay' );
$html_ov = kikz_fetch( $pid );
preg_match_all( '/<img\b[^>]*ki-flagged-image[^>]*>/i', $html_ov, $ov );
t( 'overlay mode: every flagged image is labeled', count( $ov[0] ) >= 2, (string) count( $ov[0] ) );
t( 'overlay mode never emits ki-auto', false === strpos( $html_ov, 'ki-auto' ) );

// Featured image in a block theme goes through wp_get_attachment_image().
$home = '';
$r    = wp_remote_get( home_url( '/?p=' . $pid ), array( 'sslverify' => false, 'timeout' => 30 ) );
t( 'short link redirects to the same labeled content', 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), 'ki-flagged-image' ) );

update_option( 'kikz_display_mode', 'auto' );

section( 'Elementor over HTTP: cold render and cached render must look the same' );
if ( class_exists( '\\Elementor\\Plugin' ) && \Elementor\Plugin::$instance->experiments->is_feature_active( 'container' ) ) {
	update_option( 'kikz_display_mode', 'auto' );
	update_option( 'kikz_grace_days', '0' );
	$tree = array( array( 'id' => 'cwBox0001', 'elType' => 'container', 'settings' => array( 'content_width' => 'full', 'background_background' => 'classic', 'background_image' => array( 'url' => (string) wp_get_attachment_url( $ai ), 'id' => $ai, 'size' => '', 'alt' => '', 'source' => 'library' ), 'background_size' => 'cover' ), 'elements' => array(), 'isInner' => false ) );
	$epid = wp_insert_post( array( 'post_title' => 'kikz-e2e-elementor', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$GLOBALS['kikz_cleanup'][] = $epid;
	update_post_meta( $epid, '_elementor_edit_mode', 'builder' );
	update_post_meta( $epid, '_elementor_template_type', 'wp-page' );
	update_post_meta( $epid, '_wp_page_template', 'elementor_canvas' );
	update_post_meta( $epid, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
	\Elementor\Plugin::$instance->files_manager->clear_cache();
	$visits = array( 'cold (element rendered)' => kikz_fetch( $epid ), 'warm (served from the element cache)' => kikz_fetch( $epid ), 'warm again' => kikz_fetch( $epid ) );
	foreach ( $visits as $label => $page ) {
		preg_match( '/<div[^>]*data-id="cwBox0001"[^>]*>/', $page, $wrap );
		t( "$label: wrapper has ki-flagged-bg and data-kikz-rects", false !== strpos( $wrap[0] ?? '', 'ki-flagged-bg' ) && false !== strpos( $wrap[0] ?? '', 'data-kikz-rects' ), $wrap[0] ?? 'wrapper not found' );
		t( "$label: the front-end script is loaded (it was missing on every cached request before)", 1 === substr_count( $page, 'assets/js/frontend.js' ), (string) substr_count( $page, 'assets/js/frontend.js' ) );
	}
} else {
	echo "  skip Elementor cold/warm test (Elementor with the Container feature is not available)\n";
}
