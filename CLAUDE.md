# CLAUDE.md — ContentCreator

Technischer Steckbrief für die Arbeit an diesem Plugin. Stand: 2026-07-02, Version 0.10.0.

## Neu in 0.8.0-0.10.0 (Nacht-Session, Details im CHANGELOG)
- **Fokus-Keyword** je Produkt/Kategorie/Hersteller (customField `content_creator_focus_keyword`, steuert Prompt-Pflichtplatzierungen; `FocusKeywordChecker` liefert On-Page-✓/✗-Checks) + Live-**Kannibalisierungs-Warnung** (`CannibalizationScanner`).
- **SERP-Pixel-Vorschau** (serp-preview.js, Canvas: Title 580px/Desc 920px) + **1-Klick-Fix** (Report → Generator-Deep-Link mit entityType/id/mode/lang-Query).
- **OpenAI auf Responses API** (`/v1/responses`) — Web-Recherche für BEIDE Provider (OpenAI E2E getestet), Reasoning-Modelle mit effort+Token-Floor; Refusal wirft; leere Antworten scheitern hart am Gate; Recherche-Zitat-Links werden aus Content gestrippt; `researchEnabled` via filter_var (String-Bool-Falle).
- **ReadabilityChecker** (Satzlängen/Passiv/Absatz/Überschriften — informativ), **Hersteller** als Objekttyp (`manufacturer_description`, DAL `product_manufacturer`), **FAQ-Block** (customField `content_creator_faq` — Theme-Rendering offen!), **Content-Freshness** (`content_creator_generated_at`-Stempel bei jedem Write + `FreshnessScanner` changedSince/aging), **SERP-Briefing** bei Recherche+Fokus-Keyword.
- **Git-Trennung eingerichtet** (`.git`-Datei zeigt auf externes Git-Verzeichnis nach Projektstandard).

## Zweck
KI-gestützte SEO-Texterstellung UND -Optimierung (Produkt/Kategorie/Meta/Media-Alt) für Shopware 6.7, Provider Claude + OpenAI. Kernversprechen: Der User muss generierten/optimierten Content NICHT manuell nachprüfen — serverseitige Qualitäts-Gates (KI-Muster-Score, Meta-Längen, Fakten-Erhalt) mit automatischer Retry-Schleife erzwingen die Qualität; im Batch wird NUR Gate-bestandener Content geschrieben (abgelehnte Objekte → `rejected`-Zähler + Log).

## Modi (seit 0.2.0)
- `create` — Neu erstellen (Lücken füllen).
- `optimize` — Bestand als Basis: Fakten (Zahlen/MPN) müssen nachweislich erhalten bleiben (`FactGuard`), HTML-Struktur bleibt; selektive Meta-Optimierung über `metaFields` (nicht gewählte Felder werden deterministisch auf Bestand gepinnt). Batch mit `optimize` fällt bei Objekten ohne Bestandstext automatisch auf `create` zurück.
- Shopname (seit 0.7.0): NUR im Startseiten-Title (`home_meta`), automatisch aus der Domain des Verkaufskanals (sprachpassend, `FactLoader::shopBrand()`). Produkt-/Media-Prompts erwähnen NIE einen Shopnamen; Kategorie-Titles bewusst OHNE Suffix (Google zeigt Site-Namen selbst, Suffix = verschenkter Keyword-Platz). Kein `brandName`-Config-Feld mehr.

