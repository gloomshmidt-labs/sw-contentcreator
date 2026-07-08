<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

/**
 * Provider-neutrale Anfrage an ein LLM.
 */
class AiRequest
{
    public function __construct(
        public string $system,
        public string $userPrompt,
        public int $maxTokens = 4000,
        public ?string $model = null,
        /** Optionale Bild-URL für Vision (z.B. Media-Alt-Texte). */
        public ?string $imageUrl = null,
        /** Bild als Base64 (bevorzugt vor imageUrl — unabhängig von robots.txt/Bot-Blockern). */
        public ?string $imageB64 = null,
        public ?string $imageMime = null,
        /** Web-Recherche erlauben (nur Claude: web_search-Server-Tool). */
        public bool $allowWebSearch = false,
        /**
         * Variabler System-Zusatz (z.B. Fokus-Keyword-Block): landet HINTER dem
         * Prompt-Cache-Breakpoint, damit der stabile Teil in `system` über alle
         * Objekte eines Laufs hinweg cachebar bleibt (Prefix-Match!).
         */
        public ?string $systemSuffix = null,
    ) {
    }
}
