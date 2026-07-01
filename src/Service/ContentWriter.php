<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Schreibt generierte Inhalte zurück in Produkte, Kategorien, Medien und
 * Verkaufskanäle (Startseiten-Meta) – sprachspezifisch über die translations-Payload.
 * Der Kategorie-Teaser wird in den CMS-slotConfig geschrieben (bestehende
 * Slot-Overrides der Zielsprache werden gemerged, wie im Textoptimierung-Tool).
 */
class ContentWriter
{
    public const GENERATED_AT_FIELD = 'content_creator_generated_at';
    public const FAQ_FIELD = 'content_creator_faq';

    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly CmsSlotResolver $slotResolver,
        private readonly ContentBackupService $backupService
    ) {
    }

    /**
     * @param array<string, mixed> $generatorResult Rückgabe von ContentGenerator::generate()
     */
    public function apply(string $entityType, string $id, string $languageId, string $type, array $generatorResult, Context $context): void
    {
        if ($type === PromptBuilder::TYPE_CATEGORY_TEASER) {
            $content = \is_string($generatorResult['content'] ?? null) ? trim($generatorResult['content']) : '';
            if ($content !== '') {
                $this->backupService->snapshot($entityType, $id, $languageId, $type, $context);
                $this->writeCategoryTeaser($id, $languageId, $content, $context);
            }

            return;
        }

        $fields = $this->fieldsFor($type, $generatorResult);
        if ($fields === []) {
            return;
        }

        // Vertrauens-Feature: Alt-Zustand sichern, bevor überschrieben wird
        $this->backupService->snapshot($entityType, $id, $languageId, $type, $context);

        // Freshness-Stempel (DAL merged customFields, überschreibt nicht)
        if (\in_array($entityType, ['product', 'category', 'manufacturer'], true)) {
            $fields['customFields'] = array_merge(
                $fields['customFields'] ?? [],
                [self::GENERATED_AT_FIELD => (new \DateTimeImmutable())->format(\DATE_ATOM)]
            );
        }

        $payload = [['id' => $id, 'translations' => [$languageId => $fields]]];

        match ($entityType) {
            'product' => $this->productRepository->update($payload, $context),
            'category' => $this->categoryRepository->update($payload, $context),
            'media' => $this->mediaRepository->update($payload, $context),
            'sales_channel' => $this->salesChannelRepository->update($payload, $context),
            'manufacturer' => $this->manufacturerRepository->update($payload, $context),
            default => throw new \InvalidArgumentException('Unbekannter Entity-Typ: ' . $entityType),
        };
    }

    /**
     * Teaser in den slotConfig der Zielsprache schreiben — nur der Ziel-Slot wird
     * ersetzt, alle anderen Slot-Overrides dieser Sprache bleiben erhalten.
     */
    private function writeCategoryTeaser(string $categoryId, string $languageId, string $html, Context $context): void
    {
        $slotId = $this->slotResolver->categoryTeaserSlotId($categoryId, $context);
        if ($slotId === null) {
            throw new \RuntimeException('Kein Teaser-Textslot im CMS-Layout der Kategorie gefunden (Text-Slot vor dem Produkt-Listing).');
        }

        // Rohe Übersetzung der Zielsprache lesen (NICHT die geerbte/gemergte),
        // damit keine vererbten Werte in die Übersetzung materialisiert werden.
        $criteria = new Criteria([$categoryId]);
        $criteria->addAssociation('translations');
        $category = $this->categoryRepository->search($criteria, $context)->first();
        $slotConfig = RawTranslation::forLanguage($category, $languageId)?->getSlotConfig() ?? [];

        $slotConfig[$slotId] = array_merge($slotConfig[$slotId] ?? [], [
            'content' => ['source' => 'static', 'value' => $html],
        ]);

        $this->categoryRepository->update([[
            'id' => $categoryId,
            'translations' => [$languageId => ['slotConfig' => $slotConfig]],
        ]], $context);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function fieldsFor(string $type, array $result): array
    {
        $content = \is_string($result['content'] ?? null) ? trim($result['content']) : '';

        return match ($type) {
            PromptBuilder::TYPE_PRODUCT_DESCRIPTION,
            PromptBuilder::TYPE_CATEGORY_DETAIL,
            PromptBuilder::TYPE_MANUFACTURER_DESCRIPTION => $content !== '' ? ['description' => $content] : [],
            PromptBuilder::TYPE_MEDIA_ALT => $content !== '' ? ['alt' => $content] : [],
            PromptBuilder::TYPE_FAQ => $content !== '' ? ['customFields' => [self::FAQ_FIELD => $content]] : [],
            PromptBuilder::TYPE_PRODUCT_META,
            PromptBuilder::TYPE_CATEGORY_META => $this->metaFields($result),
            PromptBuilder::TYPE_HOME_META => $this->homeMetaFields($result),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function homeMetaFields(array $result): array
    {
        $meta = $this->metaFields($result);
        $mapped = [];
        foreach (['metaTitle' => 'homeMetaTitle', 'metaDescription' => 'homeMetaDescription', 'keywords' => 'homeMetaKeywords'] as $from => $to) {
            if (isset($meta[$from])) {
                $mapped[$to] = $meta[$from];
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, string>
     */
    private function metaFields(array $result): array
    {
        $meta = $result['meta'] ?? null;
        if (!\is_array($meta)) {
            return [];
        }

        $fields = [];
        if (($meta['metaTitle'] ?? '') !== '') {
            $fields['metaTitle'] = $meta['metaTitle'];
        }
        if (($meta['metaDescription'] ?? '') !== '') {
            $fields['metaDescription'] = $meta['metaDescription'];
        }
        if (($meta['metaKeywords'] ?? '') !== '') {
            $fields['keywords'] = $meta['metaKeywords'];
        }

        return $fields;
    }
}
