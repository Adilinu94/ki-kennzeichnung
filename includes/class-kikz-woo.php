<?php
/**
 * WooCommerce: gallery images already pass wp_get_attachment_image(); this adds
 * the variation image swap, which replaces the <img> via JavaScript.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Woo {

	public static function init(): void {
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 10, 3 );
	}

	/**
	 * Tell the front-end script whether a variation's image is flagged.
	 */
	public static function variation_data( $data, $product = null, $variation = null ) {
		if ( ! is_array( $data ) || ! KI_Kennzeichnung_Flags::has_flagged() ) {
			return $data;
		}
		$id = isset( $data['image_id'] ) ? (int) $data['image_id'] : 0;
		if ( $id > 0 && KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
			$data['kikz_flagged'] = true;
			$data['kikz_classes'] = KI_Kennzeichnung_Frontend::overlay_classes( $id );
			KI_Kennzeichnung_Frontend::mark_used( $id );
		}
		return $data;
	}
}
