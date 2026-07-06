<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * On-Page-Checks für das Fokus-Keyword (Yoast/RankMath-Muster, deterministisch):
 * Sitzt das Keyword in Meta-Title/-Description, in der Überschriften-Struktur,
 * im ersten Absatz, und stimmt die Dichte im Fließtext (0,5-2,5 %)?
 * Rein informativ — kein hartes Gate (das Keyword steuert bereits den Prompt).
 */
class FocusKeywordChecker
{
    private const DENSITY_MIN = 0.5;
    private const DENSITY_MAX = 2.5;

    /**
     * @param array<string, string>|null $meta
     *
     * @return array{keyword: string, checks: list<array{key: string, passed: bool, detail: string}>, passedCount: int, total: int}|null
     */
    public function check(string $keyword, ?string $html, ?array $meta): ?array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return null;
        }

        $needle = mb_strtolower($keyword);
        $checks = [];

        if ($meta !== null) {
            $checks[] = $this->result('metaTitle', str_contains(mb_strtolower($meta['metaTitle'] ?? ''), $needle), '');
            $checks[] = $this->result('metaDescription', str_contains(mb_strtolower($meta['metaDescription'] ?? ''), $needle), '');
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        if ($plain !== '') {
            $lower = mb_strtolower($plain);

            // Überschriften-Struktur (H1-H3)
            preg_match_all('/<(h[1-3])[^>]*>(.*?)<\/\1>/is', (string) $html, $headings, \PREG_SET_ORDER);
            $headingText = mb_strtolower(strip_tags(implode(' ', array_map(static fn (array $m) => $m[2], $headings))));
            if ($headingText !== '') {
                $checks[] = $this->result('headings', str_contains($headingText, $needle), '');
            }

            // Erster Absatz (erste ~100 Wörter)
            $firstWords = implode(' ', \array_slice(explode(' ', $lower), 0, 100));
            $checks[] = $this->result('firstParagraph', str_contains($firstWords, $needle), '');

            // Dichte: Keyword-WÖRTER relativ zur Wortzahl (Mehrwort-Keywords zählen
            // mit ihrer Wortanzahl, sonst wird die Dichte systematisch unterschätzt)
            $wordCount = max(1, \count(explode(' ', $lower)));
            $keywordWordCount = max(1, \count(preg_split('/\s+/u', $needle) ?: []));
            $occurrences = mb_substr_count($lower, $needle);
            $density = round((($occurrences * $keywordWordCount) / $wordCount) * 100, 2);
            $checks[] = $this->result(
                'density',
                $occurrences > 0 && $density >= self::DENSITY_MIN && $density <= self::DENSITY_MAX,
                $occurrences . 'x / ' . $density . '%',
            );
        }

        if ($checks === []) {
            return null;
        }

        return [
            'keyword' => $keyword,
            'checks' => $checks,
            'passedCount' => \count(array_filter($checks, static fn (array $c) => $c['passed'])),
            'total' => \count($checks),
        ];
    }

    /**
     * @return array{key: string, passed: bool, detail: string}
     */
    private function result(string $key, bool $passed, string $detail): array
    {
        return ['key' => $key, 'passed' => $passed, 'detail' => $detail];
    }
}