## Umgebung
- Shopware 6.7 (`>=6.7,<7.0`), PHP 8.4.
- Vendor: `gloomshmidt-labs/sw-contentcreator`, Namespace `ContentCreator\`.
- **Keine Composer-Dependencies** außer `shopware/core` — LLM-Calls laufen über den mitgelieferten Symfony `HttpClient` (raw HTTP), damit kein `vendor/` ins ZIP muss.

## Architektur

### Backend (PHP)
- `Service/Provider/` — `AiProviderInterface`, `ClaudeProvider` (POST `api.anthropic.com/v1/messages`), `OpenAiProvider` (POST `api.openai.com/v1/chat/completions`), DTOs `AiRequest`/`AiResult`. Keys aus `SystemConfigService` (`ContentCreator.config.*`).
- `Service/ProviderRegistry` — wählt Provider laut Config (`provider`), prüft `isConfigured()`.
- `Service/PromptBuilder` — System-/User-Prompts pro Texttyp (`TYPE_*`) und Modus (`MODE_CREATE`/`MODE_OPTIMIZE`), abgeleitet aus den SEO-Skills seo-produkt/seo-kategorie (inkl. Keyword-Konventionen DE+EN/UK, QA-Checkliste, E-E-A-T/LSI, 25-Wörter-Regel) + Tool-Meta-Regeln (keine Wortumstellungs-Duplikate etc.).
- `Service/PromptSanitizer` — Prompt-Injection-Schutz (Rollen-Präfixe, "Ignoriere..."-Muster → `[filtered]`; `"""`-Delimiter um Freitexte im factBlock).
- `Service/ForbiddenPhrases` — portierte KI-Muster als Verbotsliste im Prompt; `sparingLine()` = "max. 1x wenn belegbar"-Nuance (hochwertig etc.).
- `Service/QualityChecker` — serverseitiger KI-Muster-Scan (Port von engine.js `_detectAiPatterns`/`_buildInflectedRegex` inkl. DE-Flexion, Kontext-Halbierung, Score-Bänder ≤10/30/60/100); Regeldaten `src/Resources/rules/rules-{de,en}.json` (via Node aus den engine-JS-Dateien extrahiert — bei Regeländerung neu generieren!). `promptFeedback()` baut das Retry-Feedback (Muster + Alternativen).
- `Service/FactGuard` — Fakten-Erhalt-Gate (Zahlen+Einheiten, MPN; normalisiertes Matching wie `checkFactsPreserved`).
- `Service/ContentGenerator` — orchestriert mit Gate-Schleife: generieren → scoren/prüfen → bei Verstoß Regenerierung mit konkretem Feedback (`qualityMaxRetries`, Default 2; Schwelle `qualityMaxScore`, Default 30) → für Meta zusätzlich bis zu 2 fokussierte Längen-Korrektur-Calls (`_fixMetaLengths`-Muster). Meta-Längen: Title 50-60, Desc 140-155, Gate-Toleranz ±3. Rückgabe enthält `quality` (score/level/passed/attempts/findings/lengthIssues/missingFacts/originalScore).
- `Service/FactLoader` — lädt Produkt/Kategorie/Media/Sales-Channel (Name, Hersteller, MPN, Keywords, bestehende Meta, Bild-URL, Teaser-Slot-Text) sprachaufgelöst; `langCode()` mappt languageId → de/en.
- `Service/CmsSlotResolver` — findet den Teaser-Slot einer Kategorie (erster Text-Slot vor dem `product-listing`-Block, Sortierung Section→Block-Position; Cache pro Kategorie).
- `Service/ContentWriter` — schreibt per `translations`-Payload zurück (Produkt/Kategorie: description + meta; Media: alt; Sales-Channel: homeMeta*). **Kategorie-Teaser** → slotConfig-Merge in der ROHEN Übersetzung der Zielsprache (nicht die geerbte!), nur der Ziel-Slot wird ersetzt. Kein Teaser-Slot im Layout → RuntimeException mit klarer Meldung.
- `Service/RawTranslation` — statischer Helper (kein Service): liest die ROHE Übersetzung einer Entity für eine Zielsprache (nicht die geerbte); genutzt von ContentWriter, ContentBackupService und LineBreakScanner.
- `Service/BatchDispatcher` — legt Job an (`content_creator_generation_job`) + verteilt pro Objekt eine `BatchGenerateMessage`.
- `Service/Provider/HttpRetry` — 429/5xx/Transport-Retry mit exponentiellem Backoff (Retry-After respektiert, max. 3 Versuche) für beide Provider.
- `MessageQueue/` — `BatchGenerateMessage` (LowPriority) + `BatchGenerateHandler` (pro Objekt generieren + NUR bei `quality.passed` zurückschreiben; Zähler `processed`/`failed`/`rejected` atomar via DBAL; `effectiveMode()`-Fallback optimize→create bei fehlendem Bestand).
- `ScheduledTask/` — `FillMissingContentTask` (täglich) füllt fehlende Produktbeschreibungen (nur wenn `dailyFillEnabled`).
- `Controller/ContentCreatorController` — `/api/content-creator/generate|test-connection|batch|batch/{jobId}`. `routes.xml` importiert die Attribut-Routen.
- `Command/GenerateCommand` — CLI-Test (`content-creator:generate`), inkl. `--product-id`/`--write`.
- `Core/Content/GenerationJob/` — Entity für Batch-Jobs; Migration `Migration1751000000CreateGenerationJob`.

### Admin (JS/Vue 3, `Resources/app/administration/src`)
- **Konfiguration:** KEINE `config.xml` mehr. Eigene Seite `sw-content-creator-settings` (Modul-Route `settings`) rendert nur die Felder des aktiven Providers und speichert via `systemConfigApiService.getValues/saveValues('ContentCreator.config')`. Defaults setzt `ContentCreator::activate()`. Konfig erreichbar über Smart-Bar „Einstellungen" auf Generator/Batch (kein Extensions→Configure mehr).
- `module/content-creator/engine/` — **übernommene** Dateien aus dem Textoptimierung-Tool: `rules-de.js`, `rules-en.js`, `engine.js` (`TextOptimiser`), nur zu ES-Modulen umgebaut (export/import). Nur die lokale `analyse()` wird genutzt (Scoring, kein API-Call); die LLM-Methode darin ist ungenutzt (referenziert ein nicht vorhandenes `llmValidator`, wird nie aufgerufen).
- `service/content-creator.api.service.js` (+ `init/`) — API-Wrapper.
- `module/sw-content-creator/mixin/language-resolve.mixin.js` — gemeinsame de/en→languageId-Auflösung (LOCALE_FOR_LANG, `resolveLanguageId`, `languageContext`) für Generator- und Batch-Seite.
- `module/sw-content-creator/` — Modul mit zwei Seiten: `sw-content-creator-generator` (Einzelobjekt, Qualitäts-Ampel via `TextOptimiser`, Übernehmen via `repositoryFactory`) und `sw-content-creator-batch` (Mehrfachauswahl, Fortschritt-Polling).
- `component/sw-content-creator-test-connection` — Button für die config.xml-`<component name="content-creator-test-connection">`.
- `acl/index.js` — Privileg `content_creator` (viewer/editor).

## Wichtige Regeln / Fallen
- **$tc-Interpolation (6.7):** `$tc(key, anzahl, {werte})` verwirft benannte Platzhalter stillschweigend (leere Stellen im Text). Richtig: `$tc(key, {werte}, anzahl)` für Plural+Werte, `$t(key, {werte})` ohne Plural. `{count}` funktioniert bei $tc implizit über das Zahl-Argument.
- **Admin-Snippet-Cache (Redis DB 1):** `/api/_admin/snippets` wird via `CachedSnippetFinder` in `cache.object` gecacht. `admin_snippet_*`-Keys unter ALTEN Cache-Präfixen (nach Secret-/Deployment-Wechseln) entfernt KEIN `cache:clear`/`cache:pool:clear` — Snippet-Änderungen bleiben dann unsichtbar. Bereinigung: `docker exec shopware-redis redis-cli -n 1 --scan --pattern "*admin_snippet*"` + del.
- **Admin-Selects sind Vue 3:** `sw-single-select`, `sw-entity-single-select`, `sw-entity-multi-id-select` emittieren **`update:value`** (NICHT `change`). Binden mit `:value` + `@update:value`; der Handler setzt die Property selbst. `this.$set` existiert in Vue 3 nicht mehr → normale Zuweisung / Spread nutzen.
- **Admin-Build überspringt das Plugin:** Vite baut ein Plugin nicht neu, solange `src/Resources/public/administration` existiert (gleicher Bundle-Hash trotz Quelländerung). Fix zum Erzwingen: Ausgabe löschen (`rm -rf .../Resources/public/administration` und `public/bundles/contentcreator`), dann im Admin-Root `SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1 npm run build`, danach `assets:install`. Immer den Bundle-Hash/-Inhalt in `public/bundles/contentcreator/administration/assets/` verifizieren.
- **Config-Cache:** Nach dem ersten Hinterlegen der Keys kann der CLI/Worker-Prozess den `system_config`-Redis-Cache stale lesen → `bin/console cache:pool:clear --all`. Im Admin-Betrieb invalidiert das Speichern selbst.
- **Kategorie-Teaser** (seit 0.3.0): wird in den CMS-`slotConfig` geschrieben (auch im Batch). Admin-Apply läuft für den Teaser über `POST /api/content-creator/apply` (serverseitiger Merge), alle anderen Typen weiter client-seitig via `repositoryFactory`. Ohne Text-Slot vor dem Listing im Layout → Fehler „Kein Teaser-Textslot...".
- **Batch braucht einen laufenden Worker** (Admin-Worker oder `messenger:consume`), sonst bleiben Jobs auf `running`.
- **Meta-Ausgabe** kommt als JSON — `ContentGenerator::extractJson()` toleriert Codeblöcke.
- **Modellwahl:** Einzeltexte `anthropicModel` (Default Opus 4.8), Batch `batchModel` (Default Sonnet 4.6, günstiger).
- **Vor jedem Push:** Anonymisierungs-Grep (Markennamen/personenbezogene Daten) — seit 0.7.0 gibt es keinen brandName-Default mehr (Shop-Marke kommt aus der Kanal-Domain).

## Bewusste Entscheidungen
- **Keine interne Verlinkung in KI-Texten** (2026-07-02, User bestätigt): kanal-spezifische SEO-URLs vs. kanalneutrale Produkttexte + LinkJuicer übernimmt das bereits storefront-seitig.
- **Freshness-Automatik im Cron vertagt** (User): erst nach ausgiebigem Praxistest; manuell über Batch-Karte.
- **Kein LanguageTool** (0.4.0): Drittanbieter-API würde Shop-Content nach außen senden; LLM-Prompts + Gates decken Grammatik ab. Whitelist wirkt stattdessen aufs Muster-Scoring (Server `qualityWhitelist`-Config + Client-Anzeige, identische Wertung).
- **MPN in Keywords als Teil eines Keywords** (nicht nackt) — Tool-Regel schlägt Skill-Beispiel (nackte `3767`), da neuer.
- **CSV-Batch/HTML-Modus des Tools nicht portiert**: Workarounds für fehlenden DB-Zugriff des Browser-Tools; im Plugin ersetzt durch DAL-Objektauswahl.

## Offen / Roadmap (Reihenfolge lt. Session 2026-07-01)
- Web-Recherche (`researchEnabled`, Anthropic web_search-Tool) live testen, sobald sk-ant-Key hinterlegt ist.

## Feature-Ideen aus der Markt-Recherche (2026-07-01, priorisiert — noch NICHT beauftragt)
1. Fokus-Keyword pro Entität + On-Page-Score (Yoast/RankMath-Kern; Checks Title/H-Struktur/erster Absatz/Dichte) — mittel
2. SERP-/Snippet-Vorschau mit Pixel-Längenmessung (Google schneidet nach Pixeln) — klein
3. 1-Klick-Fix aus dem Qualitäts-Report (Befund → vorbefüllte Generierung) — klein
4. Interne Verlinkung in KI-Texten (Kandidaten aus DAL, max. 1 Link/Begriff) — mittel-groß
5. KI-FAQ-Generierung (+ optional FAQPage-JSON-LD) — mittel
6. Keyword-Kannibalisierungs-Check (gleiches Fokus-Keyword/ähnliche Titles entitätsübergreifend) — klein
7. SERP-Recherche vor Generierung (Top-Ergebnisse → NLP-Begriffe/W-Fragen als Prompt-Briefing) — mittel
8. Erweiterte Readability-Gates (Satzlängen-Verteilung, Passiv-Quote, Absatzlänge — deterministisch) — klein
9. Content-Freshness/Stale-Detection (Produktdaten geändert seit Generierung → Re-Optimierungs-Queue) — mittel
10. Hersteller-Seiten als Content-Typ (Beschreibung + Meta analog Kategorien) — klein
Bewusst nicht: Redirects/Sitemap/Canonical/hreflang/Rich-Snippets (fremdes Feld, dicht besetzt).
- GitHub-Veröffentlichung: lokales Repo steht (Git-Trennung nach Projektstandard), Push wartet auf Freigabe.
- KI-Button direkt in Produkt-/Kategorie-Detailseiten (Core-Template-Override).

## Verifiziert (0.1.7, per Chrome-DevTools)
- Backend: Generierung Produkt + Meta (Claude/OpenAI), FactLoader an echtem Produkt, ContentWriter-Rückschreiben in DB, Container-Kompilierung, alle vier API-Routen.
- Admin: Dropdowns (Vue3 `update:value`), Sprache (de/en→languageId, steuert Objektliste + Generierung + Speichern), Generator inkl. Qualitäts-Ampel, Batch (Auswahl + Verarbeitung → done + Rückschreiben), eigene Settings-Seite (nur aktiver Provider, Speichern persistiert), „Übernehmen" behält generierte Texte.
- Build: nur zuverlässig via `SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1 npm run build` nach Löschen von `Resources/public/administration` (Vite-Skip umgehen).
