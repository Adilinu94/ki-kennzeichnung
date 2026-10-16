# Changelog

## 2.0.1

Gefunden beim Test auf einer echten Seite (Elementor 4.3 Beta, PHP 8.4, „Modern Image Formats“).

- **Behoben: „Bild konnte nicht dekodiert werden“.** „Modern Image Formats“ macht aus einem hochgeladenen PNG/JPEG eine `.webp`-Datei, der Medientyp in WordPress bleibt aber `image/png`. Das Plugin vertraute dem Medientyp und versuchte, eine WebP-Datei als PNG zu lesen. Der Dateityp wird jetzt aus dem **Dateiinhalt** bestimmt (Ersatz: `imagecreatefromstring`). Betroffene Bilder: „Alle neu stempeln“ in den Einstellungen.
- **Behoben: Die Option war im Elementor-Editor nicht auffindbar.** Sie lag im Tab „Erweitert“ zwischen vielen Zusatz-Abschnitten. Jetzt steht sie dort, wo das Bild eingestellt wird: **Stil → Hintergrund** (Container, Section, Spalte) bzw. **Inhalt → Bild** (Bild, Bildbox), mit klareren Bezeichnungen.
- **Neu: Elementor-4-Container (`e-div-block`, `e-flexbox`, `e-grid`).** Deren Hintergrundbilder liegen in den typisierten `styles`, nicht in den Einstellungen, und wurden bisher nicht erkannt. Das atomare Bild-Widget `e-image` bekommt jetzt einen CSS-Anker. Erzwingen lässt sich das Badge dort über die CSS-Klasse `ki-badge-anzeigen`.
- CSS: `:has()`-Selektoren stehen in `:is()`, damit ein Browser ohne `:has()` nicht die ganze Regel verwirft.

## 2.0.0

Große Überarbeitung. Bestehende Einstellungen, Markierungen und Backups werden beim ersten Aufruf des Adminbereichs automatisch übernommen (1.x mit eingeschaltetem Einbrennen wird zum Modus „Automatisch“).

### Elementor (komplett neu)
- Die 1.x-Integration hat nie etwas bewirkt: Sie hing an Hooks, die es in Elementor nicht gibt (`elementor/container/before_render`, `elementor/widget/before_render`), und schrieb in ein falsches Render-Attribut. Die neue Integration nutzt die in Elementor 3.35 geprüften Hooks.
- Erkennung **per Bild-ID aus den Elementor-Einstellungen** statt per URL: Container (Flexbox), Sections, Spalten, Desktop/Tablet/Mobil-Hintergründe, Hover-, Overlay-Bilder, Hintergrund-Slideshows, dynamische Tags (z. B. „Beitragsbild“), Bild-/Bildbox-/CTA-Widgets (auch mit benutzerdefinierter Bildgröße), Bilder in Text-Editor-Widgets und Inline-`background-image`.
- Neues Bedienfeld „Erweitert → KI-Kennzeichnung“ (Automatisch / Immer / Nie) für Container, Sections, Spalten, Bild und Bildbox.
- Das Label hängt als `::after` am Element: kein zusätzliches Markup, kein Layout-Shift im Flex-Layout.

