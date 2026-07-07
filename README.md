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

Produkte sind kanalneutral: In Produkt-Texten und -Meta erscheint nie der Shopname — Markenbezug läuft über den Hersteller. Der Shopname erscheint ausschließlich im Startseiten-Title und wird automatisch aus der Domain des Verkaufskanals abgeleitet (nichts zu konfigurieren); Kategorie-Titles verzichten bewusst auf ein Shop-Suffix.

## Funktionen

- **Provider:** Anthropic Claude (Default) oder OpenAI — API-Keys ausschließlich serverseitig in der Systemkonfiguration.
- **Texttypen:**
  - Produktbeschreibung (Hauptteil + optional Tier-Steckbrief + „Wussten Sie"-Fun-Fact)
  - Produkt-Meta (Titel/Description/Keywords, MPN als letztes Keyword)
  - Kategorie-Teaser (wird in den CMS-Slot geschrieben), Kategorie-Detailtext, Kategorie-Meta
  - Hersteller-Beschreibung
  - FAQ-Block (Produkt-Zusatzfeld, Storefront-Rendering über mitgelieferte Twig-Funktion)
  - Startseiten-Meta je Verkaufskanal
  - Media-Alt-Texte (Vision — die KI „sieht" das Bild; Zweitsprachen werden kostengünstig aus der Standardsprache übersetzt)
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

Die Konfiguration ist eine eigene Seite im Plugin-Modul — erreichbar über den Button „Einstellungen" oben auf den Plugin-Seiten (nicht über Erweiterungen → Konfigurieren):

- **KI-Provider & API-Keys:** aktiver Provider, Anthropic-Key (`sk-ant-…`), Claude-Modell, OpenAI-Key/-Modell, „Verbindung testen".
- **Stapelverarbeitung & Automatik:** günstigeres Batch-Modell, tägliches Auffüllen an/aus, Limit pro Lauf.
- **Text-Optionen:** Tier-Steckbrief/Fun-Fact an/aus, Web-Recherche an/aus. Der Shopname wird nicht konfiguriert: Er erscheint nur im Startseiten-Title und kommt automatisch aus der Domain des Verkaufskanals.

> Claude-Keys beginnen mit `sk-ant-…`, OpenAI-Keys mit `sk-…`/`sk-proj-…`. Bitte den passenden Key ins richtige Feld eintragen.

## Nutzung

**Inhalte → ContentCreator**

- **Texte generieren:** Objekt (Produkt/Kategorie/Hersteller) wählen, aktuellen Text + Qualitäts-Score sehen, gewünschten Texttyp generieren, Ergebnis prüfen und „Übernehmen & speichern"; inkl. Bilder-Karte für Alt-Texte und Dateinamen des Produkts.
- **Stapelverarbeitung:** Objekt-Typ + mehrere Objekte + Texttypen wählen, Batch starten, Fortschritt verfolgen; Dry-Run-Ergebnisse lassen sich prüfen/editieren und gesammelt übernehmen, frühere Läufe können wieder geöffnet werden.
- **SEO-Werkzeuge:** Lücken-Scan, Qualitäts-Report, Content-Freshness, Kannibalisierungs-Check, Zeilenumbruch-Bereinigung und SEO-Dateinamen (siehe unten) — Funde lassen sich direkt in die Batch-Auswahl übernehmen.

> Für die Stapelverarbeitung muss ein Message-Worker laufen (Admin-Worker oder `bin/console messenger:consume`).

## SEO-Dateinamen & Bild-Redirects (Plesk)

Die SEO-Werkzeuge-Seite kann Produktbilder mit Artikelnummer-/Hash-Dateinamen auf beschreibende Namen umbenennen. **Wichtig:** Dabei ändert sich die komplette Bild-URL — damit alte URLs (Google Bilder, externe Links) erhalten bleiben:

**Empfohlen — automatische Redirect-Datei (einmalige Einrichtung):**
1. In den Einstellungen unter „Bild-Redirects" einen Datei-Pfad eintragen (z.B. `<shoproot>/var/media-redirects.conf`). Das Plugin schreibt die komplette, kumulative Redirect-Datei nach jedem Umbenennungs-Lauf automatisch dorthin.
2. In Plesk unter **Apache & nginx Einstellungen → Zusätzliche nginx-Anweisungen** einmalig einbinden: `include <PFAD>;`
3. Täglichen Root-Cronjob anlegen: `systemctl reload nginx` — nginx liest Include-Dateien nur beim Reload. Für frisch umbenannte Bilder ist die Verzögerung unkritisch (deren alte URLs sind noch nicht indexiert); ein Reload mit fehlerhafter Datei lässt die laufende Konfiguration unangetastet.

**Alternativ — manueller Export:** Nach dem Umbenennungs-Lauf „nginx-Redirects herunterladen" und den Inhalt in die Plesk-nginx-Anweisungen einfügen (Datei enthält immer ALLE bisherigen Redirects inkl. Thumbnails — bestehenden Block komplett ersetzen).

Bilder, die an mehreren Produkten hängen, erhalten den Namen des zuerst gefundenen Produkts.

## CLI (Test)

```
bin/console content-creator:generate --type=product_meta --product-id=<ID>
bin/console content-creator:generate --type=product_description --name="Handpuppe Wombat" --manufacturer="Hansa Creation" --mpn=3767
```

Optionen: `--type`, `--lang` (de/en), `--provider` (claude/openai), `--model`, `--product-id`/`--category-id`/`--media-id`, `--write`.

## Entwicklung & Tests

Das Plugin hat bewusst keine eigenen Composer-Laufzeit-Abhängigkeiten (nur `shopware/core`) und kein `vendor/`-Verzeichnis. Unit-Tests und Statische Analyse laufen im Kontext einer Shopware-Installation (Shop-Autoloader):

```
# Unit-Tests (PHPUnit aus dem Shop-vendor, im Shop-Root ausführen)
vendor/bin/phpunit -c custom/plugins/ContentCreator/phpunit.xml

# Statische Analyse (Level 6)
php phpstan.phar analyse -c custom/plugins/ContentCreator/phpstan.neon

# Code-Style (PSR-12 + Shopware-Regeln)
php php-cs-fixer.phar fix --config custom/plugins/ContentCreator/.php-cs-fixer.dist.php
```

## Lizenz

PolyForm Noncommercial License 1.0.0 — siehe [LICENSE](LICENSE).

## Headless-Betrieb (API-Integration)

Alle Plugin-Funktionen sind über die Admin-API erreichbar (`/api/content-creator/*`) und lassen sich damit auch ohne Admin-UI betreiben — z.B. durch Automatisierungen oder KI-Assistenten. Empfohlenes Setup nach dem Least-Privilege-Prinzip:

1. **ACL-Rolle** anlegen (Einstellungen → System → Benutzer & Rechte → Rollen) mit ausschließlich den Privilegien `content_creator.viewer` und `content_creator.editor`.
2. **Integration** anlegen (Einstellungen → System → Integrationen), *Administrator NICHT aktivieren*, stattdessen die Rolle aus Schritt 1 zuweisen.
3. Zugriff per OAuth `client_credentials` (Access-Key + Secret). Die Integration erreicht damit NUR die Plugin-Endpoints — Produkte-, Kunden- und Systemverwaltung antworten mit 403.

Empfohlener Arbeitsmodus für Automatisierungen: Batches immer als **Dry-Run** starten und die Übernahme (`commit`) als bewussten zweiten Schritt ausführen.

## Datenschutz

Bei der Generierung werden ausschließlich **Katalogdaten** an den konfigurierten KI-Provider (Anthropic bzw. OpenAI) übertragen: Produkt-/Kategorie-/Herstellernamen, Herstellernummern, vorhandene Beschreibungs- und Meta-Texte, Fokus-Keywords sowie Produktbilder (für Alt-Texte). **Kunden- und Bestelldaten werden nie übertragen** — das Plugin hat auf diese Daten keinerlei Zugriff (in `services.xml` sind ausschließlich Katalog-Repositories verdrahtet).
