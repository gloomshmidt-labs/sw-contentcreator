<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.38.0: Prompt-Caching-Spalten für die Verbrauchsanzeige — Cache-Writes
 * (~1,25x Input-Preis) und Cache-Reads (~0,1x) werden von Anthropic getrennt
 * von input_tokens gemeldet und fließen so in die Kosten-Schätzung ein.
 */
class Migration1783010000AddUsageCacheTokens extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1783010000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `content_creator_usage`');
        if (!\in_array('cache_creation_tokens', $columns, true)) {
            $connection->executeStatement('
                ALTER TABLE `content_creator_usage`
                    ADD COLUMN `cache_creation_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `output_tokens`,
                    ADD COLUMN `cache_read_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `cache_creation_tokens`;
            ');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
