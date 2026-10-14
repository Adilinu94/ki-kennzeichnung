<?php
/**
 * Settings for KI-Kennzeichnung: defaults, validation, colour helpers and the
 * settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KI_Kennzeichnung_Settings {

	public const POSITIONS = array(
		'top-left',
		'top-center',
		'top-right',
		'middle-left',
		'center',
		'middle-right',
		'bottom-left',
		'bottom-center',
		'bottom-right',
	);

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'plugin_action_links_' . KIKZ_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'update_option_kikz_display_mode', array( __CLASS__, 'on_mode_change' ), 10, 2 );
	}

	/**
	 * Option defaults.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'badge_text'            => 'KI-generiert',
			'alt_suffix'            => 'KI-generiert',
			'footnote'              => 'Hinweis: Einzelne Bilder auf dieser Website wurden mit KI erstellt.',
			'footnote_on'           => '1',
			'display_mode'          => 'auto',
			'burn_in'               => '1',
			'badge_size'            => 'medium',
			'badge_corner'          => 'bottom-right',
			'badge_theme'           => 'dark',
			'badge_short'           => '0',
			'badge_color'           => '',
			'badge_bg_color'        => '',
			'badge_font'            => '',
			'badge_google_font_url' => '',
			'badge_margin'          => '10',
			'grace_days'            => '7',
			'auto_detect'           => '1',
			'patterns'              => 'chatgpt, dall-e, dalle, midjourney, stable-diffusion, firefly, imagen, flux-dev, ai-generated, ki-generiert',
			'preview_image'         => '0',
		);
	}

	/**
	 * Read one plugin option with its default.
	 */
	public static function get( string $key ): string {
		$defaults = self::defaults();
		$value    = get_option( 'kikz_' . $key, $defaults[ $key ] ?? '' );
		if ( false === $value || null === $value ) {
			$value = $defaults[ $key ] ?? '';
		}
		return (string) $value;
	}

	/**
	 * Active display mode: auto | file | overlay.
	 */
	public static function mode(): string {
		$mode = self::get( 'display_mode' );
		return in_array( $mode, array( 'auto', 'file', 'overlay' ), true ) ? $mode : 'auto';
	}

	/**
	 * Whether files are stamped (burn-in) in the current mode.
	 */
	public static function stamping_enabled(): bool {
		return 'overlay' !== self::mode();
	}

	public static function position(): string {
		$pos = self::get( 'badge_corner' );
		return in_array( $pos, self::POSITIONS, true ) ? $pos : 'bottom-right';
	}

	public static function label(): string {
		return '1' === self::get( 'badge_short' ) ? 'KI' : self::get( 'badge_text' );
	}

	public static function margin(): int {
		return max( 0, min( 60, (int) self::get( 'badge_margin' ) ) );
	}

	public static function grace_seconds(): int {
		return max( 0, min( 90, (int) self::get( 'grace_days' ) ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Font size in CSS pixels for the overlay badge.
	 */
	public static function overlay_font_px(): int {
		$map = array( 'small' => 11, 'medium' => 12, 'large' => 14 );
		return $map[ self::get( 'badge_size' ) ] ?? 12;
	}

	/**
	 * Burn-in parameters per size option.
	 *
	 * @return array{factor: float, min_px: int}
	 */
	public static function stamp_size(): array {
		$map = array(
			'small'  => array( 'factor' => 0.0075, 'min_px' => 10 ),
			'medium' => array( 'factor' => 0.0100, 'min_px' => 11 ),
			'large'  => array( 'factor' => 0.0140, 'min_px' => 13 ),
		);
		return $map[ self::get( 'badge_size' ) ] ?? $map['medium'];
	}

	/**
	 * Resolved badge colours (CSS strings), preset by theme unless customised.
	 *
	 * @return array{bg: string, fg: string}
	 */
	public static function colors(): array {
		$presets = array(
			'dark'  => array( 'bg' => 'rgba(0,0,0,.65)', 'fg' => '#ffffff' ),
			'light' => array( 'bg' => 'rgba(255,255,255,.88)', 'fg' => '#1d2327' ),
		);
		$theme  = 'light' === self::get( 'badge_theme' ) ? 'light' : 'dark';
		$bg     = self::sanitize_color( self::get( 'badge_bg_color' ) );
		$fg     = self::sanitize_color( self::get( 'badge_color' ) );
		$norm   = static function ( string $v ): string {
			return strtolower( str_replace( array( ' ', '0.' ), array( '', '.' ), $v ) );
		};
		// Values equal to the legacy hard defaults count as "not customised".
		if ( '' === $bg || 'rgba(0,0,0,.65)' === $norm( $bg ) ) {
			$bg = $presets[ $theme ]['bg'];
		}
		if ( '' === $fg || '#ffffff' === strtolower( $fg ) ) {
			$fg = $presets[ $theme ]['fg'];
		}
		return array( 'bg' => $bg, 'fg' => $fg );
	}

	/**
	 * Parse a CSS colour (hex, rgb(), rgba()) into [r, g, b, a(0..1)].
	 *
	 * @return array{0:int,1:int,2:int,3:float}|null
	 */
	public static function parse_color( string $value ): ?array {
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m ) ) {
			$h = $m[1];
			if ( strlen( $h ) <= 4 ) {
				$h = implode( '', array_map( static function ( $c ) {
					return $c . $c;
				}, str_split( $h ) ) );
			}
			$a = 8 === strlen( $h ) ? round( hexdec( substr( $h, 6, 2 ) ) / 255, 3 ) : 1.0;
			return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), (float) $a );
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*(?:[,\/]\s*(\d*\.?\d+%?))?\s*\)$/', $value, $m ) ) {
			$a = 1.0;
			if ( isset( $m[4] ) && '' !== $m[4] ) {
				$a = '%' === substr( $m[4], -1 ) ? (float) $m[4] / 100 : (float) $m[4];
			}
			return array( min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ), max( 0.0, min( 1.0, $a ) ) );
		}
		return null;
	}

	/**
	 * Return a safe CSS colour string or an empty string.
	 */
	public static function sanitize_color( $value ): string {
		$value = trim( (string) $value );
		return null === self::parse_color( $value ) ? '' : $value;
	}

	/**
	 * Convert a CSS colour to GD values (alpha 0 = opaque .. 127 = transparent).
	 *
	 * @return array{0:int,1:int,2:int,3:int}
	 */
	public static function gd_color( string $css, array $fallback ): array {
		$c = self::parse_color( $css );
		if ( null === $c ) {
			return $fallback;
		}
		return array( $c[0], $c[1], $c[2], (int) round( ( 1 - $c[3] ) * 127 ) );
	}

	/**
	 * Escape a string for use inside a CSS string literal.
	 */
	public static function css_string( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', ' ' ), (string) $value );
	}

	/**
	 * Safe font-family value (empty = inherit from the page).
	 */
	public static function sanitize_font_family( $value ): string {
		$value = trim( (string) $value );
		if ( 'system' === $value ) {
			return '';
		}
		return trim( (string) preg_replace( '/[^A-Za-z0-9 ,\'"_-]/', '', $value ) );
	}

	/**
	 * Search patterns for the file-name scan.
	 *
	 * @return string[]
	 */
	public static function patterns(): array {
		$parts = array_filter( array_map( 'trim', explode( ',', self::get( 'patterns' ) ) ) );
		if ( empty( $parts ) ) {
			$parts = array( 'chatgpt', 'dall-e', 'midjourney' );
		}
		return array_values( array_unique( array_map( 'strtolower', $parts ) ) );
	}

	// ---------------------------------------------------------------------
	// Registration and upgrade.
	// ---------------------------------------------------------------------

	public static function register(): void {
		$text = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		foreach ( array( 'badge_text', 'alt_suffix', 'footnote', 'patterns' ) as $key ) {
			register_setting( 'kikz', 'kikz_' . $key, $text );
		}
		foreach ( array( 'footnote_on', 'badge_short', 'auto_detect' ) as $key ) {
			register_setting( 'kikz', 'kikz_' . $key, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_flag' ) ) );
		}
		register_setting( 'kikz', 'kikz_display_mode', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ) ) );
		register_setting( 'kikz', 'kikz_badge_size', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_size' ) ) );
		register_setting( 'kikz', 'kikz_badge_corner', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_position' ) ) );
		register_setting( 'kikz', 'kikz_badge_theme', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_theme' ) ) );
		register_setting( 'kikz', 'kikz_badge_color', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_color' ) ) );
		register_setting( 'kikz', 'kikz_badge_bg_color', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_color' ) ) );
		register_setting( 'kikz', 'kikz_badge_font', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_font_family' ) ) );
		register_setting( 'kikz', 'kikz_badge_margin', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_margin' ) ) );
		register_setting( 'kikz', 'kikz_grace_days', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_grace' ) ) );
		register_setting( 'kikz', 'kikz_preview_image', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
	}

	public static function sanitize_flag( $v ): string {
		return '1' === (string) $v ? '1' : '0';
	}

	public static function sanitize_mode( $v ): string {
		return in_array( $v, array( 'auto', 'file', 'overlay' ), true ) ? $v : 'auto';
	}

	public static function sanitize_size( $v ): string {
		return in_array( $v, array( 'small', 'medium', 'large' ), true ) ? $v : 'medium';
	}

	public static function sanitize_position( $v ): string {
		return in_array( $v, self::POSITIONS, true ) ? $v : 'bottom-right';
	}

	public static function sanitize_theme( $v ): string {
		return 'light' === $v ? 'light' : 'dark';
	}

	public static function sanitize_margin( $v ): string {
		return (string) max( 0, min( 60, (int) $v ) );
	}

	public static function sanitize_grace( $v ): string {
		return (string) max( 0, min( 90, (int) $v ) );
	}

	/**
	 * One-time migration from 1.x options.
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( 'kikz_db_version', '0' ), KIKZ_VERSION, '>=' ) ) {
			return;
		}
		if ( false === get_option( 'kikz_display_mode', false ) ) {
			// 1.x: burn-in on = file stamping; keep that and add the safety overlay.
			update_option( 'kikz_display_mode', '0' === (string) get_option( 'kikz_burn_in', '1' ) ? 'overlay' : 'auto', false );
		}
		KI_Kennzeichnung_Flags::rebuild_stem_map();
		KI_Kennzeichnung_Stamper::migrate_legacy_backups();
		update_option( 'kikz_db_version', KIKZ_VERSION, false );
	}

	/**
	 * Overlay -> file/auto: images flagged in the meantime still have clean files.
	 * Stamp them in the background so the chosen mode is actually in effect.
	 */
	public static function on_mode_change( $old, $new ): void {
		if ( 'overlay' === $old && in_array( $new, array( 'auto', 'file' ), true ) ) {
			KI_Kennzeichnung_Stamper::enqueue_restamp_all();
		}
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=ki-kennzeichnung' ) ) . '">' . esc_html__( 'Einstellungen', 'ki-kennzeichnung' ) . '</a>' );
		return $links;
	}

	public static function add_page(): void {
		add_options_page( 'KI-Kennzeichnung', 'KI-Kennzeichnung', 'manage_options', 'ki-kennzeichnung', array( __CLASS__, 'render_page' ) );
	}

	// ---------------------------------------------------------------------
	// Settings page.
	// ---------------------------------------------------------------------

	private static function select( string $name, array $options, string $current, string $extra = '' ): string {
		$html = '<select class="kikz-input" name="kikz_' . esc_attr( $name ) . '" id="kikz_' . esc_attr( $name ) . '" ' . $extra . '>';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function switch_field( string $name, string $label ): void {
		?>
		<label class="kikz-switch">
			<input type="hidden" name="kikz_<?php echo esc_attr( $name ); ?>" value="0">
			<input type="checkbox" name="kikz_<?php echo esc_attr( $name ); ?>" id="kikz_<?php echo esc_attr( $name ); ?>" value="1" <?php checked( self::get( $name ), '1' ); ?>>
			<span class="kikz-track" aria-hidden="true"></span>
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		$preview_id = (int) get_option( 'kikz_preview_image', 0 );
		$stats      = KI_Kennzeichnung_Flags::stats();
		$positions  = array(
			'top-left'      => __( 'Oben links', 'ki-kennzeichnung' ),
			'top-center'    => __( 'Oben Mitte', 'ki-kennzeichnung' ),
			'top-right'     => __( 'Oben rechts', 'ki-kennzeichnung' ),
			'middle-left'   => __( 'Mitte links', 'ki-kennzeichnung' ),
			'center'        => __( 'Zentriert', 'ki-kennzeichnung' ),
			'middle-right'  => __( 'Mitte rechts', 'ki-kennzeichnung' ),
			'bottom-left'   => __( 'Unten links', 'ki-kennzeichnung' ),
			'bottom-center' => __( 'Unten Mitte', 'ki-kennzeichnung' ),
			'bottom-right'  => __( 'Unten rechts', 'ki-kennzeichnung' ),
		);
		$colors = self::colors();
		?>
		<div class="wrap kikz-wrap">
			<div class="kikz-header">
				<div class="kikz-logo">KI</div>
				<div>
					<h1>KI-Kennzeichnung <span class="kikz-version">v<?php echo esc_html( KIKZ_VERSION ); ?></span></h1>
					<p><?php esc_html_e( 'KI-Bilder zuverlässig kennzeichnen: Badge am Bild, Alt-Text-Zusatz, maschinenlesbare Metadaten und Hinweis auf der Website.', 'ki-kennzeichnung' ); ?></p>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php if ( '' !== trim( self::get( 'badge_google_font_url' ) ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Die frühere Google-Fonts-URL wird nicht mehr geladen (Datenschutz: Übertragung der IP-Adresse an Google). Trage stattdessen unter „Schriftfamilie“ eine lokal eingebundene oder System-Schrift ein.', 'ki-kennzeichnung' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'kikz' ); ?>
				<div class="kikz-grid">
					<div>
						<div class="kikz-card">
							<h2><?php esc_html_e( 'Darstellung', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Diese Texte und Einstellungen gelten für Badge und Alt-Text.', 'ki-kennzeichnung' ); ?></p>

							<div class="kikz-field">
								<label for="kikz_badge_text"><?php esc_html_e( 'Badge-Text', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_badge_text" id="kikz_badge_text" type="text" value="<?php echo esc_attr( self::get( 'badge_text' ) ); ?>">
							</div>
							<div class="kikz-field">
								<?php self::switch_field( 'badge_short', __( 'Nur Kurzform „KI“ anzeigen', 'ki-kennzeichnung' ) ); ?>
							</div>
							<div class="kikz-field">
								<label for="kikz_alt_suffix"><?php esc_html_e( 'Alt-Text-Zusatz', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_alt_suffix" id="kikz_alt_suffix" type="text" value="<?php echo esc_attr( self::get( 'alt_suffix' ) ); ?>">
								<p class="kikz-hint" style="margin:8px 0 0;"><?php esc_html_e( 'Wird in Klammern an vorhandene Alt-Texte angehängt. Dekorative Bilder (leerer Alt-Text) bleiben unverändert.', 'ki-kennzeichnung' ); ?></p>
							</div>

							<div class="kikz-field">
								<label for="kikz_display_mode"><?php esc_html_e( 'Kennzeichnungs-Modus', 'ki-kennzeichnung' ); ?></label>
								<?php
								echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput
									'display_mode',
									array(
										'auto'    => __( 'Automatisch (empfohlen): Badge in die Datei + Overlay, wo das Badge abgeschnitten oder zu klein wäre', 'ki-kennzeichnung' ),
										'file'    => __( 'Nur in die Datei einbrennen (Overlay nur als Ersatz, wenn das Einbrennen nicht möglich ist)', 'ki-kennzeichnung' ),
										'overlay' => __( 'Nur Overlay über dem Bild (Datei bleibt unverändert)', 'ki-kennzeichnung' ),
									),
									self::mode()
								);
								?>
								<p class="kikz-hint" style="margin:8px 0 0;"><?php esc_html_e( 'Ein eingebranntes Badge überlebt Download, Teilen und Lightbox, kann aber durch Zuschnitt (Object-Fit „Cover“, Hintergrundbilder) verschwinden. Im automatischen Modus prüft ein kleines Skript das und blendet das Overlay nur dann ein, wenn es nötig ist.', 'ki-kennzeichnung' ); ?></p>
							</div>

							<div class="kikz-field">
								<span style="display:block;font-weight:600;margin-bottom:6px;"><?php esc_html_e( 'Badge-Stil', 'ki-kennzeichnung' ); ?></span>
								<div style="display:flex;gap:18px;flex-wrap:wrap;">
									<label style="display:block;"><span class="kikz-sub"><?php esc_html_e( 'Größe', 'ki-kennzeichnung' ); ?></span>
										<?php echo self::select( 'badge_size', array( 'small' => __( 'Klein', 'ki-kennzeichnung' ), 'medium' => __( 'Mittel', 'ki-kennzeichnung' ), 'large' => __( 'Groß', 'ki-kennzeichnung' ) ), self::get( 'badge_size' ), 'style="max-width:150px;"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</label>
									<label style="display:block;"><span class="kikz-sub"><?php esc_html_e( 'Position', 'ki-kennzeichnung' ); ?></span>
										<?php echo self::select( 'badge_corner', $positions, self::position(), 'style="max-width:170px;"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</label>
									<label style="display:block;"><span class="kikz-sub"><?php esc_html_e( 'Stil', 'ki-kennzeichnung' ); ?></span>
										<?php echo self::select( 'badge_theme', array( 'dark' => __( 'Dunkel', 'ki-kennzeichnung' ), 'light' => __( 'Hell', 'ki-kennzeichnung' ) ), self::get( 'badge_theme' ), 'style="max-width:150px;"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</label>
									<label style="display:block;"><span class="kikz-sub"><?php esc_html_e( 'Randabstand (px)', 'ki-kennzeichnung' ); ?></span>
										<input class="kikz-input" type="number" min="0" max="60" name="kikz_badge_margin" id="kikz_badge_margin" value="<?php echo esc_attr( (string) self::margin() ); ?>" style="max-width:110px;">
									</label>
								</div>
							</div>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Farben & Schrift', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Leer lassen, um die Farben des gewählten Stils zu verwenden. Erlaubt: Hex (#rrggbb, #rrggbbaa) oder rgb()/rgba().', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-field">
								<label for="kikz_badge_color"><?php esc_html_e( 'Schriftfarbe', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_badge_color" id="kikz_badge_color" type="text" placeholder="<?php echo esc_attr( $colors['fg'] ); ?>" value="<?php echo esc_attr( self::sanitize_color( self::get( 'badge_color' ) ) ); ?>">
							</div>
							<div class="kikz-field">
								<label for="kikz_badge_bg_color"><?php esc_html_e( 'Hintergrund', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_badge_bg_color" id="kikz_badge_bg_color" type="text" placeholder="<?php echo esc_attr( $colors['bg'] ); ?>" value="<?php echo esc_attr( self::sanitize_color( self::get( 'badge_bg_color' ) ) ); ?>">
							</div>
							<div class="kikz-field">
								<label for="kikz_badge_font"><?php esc_html_e( 'Schriftfamilie (nur Overlay)', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_badge_font" id="kikz_badge_font" type="text" placeholder="inherit" value="<?php echo esc_attr( self::sanitize_font_family( self::get( 'badge_font' ) ) ); ?>">
								<p class="kikz-hint" style="margin:8px 0 0;"><?php esc_html_e( 'Beispiel: Inter, system-ui, sans-serif. Es werden keine Schriften von Drittanbietern nachgeladen; nutze eine Schrift, die dein Theme bereits lokal ausliefert. Das eingebrannte Badge verwendet die mitgelieferte DejaVu Sans.', 'ki-kennzeichnung' ); ?></p>
							</div>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Footer-Hinweis', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Ergänzend zur Kennzeichnung am Bild, abschaltbar.', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-field"><?php self::switch_field( 'footnote_on', __( 'Hinweis automatisch im Footer anzeigen', 'ki-kennzeichnung' ) ); ?></div>
							<div class="kikz-field">
								<label for="kikz_footnote"><?php esc_html_e( 'Hinweis-Text', 'ki-kennzeichnung' ); ?></label>
								<textarea class="kikz-input" name="kikz_footnote" id="kikz_footnote" rows="2"><?php echo esc_textarea( self::get( 'footnote' ) ); ?></textarea>
							</div>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Elementor, Hintergrundbilder & Shortcodes', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint" style="margin-bottom:0;">
								<?php esc_html_e( 'Elementor-Container, Flexbox-Container, Sections und Bild-Widgets werden automatisch erkannt: Das Plugin liest die Bild-ID aus den Elementor-Einstellungen (inkl. Tablet/Mobil, Hover, Overlay, Slideshow und dynamischem Beitragsbild). Unter „Erweitert → KI-Kennzeichnung“ kannst du pro Element „Immer“ oder „Nie“ erzwingen. Für andere Stellen: Shortcode [ki-badge] oder CSS-Klasse ki-badge-anzeigen.', 'ki-kennzeichnung' ); ?>
							</p>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Erkennung', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Metadaten (C2PA, IPTC DigitalSourceType, KI-Software, Prompt-Felder) sind das verlässlichste Signal. Dateinamen sind nur ein Hinweis und werden nie ohne Bestätigung markiert.', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-field"><?php self::switch_field( 'auto_detect', __( 'Beim Upload automatisch per Metadaten erkennen (ab „sehr wahrscheinlich“)', 'ki-kennzeichnung' ) ); ?></div>
							<div class="kikz-actions" style="margin:0 0 6px;">
								<button type="button" class="button" id="kikz-meta-scan"><?php esc_html_e( 'Mediathek per Metadaten prüfen', 'ki-kennzeichnung' ); ?></button>
							</div>
							<div id="kikz-meta-result" class="kikz-hint" style="margin:8px 0 18px;"></div>

							<div class="kikz-field">
								<label for="kikz_patterns"><?php esc_html_e( 'Dateinamen-Muster (Komma-getrennt, ganze Wörter)', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" name="kikz_patterns" id="kikz_patterns" type="text" value="<?php echo esc_attr( self::get( 'patterns' ) ); ?>">
							</div>
							<div class="kikz-actions" style="margin:0;">
								<button type="button" class="button" id="kikz-scan"><?php esc_html_e( 'Nach Dateinamen suchen', 'ki-kennzeichnung' ); ?></button>
								<button type="button" class="button button-primary" id="kikz-mark-matches" style="display:none;"><?php esc_html_e( 'Ausgewählte markieren', 'ki-kennzeichnung' ); ?></button>
							</div>
							<div id="kikz-scan-result" class="kikz-hint" style="margin-top:12px;"></div>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Cache', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Nach dem Einbrennen bleibt die Bild-URL gleich. Bereits besuchte Seiten können das alte Bild im Browser-Cache haben. Deshalb bleibt das Overlay nach dem Stempeln noch einige Tage aktiv.', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-field">
								<label for="kikz_grace_days"><?php esc_html_e( 'Overlay nach dem Einbrennen beibehalten (Tage, 0 = aus)', 'ki-kennzeichnung' ); ?></label>
								<input class="kikz-input" type="number" min="0" max="90" name="kikz_grace_days" id="kikz_grace_days" value="<?php echo esc_attr( (string) ( self::grace_seconds() / DAY_IN_SECONDS ) ); ?>" style="max-width:110px;">
							</div>
						</div>
					</div>

					<div>
						<div class="kikz-card">
							<h2><?php esc_html_e( 'Live-Vorschau', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Wähle ein Bild aus der Mediathek, um die Kennzeichnung darauf live zu sehen.', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-actions" style="margin:0 0 14px;">
								<button type="button" class="button" id="kikz-pick-image"><?php esc_html_e( 'Bild auswählen', 'ki-kennzeichnung' ); ?></button>
								<button type="button" class="button" id="kikz-clear-image"<?php echo $preview_id > 0 ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Entfernen', 'ki-kennzeichnung' ); ?></button>
								<input type="hidden" name="kikz_preview_image" id="kikz_preview_image" value="<?php echo esc_attr( (string) $preview_id ); ?>">
							</div>
							<div class="kikz-preview<?php echo '1' === self::get( 'footnote_on' ) ? ' show-notice' : ''; ?><?php echo $preview_id > 0 ? ' has-image' : ''; ?>" id="kikz-preview"
								data-bg="<?php echo esc_attr( $colors['bg'] ); ?>" data-fg="<?php echo esc_attr( $colors['fg'] ); ?>">
								<?php if ( $preview_id > 0 ) : ?>
									<img id="kikz-preview-img" src="<?php echo esc_url( (string) wp_get_attachment_image_url( $preview_id, 'large' ) ); ?>" alt="">
								<?php else : ?>
									<span id="kikz-preview-placeholder"><?php esc_html_e( 'Bildbereich', 'ki-kennzeichnung' ); ?></span>
									<img id="kikz-preview-img" src="" alt="" style="display:none;">
								<?php endif; ?>
								<span class="kikz-badge-demo" id="kikz-badge-demo"><?php echo esc_html( self::label() ); ?></span>
								<div class="kikz-notice-demo" id="kikz-notice-demo"><?php echo esc_html( self::get( 'footnote' ) ); ?></div>
							</div>
						</div>

						<div class="kikz-card" id="kikz-status-card">
							<h2><?php esc_html_e( 'Status', 'ki-kennzeichnung' ); ?></h2>
							<div class="kikz-stat">
								<div><strong id="kikz-count"><?php echo (int) $stats['flagged']; ?></strong><span><?php esc_html_e( 'markierte Bilder', 'ki-kennzeichnung' ); ?></span></div>
								<div><strong id="kikz-stamped"><?php echo (int) $stats['stamped']; ?></strong><span><?php esc_html_e( 'eingebrannt', 'ki-kennzeichnung' ); ?></span></div>
								<div><strong id="kikz-pending"><?php echo (int) $stats['pending']; ?></strong><span><?php esc_html_e( 'in Warteschlange', 'ki-kennzeichnung' ); ?></span></div>
								<div><strong id="kikz-failed"><?php echo (int) $stats['failed']; ?></strong><span><?php esc_html_e( 'nur Overlay', 'ki-kennzeichnung' ); ?></span></div>
							</div>
							<div id="kikz-queue-msg" class="kikz-hint" style="margin:12px 0 0;"></div>
							<div class="kikz-actions" style="margin:14px 0 0;flex-wrap:wrap;">
								<a class="button" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&kikz_filter=marked' ) ); ?>"><?php esc_html_e( 'In der Mediathek', 'ki-kennzeichnung' ); ?></a>
								<button type="button" class="button" id="kikz-run-queue"><?php esc_html_e( 'Warteschlange verarbeiten', 'ki-kennzeichnung' ); ?></button>
								<button type="button" class="button" id="kikz-restamp"><?php esc_html_e( 'Alle neu stempeln', 'ki-kennzeichnung' ); ?></button>
								<?php if ( ! self::stamping_enabled() && (int) $stats['stamped'] > 0 ) : ?>
									<button type="button" class="button" id="kikz-unstamp"><?php esc_html_e( 'Eingebrannte Badges entfernen', 'ki-kennzeichnung' ); ?></button>
								<?php endif; ?>
							</div>
							<p class="kikz-hint" style="margin:10px 0 0;"><?php esc_html_e( '„Alle neu stempeln“ wendet geänderte Badge-Einstellungen an und stempelt markierte Bilder, die noch keine eingebrannte Kennzeichnung haben (aus den gesicherten Originalen, ohne Qualitätsverlust). Im Modus „Overlay“ bleiben bereits eingebrannte Badges bestehen, bis du sie hier entfernst.', 'ki-kennzeichnung' ); ?></p>
						</div>

						<div class="kikz-card">
							<h2><?php esc_html_e( 'Prüfung & Nachweis', 'ki-kennzeichnung' ); ?></h2>
							<p class="kikz-hint"><?php esc_html_e( 'Die Seitenprüfung ruft deine Seiten ab und kontrolliert, ob jedes markierte Bild und jeder Elementor-Hintergrund tatsächlich gekennzeichnet ausgeliefert wird.', 'ki-kennzeichnung' ); ?></p>
							<div class="kikz-actions" style="margin:0;flex-wrap:wrap;">
								<button type="button" class="button" id="kikz-audit"><?php esc_html_e( 'Seitenprüfung starten', 'ki-kennzeichnung' ); ?></button>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kikz_export' ), 'kikz_export' ) ); ?>"><?php esc_html_e( 'Nachweis als CSV', 'ki-kennzeichnung' ); ?></a>
								<?php if ( defined( 'ELEMENTOR_VERSION' ) ) : ?>
									<button type="button" class="button" id="kikz-clear-elementor"><?php esc_html_e( 'Elementor-Cache leeren', 'ki-kennzeichnung' ); ?></button>
								<?php endif; ?>
							</div>
							<div id="kikz-audit-result" class="kikz-hint" style="margin-top:12px;"></div>
						</div>
					</div>
				</div>

				<div class="kikz-actions"><?php submit_button( __( 'Änderungen speichern', 'ki-kennzeichnung' ), 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<?php
	}
}
