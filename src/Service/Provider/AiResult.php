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
    ) {
    }
}
