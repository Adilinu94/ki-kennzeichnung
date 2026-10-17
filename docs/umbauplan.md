# Umbauplan KI-Kennzeichnung 2.x

Vier Phasen. Jede Phase liefert für sich einen verkaufbaren Nutzen. Der Plan ist eine Arbeitsgrundlage, keine Zusage.

## Ausgangslage

Das Plugin kennzeichnet KI-Bilder heute zuverlässig: Checkbox und Sammelaktionen in der Mediathek, Erkennung über Metadaten und Dateinamen, Badge eingebrannt in jede Bildgröße und/oder als Overlay, ein Automatik-Modus, der pro Bild entscheidet, Alt-Text-Zusatz, IPTC DigitalSourceType in JPEG und PNG, Footer-Hinweis, Seitenprüfung, CSV-Nachweis, Elementor-Integration, WooCommerce.

Der Markt und die Rechtslage haben sich bewegt:

- Der Code of Practice der EU (Juni 2026) beschreibt zwei Dinge getrennt: maschinenlesbare Metadaten, die der KI-Anbieter setzt, und die sichtbare Offenlegung, die der Website-Betreiber macht. Für die sichtbare Offenlegung nennt er die offiziellen EU-Symbole.
- Die Konkurrenz (IMG Performer, AI-Label24, TransparAI) wirbt mit einem Punkt, den wir nicht lösen: Auf der Website wird per `srcset` fast immer eine abgeleitete Bildgröße ausgeliefert, und in WebP und AVIF schreiben wir bislang keine Markierung.
- TransparAI deckt zusätzlich C2PA-Prüfung, EU-Symbole, Compliance-Bericht und Schema.org ab. Diese Merkmale sind damit kein Alleinstellungsmerkmal mehr, sondern Stand der Technik.

## Leitplanken und Nicht-Ziele

Was wir sind und bleiben: die Lösung, deren Kennzeichnung die Website überlebt. Alleinstellungsmerkmal bleibt die Kombination aus eingebranntem Badge (hält bei Download, Screenshot, Social Media) und Overlay (sauber im Layout), dazu die automatische Entscheidung pro Bild.

Nicht-Ziele:

- **Text-Kennzeichnung.** Anderer Rechtsrahmen, anderer Standard, für die Zielgruppe selten Pflicht. Bewusst ausgeklammert.
- **Eigene Bildoptimierung** (WebP/AVIF-Konvertierung). Dafür gibt es Spezialisten, wir arbeiten mit ihnen zusammen.
- **Cloud-Erkennung** (Bilder an einen externen Dienst schicken). Wir bleiben bei "kein Byte verlässt den Server".
- **Kryptografisch signierte C2PA-Manifeste.** Die Pflicht zur maschinenlesbaren Markierung trifft nach Art. 50 Abs. 2 den Anbieter des KI-Systems, nicht den Website-Betreiber. Ein vertrauenswürdiges Zertifikat setzt das C2PA-Conformance-Verfahren für das Plugin voraus, und es gibt keine C2PA-Implementierung für PHP (nur das Rust-Werkzeug `c2patool` als externes Programm, das auf Shared Hosting oft nicht läuft). Aufwand und Nutzen stehen für unsere Zielgruppe in keinem Verhältnis. **Prüfen ist etwas anderes und bleibt eingeplant (siehe 2.5).**

## Phase 1: Die Compliance-Lücke schließen

Kern der Arbeit. Heute schreiben wir maschinenlesbare Daten nur in JPEG und PNG. Auf der Website wird fast immer eine abgeleitete Größe ausgeliefert, und bei WebP und AVIF fehlt die Deklaration komplett.

### 1.1 Metadaten in jede Bildgröße, auch WebP und AVIF

**Warum:** Das ist das Hauptargument der Konkurrenz und die einzige inhaltliche Schwäche des Plugins.

**Wie:**

