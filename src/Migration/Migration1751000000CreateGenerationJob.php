<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1751000000CreateGenerationJob extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1751000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `content_creator_generation_job` (
                `id` BINARY(16) NOT NULL,
                `status` VARCHAR(32) NOT NULL DEFAULT \'open\',
                `entity_type` VARCHAR(32) NOT NULL,
                `types` JSON NULL,
                `item_ids` JSON NULL,
                `language_id` BINARY(16) NULL,
                `provider` VARCHAR(32) NULL,
                `model` VARCHAR(64) NULL,
                `total` INT NOT NULL DEFAULT 0,
                `processed` INT NOT NULL DEFAULT 0,
                `failed` INT NOT NULL DEFAULT 0,
                `error_message` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Tabelle wird bei Deinstallation mit --clear-data über ContentCreator::uninstall() entfernt.
    }
}
