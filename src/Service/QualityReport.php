<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Katalogweiter Qualitäts-Report: scort Bestandstexte seitenweise mit dem
 * QualityChecker (gleiche Wertung wie das Generier-Gate). Der Client ruft
 * seitenweise ab und aggregiert — so bleibt jeder Request kurz.
 */
class QualityReport
{
    public const PAGE_SIZE = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly QualityChecker $qualityChecker,
    ) {
    }

    /**
     * @param list<string> $whitelist
     *
     * @return array{items: list<array{id: string, name: string, score: int, level: string}>, scanned: int, done: bool}
     */
    public function page(string $entityType, string $languageId, string $langCode, int $offset, array $whitelist = []): array
    {
        $limit = self::PAGE_SIZE;
        $offset = max(0, $offset);

        $rows = match ($entityType) {
            'product' => $this->connection->fetchAllAssociative(
                "SELECT LOWER(HEX(p.id)) id, pt.name, pt.description
                 FROM product p
                 INNER JOIN product_translation pt
                    ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = UNHEX(:lang)
                 WHERE p.version_id = UNHEX(:live) AND p.parent_id IS NULL AND p.active = 1
                   AND pt.description IS NOT NULL AND pt.description != ''
                 ORDER BY p.id LIMIT {$limit} OFFSET {$offset}",
                ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION],
            ),
            'category' => $this->connection->fetchAllAssociative(
                "SELECT LOWER(HEX(c.id)) id, ct.name, ct.description
                 FROM category c
                 INNER JOIN category_translation ct
                    ON ct.category_id = c.id AND ct.category_version_id = c.version_id AND ct.language_id = UNHEX(:lang)
                 WHERE c.version_id = UNHEX(:live) AND c.active = 1
                   AND ct.description IS NOT NULL AND ct.description != ''
                 ORDER BY c.id LIMIT {$limit} OFFSET {$offset}",
                ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION],
            ),
            default => throw new \InvalidArgumentException('Report unterstützt product und category.'),
        };

        $items = [];
        foreach ($rows as $row) {
            $analysis = $this->qualityChecker->analyse((string) $row['description'], $langCode, $whitelist);
            if ($analysis['score'] <= 0) {
                continue;
            }
            $items[] = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'score' => $analysis['score'],
                'level' => $analysis['level'],
            ];
        }

        return [
            'items' => $items,
            'scanned' => \count($rows),
            'done' => \count($rows) < self::PAGE_SIZE,
        ];
    }
}
