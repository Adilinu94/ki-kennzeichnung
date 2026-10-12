# Elementor-Integration: „KI-Kennzeichnung“-Control für Container, Flexbox & Bild

Detailierter Umbauplan für das Plugin **KI-Kennzeichnung** (1.3.0), kompatibel zu Elementor ≥3.10 (Container) und ≥3.18 (Flexbox-Widget).

## 1. Ziel & Scope

- In Elementors Container (`elementor-container`) und Flexbox-Widget (`flexbox`) sowie im Bild-Widget (`.elementor-widget-image`) jeweils einen Toggle „KI-generiertes Bild“ als Control hinzufügen.
- Bei aktivem Control werden automatisch CSS-Klasse `ki-flagged-bg` (Container/Flex) bzw. Markierung des Bild-Attachments (Bild) gesetzt.
- Frontend gibt Badger, Alt-Text-Suffix und Datei-Stempel (wenn burn-in) wie bei normalen Bildern aus.
- Keine externe Datenübertragung, GPLv2-konform.

## 2. Architektur (neue Dateien)

```
ki-kennzeichnung/
├── ki-kennzeichnung.php (Boot + includes-Loader)
├── includes/
│   ├── media-library.php           (bestehend, auslagern)
│   ├── settings.php               (bestehend, auslagern)
│   ├── frontend.php               (bestehend, auslagern)
│   ├── elementor/
│   │   ├── class-elementor-control.php
│   │   └── class-elementor-render.php
│   └── assets/
│       ├── admin-settings.css
│       ├── admin-media.js
│       ├── admin-settings.js
│       └── frontend-elementor.js
├── assets/
│   ├── css/ ...  und  js/
└── docs/
```

Modulare Einbindung: `require_once __DIR__ . '/includes/...'` nach Priorität im `init()` (z. B. `plugins_loaded`, priority 10), Elementor-Modul nur laden, wenn `defined('ELEMENTOR_VERSION')`.

## 3. Elementor-Hooks & zu nutzende APIs

### 3.1 Controls hinzufügen
- `add_control` über `elementor/element/container/section_layout/after_section_end` (Container) bzw. `elementor/element/flexbox/section_layout/after_section_end`.
- Bild-Widget über `elementor/element/image/section_image/after_section_end`.
- Control-Keys:
  - `_ki_container_flagged` (Switcher) – Container/Flexbox
  - `_ki_image_attachment` (Media-Upload) – Bild-Widget (ersetzt/ergänzt die Standard-Quelle)
  - `_ki_image_alt_suffix` (Text) – optional direkt im Builder editierbar

Implementierung:
```php
add_action('elementor/element/container/section_layout/after_section_end', function($element,$args){
  $element->add_control('ki_flagged_bg',['label'=>__('KI-generiert','ki-kennzeichnung'),'type'=>\Elementor\Controls_Manager::SWITCHER,'label_on'=>'Ja','label_off'=>'Nein','return_value'=>'yes','default'=>'','selectors'=>['{{WRAPPER}}'=>'position:relative;', '{{WRAPPER}}'=>'--ki-badge: var(--ki-badge-text, "KI-generiert");']]);
});
```

Empfohlen: Switcher direkt in „Layout“-Tab am Anfang (nicht eigenstabreiter Abschnitt) – weniger Klicks für User.

### 3.2 Render-Output beeinflussen
- Container/Flexbox: Klassen-Array via `elementor/element/parse_css_classes` oder einfach via `add_render_attribute('wrapper', 'class', 'ki-flagged-bg')` innerhalb `elementor/container/before_render`/`elementor/flexbox/before_render` – prüfen ob Klasse `_ki_container_flagged === yes` im Settings.
- Bild-Widget: `elementor/widget/image/before_render` bzw. Filter `elementor/widget/render/filter` um Bild-Attachment-ID aus dem Element zu extrahieren; setzen eines `data-ki-attachment`-Attributs; alternativ `elementor/widget/image/render` (alc.).

Wichtig: Elementor caches rendered markup & CSS; after Plugin-Update ggf. Cache clear (Warnung in Einstellungen).

### 3.3 Serverseitiges Boaed-to-media
Für Bild-Widget: Wenn `_ki_image_attachment` (Media-Control) ausgewählt, Attachment-ID steht im Element-Settings. `filter_image_attributes` kann nicht greifen, weil Elementor eigene Renderer hat. Deshalb in `elementor/widget/image/before_render`:
```php
$attachment_id = (int) $widget->get_settings_for_display('image')['id'];
if ($attachment_id && is_flagged($attachment_id)) {
  $widget->add_render_attribute('wrapper','class','ki-flagged-image-wrapper');
  $widget->add_render_attribute('image','class','ki-flagged-image');
  $widget->add_render_attribute('image','data-ki','1');
}
```
Placeholder/Badge: CSS `::after` am Bild-Wrapper (siehe print_styles).

## 4. CSS-Strategie (print_styles erweitern)

- Existierende `:has()`-Regel ergänzen durch stabile Klassen:
  - `.elementor-widget-image.ki-flagged-image-wrapper::after` Badge
  - `.elementor-container.ki-flagged-bg::after` Badge
  - `.e-con.ki-flagged-bg, .elementor-section.ki-flagged-bg::after`
  - `.flexbox.ki-flagged-bg::after`
- Farben & Position aus globalen Optionen (badge_text, theme, size, corner, custom color/font).
- Burn-in-File-Badge: weiter wie bisher, da Datei selbst gestempelt; Frontend-Klasse um Badge nicht doppelt zu zeigen (File-labeled → Flagge suppressed, wie `filter_image_attributes` schon macht).

