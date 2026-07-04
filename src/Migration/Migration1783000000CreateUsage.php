<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.19.0: Kumulierter API-Verbrauch je Monat/Provider/Modell — Grundlage der
 * Verbrauchsanzeige (Guthaben selbst ist mit normalen API-Keys nicht abfragbar).
 */
class Migration1783000000CreateUsage extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1783000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `content_creator_usage` (
                `id` BINARY(16) NOT NULL,
                `month` CHAR(7) NOT NULL,
                `provider` VARCHAR(32) NOT NULL,
                `model` VARCHAR(64) NOT NULL,
                `input_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `output_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `requests` INT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.ccu.month_provider_model` (`month`, `provider`, `model`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
