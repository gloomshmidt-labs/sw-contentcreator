<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Serverseitiges KI-Muster-Scoring — Portierung des aiPatterns-Scans aus der
 * Engine des Textoptimierung-Tools (engine.js _detectAiPatterns/_buildInflectedRegex),
 * Regeldaten aus src/Resources/rules/rules-{de,en}.json (aus rules-de/en.js extrahiert).
 *
 * Bewusste Abweichung: Struktur-/Natürlichkeits-Heuristiken bleiben client-seitig
 * (Anzeige); das serverseitige Gate stützt sich auf den deterministischen Muster-Scan,
 * der den Löwenanteil des Scores liefert und als Retry-Feedback direkt umsetzbar ist.
 */
class QualityChecker
{
    /** Score-Bänder wie engine.js _getRating */
    private const LEVELS = [
        [10, 'excellent'],
        [30, 'good'],
        [60, 'moderate'],
        [100, 'poor'],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $rules = [];

    /**
     * Kommagetrennten Config-Wert (qualityWhitelist) in eine Musterliste zerlegen.
     *
     * @return list<string>
     */
    public static function parseWhitelist(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @param list<string> $whitelist Muster, die nicht gewertet werden (Config qualityWhitelist)
     *
     * @return array{score:int, level:string, findings:list<array{pattern:string, score:int, count:int, severity:string, alternatives:list<string>}>}
     */
    public function analyse(string $text, string $lang, array $whitelist = []): array
    {
        $lang = str_starts_with(strtolower($lang), 'en') ? 'en' : 'de';
        $rules = $this->rules($lang);
        $whitelist = array_filter(array_map(static fn (string $w) => mb_strtolower(trim($w)), $whitelist));

        $plain = $this->toPlainText($text);
        $lower = mb_strtolower($plain);

        $allPatterns = array_merge(
            $rules['aiPatterns']['strong'] ?? [],
            $rules['aiPatterns']['medium'] ?? [],
            $rules['aiPatterns']['weak'] ?? []
        );
        $allAlternatives = array_merge(
            $rules['alternatives']['strong'] ?? [],
            $rules['alternatives']['medium'] ?? [],
            $rules['alternatives']['weak'] ?? []
        );

        $score = 0;
        $findings = [];

        foreach ($allPatterns as $p) {
            // Teilstring-Match wie in der Admin-Anzeige — Whitelist-Eintrag "hochwertig"
            // deckt auch "hochwertige verarbeitung" ab (konsistente Wertung beider Welten)
            $patternLower = mb_strtolower((string) $p['pattern']);
            foreach ($whitelist as $whitelisted) {
                if (str_contains($patternLower, $whitelisted)) {
                    continue 2;
                }
            }
            $regex = $this->buildInflectedRegex((string) $p['pattern'], $lang);
            if (preg_match_all($regex, $lower, $matches, \PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            $patternScore = 0;
            foreach ($matches[0] as [$matched, $offset]) {
                $s = (int) $p['score'];
                if (isset($p['context'])) {
                    $window = substr($lower, max(0, $offset - 50), \strlen($matched) + 100);
                    if (!str_contains($window, mb_strtolower((string) $p['context']))) {
                        $s = intdiv($s, 2);
                    }
                }
                $patternScore += $s;
            }

            $score += $patternScore;
            $perHit = intdiv($patternScore, \count($matches[0]));
            $findings[] = [
                'pattern' => (string) $p['pattern'],
                'score' => $patternScore,
                'count' => \count($matches[0]),
                'severity' => $perHit >= 10 ? 'high' : ($perHit >= 4 ? 'medium' : 'low'),
                'alternatives' => array_values($allAlternatives[$p['pattern']] ?? []),
            ];
        }

        usort($findings, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return ['score' => $score, 'level' => $this->level($score), 'findings' => $findings];
    }

    /**
     * Retry-Feedback fürs LLM — Format wie das patternHint des Tools
     * (llm-validator.js rewriteWithFacts): Muster + konkrete Alternativen.
     *
     * @param list<array{pattern:string, alternatives:list<string>}> $findings
     */
    public function promptFeedback(array $findings, string $lang): string
    {
        if ($findings === []) {
            return '';
        }

        $de = !str_starts_with(strtolower($lang), 'en');
        $lines = [$de
            ? 'DEIN TEXT ENTHÄLT NOCH KI-TYPISCHE MUSTER — ERSETZE SIE:'
            : 'YOUR TEXT STILL CONTAINS AI-TYPICAL PATTERNS — REPLACE THEM:'];

        foreach (\array_slice($findings, 0, 15) as $f) {
            $line = '- "' . $f['pattern'] . '"';
            $alts = \array_slice($f['alternatives'], 0, 4);
            if ($alts !== []) {
                $line .= ($de ? ' → verwende z.B.: ' : ' → use e.g.: ') . '"' . implode('", "', $alts) . '"';
            } else {
                $line .= $de ? ' → umformulieren oder weglassen' : ' → rephrase or drop it';
            }
            $lines[] = $line;
        }

        $lines[] = $de
            ? 'Schreibe den Text entsprechend um. Fakten und HTML-Struktur bleiben unverändert.'
            : 'Rewrite the text accordingly. Facts and HTML structure stay unchanged.';

        return implode("\n", $lines);
    }

    private function level(int $score): string
    {
        foreach (self::LEVELS as [$max, $level]) {
            if ($score <= $max) {
                return $level;
            }
        }

        return 'critical';
    }

    private function toPlainText(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $plain));
    }

    /**
     * Portierung von engine.js _buildInflectedRegex: DE-Einzelwörter mit
     * Adjektiv-/Verb-Endungen, DE-Mehrwortmuster mit Konjugations-Flex pro Wort,
     * EN mit strikten Wortgrenzen. \pL-Lookarounds statt \b (Umlaut-sicher).
     */
    private function buildInflectedRegex(string $pattern, string $lang): string
    {
        $isSingleWord = !str_contains($pattern, ' ') && mb_strlen($pattern) > 2;

        if ($lang === 'de' && $isSingleWord) {
            $body = preg_quote($pattern, '/') . '(?:e[rsnm]?|em|en)?';

            return '/(?<!\pL)' . $body . '(?!\pL)/iu';
        }

        if ($lang === 'de' && str_contains($pattern, ' ')) {
            $words = preg_split('/\s+/', $pattern) ?: [];
            $parts = [];
            $last = \count($words) - 1;
            foreach ($words as $idx => $word) {
                $parts[] = $this->germanWordFlex($word, $idx === $last);
            }

            return '/(?<!\pL)' . implode('\s+', $parts) . '(?=\s|[.,;:!?)]|$)/iu';
        }

        if ($isSingleWord) {
            return '/(?<!\pL)' . preg_quote($pattern, '/') . '(?!\pL)/iu';
        }

        return '/(?<!\pL)' . preg_quote($pattern, '/') . '(?=\s|[.,;:!?)]|$)/iu';
    }

    /** Portierung von engine.js _germanWordFlex (Verb-Konjugation, Adjektiv-Endungen). */
    private function germanWordFlex(string $word, bool $isLastWord): string
    {
        $escaped = preg_quote($word, '/');

        if (mb_strlen($word) <= 4) {
            return $escaped;
        }

        if (str_ends_with($word, 'tet')) {
            return mb_substr($escaped, 0, -3) . '(?:tet|ten|te|test)';
        }
        if (str_ends_with($word, 'ert') && mb_strlen($word) > 4) {
            return mb_substr($escaped, 0, -3) . '(?:ert|ern|ere|erst)';
        }
        if (str_ends_with($word, 'et')) {
            return mb_substr($escaped, 0, -2) . '(?:et|en|e|est)';
        }
        if (str_ends_with($word, 'en')) {
            return mb_substr($escaped, 0, -2) . '(?:en|et|t|e|est)';
        }
        if (str_ends_with($word, 't')) {
            return mb_substr($escaped, 0, -1) . '(?:t|en|e|est)';
        }

        if ($isLastWord) {
            return $escaped . '(?:e[rsnm]?|em|en)?';
        }

        return $escaped;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $lang): array
    {
        if (!isset($this->rules[$lang])) {
            $file = __DIR__ . '/../Resources/rules/rules-' . $lang . '.json';
            $data = json_decode((string) file_get_contents($file), true);
            if (!\is_array($data)) {
                throw new \RuntimeException('ContentCreator: Regeldatei fehlt oder ist ungültig: ' . $file);
            }
            $this->rules[$lang] = $data;
        }

        return $this->rules[$lang];
    }
}
