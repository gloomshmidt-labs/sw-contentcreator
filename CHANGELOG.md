# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden hier dokumentiert.
Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.27.3] - 2026-07-04

### Fixed
- **Generator-Bilder-Karte**: Nach „Nur dieses umbenennen" bleiben die Vorschläge der übrigen Bilder erhalten (werden nach dem URL-Reload automatisch nachgeladen) — kein erneutes Klicken auf „SEO-Dateinamen vorschlagen" pro Bild mehr. Bereits perfekte Namen verschwinden dabei automatisch aus der Liste.

## [0.27.2] - 2026-07-04

### Fixed
- **Ketten-Glättung jetzt auch für Thumbnails**: Bei Nachkorrekturen zeigten die Thumbnail-Redirects der Ur-Namen auf den Zwischennamen (301→301-Doppel-Hop, funktionierte, aber unsauber). Jetzt zeigt JEDE alte URL — Hauptbild wie alle Thumbnail-Größen — direkt aufs finale Ziel (Invariante geprüft: kein Redirect-Ziel ist zugleich Quelle).

## [0.27.1] - 2026-07-04

### Added / Fixed (Nachkorrektur bereits umbenannter Bilder)
- **Korrektur-Scan im Produkt-Kontext**: Der gezielte Scan (Generator-Bilder-Karte) bietet jetzt ALLE Bilder des Produkts an — auch bereits umbenannte können erneut korrigiert werden. Der Artikelnummer-Anker kommt dabei aus dem Umbenennungs-Protokoll (ursprünglicher Name), Namen die bereits dem Vorschlag entsprechen werden ausgeblendet. Der globale Wellen-Scan bleibt auf Artikelnummer-/Hash-Namen beschränkt.
- **Ketten-Redirects E2E bestätigt**: a→b→c erzeugt automatisch a→c UND b→c — Nachkorrekturen sind also redirect-seitig voll abgedeckt (war bereits implementiert, jetzt getestet).

## [0.27.0] - 2026-07-04

### Changed (Batch-Tauglichkeit der Dateinamen)
- **Namensvorschläge bewusst vereinfacht: nur Produktname + Artikelnummer-Anker** (`folkmanis-handpuppe-schnecke-15601a`). Die Alt-Text-Wörter fließen nicht mehr ein — sie brachten gebeugte Wortfragmente und Kürzungs-Wackler, die Einzelprüfung erzwangen, bei minimalem SEO-Nutzen (der Dateiname ist ein schwaches Signal; die Beschreibungskraft liefert der Alt-Text). Ergebnis: grammatisch saubere, vollständig vorhersehbare Vorschläge — **batch-tauglich ohne Einzelprüfung**, editierbar bleiben sie trotzdem.

## [0.26.1] - 2026-07-04

### Fixed
- **Browser-Autofill konnte die API-Keys überschreiben**: Die Passwortfelder tragen jetzt `autocomplete="new-password"` und neutrale Feldnamen — Passwort-Manager füllen dort keine gespeicherten Website-Passwörter mehr ein, die beim nächsten Speichern den echten Key ersetzt hätten (Symptom: „sehr kurzer Key" nach dem Öffnen der Einstellungen).

## [0.26.0] - 2026-07-04

### Added (gezieltes Testen pro Produkt)
- **SEO-Dateinamen in der Generator-Bilder-Karte**: Button „SEO-Dateinamen vorschlagen" lädt die Vorschläge NUR für die Bilder des gewählten Produkts (statt der 300er-Zufallswelle der SEO-Werkzeuge) — je Bild editierbar und einzeln umbenennbar („Nur dieses umbenennen"), inkl. automatischer Redirect-Datei; die Bilderliste lädt nach dem Umbenennen mit neuen URLs neu. Der Scan-Endpoint akzeptiert dafür einen optionalen `productId`-Filter.

## [0.25.0] - 2026-07-04

