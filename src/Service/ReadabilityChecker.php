<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Deterministische Readability-Checks (Yoast-Muster) für generierte Texte:
 * Satzlängen-Verteilung, Passiv-Quote, Absatzlänge, Überschriften-Dichte.
 * Rein informativ (kein Gate) — die Prompts fordern die Regeln bereits ein;
 * hier wird sichtbar, ob das Modell sie eingehalten hat.
 */
class ReadabilityChecker
{
    private const LONG_SENTENCE_WORDS = 25;
    private const LONG_SENTENCE_MAX_SHARE = 25.0;
    private const PASSIVE_MAX_SHARE = 20.0;
    private const PARAGRAPH_MAX_WORDS = 150;
    private const WORDS_PER_HEADING = 300;

    /**
     * @return array{checks: list<array{key: string, passed: bool, detail: string}>, passedCount: int, total: int}|null
     */
    public function check(?string $html, string $lang): ?array
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        if ($plain === '' || mb_strlen($plain) < 100) {
            return null;
        }

        $isEnglish = str_starts_with(strtolower($lang), 'en');
        $sentences = array_values(array_filter(
            preg_split('/(?<=[.!?])\s+(?=[A-ZÄÖÜ])/u', $plain) ?: [],
            static fn (string $s) => str_word_count($s) > 2
        ));
        if ($sentences === []) {
            return null;
        }

        $checks = [];

        // Satzlängen: Anteil Sätze über 25 Wörter
        $longCount = \count(array_filter($sentences, static fn (string $s) => \count(preg_split('/\s+/u', trim($s)) ?: []) > self::LONG_SENTENCE_WORDS));
        $longShare = round(($longCount / \count($sentences)) * 100, 1);
        $checks[] = ['key' => 'sentenceLength', 'passed' => $longShare <= self::LONG_SENTENCE_MAX_SHARE, 'detail' => $longShare . '% > ' . self::LONG_SENTENCE_WORDS . ' ' . ($isEnglish ? 'words' : 'Wörter')];

        // Passiv-Quote (Heuristik je Sprache)
        $passivePattern = $isEnglish
            ? '/\b(?:is|are|was|were|been|being)\s+\w+(?:ed|en)\b/i'
            : '/\b(?:wird|werden|wurde|wurden|worden)\s+(?:\w+\s+){0,3}?\w+(?:t|en)\b/iu';
        $passiveCount = \count(array_filter($sentences, static fn (string $s) => preg_match($passivePattern, $s) === 1));
        $passiveShare = round(($passiveCount / \count($sentences)) * 100, 1);
        $checks[] = ['key' => 'passiveVoice', 'passed' => $passiveShare <= self::PASSIVE_MAX_SHARE, 'detail' => $passiveShare . '%'];

        // Längster Absatz
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', (string) $html, $paragraphs);
        $maxParagraphWords = 0;
        foreach ($paragraphs[1] ?? [] as $paragraph) {
            $words = \count(preg_split('/\s+/u', trim(strip_tags($paragraph))) ?: []);
            $maxParagraphWords = max($maxParagraphWords, $words);
        }
        if ($maxParagraphWords > 0) {
            $checks[] = ['key' => 'paragraphLength', 'passed' => $maxParagraphWords <= self::PARAGRAPH_MAX_WORDS, 'detail' => 'max. ' . $maxParagraphWords . ' ' . ($isEnglish ? 'words' : 'Wörter')];
        }

        // Überschriften-Dichte bei Langtexten
        $wordCount = \count(preg_split('/\s+/u', $plain) ?: []);
        if ($wordCount > self::WORDS_PER_HEADING) {
            $headingCount = preg_match_all('/<h[1-6][^>]*>/i', (string) $html);
            $checks[] = [
                'key' => 'headingDensity',
                'passed' => $headingCount > 0 && ($wordCount / $headingCount) <= self::WORDS_PER_HEADING,
                'detail' => $headingCount . ' / ' . $wordCount . ' ' . ($isEnglish ? 'words' : 'Wörter'),
            ];
        }

        return [
            'checks' => $checks,
            'passedCount' => \count(array_filter($checks, static fn (array $c) => $c['passed'])),
            'total' => \count($checks),
        ];
    }
}
