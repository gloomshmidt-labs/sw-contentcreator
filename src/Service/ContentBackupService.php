<?php declare(strict_types=1);

namespace ContentCreator\Service;

use ContentCreator\Core\Content\Backup\ContentBackupCollection;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

/**
 * Text-Backup vor jedem Überschreiben + Ein-Klick-Wiederherstellen.
 * Gesichert wird immer die ROHE Übersetzung der Zielsprache (nicht die geerbte),
 * damit Restore exakt den vorherigen Zustand herstellt — inkl. "Feld war leer".
 */
class ContentBackupService
{
    /**
     * @param EntityRepository<ContentBackupCollection> $backupRepository
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<CategoryCollection> $categoryRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<ProductManufacturerCollection> $manufacturerRepository
     */
    public function __construct(
        private readonly EntityRepository $backupRepository,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly CmsSlotResolver $slotResolver,
    ) {
    }

    public function snapshot(string $entityType, string $entityId, string $languageId, string $type, Context $context): void
    {
        $payload = $this->currentPayload($entityType, $entityId, $languageId, $type, $context);
        if ($payload === null) {
            return;
        }

        $this->backupRepository->create([[
            'id' => Uuid::randomHex(),
            'entityType' => $entityType,
            'entityId' => $entityId,
            'languageId' => $languageId,
            'contentType' => $type,
            'payload' => $payload,
        ]], $context);
    }

    /**
     * @return array{id: string, createdAt: ?string}|null
     */
    public function latest(string $entityType, string $entityId, string $languageId, string $type, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityType', $entityType));
        $criteria->addFilter(new EqualsFilter('entityId', $entityId));
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));
        $criteria->addFilter(new EqualsFilter('contentType', $type));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $backup = $this->backupRepository->search($criteria, $context)->first();
        if ($backup === null) {
            return null;
        }

        return [
            'id' => $backup->getId(),
            'createdAt' => $backup->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }

    public function restore(string $backupId, Context $context): void
    {
        $backup = $this->backupRepository->search(new Criteria([$backupId]), $context)->first();
        if ($backup === null) {
            throw new \RuntimeException('Backup nicht gefunden: ' . $backupId);
        }

        $entityType = $backup->getEntityType();
        $entityId = $backup->getEntityId();
        $languageId = $backup->getLanguageId();
        $payload = $backup->getPayload() ?? [];

        if (isset($payload['slotId'])) {
            // Slot-Backup (Teaser oder Detailtext im Layout-Slot)
            $this->restoreTeaser($entityId, $languageId, $payload, $context);
        } else {
            $this->repositoryFor($entityType)->update([[
                'id' => $entityId,
                'translations' => [$languageId => $payload],
            ]], $context);
        }

        $this->backupRepository->update([[
            'id' => $backupId,
            'restoredAt' => new \DateTimeImmutable(),
        ]], $context);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function restoreTeaser(string $categoryId, string $languageId, array $payload, Context $context): void
    {
        $slotId = (string) ($payload['slotId'] ?? '');
        if ($slotId === '') {
            throw new \RuntimeException('Teaser-Backup ohne Slot-ID.');
        }

        $slotConfig = $this->rawField('category', $categoryId, $languageId, 'slotConfig', $context) ?? [];
        if (($payload['value'] ?? null) === null) {
            unset($slotConfig[$slotId]);
        } else {
            $slotConfig[$slotId] = array_merge($slotConfig[$slotId] ?? [], [
                'content' => ['source' => 'static', 'value' => (string) $payload['value']],
            ]);
        }

        $this->categoryRepository->update([[
            'id' => $categoryId,
            'translations' => [$languageId => ['slotConfig' => $slotConfig === [] ? null : $slotConfig]],
        ]], $context);
    }

    /**
     * @return array<string, mixed>|null null = Entity nicht gefunden
     */
    private function currentPayload(string $entityType, string $entityId, string $languageId, string $type, Context $context): ?array
    {
        if ($type === PromptBuilder::TYPE_CATEGORY_TEASER) {
            $slotId = $this->slotResolver->categoryTeaserSlotId($entityId, $context);
            if ($slotId === null) {
                return null;
            }
            $slotConfig = $this->rawField('category', $entityId, $languageId, 'slotConfig', $context) ?? [];
            $content = $slotConfig[$slotId]['content'] ?? null;
            $value = (\is_array($content) && ($content['source'] ?? '') === 'static') ? ($content['value'] ?? null) : null;

            return ['slotId' => $slotId, 'value' => $value];
        }

        // Detailtext im Layout-Slot → Slot-Backup (gleiche Form wie beim Teaser)
        if ($type === PromptBuilder::TYPE_CATEGORY_DETAIL) {
            $detailSlotId = $this->slotResolver->categoryDetailSlotId($entityId, $context);
            if ($detailSlotId !== null) {
                $slotConfig = $this->rawField('category', $entityId, $languageId, 'slotConfig', $context) ?? [];
                $content = $slotConfig[$detailSlotId]['content'] ?? null;
                $value = (\is_array($content) && ($content['source'] ?? '') === 'static') ? ($content['value'] ?? null) : null;

                return ['slotId' => $detailSlotId, 'value' => $value];
            }
        }

        $fields = match ($type) {
            PromptBuilder::TYPE_PRODUCT_DESCRIPTION, PromptBuilder::TYPE_CATEGORY_DETAIL,
            PromptBuilder::TYPE_MANUFACTURER_DESCRIPTION => ['description'],
            PromptBuilder::TYPE_MEDIA_ALT => ['alt', 'title'],
            PromptBuilder::TYPE_PRODUCT_META, PromptBuilder::TYPE_CATEGORY_META => ['metaTitle', 'metaDescription', 'keywords'],
            PromptBuilder::TYPE_HOME_META => ['homeMetaTitle', 'homeMetaDescription', 'homeMetaKeywords'],
            default => [],
        };

        // FAQ liegt in den customFields — Backup als customFields-Teilmenge,
        // Restore läuft über den generischen translations-Pfad (DAL merged)
        if ($type === PromptBuilder::TYPE_FAQ) {
            $customFields = $this->rawField($entityType, $entityId, $languageId, 'customFields', $context) ?? [];

            return ['customFields' => [ContentWriter::FAQ_FIELD => $customFields[ContentWriter::FAQ_FIELD] ?? null]];
        }
        if ($fields === []) {
            return null;
        }

        $payload = [];
        foreach ($fields as $field) {
            $payload[$field] = $this->rawField($entityType, $entityId, $languageId, $field, $context);
        }

        return $payload;
    }

    private function rawField(string $entityType, string $entityId, string $languageId, string $field, Context $context): mixed
    {
        $criteria = new Criteria([$entityId]);
        $criteria->addAssociation('translations');
        $entity = $this->repositoryFor($entityType)->search($criteria, $context)->first();

        $translation = RawTranslation::forLanguage($entity, $languageId);
        if ($translation === null) {
            return null;
        }

        $getter = 'get' . ucfirst($field);
        if (method_exists($translation, $getter)) {
            return $translation->$getter();
        }

        return $translation->get($field);
    }

    /**
     * @return EntityRepository<covariant EntityCollection<covariant Entity>>
     */
    private function repositoryFor(string $entityType): EntityRepository
    {
        return match ($entityType) {
            'product' => $this->productRepository,
            'category' => $this->categoryRepository,
            'media' => $this->mediaRepository,
            'sales_channel' => $this->salesChannelRepository,
            'manufacturer' => $this->manufacturerRepository,
            default => throw new \InvalidArgumentException('Unbekannter Entity-Typ: ' . $entityType),
        };
    }
}
