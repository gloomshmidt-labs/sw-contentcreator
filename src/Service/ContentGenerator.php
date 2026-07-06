<?php declare(strict_types=1);

namespace ContentCreator\Service;

use ContentCreator\Service\Provider\AiRequest;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Orchestriert die Text-Generierung: baut Prompt, ruft den Provider, normalisiert
 * das Ergebnis — und erzwingt Qualität über serverseitige Gates mit Retry-Schleife:
 *
 *  1. KI-Muster-Gate (QualityChecker): Score muss unter der Schwelle liegen; sonst
 *     Regenerierung mit konkretem Muster-Feedback (Tool-Muster rewriteWithFacts).
 *  2. Meta-Längen-Gate: Title 50-60, Description 140-155 Zeichen; sonst
 *     Korrektur-Runde (Tool-Muster _fixMetaLengths).
 *  3. Fakten-Gate (FactGuard, nur Optimieren-Modus): Zahlen/MPN des Originals
 *     müssen erhalten bleiben; sonst Ablehnung mit Begründung an das LLM.
 *
 * Besteht ein Ergebnis nach allen Versuchen nicht, wird der beste Kandidat mit
 * quality.passed=false zurückgegeben — Batch/Cron schreiben dann NICHT.
 */
class ContentGenerator
{
    private const TITLE_MIN = 50;
    private const TITLE_MAX = 60;
    private const DESC_MIN = 140;
    private const DESC_MAX = 155;

    /**
     * Gate-Toleranz in Zeichen (Tool-Muster _fixMetaLengths: ±3). Die Prompts
     * fordern weiter die exakten Bereiche; das Gate akzeptiert knappe Treffer,
     * die für Google gleichwertig sind, statt Tokens in Endlos-Retries zu verbrennen.
     */
    private const LENGTH_TOLERANCE = 3;

