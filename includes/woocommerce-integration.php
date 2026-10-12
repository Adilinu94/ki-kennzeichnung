<?php
/**
 * WooCommerce integration for KI-Kennzeichnung.
 * Re-runs the frontend badge scan after variation changes; injects badge into the photoswipe lightbox.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class KI_Kennzeichnung_Woo {
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) { return; }
		add_action( 'wp_footer', [ __CLASS__, 'print_script' ], 20 );
	}
	public static function print_script(): void {
		if ( ! is_product() ) { return; }
		?>
		<script id="kikz-woo-js">
		(function(){
			if (typeof jQuery === 'undefined') return;
			function rerun(){ if (window.kikzRun) window.kikzRun(); }
			jQuery(document).on('found_variation reset_data', '.variations_form', function(){ setTimeout(rerun, 50); });
			jQuery(document).ajaxComplete(function(){ setTimeout(rerun, 100); });
		})();
		</script>
		<?php
	}
}
add_action( 'init', function(){ KI_Kennzeichnung_Woo::init(); }, 13 );