- `includes/class-kikz-image.php` bekommt `write_declaration( string $path, string $dst_uri ): bool`, die einen kleinen XMP-Block schreibt. Pro Container:
  - JPEG: APP1-Segment mit `http://ns.adobe.com/xap/1.0/\0` (teilweise vorhanden, ausbauen)
  - PNG: `iTXt`-Chunk mit dem Keyword `XML:com.adobe.xmp` (teilweise vorhanden)
  - WebP: RIFF-Chunk mit der Kennung `XMP ` (neu)
  - AVIF: ISOBMFF-Box `uuid` mit XMP (neu, aufwendigster Teil)
- Gegenfunktion `read_declaration( string $path ): ?string` in `includes/class-kikz-detect.php`, damit die Erkennung die Markierung wiederfindet.
- Der Stempler ruft `write_declaration()` für **jede** erzeugte Größe auf, nicht nur für die Hauptdatei. Größen, die später durch Theme oder Optimierer entstehen, werden nachgezogen.
- Neuer Cron-Job `kikz_verify_meta` (stündlich): prüft pro markiertem Anhang, ob die Deklaration in allen Größen steckt, und schreibt fehlende nach. Das fängt Optimierer ab, die Metadaten entfernen.
- Neue Option `kikz_declaration_scope` (`all` = alle Größen, `main` = nur Hauptdatei).

**Test:** `tests/test-image.php` erweitern (Rundlauf schreiben und lesen je Container). Die Integrationssuite prüft nach dem Stempeln per `read_declaration()` alle Größen und validiert mit `getimagesize()`, dass die Pixel unverändert sind.

**Risiko:** Dateibeschädigung durch falsche Container-Längen. Gegenmaßnahmen: atomares Schreiben über eine temporäre Datei, Längenvalidierung, bei Fehler die unveränderte Datei behalten.

### 1.2 Zwei Deklarationstypen

**Warum:** Art. 50 unterscheidet "erzeugt" und "manipuliert". Subreality trennt das ebenfalls.

**Wie:** Neue Option `kikz_declaration_type` mit `generated` (IPTC `trainedAlgorithmicMedia`) und `edited` (IPTC `compositeWithTrainedAlgorithmicMedia`). Pro Bild überschreibbar über das Meta-Feld `_ki_declaration_type`. Das Badge kann je Typ einen anderen Text tragen (`kikz_badge_text_edited`).

### 1.3 Offizielle EU-Symbole als Badge-Stil

**Warum:** Der Code of Practice nennt sie ausdrücklich. Achtung: TransparAI und AIActify haben sie inzwischen auch, es ist damit Pflichtprogramm statt Alleinstellung.

**Wie:** Option `kikz_badge_style` (`classic`, `eu-generated`, `eu-modified`, `eu-basic`), Icons als SVG unter `assets/icons/`, Farbe schwarz oder weiß. Derselbe Stil muss im Frontend (`includes/class-kikz-frontend.php`) und beim Einbrennen (`includes/class-kikz-image.php`) gerendert werden.

### 1.4 Sichtbares Badge abschaltbar, Deklaration bleibt

**Warum:** Kunst und Satire sind von der Offenlegung ausgenommen. Das sichtbare Label muss pro Bild abschaltbar sein, ohne die Datei-Deklaration zu verlieren.

**Wie:** Neues Meta-Feld `_ki_badge_hidden`. `class-kikz-frontend.php` überspringt das Overlay, das Einbrennen wird ausgesetzt (Rückgriff auf das gesicherte Original), die XMP-Deklaration bleibt. Schalter in den Anhang-Details (`includes/class-kikz-media.php`).

### 1.5 Deepfake-Modus

**Warum:** Absatz 4 verlangt bei Deepfakes eine klare, nicht versteckte Offenlegung. Ein Eck-Badge ist dafür zu leise.

**Wie:** Option `kikz_deepfake_mode` mit deutlich sichtbarer Ausgabe (Hinweiszeile unter oder über dem Bild, optional Banner). Wirkt auf das Overlay und, wo sinnvoll, auf das eingebrannte Badge. Kandidat für die Pro-Version.

### 1.6 Barrierefreiheit und Kontrast

**Warum:** Ein Badge, das niemand lesen kann, erfüllt seinen Zweck nicht.

