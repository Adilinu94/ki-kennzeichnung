# KI-Kennzeichnung

WordPress-Plugin, das KI-generierte Bilder zuverlässig kennzeichnet – auch in **Elementor** (Container/Flexbox, Sections, Spalten, Bild-Widgets, Hintergrundbilder).

- Häkchen in der Mediathek, Sammelaktionen, Erkennung per Metadaten
- Badge **in der Datei** (jede Bildgröße einzeln) und/oder als **Overlay**, mit Absicherung gegen Zuschnitt
- Alt-Text-Zusatz, maschinenlesbarer IPTC-Vermerk, Footer-Hinweis
- Seitenprüfung und CSV-Nachweis

Voraussetzungen: WordPress ≥ 6.2, PHP ≥ 7.4, PHP-Erweiterung GD (für das Einbrennen; ohne GD arbeitet das Overlay allein).

## Kennzeichnungs-Modi

| Modus | Datei | Overlay | Gedacht für |
|---|---|---|---|
| **Automatisch** (Standard) | Badge eingebrannt | nur wo nötig | „immer gekennzeichnet“ |
| **Datei** | Badge eingebrannt | nur als Ersatz, wenn Einbrennen nicht möglich | sauberes Aussehen |
| **Overlay** | unverändert | immer | Originale unangetastet lassen |

**Warum nicht einfach nur einbrennen?** Ein eingebranntes Badge sitzt fest in einer Bildecke. Zeigt ein Elementor-Bild- oder Container-Hintergrund das Bild mit `object-fit: cover` / `background-size: cover` an, kann genau diese Ecke je nach Breakpoint abgeschnitten werden – oder das Bild wird so klein dargestellt, dass das Badge unlesbar wird. Im Modus „Automatisch“ berechnet ein kleines Skript für das tatsächlich angezeigte Bild, ob das eingebrannte Badge **vollständig sichtbar und lesbar** ist, und blendet das Overlay nur dann aus. Fehlt Skript oder Information, bleibt das Overlay sichtbar – im Zweifel gibt es lieber ein Badge zu viel als eins zu wenig.

Bilder, die nicht eingebrannt werden können (GIF, SVG, zu klein, zu wenig Speicher), fallen automatisch auf das Overlay zurück und sind in der Mediathek als „!“ markiert.

## Elementor

Die Integration liest die **Bild-ID aus den Elementor-Einstellungen** (nicht aus URLs) und erkennt:

- Container (Flexbox), Sections, Spalten: Hintergrundbild für Desktop/Tablet/Mobil, Hover, Overlay-Bild, Slideshow, dynamische Tags
- Bild, Bildbox, Call-to-Action, Testimonial, Flip-Box (auch mit benutzerdefinierter Bildgröße)
- Bilder in Text-Editor-/HTML-Widgets und Inline-`background-image`

Pro Element gibt es unter **Erweitert → KI-Kennzeichnung** die Wahl *Automatisch / Immer / Nie*. Details und Grenzen: [docs/elementor.md](docs/elementor.md).

## So funktioniert das Einbrennen

1. Das Original wird in `uploads/ki-kennzeichnung-originals/` gesichert (per `.htaccess` gesperrt).
2. Alle Bildgrößen werden **aus dem Original** neu erzeugt und einzeln gestempelt (Mindestgröße in Pixeln; bei sehr kleinen Bildern die Kurzform „KI“).
3. EXIF, ICC und IPTC bleiben erhalten; im XMP wird `DigitalSourceType = trainedAlgorithmicMedia` ergänzt (JPEG, PNG).
4. Das Stempeln läuft **vor** Bildoptimierern (WebP/AVIF-Kopien entstehen aus dem gestempelten Bild) und in einer Warteschlange – auch bei 500 Bildern gibt es keinen Timeout.
5. Entfernen der Markierung stellt das Original wieder her. Wurde die Datei nach dem Stempeln im WP-Bildeditor bearbeitet, fragt das Plugin vorher nach.

Ändert sich der Badge-Stil, wendet **„Alle neu stempeln“** ihn auf bestehende Bilder an (aus den gesicherten Originalen, ohne Qualitätsverlust durch Mehrfach-Kompression).

## Erkennung

- **Metadaten** (empfohlen): IPTC/C2PA `DigitalSourceType`, KI-Software (Midjourney, DALL·E, Firefly, Gemini …), Stable-Diffusion-/ComfyUI-Felder in PNGs. Ab Score 80 („sehr wahrscheinlich“) wird beim Upload automatisch markiert; Kandidaten ab 40 werden zur Prüfung angeboten. Es werden nur Metadaten-Bereiche durchsucht, nie die Pixeldaten.
- **Dateinamen**: nur ein Hinweis, auf ganzen Wörtern (`dalle` trifft `dalle-3.png`, nicht `dallemagne.jpg`). Treffer werden angezeigt und **nie ohne Bestätigung** markiert.

## Caching

