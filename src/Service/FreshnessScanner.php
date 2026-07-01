<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Content-Freshness (Yoast-"stale content"-Muster): findet Texte, deren Entity
 * sich seit der letzten Generierung geändert hat (Datenänderung → Text passt
 * evtl. nicht mehr) oder deren Generierung älter als X Monate ist.
 * Basis ist der Freshness-Stempel, den der ContentWriter bei jedem Schreiben
 * in die customFields setzt (content_creator_generated_at).
 */
class FreshnessScanner
{
    private const MAX_ITEMS = 200;
    private const STALE_MONTHS = 6;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{changedSince: list<array{id: string, name: string, generatedAt: string}>, aging: list<array{id: string, name: string, generatedAt: string}>}
     */
    public function scan(string $entityType, string $languageId): array
    {
        [$entityTable, $translationTable, $fk, $versionJoin, $versionWhere] = match ($entityType) {
            'product' => ['product', 'product_translation', 'product_id', 'AND t.product_version_id = e.version_id', 'AND e.version_id = UNHEX(:live)'],
            'category' => ['category', 'category_translation', 'category_id', 'AND t.category_version_id = e.version_id', 'AND e.version_id = UNHEX(:live)'],
            'manufacturer' => ['product_manufacturer', 'product_manufacturer_translation', 'product_manufacturer_id', '', ''],
            default => throw new \InvalidArgumentException('Freshness-Scan unterstützt product, category und manufacturer.'),
        };

        $params = ['lang' => $languageId];
        if ($versionWhere !== '') {
            $params['live'] = Defaults::LIVE_VERSION;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(e.id)) AS id, t.name,
                    JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_generated_at')) AS generated_at,
                    GREATEST(COALESCE(e.updated_at, e.created_at), COALESCE(t.updated_at, t.created_at)) AS entity_updated
             FROM {$entityTable} e
             INNER JOIN {$translationTable} t ON t.{$fk} = e.id {$versionJoin} AND t.language_id = UNHEX(:lang)
             WHERE JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_generated_at')) IS NOT NULL {$versionWhere}
             LIMIT " . (self::MAX_ITEMS * 5),
            $params
        );

        $changedSince = [];
        $aging = [];
        $staleThreshold = new \DateTimeImmutable('-' . self::STALE_MONTHS . ' months');

        foreach ($rows as $row) {
            $generatedAt = new \DateTimeImmutable((string) $row['generated_at']);
            $item = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'generatedAt' => $generatedAt->format(\DATE_ATOM),
            ];

            // Entity nach der Generierung geändert? (Kulanz 2 Min., weil der
            // Stempel selbst ein Update auslöst)
            $entityUpdated = new \DateTimeImmutable((string) $row['entity_updated']);
            if ($entityUpdated > $generatedAt->modify('+2 minutes')) {
                $changedSince[] = $item;
            } elseif ($generatedAt < $staleThreshold) {
                $aging[] = $item;
            }
        }

        return [
            'changedSince' => \array_slice($changedSince, 0, self::MAX_ITEMS),
            'aging' => \array_slice($aging, 0, self::MAX_ITEMS),
        ];
    }
}
