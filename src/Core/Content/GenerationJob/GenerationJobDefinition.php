<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\GenerationJob;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class GenerationJobDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'content_creator_generation_job';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return GenerationJobEntity::class;
    }

    public function getCollectionClass(): string
    {
        return GenerationJobCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required(), new ApiAware()),
            (new StringField('status', 'status'))->addFlags(new Required(), new ApiAware()),
            (new StringField('entity_type', 'entityType'))->addFlags(new Required(), new ApiAware()),
            (new JsonField('types', 'types'))->addFlags(new ApiAware()),
            (new JsonField('item_ids', 'itemIds'))->addFlags(new ApiAware()),
            (new IdField('language_id', 'languageId'))->addFlags(new ApiAware()),
            (new StringField('provider', 'provider'))->addFlags(new ApiAware()),
            (new StringField('model', 'model'))->addFlags(new ApiAware()),
            (new StringField('mode', 'mode'))->addFlags(new ApiAware()),
            (new JsonField('meta_fields', 'metaFields'))->addFlags(new ApiAware()),
            (new BoolField('dry_run', 'dryRun'))->addFlags(new ApiAware()),
            (new IntField('total', 'total'))->addFlags(new ApiAware()),
            (new IntField('processed', 'processed'))->addFlags(new ApiAware()),
            (new IntField('failed', 'failed'))->addFlags(new ApiAware()),
            (new IntField('rejected', 'rejected'))->addFlags(new ApiAware()),
            (new IntField('input_tokens', 'inputTokens'))->addFlags(new ApiAware()),
            (new IntField('output_tokens', 'outputTokens'))->addFlags(new ApiAware()),
            (new LongTextField('error_message', 'errorMessage'))->addFlags(new ApiAware()),
        ]);
    }
}
