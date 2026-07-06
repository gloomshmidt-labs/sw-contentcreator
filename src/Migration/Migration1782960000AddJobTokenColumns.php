<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.5.0: Token-Verbrauch pro Batch-Job (Kosten-Tracking).
 */
class Migration1782960000AddJobTokenColumns extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782960000;
    }

    public function update(Connection $connection): void
    {
        $columns = array_column($connection->fetchAllAssociative(
            'SHOW COLUMNS FROM `content_creator_generation_job`',
        ), 'Field');

        if (!\in_array('input_tokens', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `input_tokens` INT(11) NOT NULL DEFAULT 0 AFTER `rejected`',
            );
        }
        if (!\in_array('output_tokens', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `output_tokens` INT(11) NOT NULL DEFAULT 0 AFTER `input_tokens`',
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
