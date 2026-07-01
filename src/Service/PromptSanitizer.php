<?php declare(strict_types=1);

namespace ContentCreator\Service;

/**
 * Schutz vor Prompt-Injection: Shop-Inhalte (Bestandstexte, Namen, Keywords)
 * werden als Daten in Prompts eingebettet und könnten Anweisungen enthalten.
 * Portiert aus dem Textoptimierung-Tool (_sanitizeForPrompt) — Rollen-Präfixe
 * und "Ignoriere alle Anweisungen"-Muster werden durch [filtered] ersetzt,
 * Triple-Quote-Delimiter im Text werden entschärft.
 */
final class PromptSanitizer
{
    private const PATTERNS = [
        '/\b(system|assistant|user)\s*:/iu',
        '/\bignorier\w*\s+(alle\s+|die\s+|bitte\s+)*(vorherig\w*|bisherig\w*|obig\w*|alle)\s*(anweisung\w*|instruktion\w*|regeln?|prompts?)?/iu',
        '/\bignore\s+(all\s+|any\s+|the\s+)*(previous|prior|above|earlier|all)\s*(instructions?|rules?|prompts?)?/iu',
        '/\bdisregard\s+(all\s+|any\s+|the\s+)*(previous|prior|above|earlier)\s+(instructions?|rules?|prompts?)/iu',
        '/\bneue\s+anweisung(en)?\b/iu',
        '/\bnew\s+instructions?\b/iu',
    ];

    public static function sanitize(string $text): string
    {
        // Delimiter unbrechbar halten: """ im Inhalt darf den Fakten-Block nicht beenden
        $text = str_replace('"""', '"', $text);

        foreach (self::PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, '[filtered]', $text);
        }

        return trim($text);
    }
}
