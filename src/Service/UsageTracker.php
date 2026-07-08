<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Kumuliert den API-Verbrauch (Tokens/Requests) je Monat, Provider und Modell.
 * Erfasst zentral in ContentGenerator::generate — deckt Einzeltexte, Batch,
 * CLI und Cron ab.
 */
class UsageTracker
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $provider, string $model, int $inputTokens, int $outputTokens, int $cacheCreationTokens = 0, int $cacheReadTokens = 0): void
    {
        if ($inputTokens <= 0 && $outputTokens <= 0 && $cacheCreationTokens <= 0 && $cacheReadTokens <= 0) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO content_creator_usage (id, `month`, provider, model, input_tokens, output_tokens, cache_creation_tokens, cache_read_tokens, requests, updated_at)
             VALUES (UNHEX(:id), :month, :provider, :model, :input, :output, :cacheCreation, :cacheRead, 1, NOW(3))
             ON DUPLICATE KEY UPDATE
                input_tokens = input_tokens + VALUES(input_tokens),
                output_tokens = output_tokens + VALUES(output_tokens),
                cache_creation_tokens = cache_creation_tokens + VALUES(cache_creation_tokens),
                cache_read_tokens = cache_read_tokens + VALUES(cache_read_tokens),
                requests = requests + 1,
                updated_at = NOW(3)',
            [
                'id' => Uuid::randomHex(),
                'month' => (new \DateTimeImmutable())->format('Y-m'),
                'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model !== '' ? $model : 'default', 0, 64),
                'input' => $inputTokens,
                'output' => $outputTokens,
                'cacheCreation' => $cacheCreationTokens,
                'cacheRead' => $cacheReadTokens,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>> Monatszeilen, neueste zuerst (max. 12 Monate)
     */
    public function report(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT `month`, provider, model,
                    input_tokens AS inputTokens, output_tokens AS outputTokens,
                    cache_creation_tokens AS cacheCreationTokens, cache_read_tokens AS cacheReadTokens, requests
             FROM content_creator_usage
             ORDER BY `month` DESC, provider ASC, model ASC
             LIMIT 36',
        );
    }
}
