<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\Backup;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ContentBackupDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'content_creator_backup';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ContentBackupEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ContentBackupCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new StringField('entity_type', 'entityType'))->addFlags(new Required(), new ApiAware()),
            (new IdField('entity_id', 'entityId'))->addFlags(new Required(), new ApiAware()),
            (new IdField('language_id', 'languageId'))->addFlags(new Required(), new ApiAware()),
            (new StringField('content_type', 'contentType'))->addFlags(new Required(), new ApiAware()),
            (new JsonField('payload', 'payload'))->addFlags(new Required(), new ApiAware()),
            (new DateTimeField('restored_at', 'restoredAt'))->addFlags(new ApiAware()),
        ]);
    }
}
