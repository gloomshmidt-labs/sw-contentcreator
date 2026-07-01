<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.6.0: Text-Backup vor jedem Überschreiben (Ein-Klick-Wiederherstellen).
 */
class Migration1782970000CreateContentBackup extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782970000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `content_creator_backup` (
                `id` BINARY(16) NOT NULL,
                `entity_type` VARCHAR(64) NOT NULL,
                `entity_id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `content_type` VARCHAR(64) NOT NULL,
                `payload` JSON NOT NULL,
                `restored_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.ccb.entity` (`entity_type`, `entity_id`, `language_id`),
                CONSTRAINT `json.ccb.payload` CHECK (JSON_VALID(`payload`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
