<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Findet den Teaser-Textslot einer Kategorie: der erste Text-Slot im
 * CMS-Layout der Kategorie, der VOR dem Produkt-Listing-Block liegt.
 * Sortierung Section-Position → Block-Position → Slot-Position wie im
 * Textoptimierung-Tool (_extractCmsPageSlots).
 */
class CmsSlotResolver
{
    private const TEXT_SLOT_TYPES = ['text', 'html'];
    private const LISTING_BLOCK_TYPES = ['product-listing'];

    /** @var array<string, ?string> */
    private array $cache = [];

    /**
     * @param EntityRepository<CategoryCollection> $categoryRepository
     * @param EntityRepository<CmsPageCollection> $cmsPageRepository
     */
    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $cmsPageRepository,
    ) {
    }

    public function categoryTeaserSlotId(string $categoryId, Context $context): ?string
    {
        if (\array_key_exists($categoryId, $this->cache)) {
            return $this->cache[$categoryId];
        }

        $category = $this->categoryRepository->search(new Criteria([$categoryId]), $context)->first();
        $cmsPageId = $category?->getCmsPageId();
        if ($cmsPageId === null) {
            return $this->cache[$categoryId] = null;
        }

        $criteria = new Criteria([$cmsPageId]);
        $criteria->addAssociation('sections.blocks.slots');
        $page = $this->cmsPageRepository->search($criteria, $context)->first();
        if ($page === null) {
            return $this->cache[$categoryId] = null;
        }

        $sections = $page->getSections();
        if ($sections === null) {
            return $this->cache[$categoryId] = null;
        }

        $sorted = $sections->getElements();
        usort($sorted, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        foreach ($sorted as $section) {
            $blocks = $section->getBlocks()?->getElements() ?? [];
            usort($blocks, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

            foreach ($blocks as $block) {
                if (\in_array($block->getType(), self::LISTING_BLOCK_TYPES, true)) {
                    // Listing erreicht, ohne Text-Slot davor → kein Teaser-Slot
                    return $this->cache[$categoryId] = null;
                }

                $slots = $block->getSlots()?->getElements() ?? [];
                foreach ($slots as $slot) {
                    if (\in_array($slot->getType(), self::TEXT_SLOT_TYPES, true)) {
                        return $this->cache[$categoryId] = $slot->getId();
                    }
                }
            }
        }

        return $this->cache[$categoryId] = null;
    }

    /**
     * Liegt der Detailtext einer Kategorie im Layout statt in der description?
     * Liefert die Slot-ID des ersten statischen Text-Slots im slotConfig, wenn
     * die description leer ist (Tool-Muster: Optimiertes gehört in den Slot
     * zurück, sonst zeigt der Shop weiter den alten Text).
     */
    public function categoryDetailSlotId(string $categoryId, Context $context): ?string
    {
        $category = $this->categoryRepository->search(new Criteria([$categoryId]), $context)->first();
        if ($category === null) {
            return null;
        }
        if (trim(strip_tags((string) ($category->getTranslation('description') ?? ''))) !== '') {
            return null;
        }

        // Teaser-Slot ist ein eigener Texttyp — nie als Detail-Slot behandeln
        $teaserSlotId = $this->categoryTeaserSlotId($categoryId, $context);

        foreach ($category->getTranslation('slotConfig') ?? [] as $slotId => $config) {
            if ((string) $slotId === (string) $teaserSlotId) {
                continue;
            }
            $content = $config['content'] ?? [];
            $value = (string) ($content['value'] ?? '');
            if (($content['source'] ?? '') === 'static' && mb_strlen(trim(strip_tags($value))) > 10) {
                return (string) $slotId;
            }
        }

        return null;
    }

    /**
     * Gesamter Text-Inhalt einer CMS-Seite (Erlebniswelt) in der Kontext-Sprache:
     * alle Text-/HTML-Slots in Seitenreihenfolge, wie im Textoptimierung-Tool
     * (Startseiten-Content liegt in der Erlebniswelt, nicht am Verkaufskanal).
     */
    public function pageText(string $cmsPageId, Context $context): string
    {
        $criteria = new Criteria([$cmsPageId]);
        $criteria->addAssociation('sections.blocks.slots');
        $page = $this->cmsPageRepository->search($criteria, $context)->first();

        $sections = $page?->getSections()?->getElements() ?? [];
        usort($sections, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

        $texts = [];
        foreach ($sections as $section) {
            $blocks = $section->getBlocks()?->getElements() ?? [];
            usort($blocks, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());

            foreach ($blocks as $block) {
                foreach ($block->getSlots()?->getElements() ?? [] as $slot) {
                    if (!\in_array($slot->getType(), self::TEXT_SLOT_TYPES, true)) {
                        continue;
                    }
                    $config = $slot->getTranslation('config') ?? [];
                    $content = $config['content'] ?? [];
                    $value = $content['value'] ?? null;
                    // Nur statische Inhalte — gemappte Slots (z.B. category.description)
                    // enthalten den Mapping-Pfad, keinen Text
                    if (($content['source'] ?? '') === 'static' && \is_string($value) && mb_strlen(trim(strip_tags($value))) > 10) {
                        $texts[] = trim($value);
                    }
                }
            }
        }

        return implode("\n\n", $texts);
    }
}
