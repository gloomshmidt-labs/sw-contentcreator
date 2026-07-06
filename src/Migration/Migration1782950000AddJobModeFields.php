<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.2.0: Optimieren-Modus + selektive Meta-Felder + Qualitäts-Gate-Zähler
 * (rejected = generiert, aber vom Gate abgelehnt und daher NICHT geschrieben).
 */
class Migration1782950000AddJobModeFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782950000;
    }

    public function update(Connection $connection): void
    {
        $columns = array_column($connection->fetchAllAssociative(
            'SHOW COLUMNS FROM `content_creator_generation_job`',
        ), 'Field');

        if (!\in_array('mode', $columns, true)) {
            $connection->executeStatement(
                "ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `mode` VARCHAR(32) NOT NULL DEFAULT 'create' AFTER `model`",
            );
        }
        if (!\in_array('meta_fields', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `meta_fields` JSON NULL AFTER `mode`',
            );
        }
        if (!\in_array('rejected', $columns, true)) {
            $connection->executeStatement(
                'ALTER TABLE `content_creator_generation_job`
                 ADD COLUMN `rejected` INT(11) NOT NULL DEFAULT 0 AFTER `failed`',
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
