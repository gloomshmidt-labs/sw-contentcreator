<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\Backup;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ContentBackupEntity>
 */
class ContentBackupCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ContentBackupEntity::class;
    }
}