### Kennzeichnung „immer“ zuverlässig
- **Neuer Modus „Automatisch“**: Badge in der Datei + Overlay genau dort, wo das eingebrannte Badge abgeschnitten (`object-fit: cover`, `background-size: cover`) oder zu klein wäre. Ein kleines Skript prüft das; ohne Skript bleibt das Overlay einfach sichtbar.
- Jede Bildgröße wird **einzeln** gestempelt (Mindestgröße in px, Kurzform „KI“ bei sehr kleinen Bildern) statt eines mitverkleinerten Badges.
- Overlay bleibt nach dem Einbrennen 7 Tage aktiv (einstellbar), weil Browser/CDN noch das alte Bild haben können.
- Bilder im Beitragsinhalt (`wp-image-N`, Block-Editor, klassischer Editor) und per URL-Abgleich werden serverseitig erkannt (bisher: gar nicht).
- Fehler sind sichtbar: Status pro Bild (eingebrannt / Warteschlange / nur Overlay) in Mediathek und Einstellungen; nicht stempelbare Bilder (GIF, SVG, zu klein) fallen automatisch auf das Overlay zurück.
- Neue Seitenprüfung: ruft Seiten ab und kontrolliert, dass jedes markierte Bild und jeder Elementor-Hintergrund gekennzeichnet ausgeliefert wird.
- Maschinenlesbar: IPTC `DigitalSourceType = trainedAlgorithmicMedia` im XMP (JPEG, PNG); EXIF, ICC und IPTC bleiben erhalten.
- 9 Positionen, Randabstand, Stil „Hell/Dunkel“ wirkt jetzt tatsächlich auf Overlay **und** eingebranntes Badge.
- CSV-Nachweis (Quelle, Zeitpunkt, Erkennungs-Score, Status).

### Behoben
- Eingebrannte Badges: überlappende halbtransparente Formen ergaben dunklere Flecken; Kanten jetzt geglättet.
- Transparente PNGs verloren beim Stempeln den Alpha-Kanal.
- `original_image` (unskalierte Datei) und andere Metadaten gingen beim Neuerzeugen der Größen verloren.
- Wiederherstellen funktionierte nicht, wenn das Einbrennen zwischenzeitlich ausgeschaltet wurde.
- Wiederherstellen überschrieb nachträgliche Bearbeitungen im WP-Bildeditor ohne Rückfrage.
- JS-Fallback beobachtete jede `style`/`class`-Änderung der gesamten Seite (Performance, Endlosschleifen-Risiko) und verglich nur 200 Dateinamen. Ersetzt durch serverseitige Erkennung.
- Galerien bekamen zusätzlich ein Badge am Galerie-Container; Elementor-Bild-Widgets mit schmalerem Bild trugen das Badge an der falschen Ecke.
- Admin-Grid markierte nur die ersten 500 Bilder.
- Footer-Hinweis/CSS: doppeltes HTML-Escaping und falsches CSS-Escaping des Badge-Textes.
- Scan: Dateinamen-Muster trafen Teilstrings („imagen“ ↔ „imagenes“, „dalle“ ↔ „dallemagne“) und markierten **ohne Rückfrage**; Limit von 1000 Treffern ohne Paging.
- `uninstall.php` löschte die Zuordnung zu den Backups, ließ aber gestempelte Bilder zurück.

### Sicherheit & Datenschutz
- Alle Aktionen prüfen `edit_post` pro Bild (vorher nur `upload_files` für beliebige Anhänge).
- Backups liegen in einem geschützten Ordner (`uploads/ki-kennzeichnung-originals/`, `.htaccess`), nicht mehr öffentlich neben dem Bild; Backups werden beim Löschen des Bildes entfernt.
- Es werden keine Google Fonts mehr nachgeladen (DSGVO). Die Option „Google-Fonts-URL“ wird ignoriert und im Adminbereich erklärt.
- Farben und Schriftfamilie werden streng validiert, bevor sie ins CSS gelangen; CSV-Export neutralisiert Formeln.

### Technik
- Stempeln läuft in einer Warteschlange (Cron + Admin-Runner) statt in einem einzigen Request; Stempeln passiert vor Bildoptimierern (WebP/AVIF-Kopien entstehen aus dem gestempelten Bild).
- Cache-Purge (WP Rocket, W3TC, LiteSpeed, WP Super Cache, SG, WPFC, Autoptimize, Elementor-Cache/CSS) – gebündelt, ein Purge pro Stapel.
- Code in Klassen unter `includes/` aufgeteilt, CSS/JS als Dateien, Übersetzungsfunktionen + `.pot`.
- Tests: Bildbibliothek, Geometrie, DOM (jsdom), Integration gegen echtes WordPress + Elementor, E2E über HTTP; CI-Workflow.

## 1.3.0
Ausgangsstand.
