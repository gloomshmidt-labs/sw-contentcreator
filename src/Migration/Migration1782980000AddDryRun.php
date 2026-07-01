<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.6.0: Dry-Run-Modus im Batch — Ergebnisse werden gespeichert statt
 * geschrieben und können nach Review gesammelt übernommen werden.
 */
class Migration1782980000AddDryRun extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782980000;
    }

    public function update(Connection $connection): void
    {
        $columns = array_column($connection->fetchAllAssociative(
            'SHOW COLUMNS FROM `content_creator_generation_job`'
        ), 'Field');

        if (!\in_array('dry_run', $columns, true)) {
            $connection->executeStatement(
                "ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `dry_run` TINYINT(1) NOT NULL DEFAULT 0 AFTER `meta_fields`"
            );
        }

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `content_creator_batch_result` (
                `id` BINARY(16) NOT NULL,
                `job_id` BINARY(16) NOT NULL,
                `entity_id` BINARY(16) NOT NULL,
                `content_type` VARCHAR(64) NOT NULL,
                `payload` JSON NOT NULL,
                `passed` TINYINT(1) NOT NULL DEFAULT 0,
                `applied` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.ccbr.job` (`job_id`),
                CONSTRAINT `json.ccbr.payload` CHECK (JSON_VALID(`payload`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