## 5. Optionen ergänzen (Defaults & Register)

Neue Settings:
- `badge_color` (hex) – Hintergrundfarbe Badge
- `badge_text_color` (hex) – Schriftfarbe
- `badge_font` – Choice (System, Google Fonts) + `badge_font_url` (Google Fonts CSS-URL)
- `accent_corner` erweitern (4 Ecken statt 2)
- `elementor_cache_clear` (Button, admin-ajax)
- `metadata_scan` Toggle (für automatische KI-Erkennung)

Register in `register_settings`, rendern in bestehender Settings-Seite (Tabs).

## 6. Automatische KI-Erkennung (Metadaten)

Neue Funktion `scan_attachment_metadata(int $attachment_id): bool`:
- EXIF `Software` (image_exif_data) → regex ai, midjourney, …
- IPTC `SpecialInstructions` (`iptcparse`) → ContentCredentials/ C2PA marker
- XMP (SimpleXML) → `dc:creator` oder `cc:ai`-ähnliche Felder
- Bei Treffer: set attachment meta `_ki_generated = 1`, `apply_file_label`, falls burn-in.
- Aufruf: auf `wp_generate_attachment_metadata` Action-Hook (Upload) + manueller Button „Alle prüfen“ via AJAX (ähnlich `ajax_scan_names`, aber Metadaten).

Performance: Limit 200 Attachments pro Batch, Cron-basiert optional.

## 7. Hintergrundbilder-Markierung (JS Fallback + robust)

Aktuelle JS-Funktion `markBackgrounds` nur für inline-Styles. Erweiterung:
- Elementor-Container: CSS-Backgrounds werden via dynamische Klassen `_element_id` oft gesetzt – manuell via Editor-Class steuerbar; zusätzlich `MutationObserver` auf DOM, die `background-image` async liest und Klasse setzt.
- Divi: `.et_pb_section[style*=background-image]` etc.
- WooCommerce-Product-Slider: MutationObserver + `wc_variations` Event.

## 8. WooCommerce-Integration

- `woocommerce_single_product_image_gallery_classes` / `woocommerce_product_thumbnails` – apply `ki-flagged-image` to main & gallery images via `wp_get_attachment_image_attributes` (schon).
- Lightbox (Photoswipe): `photoswipe.js` Sort order JSON `ki`-Flag anhängen; bei open-event Overlay-Badge AJAX-konstruieren für gelabelte Dateien.
- Varianten-Ajax (`wc_variation_form`): after `found_variation` JS-Callback → `run()` erneut.

## 9. Sammel-Markierung & Preview-Bild

Bereits implementiert. Verbesserung:
- Preview-Bild zur Settings-DB statt Attachment-ID (cachebusting): URL via `wp_get_attachment_image_url(id,'large')` – bereits so.
- Bulk-Action in Medienliste nutzt AJAX-Loop; Hinzufügen: Bulk-Cron für Metadaten.

## 10. Migration / Backward Compatibility

- Option-Namen behalten (`kikz_*`), neue als Erweiterung.
- Attachment-Meta `_ki_generated` wie bisher; `_ki_file_labeled` unverändert.
- Elementor-Control-Werte werden im `_elementor_data` JSON gespeichert → beim Deinstallieren des KI-Plugins bleiben sie als ungenutzte Einstellungen (unschädlich, aber dokumentieren; Uninstall kann filtern).
- Uninstall script (`uninstall.php`) erweitern: entferne `_ki_file_labeled`, `_ki_original_file`, `_ki_generated`, aber nicht Elementor `ki_flagged_bg` (um Builder-JSON nicht zu brechen).

## 11. Tests / Verifikation

- `php -l ki-kennzeichnung.php` (und jede neue Include).
- JSON-Lint Elementor-Deklaration.
- Browser-Smoke: Elementor-Editor → Container toggle → Speichern → Frontend prüfen Badge; unmarkieren → Badge weg; Burn-in on → Datei geändert; Burn-in off → JS-Badge; Preview-Image waehlen.
- WooCommerce: Produktbild Variante wechseln → Badge bleibt.

## 12. Implementierungs-Reihenfolge (Vorschlag)

1. Modulare Refaktorierung (keine Verhaltensänderung) – `includes/`, `assets/`.
2. Elementor-Control für Bild-Widget + CSS-Badge (MVP, 1 Tag).
3. Container/Flexbox-Control + `ki-flagged-bg` Klasse (2 Tage).
4. Farb-/Schriftart-Optionen & Google Fonts (1 Tag).
5. WooCommerce-Gallery + Lightbox (2 Tage).
6. Metadaten-Scan als AJAX/Cron (2 Tage).
7. Documentation & README-Changelog (0.5 Tag).

**Gesamt ca. 8-10 Tage Entwicklung.**

## 13. Risiken & Abhängigkeiten

- Elementor API ändert sich; Controls `{{WRAPPER}}`-Syntax bei Container etwas anders (keine Standard Section).
- `has()`-CSS: ältere Browser → Fallback-Klassen via JS nötig.
- Metadaten-Scan kann falsch positiv sein (z. B. Kameras „Software: Adobe Lightroom“) → Threshold/Whitelist.
- Burn-in auf Datei = irreversible Opt-in; Backup-Sicherung beibehalten.

---

Wenn du möchtest, starte ich mit Schritt 1 (Modularisierung + PHP-Lint-Check) als ersten Commit-stiligen Änderungsblock, dann Elementor-Bild-Widget MVP. Sag mir, bei welchem Schritt ich anfangen soll.