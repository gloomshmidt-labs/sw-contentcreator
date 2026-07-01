<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Fakten-Erhalt-Gate für den Optimieren-Modus: Zahlen (inkl. Einheiten) und die
 * MPN des Originals müssen im optimierten Text erhalten bleiben — sonst wird der
 * Versuch abgelehnt. Angelehnt an checkFactsPreserved aus dem Textoptimierung-Tool
 * (dort LLM-extrahierte Fakten mit Fuzzy-Match; hier deterministisch: Zahlen/MPN
 * sind die kritischen, programmatisch prüfbaren Fakten).
 */
class FactGuard
{
    /**
     * @return list<string> fehlende Fakten (leer = alles erhalten)
     */
    public function missingFacts(string $original, string $candidate, ?string $mpn = null): array
    {
        $missing = [];
        $normalizedCandidate = $this->normalize($candidate);

        foreach ($this->extractNumbers($original) as $fact) {
            if (!str_contains($normalizedCandidate, $this->normalize($fact))) {
                $missing[] = $fact;
            }
        }

        if ($mpn !== null && trim($mpn) !== '') {
            $normalizedOriginal = $this->normalize($original);
            $normalizedMpn = $this->normalize($mpn);
            // MPN nur einfordern, wenn sie im Original überhaupt vorkam
            if (str_contains($normalizedOriginal, $normalizedMpn)
                && !str_contains($normalizedCandidate, $normalizedMpn)) {
                $missing[] = $mpn;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param list<string> $missing
     */
    public function promptFeedback(array $missing, string $lang): string
    {
        if ($missing === []) {
            return '';
        }

        $de = !str_starts_with(strtolower($lang), 'en');
        $list = '"' . implode('", "', \array_slice($missing, 0, 10)) . '"';

        return $de
            ? "DEIN VORHERIGER VERSUCH WURDE ABGELEHNT — folgende Fakten aus dem Original fehlten: {$list}. Schreibe den Text erneut und übernimm diese Angaben EXAKT."
            : "YOUR PREVIOUS ATTEMPT WAS REJECTED — these facts from the original were missing: {$list}. Rewrite the text and keep these details EXACTLY.";
    }

    /**
     * Zahlen mit optionaler Einheit aus dem Original extrahieren (Plaintext-Basis).
     *
     * @return list<string>
     */
    private function extractNumbers(string $text): array
    {
        $plain = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        preg_match_all('/\d+(?:[.,]\d+)?\s*(?:cm|mm|m|kg|g|%|°C|Jahre?n?|years?)?/iu', $plain, $m);

        return array_values(array_unique(array_map('trim', $m[0])));
    }

    private function normalize(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return mb_strtolower((string) preg_replace('/\s+/u', '', $plain));
    }
}