### Added / Changed (GUI-Kritik: „warum nur über die Stapelverarbeitung?")
- **Alt-Texte jetzt auch im Generator**: Bei Produkten zeigt die neue Karte „Produktbilder & Alt-Texte" alle Bilder mit **Mini-Vorschau** und aktuellem Alt-Text — je Bild generieren (Vision), Ergebnis inline prüfen/anpassen, einzeln übernehmen (schreibt Alt + Title mit Backup).
- **Bild-Vorschau in der Dry-Run-Review**: Alt-Text-Ergebnisse zeigen das zugehörige Bild als Thumbnail neben dem editierbaren Text — prüfen ohne Rätselraten.
- **Zweck-Zeilen auf allen drei Seiten**: Generator („EIN Objekt im Detail …"), Stapelverarbeitung („VIELE Objekte auf einmal …"), SEO-Werkzeuge („Einmalige Wartungs-/Diagnose-Werkzeuge …") — die Aufgabenteilung steht jetzt direkt in der Oberfläche.

## [0.24.0] - 2026-07-04

### Added (User-Wunsch: Dry-Run-Ergebnisse sehen und korrigieren)
- **„Ergebnisse ansehen & bearbeiten"** nach jedem Dry-Run: Alle bestandenen Ergebnisse werden mit Objektname und Score aufgelistet und sind **direkt editierbar** (Alt-Texte/Texte als Textfeld, Metas als drei Felder). Änderungen speichern automatisch beim Verlassen des Feldes — „Übernehmen" schreibt den editierten Stand in den Shop. Neue Routen: `GET batch/{id}/results`, `POST batch-result/{id}`.

## [0.23.0] - 2026-07-04

### Added (User-Workflow: „Produkt wählen → alle seine Bilder")
- **Alt-Texte direkt am Produkt**: Bei Objekt-Typ „Produkt" gibt es jetzt den Texttyp **„Alt-Texte (Produktbilder)"** — der Batch verarbeitet automatisch ALLE Bilder der gewählten Produkte (Vision je Bild, Alt + Title, Gate, Dry-Run, Backup). Der Medien-Picker ist dafür nicht mehr nötig; ein defektes Bild reißt die übrigen nicht mit (je Bild eigene Diagnose-Zeile). Dry-Run-Commit schreibt die Bild-Ergebnisse korrekt auf die Medien.
- **Selbst-diagnostizierende Batch-Ablehnung**: Passt die Auswahl nicht zur Objektart, sagt die Meldung jetzt, was die IDs wirklich sind („018d6f5b… ist ein Produkt").

## [0.22.2] - 2026-07-04

### Fixed (Bug aus dem plesk-1-Test: Produkt-ID im Medien-Batch)
- **Scan-Ergebnisse werden beim Objekt-Typ-Wechsel geleert**: Lücken-/Report-/Aktualitäts-Ergebnisse blieben stehen und ihre „Übernehmen"-Buttons konnten IDs der alten Objektart in die neue Auswahl spülen (→ „Medium nicht gefunden" mit einer Produkt-ID).
- **Server-Schutznetz**: Der Batch-Start prüft alle IDs gegen die Ziel-Entity; fremde IDs fliegen raus, und eine komplett unpassende Auswahl wird mit klarer Meldung abgelehnt statt einen Fehler-Lauf zu produzieren.

## [0.22.1] - 2026-07-04

### Fixed
- Gründe-Anzeige: Fehler vor der Texttyp-Schleife (z.B. „Medium nicht gefunden") zeigen jetzt den angefragten Texttyp statt eines rohen Snippet-Keys; unbekannte Typen werden als Klartext dargestellt.

## [0.22.0] - 2026-07-04

### Added (User-Frage „warum 0 bestanden?" — jetzt beantwortet die UI das selbst)
- **Gründe-Anzeige nach jedem Batch-Lauf**: Die Fortschrittskarte listet für abgelehnte/fehlgeschlagene Objekte den konkreten Grund (Gate-Score + Längenverstöße + fehlende Fakten bzw. die Fehlermeldung, z.B. „OpenAI API-Fehler: Failed to download file" bei nicht erreichbaren Bildern). Fehler werden dafür auch als Diagnose-Zeilen gespeichert.
- **Redirect-Hinweise passen sich an**: Ist die automatische Redirect-Datei konfiguriert, zeigt die SEO-Dateinamen-Karte „✓ Automatische Redirect-Datei aktiv" statt Download-Button, und der Bestätigungsdialog erklärt, dass nichts weiter zu tun ist.

## [0.21.1] - 2026-07-04

### Changed (User-Feedback aus dem plesk-1-Test)
- **Namensvorschläge sind jetzt editierbar** — jede Zeile ist ein Eingabefeld; der bearbeitete Name wird beim Übernehmen verwendet (Server bereinigt erneut zu einem gültigen Slug).
- **Alle gescannten Bilder sichtbar** (scrollbare Liste) statt nur der ersten 50.
- **Stoppwort-Liste erweitert** (fuers, vom, zum, zur, beim, im, am, ins, auf, bei, als …) — kein „…-fuers-20930" mehr am Namensende.

## [0.21.0] - 2026-07-04

### Changed (User-Feedback zu Dateinamen + Bild-Attributen)
- **Artikelnummer-Anker garantiert am Ende des Dateinamens** und nie abgeschnitten (`…-handpuppe-grosse-30773` statt `…-30773-fuers-kasperle`); gekürzt wird nur wortweise davor (keine halben Wörter mehr), Füllwörter (der/die/das/für/mit …) fliegen raus.
- **Alt-Text-Generierung füllt jetzt auch das Title-Attribut** der Bilder (gleicher Text) — inkl. Backup/Restore beider Felder.

## [0.20.1] - 2026-07-04

### Added
- **„Nur dieses umbenennen"** pro Zeile im SEO-Dateinamen-Scan — für kontrollierte Einzeltests (z.B. ein Produkt auf dem Testshop), mit demselben Bestätigungsdialog und automatischer Redirect-Datei-Aktualisierung. Umbenannte Zeilen verschwinden aus der Liste.

## [0.20.0] - 2026-07-04

### Added (User-Wunsch: Redirects ohne wiederkehrende Handarbeit)
- **Automatische Redirect-Datei**: In den Einstellungen kann ein Datei-Pfad hinterlegt werden (z.B. `<shoproot>/var/media-redirects.conf`) — nach jedem Umbenennungs-Lauf schreibt das Plugin die komplette, kumulative nginx-Redirect-Datei automatisch dorthin. Einmalige Einrichtung: Pfad speichern, in Plesk `include <PFAD>;` eintragen, täglichen Root-Cron `systemctl reload nginx` anlegen (nginx liest Includes nur beim Reload; für frisch umbenannte Bilder unkritisch, da deren alte URLs noch nicht indexiert sind — ein Reload mit fehlerhafter Datei lässt die laufende Konfiguration aktiv). Der manuelle Download-Export bleibt als Alternative erhalten.

## [0.19.0] - 2026-07-04

### Fixed (Praxis-Feedback vom plesk-1-Test)
- **Feld-Fallback im Optimieren-Modus**: Hat das konkrete Feld keinen Bestand (z.B. FAQ, Teaser, Beschreibung), wird automatisch neu erstellt — unabhängig vom Bestand der anderen Felder. Vorher: Fehlermeldung. Der blockierende Client-Guard ist entfernt; die Logik liegt zentral im Generator (gilt für Einzeltexte, Batch, CLI, Cron).
- **Prompt-Regeln gegen Fakten-Schnitzer**: (1) Tier-/Rasse-/Artbezeichnungen werden EXAKT aus dem Produktnamen übernommen — nie durch verwandte oder Oberbegriffe ersetzt (ein „Bobtail" wird nicht zum „Schäferhund"); (2) die Funktionsweise der Produktart muss stimmen — eine Handpuppe hat keine Fäden, Fäden gehören zu Marionetten.

### Added
- **API-Verbrauchsanzeige** (Einstellungen): Kumulierte Requests, Tokens und geschätzte Kosten je Monat/Provider/Modell — zentral erfasst bei jeder Generierung (Einzeltexte, Batch, CLI, Cron; Tabelle `content_creator_usage`). Hinweis in der Karte: Das Konto-GUTHABEN ist mit normalen API-Keys technisch nicht abfragbar (nur Anthropic Console bzw. OpenAI-Dashboard).

## [0.18.1] - 2026-07-03

### Changed (User-Einwand: Zuordnung über Artikelnummer-Dateinamen)
- **Alter Dateiname bleibt im neuen Namen erhalten**: Vorschläge lauten jetzt `produktname-15601a` statt `produktname-2` — die Artikelnummer bleibt als Zuordnungs-Anker für Menschen und externe Systeme greifbar (Muster wie bei großen Shops: SKU im Bildnamen). Nur nichtssagende Hash-Namen (30+ Hex-Zeichen) werden nicht übernommen.

## [0.18.0] - 2026-07-02

### Added (User-Wunsch: Nachbearbeitung vor dem Übernehmen)
- **„Bearbeiten"-Tab** für alle generierten Texte (Beschreibung, Teaser, Detailtext, FAQ, Portrait): HTML-Quelltext im Monospace-Editor direkt nachbearbeiten — Änderungen fließen sofort in Vorschau, KI-Muster-Ansicht, Diff und „Übernehmen & speichern" ein.
- **Meta-Angaben editierbar**: Generierte Meta-Title/-Description/Keywords sind jetzt Eingabefelder mit Live-Zeichenzähler (inkl. Zielbereich) — die pixelgenaue Google-Vorschau aktualisiert sich beim Tippen (E2E verifiziert: Zähler + Pixel-Balken reagieren live).

## [0.17.0] - 2026-07-02

### Fixed / Changed (User-Feedback zur Anzeige)
- **„Vorschau" zeigt jetzt echtes HTML mit Absätzen**: Der Server liefert für die Anzeige das Roh-HTML (`existingHtml`), tag-bereinigt bleibt nur die Prompt-Variante — vorher erschien der Kategorien-/Produkttext als zusammenhängender Klumpen.
- **Tab „Markiert" heißt jetzt „KI-Muster"** — er hebt erkannte KI-Formulierungen farblich hervor (das ist der Unterschied zur Vorschau).
- **Teaser sauber getrennt**: Der Teaser-Slot wird nicht mehr in den Detailtext gemischt (weder in der Anzeige noch in der Bestand-Kaskade) und kann nie mehr fälschlich als Detail-Slot beschrieben werden. Er erscheint separat unter dem aktuellen Text.
- **IST-Meta-Angaben werden angezeigt**: Meta-Title, Meta-Description (je mit Zeichenzahl) und Keywords des aktuellen Objekts stehen jetzt in der „Aktueller Text"-Karte.

## [0.16.0] - 2026-07-02

### Changed (User-Feedback)
- **Modus-Standard ist jetzt „Bestand optimieren"** (Generator + Stapelverarbeitung) — der Haupt-Anwendungsfall.
- **Kategorie-Auswahl wie im Textoptimierung-Tool**: Erst Verkaufskanal wählen, dann öffnet sich ein Dropdown mit dem kompletten, hierarchisch eingerückten Kategoriebaum dieses Shops (Baum-Reihenfolge wie im Admin, bis 500 Kategorien) — kein Suchfeld-Raten mehr. Sprache wechseln lädt den Baum in der neuen Sprache.
- **Kanal-Auswahl zeigt nur Storefront-Verkaufskanäle** (Headless/API-Kanäle ausgefiltert) — im Generator, in der Stapelverarbeitung und für den Kategorie-Filter.

## [0.15.0] - 2026-07-02

### Fixed (Praxis-Feedback: „Kein Text vorhanden" bei Startseite/Kategorien)
- **Startseiten-Text aus der Erlebniswelt**: Der Bestandstext der Startseite wird jetzt wie im Textoptimierung-Tool geladen — CMS-Seite aus `homeCmsPageId` des Kanals (Fallback: Layout der Navigations-Root-Kategorie), alle statischen Text-Slots in Seitenreihenfolge; bei gemapptem Slot (`category.description`) die description der Root-Kategorie.
- **Kategorie-Bestandstext-Kaskade**: description → statische Text-Slots im Kategorie-Layout (slotConfig) → Text-Slots der Erlebniswelt. Damit sehen Anzeige UND Optimieren-Modus den Content, egal wo er gepflegt ist.
- **Zurückschreiben in den Layout-Slot**: Liegt der Kategorie-Detailtext im Layout (description leer), schreibt Optimieren/Übernehmen den neuen Text in GENAU diesen Slot zurück (statt unsichtbar in die description) — inkl. Slot-Backup und Restore (E2E verifiziert: Schreiben, Backup, Wiederherstellen).
- **Anzeige = Server-Sicht**: Neuer Endpoint `POST /api/content-creator/current-text`; der Generator zeigt als „Aktueller Text" exakt das, was auch die Generierung als Bestand sieht (Single Source of Truth statt nur `description`).

### Added
- **Verkaufskanal-Filter für die Kategorie-Auswahl** (Generator + Batch): Erst Kanal wählen, dann zeigt die Objektauswahl nur die Kategorien dieses Shops (Filter auf den Navigations-Unterbaum, Tool-Muster).

## [0.14.0] - 2026-07-02

### Added / Changed (Usability-Review umgesetzt)
- **Neue Seite „SEO-Werkzeuge"**: SEO-Dateinamen, Zeilenumbruch-Bereinigung und Kannibalisierungs-Check sind von der Batch-Seite auf eine eigene Seite umgezogen — sie haben keinen Bezug zur Batch-Auswahl. Die Batch-Seite folgt jetzt dem Workflow: Konfiguration → Arbeitsvorrat finden (Lücken/Report/Aktualität, direkt VOR der Auswahl) → Auswahl & Start → Fortschritt.
- **Bestätigungs-Modal vor dem Umbenennen** (irreversibel, URLs ändern sich) mit Zusammenfassung und Redirect-Pflicht-Hinweis.
- **Dry-Run ist jetzt Standard** — der Start-Button zeigt den aktiven Modus („Dry-Run starten" vs. „Batch starten").
- **Empty-States für alle Scans**: „Keine Treffer — alles sauber" statt leerer Fläche (Lücken, Report, Aktualität, Dateinamen, Umbrüche, Kannibalisierung).
- **Worker-Stillstands-Warnung**: Läuft ein Job 30 s ohne jeden Fortschritt, erscheint eine deutliche Warnung („läuft ein Message-Worker?") statt eines endlos drehenden Balkens.
- **Fortschrittszähler** für die Qualitäts-Report-Schleife („N Texte geprüft …") und „Alle bereinigen" (N/M).
- **Alt-Text-Führung im Dateinamen-Scan**: Warnung mit Anzahl, wenn gefundene Bilder noch keinen Alt-Text haben (erst Alt-Batch, dann umbenennen).
- **Begriffe vereinheitlicht**: „Aktualität prüfen" statt „Freshness", „Übernehmen"-Buttons machen den stillen Moduswechsel sichtbar („wechselt auf Modus …").

### Fixed
- **i18n-Interpolation**: `$tc(key, anzahl, werte)` verwirft in Shopware 6.7 benannte Platzhalter — alle Aufrufe auf `$tc(key, werte, anzahl)` umgestellt; davon waren u.a. Dry-Run-/Commit-Meldungen, Report-Zeilen und der Verbindungstest betroffen.
- **Veralteter Admin-Snippet-Cache**: `admin_snippet_*`-Keys unter ALTEN Cache-Präfixen in Redis DB 1 werden von `cache:clear` nie entfernt und ließen Snippet-Änderungen unsichtbar bleiben (16 verwaiste Generationen gefunden). Dokumentiert in CLAUDE.md.

## [0.13.3] - 2026-07-02

### Changed (nur intern, kein Verhaltens-/API-Unterschied)
- **Controller-Refactoring**: JSON-Body-Dekodierung + Pflichtfeld-Prüfung aller API-Routen in private Helper (`jsonBody`/`requireFields`/`missingFieldsResponse`) konsolidiert; `mode`/`metaFields`-Normalisierung (`modeFrom`/`metaFieldsFrom`) für generate + batch vereinheitlicht. Routen, Fehlermeldungen und Statuscodes unverändert.
- **Admin Batch-Seite**: gemeinsames `runBusy()`-Muster für alle Scan-/Aktions-Buttons (Busy-Flag, Fehler-Notification, Flag-Reset), `notifyApiError()`-Helper, Computed `effectiveLanguageId` statt 9x `this.languageId || Shopware.Context.api.languageId`, `removeLineBreakEntry()` gegen doppelte Filter-Logik.
- **Admin Generator-Seite**: `notifyApiError()`-Helper, gemeinsame `pillStyle()`-Badge-Optik, Import-Reihenfolge bereinigt, leere Catch-Bindings entfernt.
- **Services**: `ReadabilityChecker` mit `wordCount()`-Helper (3x dedupliziert), `FocusKeywordChecker` Überschriften-Extraktion vereinfacht (funktionslose Array-Verschachtelung entfernt), `MediaRenamer`/`CannibalizationScanner` ungenutzte Variablen entfernt.

## [0.13.0] - 2026-07-02

### Added
- **SEO-Dateinamen für Produktbilder** (Batch-Seite): Scan findet Artikelnummer-/Hash-Dateinamen (`15601a.jpg`), schlägt beschreibende Namen aus Produktname + Alt-Text vor (Dry-Run-Vorschau) und benennt per Core-FileSaver um (inkl. Thumbnails). Jede Umbenennung wird protokolliert (`content_creator_media_rename`).
- **nginx-301-Export**: Aus dem Protokoll wird eine Redirect-Datei generiert (exakte `location`-Blöcke inkl. ALLER Thumbnail-Größen, verkettete Umbenennungen aufgelöst) — zum Einbinden in Plesk („Zusätzliche nginx-Anweisungen"), damit alte Bild-URLs per 301 erhalten bleiben (Google Bilder, externe Links). Routen: `POST /api/content-creator/media-rename/scan|apply`, `GET …/export`.
- Hintergrund (Live-Analyse): Beim Umbenennen ändert sich die KOMPLETTE Media-URL (auch die Hash-Verzeichnisse, da Shopware den physischen Dateinamen hasht) — ohne Redirects wären alte URLs tot. Statischer nginx-Export statt PHP-404-Fallback: kein Kernel-Boot für Bot-Misses, passt zum bestehenden Plesk-Include-Workflow.
- Hinweis: Bilder, die an mehreren Produkten hängen, erhalten den Namen des zuerst gefundenen Produkts.

## [0.12.0] - 2026-07-02

### Added (Bilder-SEO, aus Live-Shop-Analyse)
- **Produktkontext für Alt-Texte**: Die Vision-Generierung erfährt jetzt, zu welchem Produkt (Name + Hersteller) ein Bild gehört — Alt-Texte werden dadurch keyword-tragend statt generisch.
- **Präzisere Alt-Regeln**: Motiv + auffällige Merkmale (Farbe/Material/Pose) + Perspektive; Generisches („Produktbild", Dateiname) explizit verboten.
- **Erkennung generischer Alt-Texte** im Lücken-Scan (neue Gruppe „weakAlt"): findet gepflegte, aber SEO-wertlose Alts wie „Produktbild 2", „… Demo", zu kurze oder Dateinamen-Alts — als Batch-Arbeitsvorrat.

## [0.11.0] - 2026-07-02

### Added
- **FAQ-Storefront-Anzeige + FAQPage-Rich-Snippet**: Der generierte FAQ-Block erscheint automatisch auf Produktseiten (nach der Beschreibung) und Kategorieseiten (nach dem CMS-Inhalt) — inkl. validem `FAQPage`-JSON-LD für Google-Rich-Results (`FaqParser` + Twig-Funktion `content_creator_faq_items`, Storefront-Snippets DE/EN). E2E im Storefront verifiziert.
- Plugin-Icon (gloomshmidt-Logo, wie bei den übrigen Plugins).

### Entschieden
- **Interne Verlinkung (Recherche-Feature 4) wird NICHT umgesetzt**: kanal-spezifische SEO-URLs vertragen sich nicht mit kanalneutralen Produkttexten, und der bereits eingesetzte LinkJuicer löst die interne Verlinkung storefront-seitig.
- **Freshness-Automatik im Cron vertagt** (User-Entscheidung): erst nach ausgiebigem Praxistest der Gates; bis dahin manuell über die Freshness-Karte der Batch-Seite.

## [0.10.0] - 2026-07-02

### Added
- **KI-FAQ-Block** (Produkt + Kategorie): 3-5 echte Kundenfragen mit faktenbasierten Antworten als `<h3>/<p>`-HTML, gespeichert im translatable customField `content_creator_faq` (inkl. Backup/Restore, Optimieren-Modus, Batch). Theme-Einbindung/FAQ-Rich-Snippet: siehe offene Fragen.
- **Content-Freshness**: Jedes Schreiben stempelt `content_creator_generated_at` in die customFields; der Freshness-Scan (Batch-Seite, `POST /api/content-creator/freshness`) findet Texte, deren Entity sich seit der Generierung geändert hat oder die älter als 6 Monate sind — mit Direktübernahme in die Optimieren-Auswahl.
- **SERP-Briefing**: Bei aktiver Web-Recherche + Fokus-Keyword wird die Recherche gezielt auf die Suchlandschaft des Keywords gerichtet (verwandte Begriffe, W-Fragen, Themen der Top-Ergebnisse als Briefing — RankMath-Content-AI-Muster).

## [0.9.0] - 2026-07-02

### Added
- **Keyword-Kannibalisierungs-Check**: Live-Warnung im Generator, wenn das Fokus-Keyword bereits anderen Produkten/Kategorien zugewiesen ist (entprellter Check beim Tippen), plus Vollscan auf der Batch-Seite (mehrfach vergebene Fokus-Keywords + identische Meta-Titles je Sprache). Route `POST /api/content-creator/cannibalization`.
- **Readability-Checks** (deterministisch, Yoast-Muster): Satzlängen-Verteilung (Anteil > 25 Wörter), Passiv-Quote, Absatzlänge, Zwischenüberschriften-Dichte — informativ nach jeder Text-Generierung im Admin und in der CLI (kein hartes Gate).
- **Hersteller als Objekttyp**: Hersteller-Portraits (150-300 Wörter, kanalneutral, keine erfundenen Firmengeschichten) generieren/optimieren — Generator, Batch, CLI (`--manufacturer-id`), inkl. Backup/Restore, Fokus-Keyword und Lücken-Fallback.

### Fixed
- Recherche-Zitate: Bei aktiver Web-Recherche eingebettete Markdown-Quelllinks werden aus dem Content entfernt (landeten sonst im Shop-Text).
- `researchEnabled` wird jetzt mit `filter_var` gelesen — die bekannte Shopware-Falle, dass `system:config:set` Bools als truthy String speichert, kann die Recherche nicht mehr ungewollt aktivieren.
- Review-Fixes v0.8.0: Reasoning-Modelle erhalten ein Mindest-Token-Budget (Reasoning zählt gegen max_output_tokens), OpenAI-Refusals werfen jetzt wie bei Claude einen Fehler statt leeren Text, leere KI-Antworten können das Gate nicht mehr passieren, Mehrwort-Keyword-Dichte wird korrekt gewichtet, Report-Deep-Link übergibt die Sprache.

## [0.8.0] - 2026-07-02

### Added (Top-3 der Markt-Recherche)
- **Fokus-Keyword + On-Page-Score** (Yoast/RankMath-Muster): Pro Produkt/Kategorie ein Fokus-Keyword im Generator pflegbar (persistiert als translatable customField `content_creator_focus_keyword`, wird auch im Batch aus der Entity gelesen). Es steuert die Generierung (Pflicht-Platzierung in Meta-Title, H1, erstem Absatz; 2-3x natürlich im Text) und wird nach jeder Generierung deterministisch geprüft (`FocusKeywordChecker`): Title/Description/Überschriften/erster Absatz/Dichte 0,5-2,5 % — als ✓/✗-Checkliste im Admin und in der CLI.
- **SERP-Vorschau mit Pixel-Messung**: Google-Snippet-Vorschau für generierte Meta-Daten mit pixelgenauer Längenmessung via Canvas (Title 580 px @ 20px Arial, Description 920 px @ 14px Arial — Google schneidet nach Pixeln, nicht Zeichen), inkl. farbiger Längenbalken und Ellipsen-Abschneidung wie in der echten SERP.
- **1-Klick-Fix aus dem Qualitäts-Report**: Jeder Report-Eintrag hat „Im Generator öffnen" — Deep-Link, der den Generator mit Objekt und Modus „Bestand optimieren" vorbelegt.

### Changed
- **OpenAI-Provider auf die Responses API migriert** (`/v1/responses`, wie im Textoptimierung-Tool): damit funktioniert die **Web-Recherche jetzt auch mit OpenAI** (web_search-Tool, E2E getestet) und Reasoning-Modelle (o-Serie, gpt-5-mini/nano) erhalten automatisch `reasoning.effort`. Die Recherche war nie eine Claude-Exklusivität, sondern nur eine Beschränkung der alten Chat-Completions-API.

## [0.7.0] - 2026-07-01

### Changed
- **Shopname-Feld entfernt (SEO-Entscheidung):** Der Shopname gehört nur in den Startseiten-Title — Google zeigt den Site-Namen seit 2022 selbst in den SERPs an und entfernt Marken-Suffixe beim Title-Rewriting am häufigsten (76 % aller Titles werden umgeschrieben, Quelle: Search Engine Land Q1/2025). Kategorie-Meta-Titles werden jetzt OHNE Shop-Suffix generiert (Platz für ein zweites Keyword); die Startseiten-Marke kommt automatisch aus der Domain des Verkaufskanals passend zur Sprache (kein Config-Feld mehr, Multi-Channel-korrekt).

### Fixed (Korrektheits-Review)
- Zeilenumbruch-Bereinigung verschmolz Wörter, wenn der Umbruch mitten im Fließtext stand — Umbrüche werden jetzt durch EIN Leerzeichen ersetzt (zwischen Tags anschließend normalisiert).
- Whitelist wirkte serverseitig nur bei exakter Übereinstimmung, in der Admin-Anzeige als Teilstring — jetzt beidseitig Teilstring-Matching (identische Wertung, keine überraschenden Batch-Ablehnungen).
- Selektive Meta-Optimierung: gepinnte (nicht optimierte) Bestandsfelder fließen nicht mehr in den KI-Score ein (keine irreführend rote Ampel).
- Lücken-Scan für Medien filtert jetzt auf die Live-Produktversion.
- Dry-Run: Anzeige und Commit-Button zählen jetzt die tatsächlich übernehmbaren Ergebnis-Zeilen (nicht Objekte).

## [0.6.1] - 2026-07-01

### Changed
- Refactoring (verhaltensneutral): gemeinsamer `RawTranslation`-Helper für das Lesen der rohen Sprach-Übersetzung (ContentWriter/ContentBackupService/LineBreakScanner), `QualityChecker::parseWhitelist()` statt doppeltem Whitelist-Parsing, `ProviderRegistry::get()` nutzt `activeProviderName()`, Admin: gemeinsames `language-resolve.mixin.js` für Generator- und Batch-Seite (LOCALE_FOR_LANG/resolveLanguageId/languageContext), `scoreOf()` instanziiert den TextOptimiser nicht mehr doppelt.

## [0.6.0] - 2026-07-01

### Added
- **Text-Backup + Ein-Klick-Wiederherstellen**: Vor JEDEM Überschreiben (Generator-Apply, Batch, CLI `--write`, Dry-Run-Commit) sichert das Plugin automatisch den rohen Alt-Zustand der Zielsprache (`content_creator_backup`); im Generator gibt es pro Texttyp „Letzten Stand wiederherstellen" (inkl. Teaser-slotConfig und „Feld war leer"). Übernehmen läuft dafür jetzt für ALLE Typen über das Backend.
- **Lücken-Scan** (Batch-Seite): Produkte/Kategorien ohne Beschreibung bzw. Meta, Produktbilder ohne Alt-Text — Ergebnis per Klick als Batch-Auswahl übernehmen (Modus „Neu erstellen").
- **Katalogweiter Qualitäts-Report**: scort alle Bestandstexte seitenweise mit demselben Scoring wie das Gate (inkl. Whitelist) und zeigt die schlechtesten Texte — „Schlechteste 25 als Auswahl übernehmen" startet direkt den Optimieren-Arbeitsvorrat.
- **Dry-Run im Batch**: generiert + prüft, schreibt aber NICHTS; Ergebnisse werden gespeichert (`content_creator_batch_result`) und nach Review gesammelt übernommen (`POST /api/content-creator/batch/{id}/commit`) — nur Gate-bestandene, je genau einmal.
- **Duplicate-Content-Check**: Ähnlichkeit (Jaccard über Wort-3-Gramme) zwischen generierter Kanal-Variante und der Referenz-Kategorie, farbcodiert im Qualitäts-Panel.

## [0.5.0] - 2026-07-01

### Added
- **Kanal-Varianten für Kategorietexte** (seo-kategorie-Skill): Varianten-Schwerpunkt wählbar (Hauptshop-ausführlich / pädagogisch / therapeutisch / Geschenkidee) plus optionale Referenz-Kategorie aus einem anderen Verkaufskanal — der neue Text wird mit „gleiche Fakten, andere Formulierungen"-Regeln bewusst deutlich anders geschrieben (Anti-Duplicate-Content), auch die Meta-Daten.
- **Web-Recherche vor dem Schreiben** (Recherche-Pflicht der Skills): optional zuschaltbar (Einstellungen → Text-Optionen); nutzt das Anthropic-`web_search`-Server-Tool (max. 3 Suchen pro Text, nur Claude-Provider), mit Kein-Content-Klau-Regel im Prompt. Live-Test steht aus, bis ein Claude-Key hinterlegt ist.
- **Kosten-Tracking**: Token-Verbrauch wird pro Batch-Job persistiert (`input_tokens`/`output_tokens`, Migration) und im Admin mit Kosten-Schätzung angezeigt (Preistabelle pro Modell); Einzelgenerierungen zeigen Tokens + Schätzung im Qualitäts-Panel.
- **Zeilenumbruch-Scan/-Bereinigung** (Batch-Seite): scannt alle Kategorien der gewählten Sprache auf `\n` in statischen CMS-Slots (Storefront-Darstellungsfehler) und bereinigt einzeln oder alle auf einmal (Tool-Portierung `scanLineBreaks`/`fixLineBreaks`). Neue Routen `POST /api/content-creator/linebreaks/scan|fix`.

## [0.4.0] - 2026-07-01

### Added
- **Markiert-Ansicht** im Generator (Bestandstext + generierte Texte): Stopwords und KI-Muster werden farblich hervorgehoben (Overlap-Auflösung, Portierung von `highlightText` aus dem Tool).
- **Diff-Ansicht** für Produktbeschreibung/Kategorie-Detailtext: Wort-Level-LCS-Diff, HTML-aware (Block-Struktur bleibt erhalten) — Portierung von `generateDiff`/`generateHtmlDiff`.
- **Flesch-Lesbarkeits-Index** (DE-/EN-Formel inkl. Silbenzählung) für Bestandstext und generierte Texte.
- **Scoring-Whitelist** (Einstellungen → Qualitäts-Gate): kommagetrennte Muster, die weder im Server-Gate noch in der Admin-Anzeige gewertet werden — konsistent in beiden Welten.

### Entschieden
- **LanguageTool wird bewusst NICHT integriert**: Shop-Content müsste an api.languagetool.org (Drittanbieter) gesendet werden; die Grammatik-Qualität wird bereits durch die LLM-Prompts + Qualitäts-Gates erzwungen. Die Whitelist (im Tool an LanguageTool gekoppelt) wirkt im Plugin stattdessen auf das KI-Muster-Scoring.

## [0.3.0] - 2026-07-01

### Added
- **Kategorie-Teaser wird jetzt echt gespeichert**: Beim Übernehmen (Admin, Batch, CLI `--write`) schreibt das Plugin den Teaser in den CMS-`slotConfig` der Kategorie — Ziel ist der erste Text-Slot vor dem Produkt-Listing (`CmsSlotResolver`); bestehende Slot-Overrides der Zielsprache werden gemerged. Teaser ist damit auch im Batch verfügbar; Optimieren-Modus nutzt den bestehenden Teaser-Slot-Text als Basis.
- **Startseite als neuer Objekttyp** (`sales_channel` / Texttyp `home_meta`): Startseiten-Meta (Title/Description/Keywords) je Verkaufskanal lesen, generieren/optimieren und in `sales_channel_translation.homeMeta*` schreiben — im Generator, im Batch und per CLI (`--sales-channel-id`). Alle Qualitäts-Gates gelten auch hier.
- Neue API-Route `POST /api/content-creator/apply` (ACL `content_creator.editor`) für serverseitiges Übernehmen (Teaser-slotConfig-Merge).
- CLI: Warnung, wenn `--write` trotz nicht bestandenem Qualitäts-Gate schreibt (nur im CLI-Testmodus möglich — der Batch lehnt ab).

## [0.2.0] - 2026-07-01

### Added
- **Optimieren-Modus** (Generator, Batch, CLI `--mode=optimize`): Bestehende Texte werden überarbeitet statt neu erfunden — Fakten (Zahlen/Maße/MPN) bleiben nachweislich erhalten (FactGuard-Gate), die HTML-Struktur bleibt bestehen. Im Batch fallen Objekte ohne Bestandstext automatisch auf „Neu erstellen" zurück (Lücken füllen).
- **Selektive Meta-Optimierung**: Im Optimieren-Modus lassen sich Meta-Title/-Description/-Keywords einzeln anhaken; nicht gewählte Felder werden deterministisch auf den Bestandswert gepinnt. Bestehende Meta-Daten und der Seiteninhalt (max. 2000 Zeichen) gehen als Basis/Kontext in den Prompt.
- **Serverseitiges Qualitäts-Gate mit Retry-Schleife** (QualityChecker): Jede Generierung wird gegen die portierten KI-Muster-Regeln (`rules-de/en.json`) gescort. Über der Schwelle (Standard 30, konfigurierbar) wird automatisch regeneriert — die KI erhält die gefundenen Muster samt konkreter Alternativen als Feedback (Tool-Muster `rewriteWithFacts`).
- **Meta-Längen-Gate**: Title 50-60, Description 140-155 Zeichen werden erzwungen (±3 Toleranz); bei Verstoß erst Feedback-Regenerierung, dann fokussierter Minimal-Korrektur-Call (Tool-Muster `_fixMetaLengths`).
- **Batch schreibt nur Gate-bestandenen Content**: Abgelehnte Objekte werden im neuen `rejected`-Zähler ausgewiesen (Job-Entity + Migration) und NICHT in den Shop geschrieben; Details im Log.
- Einstellungen: neue Karte „Qualitäts-Gate" (Schwelle + max. Nachbesserungen); Generator/Batch: Modus-Auswahl, Qualitäts-Panel (Score, Gate-Status, Fundstellen, vorher/nachher).
- Prompt-Ausbau aus dem Skills-GAP-Abgleich: Keyword-Konventionen DE + EN/UK (Kuscheltier-Hierarchie, „soft toy" statt „cuddly toy", „Punch and Judy" u.a.), EN-Title-Schema mit Produkttyp-Keyword, Keyword-Qualitätsregeln (keine Wortumstellungs-Duplikate, keine nackten Nummern, sprachrein), 25-Wörter-Satzregel, Rhythmus-/Satzanfangs-Regeln, E-E-A-T + Keyword-Dichte/LSI (Kategorie-Detail), QA-Checkliste vor Ausgabe, nachportierte Floskel-Verbote („Darüber hinaus", „Unser Sortiment", „überzeugt durch" u.a.), „hochwertig" jetzt als „sparsam, max. 1x"-Nuance.
- **Kanalneutralität**: Produkt- und Media-Texte erwähnen nie mehr den Shopnamen/die Domain (Produkte sind kanalübergreifend); nur Kategorie-Inhalte nutzen den konfigurierten Markennamen.

## [0.1.8] - 2026-07-01

### Security
- Prompt-Injection-Schutz: Shop-Inhalte werden vor Prompt-Einbettung sanitisiert (Rollen-Präfixe, „Ignoriere alle Anweisungen"-Muster → `[filtered]`) und lange Freitexte in `"""`-Delimiter gekapselt (PromptSanitizer, Tool-Muster `_sanitizeForPrompt`).
- ACL-Enforcement: Alle vier API-Routen verlangen jetzt `content_creator.viewer` (generate/test-connection/status) bzw. `content_creator.editor` (batch).

### Added
- HTTP-Retry mit exponentiellem Backoff für Claude/OpenAI (429/5xx/Transportfehler, `Retry-After` wird respektiert, max. 3 Versuche).

## [0.1.7] - 2026-07-01

### Fixed
- Generator: „Übernehmen & speichern" verwarf alle generierten Texte (z.B. beim Meta-Übernehmen verschwand auch die generierte Beschreibung). Jetzt wird nach dem Speichern nur der Bestandstext/Score neu geladen; bereits generierte Ergebnisse bleiben erhalten und können weiter übernommen werden.

## [0.1.6] - 2026-07-01

### Changed
- Eigene Einstellungs-Seite im Modul (Inhalte → ContentCreator → Einstellungen) statt `config.xml`: Es werden **nur die Felder des aktiven Providers** angezeigt (Claude ODER OpenAI), gespeichert über `systemConfigApiService`. `config.xml` entfernt; Standardwerte setzt jetzt `ContentCreator::activate()`.

## [0.1.5] - 2026-07-01

### Fixed
- Sprachauswahl (Generator + Batch): Die Objektliste zeigte unabhängig vom Sprachfeld nur eine Sprache. Jetzt wird de/en analog zum Textoptimierung-Tool zur echten `languageId` aufgelöst (`locale.code` de-DE/en-GB, gecacht) und steuert Objektliste (Anzeige), Laden, Generierung und Zurückschreiben über den `sw-language-id`-Kontext. Controller nimmt `languageId` mit System-Default-Fallback entgegen.

## [0.1.4] - 2026-07-01

### Fixed
- Stapelverarbeitung schlug bei aktivem OpenAI-Provider fehl (`model claude-sonnet-4-6 does not exist`): Das Claude-spezifische Batch-Modell wird jetzt nur bei aktivem Claude-Provider gesendet; bei OpenAI greift dessen Standardmodell. Gilt für Admin-Batch und Cron.

## [0.1.3] - 2026-07-01

### Fixed
- Stapelverarbeitung: Objektauswahl zeigte nichts an — `sw-entity-multi-id-select` benötigt eine `:repository`-Instanz (nicht `:entityName`). Repository via `repositoryFactory` bereitgestellt.

## [0.1.2] - 2026-07-01

### Fixed
- Dropdowns/Selects reagierten gar nicht (Objekt-Typ/Sprache/Objekt-Auswahl): In Shopware 6.7 (Vue 3) emittieren `sw-single-select`, `sw-entity-single-select` und `sw-entity-multi-id-select` `update:value` statt `change` — Bindings entsprechend umgestellt.
- Vue-3-Inkompatibilität: `this.$set` durch normale Zuweisung ersetzt (Generierungs-Ergebnis).

## [0.1.1] - 2026-07-01

### Fixed
- Admin-Dropdowns (Objekt-Typ, Sprache) und Entity-Auswahl: Auswahl blieb nach dem Klick nicht bestehen – von `v-model` auf robustes `:value` + `@change` umgestellt.

## [0.1.0] - 2026-07-01

### Added
- Erste Version.
- Provider-Abstraktion **Anthropic Claude** (Default) + **OpenAI**, serverseitig über Symfony HttpClient (kein Composer-SDK).
- `PromptBuilder` mit SEO-Regeln aus seo-produkt/seo-kategorie + portierter KI-Muster-Verbotsliste (`ForbiddenPhrases`).
- Texttypen: Produktbeschreibung (mit Tier-Steckbrief + Fun-Fact), Produkt-Meta, Kategorie-Teaser/-Detail/-Meta, Media-Alt (Vision).
- `FactLoader` (Fakten laden) + `ContentWriter` (Rückschreiben) + `ContentGenerator`.
- Admin-Modul **ContentCreator**: Generator-Seite mit Qualitäts-Ampel (übernommene `TextOptimiser`-Engine) und Stapelverarbeitung mit Fortschritt.
- Batch über Message-Queue (`BatchGenerateMessage`/-Handler, Job-Entity + Migration) + täglicher Cron (`FillMissingContentTask`).
- Admin-Button „Verbindung testen"; CLI-Command `content-creator:generate`.
