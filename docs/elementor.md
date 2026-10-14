# Elementor-Integration

Alle Hook-Namen wurden gegen den Elementor-3.35-Quellcode geprüft und werden in `tests/integration/elementor.php` mit dem echten Renderer getestet.

## Was passiert beim Rendern

| Schritt | Hook | Wirkung |
|---|---|---|
| Container/Section/Spalte | `elementor/frontend/before_render` | liest per `get_settings_for_display()` alle `background*`-Controls (Desktop/Tablet/Mobil, Hover, Overlay, Slideshow, dynamische Tags), schlägt die Bild-ID nach und setzt `ki-flagged-bg` (+ `ki-auto`) und `data-kikz-id` am Wrapper (`_wrapper`) |
| Bild-/Bildbox-/CTA-/Testimonial-/Flip-Box-Widget | `elementor/frontend/before_render` | merkt sich die Bild-ID des Widgets, setzt `ki-flagged-widget` |
| Inhalt aller Widgets | `elementor/widget/render_content` | markiert `<img>` mit `wp-image-N`, per Upload-URL oder (bei benutzerdefinierter Größe) per Widget-Einstellung; markiert Inline-`background-image` |
| Bedienfeld | `elementor/element/{container,section,column,image,image-box}/…/after_section_end` | Abschnitt **Erweitert → KI-Kennzeichnung** (Automatisch/Immer/Nie) |

`elementor/frontend/before_render` läuft **bevor** Elementor das öffnende Tag ausgibt – nur deshalb landen Attribute am Wrapper. (Die 1.x-Version nutzte `elementor/container/before_render` und `add_render_attribute( 'wrapper', … )`; beides existiert in Elementor nicht, daher hatte sie nie eine Wirkung.)

## Warum das Label ein `::after` ist

Das Badge wird als absolut positioniertes `::after` des markierten Elements gezeichnet:

- kein zusätzliches Markup → Flex-/Grid-Layouts, Gap und Wrap bleiben unverändert;
- Container nutzen `::before` für das Hintergrund-Overlay, `::after` ist frei;
- die Position richtet sich nach den CSS-Variablen `--kikz-l/-t/-r/-b`. Das Skript setzt sie, wenn ein Bild schmaler ist als sein Container (typisch beim Bild-Widget mit Breite < 100 %), damit das Badge an der Bildecke sitzt und nicht an der Containerecke.

## Caches

Elementor speichert gerenderte Elemente (Element-Cache) und CSS. Beim Markieren/Entmarkieren und bei Änderungen am Badge-Stil ruft das Plugin gebündelt `files_manager->clear_cache()` auf. Elementors eigene Zuschnitte (`uploads/elementor/thumbs/`) tragen die Änderungszeit der Quelldatei im Namen und entstehen nach dem Stempeln von selbst neu.

## Manuell

- Gewünschtes Element: **Erweitert → KI-Kennzeichnung → Immer kennzeichnen**.
- Bild nicht aus der Mediathek (z. B. extern): dem Element die CSS-Klasse `ki-badge-anzeigen` geben.
- Eigener Seitenbereich ohne Elementor: `[ki-badge]` im positionierten Container.

## Grenzen

- Live-Änderungen im Editor-Panel rendert Elementor per JavaScript neu, ohne PHP: Das Label ist nach dem Neuladen der Vorschau sichtbar.
- Hintergründe aus Farbverlauf/Video ohne zugehöriges Bild werden nur mit „Immer kennzeichnen“ markiert.
- Elementor-Pro-Widgets mit eigener Bild-Ausgabe (Slides, Media Carousel …) werden über die `<img>`-/Inline-Style-Erkennung erfasst und wurden **nicht** gegen Elementor Pro getestet; bei ungewöhnlichem Markup hilft „Immer kennzeichnen“ am umgebenden Container oder die Seitenprüfung.
