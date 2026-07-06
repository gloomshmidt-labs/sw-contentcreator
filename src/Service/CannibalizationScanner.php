<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Keyword-Kannibalisierung (Yoast-Muster "previously used keyphrase"):
 * Dasselbe Fokus-Keyword oder identische Meta-Titles auf mehreren Entities
 * konkurrieren in Google gegeneinander — der Scanner findet solche Kollisionen.
 */
class CannibalizationScanner
{
    private const MAX_GROUPS = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Vollscan: doppelte Fokus-Keywords + identische Meta-Titles je Sprache.
     *
     * @return array{duplicateKeywords: list<array{value: string, count: int, names: string}>, duplicateTitles: list<array{value: string, count: int, names: string}>}
     */
    public function scan(string $entityType, string $languageId): array
    {
        [$table, , $versionCondition] = $this->tableFor($entityType);

        $duplicateKeywords = $this->connection->fetchAllAssociative(
            "SELECT LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_focus_keyword')))) AS value,
                    COUNT(*) AS count,
                    SUBSTRING(GROUP_CONCAT(t.name SEPARATOR ' | '), 1, 300) AS names
             FROM {$table} t
             WHERE t.language_id = UNHEX(:lang) {$versionCondition}
               AND JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_focus_keyword')) IS NOT NULL
               AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_focus_keyword'))) != ''
             GROUP BY value HAVING count > 1
             ORDER BY count DESC LIMIT " . self::MAX_GROUPS,
            ['lang' => $languageId],
        );

        $duplicateTitles = $this->connection->fetchAllAssociative(
            "SELECT LOWER(TRIM(t.meta_title)) AS value,
                    COUNT(*) AS count,
                    SUBSTRING(GROUP_CONCAT(t.name SEPARATOR ' | '), 1, 300) AS names
             FROM {$table} t
             WHERE t.language_id = UNHEX(:lang) {$versionCondition}
               AND t.meta_title IS NOT NULL AND TRIM(t.meta_title) != ''
             GROUP BY value HAVING count > 1
             ORDER BY count DESC LIMIT " . self::MAX_GROUPS,
            ['lang' => $languageId],
        );

        return [
            'duplicateKeywords' => array_map([$this, 'row'], $duplicateKeywords),
            'duplicateTitles' => array_map([$this, 'row'], $duplicateTitles),
        ];
    }

    /**
     * Nutzt ein anderes Objekt dasselbe Fokus-Keyword bereits?
     *
     * @return list<string> Namen der anderen Objekte (max. 10)
     */
    public function keywordUsage(string $entityType, string $languageId, string $keyword, ?string $excludeId): array
    {
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword === '') {
            return [];
        }

        [$table, $fk, $versionCondition] = $this->tableFor($entityType);
        $excludeCondition = $excludeId !== null && $excludeId !== '' ? "AND t.{$fk} != UNHEX(:exclude)" : '';
        $params = ['lang' => $languageId, 'kw' => $keyword];
        if ($excludeCondition !== '') {
            $params['exclude'] = $excludeId;
        }

        return $this->connection->fetchFirstColumn(
            "SELECT t.name
             FROM {$table} t
             WHERE t.language_id = UNHEX(:lang) {$versionCondition} {$excludeCondition}
               AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.content_creator_focus_keyword')))) = :kw
             LIMIT 10",
            $params,
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string} [Translation-Tabelle, FK-Spalte, Versions-Bedingung]
     */
    private function tableFor(string $entityType): array
    {
        return match ($entityType) {
            'product' => ['product_translation', 'product_id', 'AND t.product_version_id = UNHEX(' . $this->quotedLiveVersion() . ')'],
            'category' => ['category_translation', 'category_id', 'AND t.category_version_id = UNHEX(' . $this->quotedLiveVersion() . ')'],
            default => throw new \InvalidArgumentException('Kannibalisierungs-Scan unterstützt product und category.'),
        };
    }

    private function quotedLiveVersion(): string
    {
        return $this->connection->quote(Defaults::LIVE_VERSION);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{value: string, count: int, names: string}
     */
    private function row(array $row): array
    {
        return [
            'value' => (string) $row['value'],
            'count' => (int) $row['count'],
            'names' => (string) $row['names'],
        ];
    }
}
