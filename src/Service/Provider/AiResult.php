<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

/**
 * Provider-neutrales Ergebnis eines LLM-Aufrufs.
 */
class AiResult
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $stopReason = null,
        public ?string $model = null,
        /** In den Prompt-Cache geschriebene Tokens (Abrechnung ~1,25x Input-Preis). */
        public int $cacheCreationTokens = 0,
        /** Aus dem Prompt-Cache gelesene Tokens (Abrechnung ~0,1x Input-Preis). */
        public int $cacheReadTokens = 0,
    ) {
    }
}
