<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;

/**
 * Zeilenumbruch-Scan/-Fix für Kategorie-CMS-Slots — Portierung von
 * scanLineBreaks/fixLineBreaks aus dem Textoptimierung-Tool: \n in statischen
 * Slot-Texten verursacht Darstellungsprobleme in Shopware-CMS-Slots.
 */
class LineBreakScanner
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly EntityRepository $categoryRepository)
    {
    }

    /**
     * @return array{affected: list<array{id: string, name: string, slots: int}>, scanned: int}
     */
    public function scan(string $languageId, Context $context): array
    {
        $affected = [];
        $scanned = 0;
        $page = 1;

        while (true) {
            $criteria = new Criteria();
            $criteria->setLimit(self::PAGE_SIZE);
            $criteria->setOffset(($page - 1) * self::PAGE_SIZE);
            $criteria->addAssociation('translations');
            $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('cmsPageId', null)]));

            $categories = $this->categoryRepository->search($criteria, $context)->getEntities();
            if (\count($categories) === 0) {
                break;
            }

            foreach ($categories as $category) {
                $scanned++;
                $slotConfig = $this->rawSlotConfig($category, $languageId);
                $count = 0;
                foreach ($slotConfig as $conf) {
                    if (($conf['content']['source'] ?? '') === 'static'
                        && preg_match('/\r?\n/', (string) ($conf['content']['value'] ?? '')) === 1) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    $affected[] = [
                        'id' => $category->getId(),
                        'name' => (string) ($category->getTranslation('name') ?? ''),
                        'slots' => $count,
                    ];
                }
            }

            if (\count($categories) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return ['affected' => $affected, 'scanned' => $scanned];
    }

    /**
     * Entfernt Zeilenumbrüche in allen betroffenen statischen Slots der Zielsprache.
     * Umbruch (inkl. umgebender Spaces/Tabs) → EIN Leerzeichen — sonst verschmelzen
     * Wörter, wenn der Umbruch mitten im Fließtext steht; Mehrfach-Spaces → ' '.
     * (Bewusste Abweichung vom Tool-Port, der '' einsetzte.)
     *
     * @return int Anzahl korrigierter Slots
     */
    public function fix(string $categoryId, string $languageId, Context $context): int
    {
        $criteria = new Criteria([$categoryId]);
        $criteria->addAssociation('translations');
        $category = $this->categoryRepository->search($criteria, $context)->first();
        if ($category === null) {
            return 0;
        }

        $slotConfig = $this->rawSlotConfig($category, $languageId);
        $fixed = 0;
        foreach ($slotConfig as $slotId => $conf) {
            $value = (string) ($conf['content']['value'] ?? '');
            if (($conf['content']['source'] ?? '') !== 'static' || preg_match('/\r?\n/', $value) !== 1) {
                continue;
            }
            $clean = (string) preg_replace('/[ \t]*(?:\r?\n)+[ \t]*/', ' ', $value);
            $clean = (string) preg_replace('/[ \t]{2,}/', ' ', $clean);
            $clean = (string) preg_replace('/>\s+</', '><', $clean);
            $slotConfig[$slotId]['content']['value'] = $clean;
            $fixed++;
        }

        if ($fixed > 0) {
            $this->categoryRepository->update([[
                'id' => $categoryId,
                'translations' => [$languageId => ['slotConfig' => $slotConfig]],
            ]], $context);
        }

        return $fixed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawSlotConfig(object $category, string $languageId): array
    {
        return RawTranslation::forLanguage($category, $languageId)?->getSlotConfig() ?? [];
    }
}