**Wie:** `aria-label` und für Screenreader lesbarer Text am Badge. Kontrastprüfung in `includes/class-kikz-settings.php` (WCAG-Schwelle 4,5:1) mit Warnung im Admin. Im Frontend ein Guard, der die echte Zeichenreihenfolge prüft und ein verdecktes Badge in eine freie Ecke verschiebt.

## Phase 2: Reichweite und Abdeckung

### 2.1 Weitere Page Builder

**Warum:** Wir können heute nur Elementor. AI-Label24 und TransparAI decken Divi, Bricks, WPBakery und Kadence ab. Jeder fehlende Builder kostet Kunden.

**Wie:** `includes/class-kikz-elementor.php` als Vorbild. Zwei generische Wege statt einer Klasse pro Builder:

- Filter auf den Bild-Markup-Pfad (`wp_get_attachment_image` und die Ausgaben der Themes).
- Erkennungslauf über Inline-`style`-Hintergründe (als eigene Option, wie TransparAI es macht).

Neue Klasse `includes/class-kikz-builders.php` mit Adaptern, aktiviert pro erkanntem Builder. Ausgabe über den neuen, öffentlichen Filter `kikz_label_media`, damit Themes eigenes Markup durchleiten können.

### 2.2 Beitragsbilder und Bloglisten

**Warum:** Beitragsbilder und Vorschaubilder in Listen werden oft vom Theme ausgegeben und laufen an unserer Erkennung vorbei. AI-Label24 hat dafür eigene Releases gebaut.

**Wie:** An `post_thumbnail_html` und die Archiv-Ausgaben andocken, Erkennung über die Attachment-ID statt über die URL, damit auch Cache-Situationen sauber sind.

### 2.3 Social-Media-Ausgabe (og:image)

**Warum:** Netzwerke entfernen Metadaten, und ein Overlay existiert dort nicht. Wer ein KI-Bild teilt, zeigt es ungekennzeichnet.

**Wie:** Option `kikz_og_image_copy`: erzeugt für Beiträge mit markiertem Beitragsbild eine Kopie mit eingebranntem Badge und liefert sie als `og:image` aus. Das Original bleibt unberührt. Dazu ein manueller "Export für Social" in den Anhang-Details.

### 2.4 Video

**Warum:** Art. 50 gilt auch für Video, und im Markt ist es noch dünn. AI-Label24 plant es erst.

**Wie:** Zwei Stufen:

- Metadaten in MP4/MOV (XMP-Box plus Auslesen vorhandener C2PA-Manifeste), ohne Neukodierung.
- Sichtbar: Badge auf dem Posterframe (läuft durch die vorhandene Bild-Pipeline), optional ein eingebranntes Standbild in den ersten Sekunden, nur mit ffmpeg und nur wenn verfügbar.

Komplexität hoch, Nutzen ebenfalls. Eigene Erweiterung nach Phase 1.

### 2.5 Erkennung ausbauen (C2PA lesen und prüfen)

**Warum:** Erkennung ist das zweite Verkaufsargument. Wenn ein Kunde ein Bild von OpenAI, Adobe oder Gemini hochlädt, sollte das Plugin die Herkunft anzeigen.

**Wie:**

- C2PA-Manifeste aus allen Bild-Containern lesen und prüfen: Signatur, Zertifikatskette gegen die mitgelieferte Trust List, Daten-Hash über die Dateibytes, RFC-3161-Zeitstempel. Das ist kostenlos und braucht kein eigenes Zertifikat (im Gegensatz zum Signieren, siehe Nicht-Ziele).
- Regel: Ein C2PA-Manifest allein kennzeichnet **nicht** automatisch. Kameras von Leica, Sony und Nikon schreiben es in echte Fotos. Automatisch markiert wird nur, wenn das Manifest eine KI-Quelle deklariert, sonst landet der Fund in einer Prüfliste.
- Prüfliste in der Mediathek: bestätigen, verwerfen, gesammelt bearbeiten.
- Punktwert und Sicherheitsstufen sind vorhanden, nur sichtbarer machen.

## Phase 3: Nachweis und Vertrauen

### 3.1 Verlauf und Hash pro Bild

