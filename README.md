# ContentCreator

KI-gestützte SEO-Texterstellung für Shopware 6 — mit **Anthropic Claude** (Standard) und optional **OpenAI**.

Generiert und **optimiert** Produktbeschreibungen, Kategorietexte und Meta-Daten sowie Media-Alt-Texte, erzwingt die Textqualität über serverseitige Qualitäts-Gates und kann Inhalte im Stapel füllen.

## Qualitäts-Garantie (v0.2.0)

Jede Generierung durchläuft serverseitig eine automatische Prüf- und Nachbesserungs-Schleife:

- **KI-Muster-Gate:** Der Text wird gegen umfangreiche KI-Floskel-Regeln (DE+EN) gescort. Über der Schwelle (Standard 30, einstellbar) wird automatisch regeneriert — die KI erhält die gefundenen Muster samt konkreter Alternativen als Feedback.
- **Meta-Längen-Gate:** Meta-Title 50–60 und Meta-Description 140–155 Zeichen werden erzwungen (inkl. fokussiertem Korrektur-Lauf).
- **Fakten-Erhalt (Optimieren-Modus):** Zahlen, Maße und MPN des Originals müssen im Ergebnis nachweisbar erhalten sein, sonst wird der Versuch abgelehnt und wiederholt.
- **Batch schreibt nur bestandenen Content:** Was das Gate nach allen Versuchen nicht besteht, wird NICHT in den Shop geschrieben, sondern als „Abgelehnt" ausgewiesen.

## Modi

- **Neu erstellen:** Texte/Meta werden aus den Produkt-/Kategoriefakten neu generiert (füllt Lücken).
- **Bestand optimieren:** Der vorhandene Text ist die Basis — Formulierungen werden humanisiert und SEO-optimiert, Fakten und HTML-Struktur bleiben erhalten. Bei Meta-Daten sind die Felder einzeln wählbar (nicht gewählte bleiben unangetastet). Im Stapel werden Objekte ohne Bestandstext automatisch neu erstellt.

Produkte sind kanalneutral: In Produkt-Texten und -Meta erscheint nie der Shopname — Markenbezug läuft über den Hersteller. Nur Kategorietexte verwenden den konfigurierten Shop-/Markennamen.

## Funktionen

- **Provider:** Anthropic Claude (Default) oder OpenAI — API-Keys ausschließlich serverseitig in der Systemkonfiguration.
- **Texttypen:**
  - Produktbeschreibung (Hauptteil + optional Tier-Steckbrief + „Wussten Sie"-Fun-Fact)
  - Produkt-Meta (Titel/Description/Keywords, MPN als letztes Keyword)
  - Kategorie-Teaser (wird in den CMS-Slot geschrieben), Kategorie-Detailtext, Kategorie-Meta
  - Startseiten-Meta je Verkaufskanal
  - Media-Alt-Texte (Vision — die KI „sieht" das Bild)
- **Sicherheitsnetz:** Automatisches Backup vor jedem Überschreiben + Ein-Klick-Wiederherstellen; Dry-Run im Batch (erst prüfen, dann gesammelt übernehmen).
- **Arbeitsvorrat:** Lücken-Scan (fehlende Beschreibungen/Meta/Alt-Texte) und katalogweiter Qualitäts-Report (schlechteste Bestandstexte) mit Direktübernahme in die Batch-Auswahl.
- **Kanal-Varianten:** Kategorietexte je Verkaufskanal mit eigenem Schwerpunkt und Anti-Duplicate-Regeln, inkl. Ähnlichkeits-Anzeige gegen die Referenz-Variante.
- **Extras:** Markiert-/Diff-Ansicht, Flesch-Lesbarkeit, Scoring-Whitelist, Kosten-Schätzung (Tokens), Zeilenumbruch-Bereinigung für CMS-Slots, optionale Web-Recherche (Claude).
- **SEO-Regeln:** Meta-Title 50–60 Zeichen, Meta-Description 140–155 Zeichen, Keyword-Konventionen, umfangreiche Verbotsliste für KI-typische Floskeln.
- **Qualitäts-Ampel:** Client-seitiges Scoring (grün → rot) für bestehende und generierte Texte.
- **Stapelverarbeitung:** Mehrere Produkte/Kategorien/Medien auf einmal, asynchron über die Message-Queue, mit Fortschrittsanzeige.
- **Täglicher Cron:** füllt optional automatisch fehlende Produktbeschreibungen auf.

## Kompatibilität

- Shopware **6.7** (`shopware/core: >=6.7,<7.0`)
- PHP 8.4

## Installation

1. Plugin-Ordner nach `custom/plugins/ContentCreator` legen (oder ZIP im Backend hochladen).
2. `bin/console plugin:refresh`
3. `bin/console plugin:install --activate ContentCreator`
4. `bin/console cache:clear`

## Konfiguration

**Einstellungen → Erweiterungen → ContentCreator:**

- **KI-Provider & API-Keys:** aktiver Provider, Anthropic-Key (`sk-ant-…`), Claude-Modell, OpenAI-Key/-Modell, „Verbindung testen".
- **Stapelverarbeitung & Automatik:** günstigeres Batch-Modell, tägliches Auffüllen an/aus, Limit pro Lauf.
- **Text-Optionen:** Tier-Steckbrief/Fun-Fact an/aus, Web-Recherche an/aus. Der Shopname wird nicht konfiguriert: Er erscheint nur im Startseiten-Title und kommt automatisch aus der Domain des Verkaufskanals.

> Claude-Keys beginnen mit `sk-ant-…`, OpenAI-Keys mit `sk-…`/`sk-proj-…`. Bitte den passenden Key ins richtige Feld eintragen.

## Nutzung

**Inhalte → ContentCreator**

- **Texte generieren:** Objekt (Produkt/Kategorie) wählen, aktuellen Text + Qualitäts-Score sehen, gewünschten Texttyp generieren, Ergebnis prüfen und „Übernehmen & speichern".
- **Stapelverarbeitung:** Objekt-Typ + mehrere Objekte + Texttypen wählen, Batch starten, Fortschritt verfolgen.

> Für die Stapelverarbeitung muss ein Message-Worker laufen (Admin-Worker oder `bin/console messenger:consume`).

## SEO-Dateinamen & Bild-Redirects (Plesk)

Die Batch-Seite kann Produktbilder mit Artikelnummer-/Hash-Dateinamen auf beschreibende Namen umbenennen. **Wichtig:** Dabei ändert sich die komplette Bild-URL — damit alte URLs (Google Bilder, externe Links) erhalten bleiben:

1. Nach jedem Umbenennungs-Lauf **„nginx-Redirects herunterladen"** klicken.
2. Den Inhalt der Datei in Plesk unter **Apache & nginx Einstellungen → Zusätzliche nginx-Anweisungen** der Domain einfügen (oder als `include`-Datei ablegen) und übernehmen.
3. Die Datei enthält immer ALLE bisherigen Redirects (inkl. Thumbnails) — bestehende Einträge einfach komplett ersetzen.

Bilder, die an mehreren Produkten hängen, erhalten den Namen des zuerst gefundenen Produkts.

## CLI (Test)

```
bin/console content-creator:generate --type=product_meta --product-id=<ID>
bin/console content-creator:generate --type=product_description --name="Handpuppe Wombat" --manufacturer="Hansa Creation" --mpn=3767
```

Optionen: `--type`, `--lang` (de/en), `--provider` (claude/openai), `--model`, `--product-id`/`--category-id`/`--media-id`, `--write`.

## Lizenz

PolyForm Noncommercial License 1.0.0 — siehe [LICENSE](LICENSE).
