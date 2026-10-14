<?php
/**
 * Media library integration: checkbox, list column, bulk actions, AJAX
 * toggle, filter, grid data, queue runner and CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Media {

	public static function init(): void {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'render_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_field' ), 10, 2 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'prepare_for_js' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_action( 'wp_ajax_kikz_toggle', array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_kikz_queue_run', array( __CLASS__, 'ajax_queue_run' ) );
		add_action( 'wp_ajax_kikz_restamp_all', array( __CLASS__, 'ajax_restamp_all' ) );
		add_action( 'wp_ajax_kikz_unstamp_all', array( __CLASS__, 'ajax_unstamp_all' ) );
		add_action( 'wp_ajax_kikz_clear_elementor_cache', array( __CLASS__, 'ajax_clear_elementor' ) );
		add_action( 'admin_post_kikz_export', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * Whether the current user may change the flag of this attachment.
	 */
	private static function can_edit( int $id ): bool {
		return $id > 0 && 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
	}

	private static function guard( string $cap = 'upload_files' ): void {
		check_ajax_referer( 'kikz_admin', 'nonce' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'ki-kennzeichnung' ) ), 403 );
		}
	}

	// ---------------------------------------------------------------------
	// Attachment edit field.
	// ---------------------------------------------------------------------

	public static function render_field( $form_fields, $post ) {
		if ( ! is_array( $form_fields ) || ! $post instanceof WP_Post || ! wp_attachment_is_image( $post ) ) {
			return $form_fields;
		}
		$id       = (int) $post->ID;
		$field_id = 'attachments-' . $id . '-ki_generated';
		$status   = (string) get_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS, true );
		$note     = '';
		if ( 'failed' === $status || 'unsupported' === $status ) {
			$note = ' <em>(' . esc_html( KI_Kennzeichnung_Stamper::error_message( (string) get_post_meta( $id, '_ki_stamp_error', true ) ) ) . ')</em>';
		}
		$form_fields['ki_generated'] = array(
			'label' => __( 'KI-Kennzeichnung', 'ki-kennzeichnung' ),
			'input' => 'html',
			'html'  => '<label for="' . esc_attr( $field_id ) . '"><input type="checkbox" id="' . esc_attr( $field_id ) . '" name="attachments[' . $id . '][ki_generated]" value="1"' . checked( KI_Kennzeichnung_Flags::is_flagged( $id ), true, false ) . '> ' . esc_html__( 'KI-generiertes Bild', 'ki-kennzeichnung' ) . '</label>' . $note,
			'helps' => __( 'Das Bild wird auf der Website gekennzeichnet (Badge, Alt-Text-Zusatz, Metadaten).', 'ki-kennzeichnung' ),
		);
		return $form_fields;
	}

	public static function save_field( $post, $attachment ) {
		$id = (int) ( $post['ID'] ?? 0 );
		if ( ! is_array( $attachment ) || ! self::can_edit( $id ) || ! wp_attachment_is_image( $id ) ) {
			return $post;
		}
		$want = ! empty( $attachment['ki_generated'] );
		if ( $want !== KI_Kennzeichnung_Flags::is_flagged( $id ) ) {
			KI_Kennzeichnung_Flags::set( $id, $want, 'manual', array(), true );
		}
		return $post;
	}

	// ---------------------------------------------------------------------
	// List column.
	// ---------------------------------------------------------------------

	public static function add_column( $columns ) {
		$columns['ki_kennzeichnung'] = 'KI';
		return $columns;
	}

	/**
	 * Chip markup shared by the list column and AJAX responses.
	 */
	public static function chip( int $id ): string {
		$flagged = KI_Kennzeichnung_Flags::is_flagged( $id );
		$status  = (string) get_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS, true );
		$class   = 'kikz-chip' . ( $flagged ? ' is-on' : '' );
		$title   = __( 'KI-Kennzeichnung umschalten', 'ki-kennzeichnung' );
		$text    = $flagged ? 'KI' : '–';
		if ( $flagged && 'pending' === $status ) {
			$class .= ' is-pending';
			$text  .= ' …';
			$title  = __( 'Wird im Hintergrund eingebrannt', 'ki-kennzeichnung' );
		} elseif ( $flagged && in_array( $status, array( 'failed', 'unsupported' ), true ) ) {
			$class .= ' is-warn';
			$text  .= ' !';
			$title  = KI_Kennzeichnung_Stamper::error_message( (string) get_post_meta( $id, '_ki_stamp_error', true ) );
		} elseif ( $flagged && 'ok' === $status ) {
			$title = __( 'Badge ist in die Datei eingebrannt', 'ki-kennzeichnung' );
		}
		return sprintf(
			'<button type="button" class="%1$s" data-id="%2$d" data-flagged="%3$d" aria-pressed="%3$d" title="%4$s">%5$s</button>',
			esc_attr( $class ),
			$id,
			$flagged ? 1 : 0,
			esc_attr( $title ),
			esc_html( $text )
		);
	}

	public static function render_column( $column, $post_id ): void {
		if ( 'ki_kennzeichnung' !== $column || ! wp_attachment_is_image( (int) $post_id ) ) {
			return;
		}
		echo self::chip( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in chip().
	}

	// ---------------------------------------------------------------------
	// Bulk actions.
	// ---------------------------------------------------------------------

	public static function register_bulk_actions( $actions ) {
		$actions['kikz_mark']   = __( 'Als KI-generiert markieren', 'ki-kennzeichnung' );
		$actions['kikz_unmark'] = __( 'KI-Kennzeichnung entfernen', 'ki-kennzeichnung' );
		return $actions;
	}

	public static function handle_bulk_actions( $redirect, $action, $ids ) {
		if ( 'kikz_mark' !== $action && 'kikz_unmark' !== $action ) {
			return $redirect;
		}
		$mark  = 'kikz_mark' === $action;
		$count = 0;
		$skip  = 0;
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( ! self::can_edit( $id ) || ! wp_attachment_is_image( $id ) ) {
				$skip++;
				continue;
			}
			KI_Kennzeichnung_Flags::set( $id, $mark, 'bulk' ); // File work is queued, not done in this request.
			$count++;
		}
		return add_query_arg(
			array(
				'kikz_done'    => ( $mark ? 'marked-' : 'unmarked-' ) . $count,
				'kikz_skipped' => $skip,
			),
			$redirect
		);
	}

	public static function bulk_notice(): void {
		if ( empty( $_GET['kikz_done'] ) || ! is_string( $_GET['kikz_done'] ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		if ( ! preg_match( '/^(marked|unmarked)-(\d+)$/', wp_unslash( $_GET['kikz_done'] ), $m ) ) {
			return;
		}
		$text = 'marked' === $m[1]
			/* translators: %d: number of images */
			? sprintf( _n( '%d Bild als KI-generiert markiert.', '%d Bilder als KI-generiert markiert.', (int) $m[2], 'ki-kennzeichnung' ), (int) $m[2] )
			/* translators: %d: number of images */
			: sprintf( _n( 'Bei %d Bild wurde die KI-Kennzeichnung entfernt.', 'Bei %d Bildern wurde die KI-Kennzeichnung entfernt.', (int) $m[2], 'ki-kennzeichnung' ), (int) $m[2] );
		$pending = KI_Kennzeichnung_Stamper::queue_length();
		if ( $pending > 0 ) {
			/* translators: %d: number of queued images */
			$text .= ' ' . sprintf( _n( '%d Bild wird im Hintergrund bearbeitet.', '%d Bilder werden im Hintergrund bearbeitet.', $pending, 'ki-kennzeichnung' ), $pending );
		}
		$skipped = isset( $_GET['kikz_skipped'] ) ? (int) $_GET['kikz_skipped'] : 0;
		if ( $skipped > 0 ) {
			/* translators: %d: number of skipped images */
			$text .= ' ' . sprintf( __( '%d übersprungen (keine Berechtigung oder kein Bild).', 'ki-kennzeichnung' ), $skipped );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	// ---------------------------------------------------------------------
	// Grid view data and list filter.
	// ---------------------------------------------------------------------

	public static function prepare_for_js( $response, $attachment ) {
		if ( is_array( $response ) && $attachment instanceof WP_Post ) {
			$response['kikzFlagged'] = KI_Kennzeichnung_Flags::is_flagged( (int) $attachment->ID );
			$response['kikzStatus']  = (string) get_post_meta( (int) $attachment->ID, KI_Kennzeichnung_Flags::META_STATUS, true );
		}
		return $response;
	}

	public static function filter_dropdown( $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$current = isset( $_GET['kikz_filter'] ) ? sanitize_key( wp_unslash( $_GET['kikz_filter'] ) ) : '';
		echo '<label for="kikz_filter" class="screen-reader-text">' . esc_html__( 'KI-Kennzeichnung', 'ki-kennzeichnung' ) . '</label>';
		echo '<select name="kikz_filter" id="kikz_filter">';
		$opts = array(
			''         => __( 'KI-Kennzeichnung: alle', 'ki-kennzeichnung' ),
			'marked'   => __( 'Nur KI-markierte', 'ki-kennzeichnung' ),
			'unmarked' => __( 'Nur nicht markierte', 'ki-kennzeichnung' ),
			'problem'  => __( 'Nur mit Stempel-Problem', 'ki-kennzeichnung' ),
		);
		foreach ( $opts as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function filter_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$mode = isset( $_GET['kikz_filter'] ) ? sanitize_key( wp_unslash( $_GET['kikz_filter'] ) ) : '';
		$mq   = (array) $query->get( 'meta_query' );
		if ( 'marked' === $mode ) {
			$mq[] = array( 'key' => KI_Kennzeichnung_Flags::META, 'value' => '1', 'compare' => '=' );
		} elseif ( 'unmarked' === $mode ) {
			$mq[] = array( 'key' => KI_Kennzeichnung_Flags::META, 'compare' => 'NOT EXISTS' );
		} elseif ( 'problem' === $mode ) {
			$mq[] = array( 'key' => KI_Kennzeichnung_Flags::META_STATUS, 'value' => array( 'failed', 'unsupported' ), 'compare' => 'IN' );
		} else {
			return;
		}
		$query->set( 'meta_query', $mq );
	}

	// ---------------------------------------------------------------------
	// Assets.
	// ---------------------------------------------------------------------

	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'upload.php', 'settings_page_ki-kennzeichnung' ), true ) ) {
			return;
		}
		$is_settings = 'settings_page_ki-kennzeichnung' === $hook;
		wp_enqueue_style( 'kikz-admin', KIKZ_URL . 'assets/css/admin.css', array(), KIKZ_VERSION );
		wp_add_inline_style( 'kikz-admin', "@font-face{font-family:'Kikz Badge';src:url('" . esc_url( KIKZ_URL . 'fonts/DejaVuSans.ttf' ) . "') format('truetype');font-display:swap}" );
		wp_enqueue_script( 'kikz-admin', KIKZ_URL . 'assets/js/admin.js', array(), KIKZ_VERSION, true );
		wp_localize_script(
			'kikz-admin',
			'kikzAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'kikz_admin' ),
				'isSettings' => $is_settings,
				'pending'    => KI_Kennzeichnung_Stamper::queue_length(),
				'canManage'  => current_user_can( 'manage_options' ),
				'i18n'       => array(
					'working'      => __( 'Wird verarbeitet …', 'ki-kennzeichnung' ),
					'queueLeft'    => __( 'Bilder in der Warteschlange: %d', 'ki-kennzeichnung' ),
					'queueDone'    => __( 'Warteschlange abgearbeitet.', 'ki-kennzeichnung' ),
					'errors'       => __( 'Probleme bei einzelnen Bildern:', 'ki-kennzeichnung' ),
					'modified'     => __( 'Die Datei wurde nach dem Stempeln bearbeitet. Trotzdem das Original wiederherstellen? Deine Bearbeitung geht dabei verloren.', 'ki-kennzeichnung' ),
					'hits'         => __( '%1$d Treffer, davon %2$d noch nicht markiert.', 'ki-kennzeichnung' ),
					'confirmMark'  => __( 'Die gewählten Bilder als KI-generiert markieren? Das Badge wird in die Dateien eingebrannt (Originale werden gesichert).', 'ki-kennzeichnung' ),
					'markedN'      => __( '%d Bilder markiert, Stempeln läuft im Hintergrund.', 'ki-kennzeichnung' ),
					'nameHint'     => __( 'Dateinamen sind nur ein Hinweis – bitte prüfe die Treffer.', 'ki-kennzeichnung' ),
					'allMatches'   => __( 'Alle %d offenen Treffer markieren (nicht nur die angezeigten)', 'ki-kennzeichnung' ),
					'metaRunning'  => __( 'Prüfe Metadaten … %d Bilder geprüft', 'ki-kennzeichnung' ),
					'metaDone'     => __( 'Fertig: %1$d Bilder automatisch markiert, %2$d Kandidaten zur Prüfung.', 'ki-kennzeichnung' ),
					'mark'         => __( 'Markieren', 'ki-kennzeichnung' ),
					'confirmRestamp' => __( 'Alle eingebrannten Bilder mit den aktuellen Einstellungen neu stempeln? Das läuft im Hintergrund.', 'ki-kennzeichnung' ),
					'confirmUnstamp' => __( 'Alle eingebrannten Badges entfernen und die Originale wiederherstellen? Die Bilder bleiben markiert (Overlay).', 'ki-kennzeichnung' ),
					'restamped'    => __( '%d Bilder zur Bearbeitung eingeplant.', 'ki-kennzeichnung' ),
					'auditRunning' => __( 'Seitenprüfung: %1$d von %2$d Seiten …', 'ki-kennzeichnung' ),
					'auditOk'      => __( 'Alles in Ordnung: %d Seiten geprüft, jedes markierte Bild ist gekennzeichnet.', 'ki-kennzeichnung' ),
					'auditIssues'  => __( 'Gefunden: %1$d Probleme auf %2$d Seiten.', 'ki-kennzeichnung' ),
					'cleared'      => __( 'Elementor-Cache geleert.', 'ki-kennzeichnung' ),
					'error'        => __( 'Anfrage fehlgeschlagen.', 'ki-kennzeichnung' ),
					'pick'         => __( 'Bild auswählen', 'ki-kennzeichnung' ),
					'change'       => __( 'Bild ändern', 'ki-kennzeichnung' ),
					'legacyHint'   => __( '%d Bilder wurden mit einer älteren Version gestempelt. „Alle neu stempeln“ aktualisiert sie (lesbare Thumbnails, Metadaten).', 'ki-kennzeichnung' ),
				),
				'legacy'     => KI_Kennzeichnung_Flags::stats()['legacy'],
			)
		);
	}

	// ---------------------------------------------------------------------
	// AJAX.
	// ---------------------------------------------------------------------

	public static function ajax_toggle(): void {
		self::guard();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! self::can_edit( $id ) || ! wp_attachment_is_image( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Ungültige ID oder keine Berechtigung.', 'ki-kennzeichnung' ) ), 403 );
		}
		$state = isset( $_POST['state'] ) && '1' === (string) $_POST['state'];
		$force = ! empty( $_POST['force'] );
		if ( ! $state && ! $force && KI_Kennzeichnung_Flags::is_labeled( $id ) && KI_Kennzeichnung_Stamper::is_modified( $id ) ) {
			// Nothing was changed yet: ask before overwriting the edited file.
			wp_send_json_error( array( 'needsConfirm' => true, 'message' => KI_Kennzeichnung_Stamper::error_message( 'modified' ) ) );
		}
		$res = KI_Kennzeichnung_Flags::set( $id, $state, 'manual', $force ? array( 'force' => true ) : array(), true );
		wp_send_json_success(
			array(
				'id'      => $id,
				'flagged' => KI_Kennzeichnung_Flags::is_flagged( $id ),
				'chip'    => self::chip( $id ),
				'code'    => $res['code'],
				'message' => in_array( $res['code'], array( '', 'ok', 'not_labeled', 'overlay_mode' ), true ) ? '' : KI_Kennzeichnung_Stamper::error_message( $res['code'] ),
				'stats'   => KI_Kennzeichnung_Flags::stats(),
			)
		);
	}

	public static function ajax_queue_run(): void {
		self::guard();
		$r = KI_Kennzeichnung_Stamper::process( 2, 15.0 );
		wp_send_json_success(
			array(
				'done'   => $r['done'],
				'left'   => $r['left'],
				'busy'   => $r['busy'],
				'errors' => array_map( 'strval', array_values( $r['errors'] ) ),
				'stats'  => KI_Kennzeichnung_Flags::stats(),
			)
		);
	}

	public static function ajax_restamp_all(): void {
		self::guard( 'manage_options' );
		wp_send_json_success( array( 'queued' => KI_Kennzeichnung_Stamper::enqueue_restamp_all() ) );
	}

	public static function ajax_unstamp_all(): void {
		self::guard( 'manage_options' );
		wp_send_json_success( array( 'queued' => KI_Kennzeichnung_Stamper::enqueue_unstamp_all() ) );
	}

	public static function ajax_clear_elementor(): void {
		self::guard( 'manage_options' );
		wp_send_json_success( array( 'cleared' => KI_Kennzeichnung_Cache::clear_elementor() ) );
	}

	// ---------------------------------------------------------------------
	// CSV export (evidence).
	// ---------------------------------------------------------------------

	private static function csv_cell( $value ): string {
		$value = (string) $value;
		// Neutralise spreadsheet formulas in user-controlled strings.
		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'ki-kennzeichnung' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'kikz_export' );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_type = 'attachment' ORDER BY m.post_id ASC", KI_Kennzeichnung_Flags::META ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ki-kennzeichnung-nachweis-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'ID', 'Titel', 'Datei', 'URL', 'Quelle', 'Markiert am (UTC)', 'Erkennung (Score)', 'Signale', 'Einbrennen', 'Fehler', 'Eingebrannt am (UTC)' ), ';' );
		foreach ( (array) $ids as $id ) {
			$id     = (int) $id;
			$detect = get_post_meta( $id, '_ki_detect', true );
			$time   = (int) get_post_meta( $id, '_ki_flag_time', true );
			$stamp  = (int) get_post_meta( $id, '_ki_stamp_time', true );
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'csv_cell' ),
					array(
						$id,
						get_the_title( $id ),
						wp_basename( (string) get_attached_file( $id ) ),
						(string) wp_get_attachment_url( $id ),
						(string) get_post_meta( $id, '_ki_flag_source', true ),
						$time ? gmdate( 'Y-m-d H:i:s', $time ) : '',
						is_array( $detect ) ? (string) ( $detect['score'] ?? '' ) : '',
						is_array( $detect ) ? implode( ', ', (array) ( $detect['signals'] ?? array() ) ) : '',
						(string) get_post_meta( $id, KI_Kennzeichnung_Flags::META_STATUS, true ),
						(string) get_post_meta( $id, '_ki_stamp_error', true ),
						$stamp ? gmdate( 'Y-m-d H:i:s', $stamp ) : '',
					)
				),
				';'
			);
		}
		fclose( $out );
		exit;
	}
}