Beim Einbrennen bleibt die Bild-URL gleich. Das Plugin leert deshalb gebündelt Seiten-Caches (WP Rocket, W3 Total Cache, LiteSpeed, WP Super Cache, SiteGround, WP Fastest Cache, Autoptimize) sowie Elementors CSS-/Element-Cache und löst `do_action( 'kikz_purge_caches' )` für CDN/Host-Caches aus. Besucher, die das alte Bild im Browser-Cache haben, sehen es bis zum Ablauf – deshalb bleibt das Overlay nach dem Stempeln noch 7 Tage aktiv (einstellbar).

## Datenschutz und Rechtliches

- Es werden **keine** Schriften oder Ressourcen von Drittanbietern geladen. (Die frühere Google-Fonts-Option ist entfernt: Das Nachladen von Google Fonts überträgt die IP-Adresse an Google und wurde in Deutschland abgemahnt/verurteilt.)
- Das Plugin ist ein technisches Werkzeug und **keine Rechtsberatung**. Welche Bilder du kennzeichnen musst und wie, hängt von Einzelfall und Rechtslage ab (u. a. Art. 50 KI-Verordnung, der vor allem bestimmte Inhalte wie Deepfakes betrifft). Das Plugin bewirbt sich daher bewusst nicht als „rechtssicher“. Lass die konkrete Pflicht für dein Angebot prüfen.

## Bekannte Grenzen

- Das Layout (Position und Anker des Overlays, Verhalten bei `cover`, kalter und gecachter Elementor-Aufruf) wird in einem echten **Chromium** getestet (Chrome 131 über das npm-Paket `@sparticuz/chromium`), nicht in Firefox oder Safari. Prüfe neue Layouts trotzdem einmal mit der **Seitenprüfung** und per Augenschein.
- Die unskalierte Originaldatei (`original_image`, z. B. `foto.jpg` neben `foto-scaled.jpg`) wird nicht gestempelt, bleibt aber unverlinkt.
- WebP/AVIF werden gestempelt, tragen aber keine Metadaten (nur JPEG/PNG). GIF/SVG: nur Overlay.
- Im Elementor-Editor erscheint das Label nach dem Neuladen der Vorschau, nicht bei jeder Live-Änderung.
- Hintergründe aus Farbverlauf oder Video werden nur gekennzeichnet, wenn das zugehörige Bild (Fallback/Overlay-Bild) als KI markiert ist – oder per „Immer kennzeichnen“.
- Bereits vorhandene WebP/AVIF-Kopien von Bildoptimierern aus der Zeit **vor** dem Stempeln werden nicht automatisch ersetzt: Bilder in dem Optimierer neu generieren.
- **nginx** ignoriert `.htaccess`: Sperre den Backup-Ordner serverseitig, z. B. `location ~ ^/wp-content/uploads/ki-kennzeichnung-originals/ { deny all; }`.

## Erweiterbarkeit

| Hook | Zweck |
|---|---|
| `kikz_detect_result` (Filter) | Erkennungsergebnis `{score, signals}` ergänzen |
| `kikz_audit_urls` (Filter) | URLs der Seitenprüfung anpassen |
| `kikz_purge_caches` (Action) | zusätzliche Cache-Ebenen leeren |

Öffentliche PHP-API (kompatibel zu 1.x): `KI_Kennzeichnung::is_flagged_public( $id )`, `::patterns_public()`, `::apply_file_label( $id, $flag )`. CSS-Klassen für eigene Stellen: `ki-badge-anzeigen` (Container), Shortcodes `[ki-badge]`, `[ki-hinweis]`.

## Deinstallation

Das Plugin entfernt seine Einstellungen und Metadaten. **Gestempelte Bilder und der Ordner mit den Originalen bleiben bewusst erhalten**; die Originale liegen als `{ID}-{Dateiname}` in `uploads/ki-kennzeichnung-originals/` (mit README) und können zurückkopiert werden. Wer die Bilder vorher zurücksetzen will, entfernt die Markierungen in der Mediathek.

## Entwicklung und Tests

```bash
npm install                      # nur für die JS-Tests (jsdom, css-tree)
npm test                         # Geometrie + DOM + CSS-Gültigkeit
php tests/test-image.php         # Stempel-Bibliothek ohne WordPress

# Integrations- und End-to-End-Tests gegen echtes WordPress + Elementor (SQLite, kein MySQL nötig)
bin/setup-test-env.sh /tmp/kikz-env
KIKZ_WP_LOAD=/tmp/kikz-env/wp/wp-load.php php tests/integration/run.php
KIKZ_WP_DIR=/tmp/kikz-env/wp tests/integration/e2e.sh

# Elementor-4-Elemente rendern und echter Browser: Elementors Twig und CSS aus den Quellen nachbauen
bin/build-elementor-assets.sh /tmp/kikz-env
KIKZ_WP_DIR=/tmp/kikz-env/wp tests/browser/run.sh      # lädt Chromium über npm, Screenshots in /tmp/kikz-shots
```

Das Setup-Skript klont WordPress, Elementor und die SQLite-Integration von GitHub (Versionen per `WP_VERSION`, `ELEMENTOR_VERSION` einstellbar). Release-Zip ohne Entwicklungsdateien: `.distignore` beachten.

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE). Mitgeliefert: DejaVu Sans (freie Bitstream-Vera-/DejaVu-Lizenz).
