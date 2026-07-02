<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Lädt die Fakten (Name, Hersteller, MPN, Keywords, Bestandstext, Bild-URL) für
 * Produkte, Kategorien und Medien – sprachaufgelöst über einen Sprach-Context.
 */
class FactLoader
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $languageRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $manufacturerRepository,
        private readonly EntityRepository $productMediaRepository,
        private readonly CmsSlotResolver $slotResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadManufacturer(string $id, Context $context): array
    {
        $manufacturer = $this->manufacturerRepository->search(new Criteria([$id]), $context)->first();

        if ($manufacturer === null) {
            throw new \RuntimeException('Hersteller nicht gefunden: ' . $id);
        }

        $description = (string) ($manufacturer->getTranslation('description') ?? '');

        return [
            'name' => (string) ($manufacturer->getTranslation('name') ?? ''),
            'manufacturer' => (string) ($manufacturer->getTranslation('name') ?? ''),
            'focusKeyword' => $this->focusKeyword($manufacturer),
            'existingText' => trim(strip_tags($description)),
            '_hasDescription' => trim($description) !== '',
        ];
    }

    /**
     * Context mit Sprachkette [gewählte Sprache, System-Default] für Übersetzungs-Fallback.
     */
    public function context(string $languageId): Context
    {
        $chain = array_values(array_unique(array_filter([$languageId, Defaults::LANGUAGE_SYSTEM])));

        return new Context(new SystemSource(), [], Defaults::CURRENCY, $chain);
    }

    /**
     * Sprachcode ('de'/'en') aus der Language-Entity über die Locale ableiten.
     */
    public function langCode(?string $languageId): string
    {
        if ($languageId === null || $languageId === '') {
            return 'de';
        }

        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        $language = $this->languageRepository->search($criteria, Context::createDefaultContext())->first();
        $code = $language?->getLocale()?->getCode() ?? 'de-DE';

        return str_starts_with(strtolower($code), 'en') ? 'en' : 'de';
    }

    /**
     * @return array<string, mixed>
     */
    public function loadProduct(string $id, Context $context): array
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('manufacturer');
        $product = $this->productRepository->search($criteria, $context)->first();

        if ($product === null) {
            throw new \RuntimeException('Produkt nicht gefunden: ' . $id);
        }

        $description = (string) ($product->getDescription() ?? '');

        return [
            'name' => (string) ($product->getName() ?? ''),
            'manufacturer' => (string) ($product->getManufacturer()?->getName() ?? ''),
            'mpn' => (string) ($product->getManufacturerNumber() ?? ''),
            'productNumber' => (string) ($product->getProductNumber() ?? ''),
            'focusKeyword' => $this->focusKeyword($product),
            'keywords' => (string) ($product->getKeywords() ?? ''),
            'existingMetaTitle' => (string) ($product->getMetaTitle() ?? ''),
            'existingMetaDescription' => (string) ($product->getMetaDescription() ?? ''),
            'existingText' => trim(strip_tags($description)),
            'existingFaq' => $this->existingFaq($product),
            '_hasDescription' => trim($description) !== '',
        ];
    }

    /**
     * Bestehender FAQ-Block aus den customFields (für Optimieren-Modus).
     */
    private function existingFaq(object $entity): string
    {
        $customFields = $entity->getTranslation('customFields') ?? [];

        return trim(strip_tags((string) ($customFields[ContentWriter::FAQ_FIELD] ?? '')));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCategory(string $id, Context $context): array
    {
        $category = $this->categoryRepository->search(new Criteria([$id]), $context)->first();

        if ($category === null) {
            throw new \RuntimeException('Kategorie nicht gefunden: ' . $id);
        }

        $description = (string) ($category->getDescription() ?? '');
        $breadcrumb = $category->getBreadcrumb();
        $slotConfig = $category->getTranslation('slotConfig') ?? [];

        // Teaser liegt im CMS-slotConfig (erster Text-Slot vor dem Listing), nicht in der description
        $teaser = '';
        $teaserSlotId = $this->slotResolver->categoryTeaserSlotId($id, $context);
        if ($teaserSlotId !== null) {
            $content = $slotConfig[$teaserSlotId]['content'] ?? [];
            if (($content['source'] ?? '') === 'static') {
                $teaser = trim(strip_tags((string) ($content['value'] ?? '')));
            }
        }

        // Bestandstext-Kaskade (Tool-Lösung): description → statische Text-Slots
        // im Kategorie-Layout (slotConfig) → Text-Slots der Erlebniswelt selbst.
        $existingText = trim(strip_tags($description));
        if ($existingText === '') {
            $slotTexts = [];
            foreach ($slotConfig as $config) {
                $content = $config['content'] ?? [];
                $value = (string) ($content['value'] ?? '');
                if (($content['source'] ?? '') === 'static' && mb_strlen(trim(strip_tags($value))) > 10) {
                    $slotTexts[] = trim($value);
                }
            }
            $existingText = trim(strip_tags(implode("\n\n", $slotTexts)));
        }
        if ($existingText === '' && $category->getCmsPageId() !== null) {
            $existingText = trim(strip_tags($this->slotResolver->pageText($category->getCmsPageId(), $context)));
        }

        return [
            'name' => (string) ($category->getName() ?? ''),
            'categoryPath' => \is_array($breadcrumb) ? implode(' › ', $breadcrumb) : '',
            'focusKeyword' => $this->focusKeyword($category),
            'keywords' => (string) ($category->getKeywords() ?? ''),
            'existingMetaTitle' => (string) ($category->getMetaTitle() ?? ''),
            'existingMetaDescription' => (string) ($category->getMetaDescription() ?? ''),
            'existingText' => $existingText,
            'existingTeaser' => $teaser,
            'existingFaq' => $this->existingFaq($category),
            '_hasDescription' => $existingText !== '',
            '_hasTeaser' => $teaser !== '',
            '_teaserSlotId' => $teaserSlotId,
        ];
    }

    /**
     * Startseiten-Meta eines Verkaufskanals (sales_channel_translation: homeMeta*).
     *
     * @return array<string, mixed>
     */
    public function loadSalesChannel(string $id, Context $context): array
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('domains');
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if ($salesChannel === null) {
            throw new \RuntimeException('Verkaufskanal nicht gefunden: ' . $id);
        }

        // Startseiten-Text liegt in der Erlebniswelt: homeCmsPageId des Kanals,
        // Fallback cmsPageId der Navigations-Root-Kategorie (Tool-Lösung).
        $rootCategory = $salesChannel->getNavigationCategoryId() !== null
            ? $this->categoryRepository->search(new Criteria([$salesChannel->getNavigationCategoryId()]), $context)->first()
            : null;
        $cmsPageId = $salesChannel->getHomeCmsPageId() ?? $rootCategory?->getCmsPageId();
        $existingText = $cmsPageId !== null ? $this->slotResolver->pageText($cmsPageId, $context) : '';
        if ($existingText === '') {
            // Gemappter Homepage-Slot (category.description): Text liegt in der
            // description der Navigations-Root-Kategorie
            $existingText = trim((string) ($rootCategory?->getTranslation('description') ?? ''));
        }

        return [
            'name' => (string) ($salesChannel->getTranslation('name') ?? ''),
            'shopBrand' => $this->shopBrand($salesChannel, $context->getLanguageId()),
            'keywords' => (string) ($salesChannel->getTranslation('homeMetaKeywords') ?? ''),
            'existingMetaTitle' => (string) ($salesChannel->getTranslation('homeMetaTitle') ?? ''),
            'existingMetaDescription' => (string) ($salesChannel->getTranslation('homeMetaDescription') ?? ''),
            'existingText' => $existingText,
            '_hasDescription' => $existingText !== '',
        ];
    }

    public const FOCUS_KEYWORD_FIELD = 'content_creator_focus_keyword';

    /**
     * Fokus-Keyword aus den (übersetzten) customFields der Entity.
     */
    private function focusKeyword(object $entity): string
    {
        $customFields = $entity->getTranslation('customFields') ?? [];

        return trim((string) ($customFields[self::FOCUS_KEYWORD_FIELD] ?? ''));
    }

    /**
     * Shop-Marke für den Startseiten-Title: Domain-Host des Kanals passend zur
     * Sprache (shop.de vs. shop.ch), Fallback erste Domain, dann Kanal-Name.
     */
    private function shopBrand(object $salesChannel, string $languageId): string
    {
        $domains = $salesChannel->getDomains()?->getElements() ?? [];
        $fallbackHost = null;
        foreach ($domains as $domain) {
            $host = (string) parse_url((string) $domain->getUrl(), \PHP_URL_HOST);
            $host = (string) preg_replace('/^www\./', '', $host);
            if ($host === '') {
                continue;
            }
            if ($domain->getLanguageId() === $languageId) {
                return $host;
            }
            $fallbackHost ??= $host;
        }

        return $fallbackHost ?? (string) ($salesChannel->getTranslation('name') ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function loadMedia(string $id, Context $context): array
    {
        $media = $this->mediaRepository->search(new Criteria([$id]), $context)->first();

        if ($media === null) {
            throw new \RuntimeException('Medium nicht gefunden: ' . $id);
        }

        $alt = (string) ($media->getAlt() ?? '');

        // Produkt-Kontext: Zu welchem Produkt gehört das Bild? Macht die
        // Vision-Alt-Generierung präzise statt generisch (Live-Analyse-Befund).
        $productName = '';
        $manufacturer = '';
        $criteria = new Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('media.id', $id));
        $criteria->addAssociation('product.manufacturer');
        $criteria->setLimit(1);
        $productMedia = $this->productMediaRepository->search($criteria, $context)->first();
        if ($productMedia?->getProduct() !== null) {
            $productName = (string) ($productMedia->getProduct()->getTranslation('name') ?? '');
            $manufacturer = (string) ($productMedia->getProduct()->getManufacturer()?->getTranslation('name') ?? '');
        }

        return [
            'name' => $productName !== '' ? $productName : (string) ($media->getFileName() ?? ''),
            'manufacturer' => $manufacturer,
            'imageUrl' => (string) ($media->getUrl() ?? ''),
            'existingText' => $alt,
            '_hasAlt' => trim($alt) !== '',
        ];
    }
}
