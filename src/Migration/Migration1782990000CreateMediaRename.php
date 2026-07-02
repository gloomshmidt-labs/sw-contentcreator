<?php declare(strict_types=1);

namespace ContentCreator\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * v0.13.0: Protokoll für SEO-Dateinamen-Umbenennungen — Basis für den
 * nginx-301-Export (alte Bild-URLs bleiben über Redirects erreichbar).
 */
class Migration1782990000CreateMediaRename extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1782990000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `content_creator_media_rename` (
                `id` BINARY(16) NOT NULL,
                `media_id` BINARY(16) NOT NULL,
                `old_path` VARCHAR(750) NOT NULL,
                `new_path` VARCHAR(750) NOT NULL,
                `thumbnails` JSON NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.ccmr.media` (`media_id`),
                CONSTRAINT `json.ccmr.thumbnails` CHECK (`thumbnails` IS NULL OR JSON_VALID(`thumbnails`))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
