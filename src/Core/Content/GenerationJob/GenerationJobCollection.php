<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\GenerationJob;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<GenerationJobEntity>
 *
 * @method GenerationJobEntity|null get(string $id)
 * @method GenerationJobEntity|null first()
 * @method GenerationJobEntity|null last()
 */
class GenerationJobCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return GenerationJobEntity::class;
    }
}