    private const DEFAULT_MAX_SCORE = 30;
    private const DEFAULT_MAX_RETRIES = 2;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly PromptBuilder $promptBuilder,
        private readonly SystemConfigService $systemConfig,
        private readonly QualityChecker $qualityChecker,
        private readonly FactGuard $factGuard,
        private readonly FocusKeywordChecker $focusKeywordChecker,
        private readonly ReadabilityChecker $readabilityChecker,
        private readonly UsageTracker $usageTracker,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * @param array<string, mixed> $ctx
     * @param list<string>|null $metaFields nur diese Meta-Felder optimieren (Optimieren-Modus)
     *
     * @return array<string, mixed>
     */
    public function generate(
        string $type,
        string $lang,
        array $ctx,
        ?string $providerName = null,
        ?string $model = null,
        string $mode = PromptBuilder::MODE_CREATE,
        ?array $metaFields = null
    ): array {
        if (!\in_array($type, PromptBuilder::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unbekannter Texttyp "%s".', $type));
        }
        if (!\in_array($mode, [PromptBuilder::MODE_CREATE, PromptBuilder::MODE_OPTIMIZE], true)) {
            throw new \InvalidArgumentException(sprintf('Unbekannter Modus "%s".', $mode));
        }

        $isMeta = \in_array($type, PromptBuilder::META_TYPES, true);

        // Teaser lebt im CMS-slotConfig — für diesen Typ ist der Teaser der Bestandstext
        if ($type === PromptBuilder::TYPE_CATEGORY_TEASER && isset($ctx['existingTeaser'])) {
            $ctx['existingText'] = (string) $ctx['existingTeaser'];
        }
        // FAQ lebt im customField — analog
        if ($type === PromptBuilder::TYPE_FAQ && isset($ctx['existingFaq'])) {
            $ctx['existingText'] = (string) $ctx['existingFaq'];
        }

        $existingText = trim((string) ($ctx['existingText'] ?? ''));

        // Feld-Fallback: Optimieren ohne Bestand für DIESES Feld → automatisch
        // neu erstellen (unabhängig vom Bestand der anderen Felder)
        if ($mode === PromptBuilder::MODE_OPTIMIZE && !$isMeta && $type !== PromptBuilder::TYPE_MEDIA_ALT && $existingText === '') {
            $mode = PromptBuilder::MODE_CREATE;
        }

        // Alt-Texte laufen immer über das (günstigere) Batch-Modell — Vision-
        // Beschreibungen brauchen kein Premium-Modell, und Generator-Tests
        // zeigen so exakt die Qualität der späteren Batch-Welle
        if ($type === PromptBuilder::TYPE_MEDIA_ALT && $model === null
            && $this->providerRegistry->activeProviderName($providerName) === 'claude') {
            $batchModel = trim((string) $this->systemConfig->get('ContentCreator.config.batchModel'));
            if ($batchModel !== '') {
                $model = $batchModel;
            }
        }

        $provider = $this->providerRegistry->get($providerName);
        $system = $this->promptBuilder->buildSystem($type, $lang, $mode);

        // Kanal-Variante (nur Kategorie-Typen): Schwerpunkt + Anti-Duplicate-Regeln
        $variantBlock = $this->promptBuilder->buildVariantBlock(
            \is_string($ctx['variantAngle'] ?? null) ? $ctx['variantAngle'] : null,
            $lang
        );
        if ($variantBlock !== '' && str_starts_with($type, 'category_')) {
            $system .= "\n\n" . $variantBlock;
        }

        // Fokus-Keyword: Pflicht-Platzierungen (Title/H1/erster Absatz/Dichte)
        $focusKeyword = trim((string) ($ctx['focusKeyword'] ?? ''));
        if ($focusKeyword !== '') {
            $system .= "\n\n" . $this->promptBuilder->buildFocusBlock($focusKeyword, $lang);
        }

        // Web-Recherche (Claude: web_search-Server-Tool, OpenAI: Responses-API-Tool).
        // filter_var statt (bool): system:config:set speichert Bools als String —
        // "false" wäre sonst truthy (bekannte Shopware-CLI-Falle).
        $allowWebSearch = $provider->supportsWebSearch()
            && filter_var($this->systemConfig->get('ContentCreator.config.researchEnabled'), \FILTER_VALIDATE_BOOLEAN)
            && $type !== PromptBuilder::TYPE_MEDIA_ALT;
        if ($allowWebSearch && $focusKeyword !== '') {
            // SERP-Briefing (RankMath-Content-AI-Muster): Recherche gezielt auf
            // die Suchlandschaft des Fokus-Keywords richten statt frei zu suchen
            $system .= "\n\n" . ($lang === 'en'
                ? "SERP BRIEFING: First analyse the top web results for \"{$focusKeyword}\": which related terms, typical questions (who/what/how/why) and topics do they cover? Use this as a briefing — cover the relevant aspects better and more concretely, without copying anything."
                : "SERP-BRIEFING: Analysiere zuerst die Top-Suchergebnisse zu \"{$focusKeyword}\": Welche verwandten Begriffe, typischen Fragen (wer/was/wie/warum) und Themen decken sie ab? Nutze das als Briefing — behandle die relevanten Aspekte besser und konkreter, ohne etwas zu kopieren.");
        }
        if ($allowWebSearch) {
            $system .= "\n\n" . ($lang === 'en'
                ? 'RESEARCH: Before writing, briefly research the web (manufacturer website, encyclopaedias, specialist sites) to gather facts (materials, use cases, animal facts). NEVER copy sentences — facts in your own words. Do not invent anything you could not verify.'
                : 'RECHERCHE: Recherchiere vor dem Schreiben kurz im Web (Herstellerwebseite, Lexika, Fachseiten), um Fakten zu sammeln (Materialien, Einsatzbereiche, Tier-Fakten). NIEMALS Sätze übernehmen — Fakten in eigenen Worten. Erfinde nichts, was du nicht verifizieren konntest.');
        }

        $baseUser = $this->promptBuilder->buildUser($type, $lang, $ctx, $mode, $metaFields);

        // Alt-Text-Übersetzung: existiert der Alt bereits in der Standardsprache,
        // wird übersetzt statt das Bild erneut per Vision zu analysieren
        $translateSource = $type === PromptBuilder::TYPE_MEDIA_ALT ? trim((string) ($ctx['translateFromAlt'] ?? '')) : '';
        if ($translateSource !== '') {
            $system = $this->promptBuilder->buildAltTranslationSystem($lang);
            $baseUser = $this->promptBuilder->buildAltTranslationUser($translateSource, $lang, $ctx);
            $ctx['imageUrl'] = null;
        }

        $threshold = $this->maxScore();
        $maxAttempts = 1 + $this->maxRetries();
        $whitelist = $this->whitelist();
        $checkFacts = $mode === PromptBuilder::MODE_OPTIMIZE && !$isMeta && $type !== PromptBuilder::TYPE_MEDIA_ALT;
        $originalScore = $checkFacts ? $this->qualityChecker->analyse($existingText, $lang, $whitelist)['score'] : null;

        $usage = ['input' => 0, 'output' => 0];
        $feedback = [];
        $best = null;
        $bestWeight = \PHP_INT_MAX;
        $resultModel = $model;

        // Bild einmalig serverseitig laden und als Base64 mitschicken —
        // unabhängig von robots.txt, Bot-Blockern und Wartungsmodus
        $image = [];
        if ($type === PromptBuilder::TYPE_MEDIA_ALT) {
            // Thumbnail zuerst (immer unter dem Größen-Limit), dann Original
            $image = $this->fetchImage((string) ($ctx['imageUrlSmall'] ?? ''))
                ?: $this->fetchImage((string) ($ctx['imageUrl'] ?? ''));
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $userPrompt = $baseUser;
            if ($feedback !== []) {
                $userPrompt .= "\n\n" . implode("\n\n", array_filter($feedback));
            }
            $feedback = [];

            $result = $provider->generate(new AiRequest(
                system: $system,
                userPrompt: $userPrompt,
                maxTokens: $this->maxTokensFor($type),
                model: $model,
                imageUrl: $type === PromptBuilder::TYPE_MEDIA_ALT ? ($ctx['imageUrl'] ?? null) : null,
                imageB64: $image['b64'] ?? null,
                imageMime: $image['mime'] ?? null,
                allowWebSearch: $allowWebSearch
            ));
            $usage['input'] += $result->inputTokens;
            $usage['output'] += $result->outputTokens;
            $resultModel = $result->model;

            if ($isMeta) {
                $meta = $this->tryParseMeta($result->text);
                if ($meta === null) {
                    if ($attempt >= $maxAttempts) {
                        throw new \RuntimeException('Die KI-Antwort war kein gültiges Meta-JSON: ' . $result->text);
                    }
                    $feedback[] = $this->jsonFeedback($lang);
                    continue;
                }

                // Nicht angeforderte Felder deterministisch auf Bestand zurücksetzen
                $meta = $this->pinUnselectedFields($meta, $ctx, $mode, $metaFields);

                $analysis = $this->qualityChecker->analyse($this->metaScoreText($meta, $mode, $metaFields), $lang, $whitelist);
                $lengthIssues = $this->metaLengthIssues($meta, $lang, $mode, $metaFields);
                $passed = $analysis['score'] <= $threshold && $lengthIssues === [];

                $weight = \count($lengthIssues) * 1000 + $analysis['score'];
                if ($weight < $bestWeight) {
                    $bestWeight = $weight;
                    $best = [
                        'meta' => $meta,
                        'content' => null,
                        'analysis' => $analysis,
                        'lengthIssues' => $lengthIssues,
                        'missingFacts' => [],
                        'passed' => $passed,
                        'attempt' => $attempt,
                        'raw' => $result->text,
                    ];
                }

                if ($passed) {
                    break;
                }

                $feedback[] = $this->lengthFeedback($lengthIssues, $lang);
                if ($analysis['score'] > $threshold) {
                    $feedback[] = $this->qualityChecker->promptFeedback($analysis['findings'], $lang);
                }
                continue;
            }

            $content = $this->cleanContent($result->text);
            if ($content === '') {
                // Leere Antwort (z.B. incomplete/Truncation) darf NIE als Erfolg zählen
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Die KI-Antwort war leer (Status: ' . ($result->stopReason ?? 'unbekannt') . ').');
                }
                $feedback[] = $lang === 'en'
                    ? 'YOUR PREVIOUS ANSWER WAS EMPTY. Return the requested text now.'
                    : 'DEINE VORHERIGE ANTWORT WAR LEER. Gib jetzt den angeforderten Text zurück.';
                continue;
            }
            $analysis = $this->qualityChecker->analyse($content, $lang, $whitelist);
            $missing = $checkFacts
                ? $this->factGuard->missingFacts($existingText, $content, isset($ctx['mpn']) ? (string) $ctx['mpn'] : null)
                : [];
            $passed = $missing === [] && $analysis['score'] <= $threshold;

            $weight = \count($missing) * 1000 + $analysis['score'];
            if ($weight < $bestWeight) {
                $bestWeight = $weight;
                $best = [
                    'meta' => null,
                    'content' => $content,
                    'analysis' => $analysis,
                    'lengthIssues' => [],
                    'missingFacts' => $missing,
                    'passed' => $passed,
                    'attempt' => $attempt,
                    'raw' => $result->text,
                ];
            }

            if ($passed) {
                break;
            }

            if ($missing !== []) {
                $feedback[] = $this->factGuard->promptFeedback($missing, $lang);
            }
            if ($analysis['score'] > $threshold) {
                $feedback[] = $this->qualityChecker->promptFeedback($analysis['findings'], $lang);
            }
        }

        if ($best === null) {
            throw new \RuntimeException('Generierung fehlgeschlagen: kein verwertbares Ergebnis.');
        }

        // Letzte Stufe für Meta: fokussierte Längen-Korrektur-Calls (Tool-Muster
        // _fixMetaLengths, "ändere so wenig wie möglich") statt Neugenerierung.
        for ($fixRound = 0; $fixRound < 2; $fixRound++) {
            if (!$isMeta || $best['passed'] || $best['lengthIssues'] === [] || $best['analysis']['score'] > $threshold) {
                break;
            }
            $fixed = $this->fixMetaLengths($best['meta'], $best['lengthIssues'], $provider, $model, $lang, $usage);
            if ($fixed === null) {
                break;
            }
            $meta = array_merge($best['meta'], $fixed);
            $analysis = $this->qualityChecker->analyse($this->metaScoreText($meta, $mode, $metaFields), $lang, $whitelist);
            $lengthIssues = $this->metaLengthIssues($meta, $lang, $mode, $metaFields);
            if (\count($lengthIssues) * 1000 + $analysis['score'] < $bestWeight) {
                $bestWeight = \count($lengthIssues) * 1000 + $analysis['score'];
                $best['meta'] = $meta;
                $best['analysis'] = $analysis;
                $best['lengthIssues'] = $lengthIssues;
                $best['passed'] = $analysis['score'] <= $threshold && $lengthIssues === [];
            }
        }

        $this->usageTracker->record(
            $provider->getName(),
            (string) ($resultModel ?? ''),
            (int) ($usage['input'] ?? 0),
            (int) ($usage['output'] ?? 0)
        );

        return [
            'type' => $type,
            'lang' => $lang,
            'mode' => $mode,
            'provider' => $provider->getName(),
            'model' => $resultModel,
            'content' => $best['content'],
            'meta' => $best['meta'],
            'usage' => $usage,
            'raw' => $best['raw'],
            'quality' => [
                'score' => $best['analysis']['score'],
                'level' => $best['analysis']['level'],
                'threshold' => $threshold,
                'passed' => $best['passed'],
                'attempts' => $best['attempt'],
                'originalScore' => $originalScore,
                'findings' => array_map(
                    static fn (array $f) => [
                        'pattern' => $f['pattern'],
                        'count' => $f['count'],
                        'score' => $f['score'],
                        'severity' => $f['severity'],
                        'alternatives' => \array_slice($f['alternatives'], 0, 4),
                    ],
                    \array_slice($best['analysis']['findings'], 0, 10)
                ),
                'lengthIssues' => $best['lengthIssues'],
                'missingFacts' => $best['missingFacts'],
            ],
            'focusChecks' => $focusKeyword !== ''
                ? $this->focusKeywordChecker->check($focusKeyword, $best['content'], $best['meta'])
                : null,
            'readability' => !$isMeta ? $this->readabilityChecker->check($best['content'], $lang) : null,
        ];
    }