**Wie:** Neues Meta-Feld `_ki_history` mit den letzten 50 Ereignissen (Zeit, Aktion, Benutzer, vorheriger Zustand) und `_ki_file_hash` (SHA-256 der Hauptdatei), damit "seit der Kennzeichnung unverändert" belegbar ist.

### 3.2 Compliance-Bericht

**Wie:** Druckansicht aus `includes/class-kikz-audit.php` mit Inventar, Deklarationen, Aktivitätsprotokoll und einem Dokument-Hash. Erweitert den vorhandenen CSV-Export. Achtung: TransparAI hat das schon, also gleicher Standard statt Differenzierung.

Optional: eigener Schlüssel plus kostenloser RFC-3161-Zeitstempel (Open TSA, freeTSA) für "unverändert seit diesem Zeitpunkt".

### 3.3 Auslieferungsprüfung

**Warum:** Ein CDN (Cloudflare Polish, Jetpack) kann die Deklaration beim Ausliefern entfernen. Davon merkt der Betreiber nichts.

**Wie:** Ein Knopf pro Bild, der die eigene öffentliche URL abruft und prüft, ob die Deklaration ankommt. Rein lokale Prüfung, keine externen Dienste.

## Phase 4: Markt und Vertrieb

### 4.1 WordPress.org

**Warum:** Der wichtigste Hebel. Alle ernsthaften Anbieter stehen dort, wir nicht.

**Wie:** Free-Version mit der reinen Kennzeichnung (Mediathek-Haken, Overlay, XMP in allen Größen, EU-Symbole), Pro mit Automatik, Erkennung, Einbrennen, Deepfake-Modus, Video und Social-Ausgabe. Banner, Screenshots und readme.txt in gutem Englisch.

### 4.2 Mehrsprachigkeit

Die `.pot` ist da, es fehlen Übersetzungen. Englisch zuerst, weil das Verzeichnis englisch liest.

### 4.3 Lizenzierung

Lizenzserver für Pro, Agentur-Lizenzen (mehrere Domains), später White-Label. Kündigung nach § 312k BGB, das ist bei Online-Abschluss Pflicht.

### 4.4 Öffentliche Roadmap

Diesen Plan als `ROADMAP.md` veröffentlichen, Changelog pflegen.

## Querschnitt

**Datenmodell (neu):** `_ki_declaration_type`, `_ki_badge_hidden`, `_ki_deepfake`, `_ki_history`, `_ki_file_hash`, `_ki_linked_video`.

**Optionen (neu):** `kikz_declaration_scope`, `kikz_declaration_type`, `kikz_badge_style`, `kikz_deepfake_mode`, `kikz_og_image_copy`.

**Migration:** Beim Upgrade einmalig die Deklaration in alle vorhandenen Größen schreiben, über die Warteschlange, damit große Bibliotheken keinen Timeout auslösen.

**Tests:** Die vorhandene Suite wächst mit. Jede Container-Änderung braucht einen Rundlauf-Test in `tests/test-image.php`, jede Ausgabe einen Eintrag in der Core-Suite, der Rollout einen E2E-Test über HTTP.

**Erweiterbarkeit:** Zwei neue Hooks, `kikz_declaration_meta` (Filter) und `kikz_label_media` (Filter).

## Reihenfolge

1. **1.1** (Metadaten in allen Größen), weil es die einzige echte Compliance-Lücke ist und die Konkurrenz damit wirbt.
2. **1.2 und 1.4**, klein und rechtlich sauber.
3. **1.3, 1.5 und 1.6** als sichtbare Ausbaustufe.
4. **4.1** (WordPress.org), weil ohne Sichtbarkeit die ganze Arbeit niemandem auffällt.
5. **2.x und 3.x** als Reichweiten- und Vertrauensausbau, Video zuletzt.

## Offene Entscheidungen

- **Free/Pro-Schnitt:** Was bleibt dauerhaft kostenlos? Empfehlung: Kennzeichnung inklusive Metadaten frei, Automatik und Erkennung bezahlt.
- **Video:** jetzt oder erst nach dem WordPress.org-Start?
