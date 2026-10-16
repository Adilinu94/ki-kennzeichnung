<?php
/**
 * Page audit: fetches the live HTML of pages that use flagged images and checks
 * that each of them is actually delivered with a label. Elementor backgrounds
 * (which live in CSS, not in <img> tags) are checked via the element IDs from
 * the Elementor data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Audit {

	private const TRANSIENT = 'kikz_audit_urls';
	private const BATCH     = 3;
	private const MAX_URLS  = 60;

	public static function init(): void {
		add_action( 'wp_ajax_kikz_audit', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Pages that contain flagged images: front page plus posts that reference them.
	 *
	 * @return array<int, array{url:string,post:int}>
	 */
	public static function collect_urls(): array {
		global $wpdb;
		$urls = array( array( 'url' => home_url( '/' ), 'post' => 0 ) );
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m WHERE m.meta_key = %s AND m.meta_value = '1' LIMIT 150", KI_Kennzeichnung_Flags::META ) );
		$posts = array();
		foreach ( (array) $ids as $att ) {
			$att = (int) $att;
			$likes = array(
				'%wp-image-' . $att . '%',
				'%"id":' . $att . ',%',
				'%"id":' . $att . '}%',
				'%"id":"' . $att . '"%',
			);
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type NOT IN ('attachment','revision','nav_menu_item') AND (p.post_content LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key = '_thumbnail_id' AND meta_value = %d) OR (meta_key = '_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s)))) LIMIT 10",
					$likes[0],
					$att,
					$likes[1],
					$likes[2],
					$likes[3]
				)
			);
			foreach ( (array) $found as $pid ) {
				$posts[ (int) $pid ] = true;
			}
			if ( count( $posts ) >= self::MAX_URLS ) {
				break;
			}
		}
		foreach ( array_keys( $posts ) as $pid ) {
			$link = get_permalink( $pid );
			if ( $link ) {
				$urls[] = array( 'url' => $link, 'post' => (int) $pid );
			}
		}
		return apply_filters( 'kikz_audit_urls', array_slice( $urls, 0, self::MAX_URLS + 1 ) );
	}

	public static function ajax(): void {
		check_ajax_referer( 'kikz_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'ki-kennzeichnung' ) ), 403 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		if ( 0 === $offset ) {
			set_transient( self::TRANSIENT, self::collect_urls(), HOUR_IN_SECONDS );
		}
		$urls = get_transient( self::TRANSIENT );
		if ( ! is_array( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'Die Prüfung ist abgelaufen, bitte neu starten.', 'ki-kennzeichnung' ) ) );
		}
		$results = array();
		foreach ( array_slice( $urls, $offset, self::BATCH ) as $item ) {
			$results[] = self::check_url( (string) $item['url'], (int) $item['post'] );
		}
		wp_send_json_success(
			array(
				'total'   => count( $urls ),
				'next'    => $offset + self::BATCH,
				'done'    => $offset + self::BATCH >= count( $urls ),
				'results' => $results,
			)
		);
	}

	/**
	 * Fetch one page and analyse it.
	 *
	 * @return array{url:string,issues:string[],images:int,error:string}
	 */
	public static function check_url( string $url, int $post_id ): array {
		$res = array( 'url' => $url, 'issues' => array(), 'images' => 0, 'error' => '' );
		$get = wp_remote_get(
			add_query_arg( 'kikz_audit', wp_generate_password( 8, false ), $url ),
			array(
				'timeout'    => 25,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent' => 'KI-Kennzeichnung-Audit',
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $get ) || 200 !== (int) wp_remote_retrieve_response_code( $get ) ) {
			$res['error'] = is_wp_error( $get ) ? $get->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $get );
			return $res;
		}
		$analysis = self::analyze_html( (string) wp_remote_retrieve_body( $get ), $post_id );
		$res['issues'] = $analysis['issues'];
		$res['images'] = $analysis['images'];
		return $res;
	}

	/**
	 * Check rendered HTML. Public so it can be unit-tested.
	 *
	 * @return array{issues:string[],images:int}
	 */
	public static function analyze_html( string $html, int $post_id = 0 ): array {
		$issues = array();
		$images = 0;
		$mode   = KI_Kennzeichnung_Settings::mode();
		$elems  = array(); // data-id => has ki-flagged-bg.

		$p = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			if ( 'IMG' === $p->get_tag() ) {
				$id = 0;
				foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
					$id = KI_Kennzeichnung_Flags::id_from_url( (string) $p->get_attribute( $attr ) );
					if ( $id > 0 ) {
						break;
					}
				}
				if ( $id <= 0 ) {
					continue;
				}
				$images++;
				$has_overlay = $p->has_class( 'ki-flagged-image' ) || null !== $p->get_attribute( 'data-kikz-id' );
				$file_only   = 'overlay' !== $mode && KI_Kennzeichnung_Flags::is_labeled( $id ) && '' === KI_Kennzeichnung_Frontend::overlay_classes( $id );
				if ( ! $has_overlay && ! $file_only ) {
					$issues[] = sprintf(
						/* translators: %s: image file name */
						__( 'Bild ohne Kennzeichnung ausgeliefert: %s', 'ki-kennzeichnung' ),
						wp_basename( (string) wp_parse_url( (string) $p->get_attribute( 'src' ), PHP_URL_PATH ) )
					);
				}
			} else {
				$did = $p->get_attribute( 'data-id' );
				if ( is_string( $did ) && '' !== $did ) {
					$elems[ $did ] = $p->has_class( 'ki-flagged-bg' ) || $p->has_class( 'ki-flagged-widget' );
				}
			}
		}

		if ( $post_id > 0 ) {
			$data = get_post_meta( $post_id, '_elementor_data', true );
			$tree = is_string( $data ) ? json_decode( $data, true ) : $data;
			if ( is_array( $tree ) ) {
				self::walk_elementor( $tree, $elems, $issues, $images );
			}
		}
		return array( 'issues' => array_values( array_unique( $issues ) ), 'images' => $images );
	}

	/**
	 * @param array<int,array> $nodes  Elementor element tree.
	 * @param array<string,bool> $elems Rendered elements: data-id => labeled.
	 */
	private static function walk_elementor( array $nodes, array $elems, array &$issues, int &$images ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$settings = (array) ( $node['settings'] ?? array() );
			$type     = (string) ( $node['elType'] ?? '' );
			$atomic = in_array( $type, array( 'e-div-block', 'e-flexbox', 'e-grid' ), true );
			if ( ( $atomic || in_array( $type, array( 'container', 'section', 'column' ), true ) ) && 'never' !== ( $settings['kikz_mode'] ?? 'auto' ) ) {
				$ids = $atomic ? KI_Kennzeichnung_Elementor::atomic_ids( $node['styles'] ?? array() ) : KI_Kennzeichnung_Elementor::background_ids( $settings );
				if ( $ids ) {
					$images++;
					$needs = '' !== ( 'always' === ( $settings['kikz_mode'] ?? '' ) ? 'x' : self::needs_overlay( $ids ) );
					$id    = (string) ( $node['id'] ?? '' );
					if ( $needs && empty( $elems[ $id ] ) ) {
						$issues[] = sprintf(
							/* translators: 1: element type, 2: Elementor element ID */
							__( 'Hintergrundbild ohne Kennzeichnung: %1$s %2$s', 'ki-kennzeichnung' ),
							$type,
							$id
						);
					}
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk_elementor( $node['elements'], $elems, $issues, $images );
			}
		}
	}

	/**
	 * @param int[] $ids IDs.
	 */
	private static function needs_overlay( array $ids ): string {
		foreach ( $ids as $id ) {
			if ( '' !== KI_Kennzeichnung_Frontend::overlay_classes( $id ) ) {
				return 'x';
			}
		}
		return '';
	}
}