    private function maxTokensFor(string $type): int
    {
        return match ($type) {
            PromptBuilder::TYPE_CATEGORY_DETAIL => 4000,
            PromptBuilder::TYPE_PRODUCT_DESCRIPTION => 2000,
            PromptBuilder::TYPE_CATEGORY_TEASER => 900,
            PromptBuilder::TYPE_FAQ => 1200,
            PromptBuilder::TYPE_PRODUCT_META, PromptBuilder::TYPE_CATEGORY_META, PromptBuilder::TYPE_HOME_META => 700,
            PromptBuilder::TYPE_MEDIA_ALT => 200,
            default => 1500,
        };
    }

    private function maxScore(): int
    {
        $value = $this->systemConfig->get('ContentCreator.config.qualityMaxScore');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_MAX_SCORE;
    }

    private function maxRetries(): int
    {
        $value = $this->systemConfig->get('ContentCreator.config.qualityMaxRetries');

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : self::DEFAULT_MAX_RETRIES;
    }

    /**
     * @return list<string>
     */
    private function whitelist(): array
    {
        return QualityChecker::parseWhitelist(
            (string) $this->systemConfig->get('ContentCreator.config.qualityWhitelist')
        );
    }

    /**
     * Score-Text für Meta: nur die Felder, die tatsächlich generiert/optimiert
     * werden — gepinnte Bestandsfelder dürfen das Gate nicht blockieren.
     *
     * @param array<string, string> $meta
     * @param list<string>|null $metaFields
     */
    private function metaScoreText(array $meta, string $mode, ?array $metaFields): string
    {
        $selective = $mode === PromptBuilder::MODE_OPTIMIZE && $metaFields !== null && $metaFields !== [];
        $parts = [];
        foreach (['metaTitle', 'metaDescription'] as $field) {
            if (!$selective || \in_array($field, $metaFields, true)) {
                $parts[] = $meta[$field];
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Nicht zur Optimierung angeforderte Meta-Felder exakt auf den Bestand pinnen.
     *
     * @param array<string, string> $meta
     * @param array<string, mixed> $ctx
     * @param list<string>|null $metaFields
     *
     * @return array<string, string>
     */
    private function pinUnselectedFields(array $meta, array $ctx, string $mode, ?array $metaFields): array
    {
        if ($mode !== PromptBuilder::MODE_OPTIMIZE || $metaFields === null || $metaFields === []) {
            return $meta;
        }

        $existing = [
            'metaTitle' => (string) ($ctx['existingMetaTitle'] ?? ''),
            'metaDescription' => (string) ($ctx['existingMetaDescription'] ?? ''),
            'metaKeywords' => (string) ($ctx['keywords'] ?? ''),
        ];

        foreach (PromptBuilder::META_FIELDS as $field) {
            if (!\in_array($field, $metaFields, true)) {
                $meta[$field] = $existing[$field];
            }
        }

        return $meta;
    }

    /**
     * @param array<string, string> $meta
     * @param list<string>|null $metaFields
     *
     * @return list<array{field:string, length:int, min:int, max:int}>
     */
    private function metaLengthIssues(array $meta, string $lang, string $mode, ?array $metaFields): array
    {
        $checkedFields = ($mode === PromptBuilder::MODE_OPTIMIZE && $metaFields !== null && $metaFields !== [])
            ? $metaFields
            : PromptBuilder::META_FIELDS;

        $issues = [];

        $tolerance = self::LENGTH_TOLERANCE;

        if (\in_array('metaTitle', $checkedFields, true)) {
            $len = mb_strlen($meta['metaTitle']);
            if ($len < self::TITLE_MIN - $tolerance || $len > self::TITLE_MAX + $tolerance) {
                $issues[] = ['field' => 'metaTitle', 'length' => $len, 'min' => self::TITLE_MIN, 'max' => self::TITLE_MAX];
            }
        }
        if (\in_array('metaDescription', $checkedFields, true)) {
            $len = mb_strlen($meta['metaDescription']);
            if ($len < self::DESC_MIN - $tolerance || $len > self::DESC_MAX + $tolerance) {
                $issues[] = ['field' => 'metaDescription', 'length' => $len, 'min' => self::DESC_MIN, 'max' => self::DESC_MAX];
            }
        }
        if (\in_array('metaKeywords', $checkedFields, true) && trim($meta['metaKeywords']) === '') {
            $issues[] = ['field' => 'metaKeywords', 'length' => 0, 'min' => 1, 'max' => 0];
        }

        return $issues;
    }

    /**
     * Korrektur-Feedback nach dem Muster _fixMetaLengths des Tools.
     *
     * @param list<array{field:string, length:int, min:int, max:int}> $issues
     */
    private function lengthFeedback(array $issues, string $lang): string
    {
        if ($issues === []) {
            return '';
        }

        $de = !str_starts_with(strtolower($lang), 'en');
        $lines = [$de
            ? 'LÄNGEN-KORREKTUR NÖTIG — halte diese Vorgaben ZEICHENGENAU ein, Inhalt und Keywords beibehalten:'
            : 'LENGTH CORRECTION NEEDED — meet these limits EXACTLY, keep content and keywords:'];

        foreach ($issues as $issue) {
            if ($issue['field'] === 'metaKeywords') {
                $lines[] = $de ? '- metaKeywords: darf nicht leer sein.' : '- metaKeywords: must not be empty.';
                continue;
            }
            $tooShort = $issue['length'] < $issue['min'];
            $lines[] = sprintf(
                $de
                    ? '- %s: aktuell %d Zeichen — %s auf %d-%d Zeichen (%s).'
                    : '- %s: currently %d characters — %s to %d-%d characters (%s).',
                $issue['field'],
                $issue['length'],
                $de ? ($tooShort ? 'VERLÄNGERE' : 'KÜRZE') : ($tooShort ? 'EXTEND' : 'SHORTEN'),
                $issue['min'],
                $issue['max'],
                $de
                    ? ($tooShort ? 'z.B. konkreten Nutzen oder Call-to-Action ergänzen' : 'unwichtigste Wörter streichen, Kernaussage behalten')
                    : ($tooShort ? 'e.g. add a concrete benefit or call-to-action' : 'drop the least important words, keep the core message')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Fokussierter Korrektur-Call für Längenverstöße — minimal-invasiv, nur die
     * betroffenen Felder. Gibt die korrigierten Felder zurück (oder null).
     *
     * @param array<string, string> $meta
     * @param list<array{field:string, length:int, min:int, max:int}> $issues
     * @param array{input:int, output:int} $usage
     *
     * @return array<string, string>|null
     */
    private function fixMetaLengths(
        array $meta,
        array $issues,
        Provider\AiProviderInterface $provider,
        ?string $model,
        string $lang,
        array &$usage
    ): ?array {
        $de = !str_starts_with(strtolower($lang), 'en');
        $instructions = [];
        $fields = [];

        foreach ($issues as $issue) {
            if ($issue['field'] === 'metaKeywords') {
                continue; // leere Keywords löst der reguläre Loop, nicht der Längen-Fix
            }
            $tooLong = $issue['length'] > $issue['max'];
            $fields[] = $issue['field'];
            $instructions[] = sprintf(
                $de
                    ? "%s hat %d Zeichen, muss aber %d-%d sein. %s den Text, behalte den Inhalt bei:\n\"%s\""
                    : "%s has %d characters, must be %d-%d. %s the text, keep the meaning:\n\"%s\"",
                $issue['field'],
                $issue['length'],
                $issue['min'],
                $issue['max'],
                $de ? ($tooLong ? 'Kürze' : 'Verlängere') : ($tooLong ? 'Shorten' : 'Lengthen'),
                $meta[$issue['field']]
            );
        }

        if ($instructions === []) {
            return null;
        }

        $system = $de
            ? 'Korrigiere die Zeichenlänge. Ändere so wenig wie möglich. Antworte NUR mit dem geforderten JSON.'
            : 'Fix the character length. Change as little as possible. Reply ONLY with the requested JSON.';
        $format = '{' . implode(',', array_map(static fn (string $f) => '"' . $f . '":"..."', $fields)) . '}';
        $user = implode("\n\n", $instructions) . "\n\n"
            . ($de ? 'Antworte NUR als JSON: ' : 'Reply ONLY as JSON: ') . $format;

        try {
            $result = $provider->generate(new AiRequest(system: $system, userPrompt: $user, maxTokens: 700, model: $model));
        } catch (\Throwable) {
            return null;
        }
        $usage['input'] += $result->inputTokens;
        $usage['output'] += $result->outputTokens;

        $data = json_decode($this->extractJson($result->text), true);
        if (!\is_array($data)) {
            return null;
        }

        $fixed = [];
        foreach ($fields as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $fixed[$field] = $value;
            }
        }

        return $fixed !== [] ? $fixed : null;
    }

    private function jsonFeedback(string $lang): string
    {
        return str_starts_with(strtolower($lang), 'en')
            ? 'YOUR PREVIOUS ANSWER WAS NOT VALID JSON. Return ONLY the JSON object {"metaTitle":"...","metaDescription":"...","metaKeywords":"..."} — no markdown, no code block, no commentary.'
            : 'DEINE VORHERIGE ANTWORT WAR KEIN GÜLTIGES JSON. Gib AUSSCHLIESSLICH das JSON-Objekt {"metaTitle":"...","metaDescription":"...","metaKeywords":"..."} zurück — ohne Markdown, ohne Codeblock, ohne Kommentar.';
    }

    /**
     * @return array<string, string>|null
     */
    private function tryParseMeta(string $text): ?array
    {
        $json = $this->extractJson($text);
        $data = json_decode($json, true);

        if (!\is_array($data)) {
            return null;
        }

        return [
            'metaTitle' => trim((string) ($data['metaTitle'] ?? '')),
            'metaDescription' => trim((string) ($data['metaDescription'] ?? '')),
            'metaKeywords' => trim((string) ($data['metaKeywords'] ?? ($data['keywords'] ?? ''))),
        ];
    }

    private function extractJson(string $text): string
    {
        $t = trim($text);
        // eventuelle Codeblock-Markierungen entfernen
        $t = (string) preg_replace('/```[a-zA-Z]*\s*/', '', $t);
        $t = str_replace('```', '', $t);
        if (preg_match('/\{.*\}/s', $t, $m) === 1) {
            return $m[0];
        }

        return $t;
    }

    private function cleanContent(string $text): string
    {
        $t = trim($text);
        $t = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
        $t = (string) preg_replace('/\s*```$/', '', $t);

        // Recherche-Zitate entfernen: Modelle betten bei aktivem web_search
        // Markdown-Quelllinks ein — "([domain](url))" komplett streichen,
        // nackte "[text](url)"-Links auf den Text reduzieren.
        $t = (string) preg_replace('/\s*\(\[[^\]]*\]\([^)]*\)\)/u', '', $t);
        $t = (string) preg_replace('/\[([^\]]*)\]\((?:https?|utm)[^)]*\)/u', '$1', $t);

        return trim($t);
    }

    /**
     * Bild für Vision serverseitig laden (max. ~4,5 MB wegen API-Limits).
     *
     * @return array{b64?: string, mime?: string} leer = Fallback auf die URL
     */
    private function fetchImage(string $url): array
    {
        if ($url === '') {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 15, 'verify_peer' => false, 'verify_host' => false]);
            $data = $response->getContent();
            if ($data === '' || \strlen($data) > 4_500_000) {
                return [];
            }
            $mime = $response->getHeaders()['content-type'][0] ?? 'image/jpeg';
            $mime = explode(';', $mime)[0];
            if (!str_starts_with($mime, 'image/')) {
                return [];
            }

            return ['b64' => base64_encode($data), 'mime' => $mime];
        } catch (\Throwable) {
            return [];
        }
    }
}
