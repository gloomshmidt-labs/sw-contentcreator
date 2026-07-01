<?php declare(strict_types=1);

namespace ContentCreator\Service;

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

    public function __construct(
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $cmsPageRepository
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
}
