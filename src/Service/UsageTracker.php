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

    public function record(string $provider, string $model, int $inputTokens, int $outputTokens): void
    {
        if ($inputTokens <= 0 && $outputTokens <= 0) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO content_creator_usage (id, `month`, provider, model, input_tokens, output_tokens, requests, updated_at)
             VALUES (UNHEX(:id), :month, :provider, :model, :input, :output, 1, NOW(3))
             ON DUPLICATE KEY UPDATE
                input_tokens = input_tokens + VALUES(input_tokens),
                output_tokens = output_tokens + VALUES(output_tokens),
                requests = requests + 1,
                updated_at = NOW(3)',
            [
                'id' => Uuid::randomHex(),
                'month' => (new \DateTimeImmutable())->format('Y-m'),
                'provider' => mb_substr($provider, 0, 32),
                'model' => mb_substr($model !== '' ? $model : 'default', 0, 64),
                'input' => $inputTokens,
                'output' => $outputTokens,
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
                    input_tokens AS inputTokens, output_tokens AS outputTokens, requests
             FROM content_creator_usage
             ORDER BY `month` DESC, provider ASC, model ASC
             LIMIT 36',
        );
    }
}
