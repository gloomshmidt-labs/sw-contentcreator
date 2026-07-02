<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Lücken-Scan: findet Objekte OHNE Inhalte in der Zielsprache — als
 * Arbeitsvorrat für "bei fehlenden Angaben alles erstellen" (Batch-Start direkt
 * aus dem Ergebnis). Bewusst SQL auf den Translation-Tabellen: gesucht ist der
 * ROHE Zustand der Sprache, nicht der vererbte.
 */
class GapScanner
{
    private const MAX_IDS = 5000;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, array{count: int, ids: list<string>}>
     */
    public function scan(string $languageId, string $entityType): array
    {
        return match ($entityType) {
            'product' => [
                'missingDescription' => $this->productGap($languageId, "(pt.description IS NULL OR pt.description = '')"),
                'missingMeta' => $this->productGap($languageId, "(pt.meta_title IS NULL OR pt.meta_title = '' OR pt.meta_description IS NULL OR pt.meta_description = '')"),
            ],
            'category' => [
                'missingDescription' => $this->categoryGap($languageId, "(ct.description IS NULL OR ct.description = '')"),
                'missingMeta' => $this->categoryGap($languageId, "(ct.meta_title IS NULL OR ct.meta_title = '' OR ct.meta_description IS NULL OR ct.meta_description = '')"),
            ],
            'media' => [
                'missingAlt' => $this->mediaGap($languageId),
                'weakAlt' => $this->weakAltGap($languageId),
            ],
            default => throw new \InvalidArgumentException('Unbekannter Entity-Typ: ' . $entityType),
        };
    }

    /**
     * @return array{count: int, ids: list<string>}
     */
    private function productGap(string $languageId, string $condition): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT LOWER(HEX(p.id))
             FROM product p
             LEFT JOIN product_translation pt
                ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = UNHEX(:lang)
             WHERE p.version_id = UNHEX(:live) AND p.parent_id IS NULL AND p.active = 1 AND {$condition}
             LIMIT " . (self::MAX_IDS + 1),
            ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION]
        );

        return $this->result($rows);
    }

    /**
     * @return array{count: int, ids: list<string>}
     */
    private function categoryGap(string $languageId, string $condition): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT LOWER(HEX(c.id))
             FROM category c
             LEFT JOIN category_translation ct
                ON ct.category_id = c.id AND ct.category_version_id = c.version_id AND ct.language_id = UNHEX(:lang)
             WHERE c.version_id = UNHEX(:live) AND c.active = 1 AND c.cms_page_id IS NOT NULL AND {$condition}
             LIMIT " . (self::MAX_IDS + 1),
            ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION]
        );

        return $this->result($rows);
    }

    /**
     * Nur Medien, die an Produkten hängen — sonst würde jede System-Grafik gezählt.
     *
     * @return array{count: int, ids: list<string>}
     */
    private function mediaGap(string $languageId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(m.id))
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live)
             LEFT JOIN media_translation mt ON mt.media_id = m.id AND mt.language_id = UNHEX(:lang)
             WHERE (mt.alt IS NULL OR mt.alt = \'\')
             LIMIT ' . (self::MAX_IDS + 1),
            ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION]
        );

        return $this->result($rows);
    }

    /**
     * Generische/schwache Alt-Texte an Produktbildern (Live-Analyse-Befund:
     * "Produktbild 2", "… Demo", Dateinamen-Alts) — gepflegt, aber SEO-wertlos.
     *
     * @return array{count: int, ids: list<string>}
     */
    private function weakAltGap(string $languageId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT LOWER(HEX(m.id))
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live)
             INNER JOIN media_translation mt ON mt.media_id = m.id AND mt.language_id = UNHEX(:lang)
             WHERE mt.alt IS NOT NULL AND TRIM(mt.alt) != ''
               AND (
                    mt.alt REGEXP '(Produktbild|Product image|Bild|Image) ?[0-9]*$'
                    OR mt.alt REGEXP 'Demo ?[0-9]*$'
                    OR CHAR_LENGTH(TRIM(mt.alt)) < 15
                    OR mt.alt = m.file_name
               )
             LIMIT " . (self::MAX_IDS + 1),
            ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION]
        );

        return $this->result($rows);
    }

    /**
     * @param list<string> $rows
     *
     * @return array{count: int, ids: list<string>}
     */
    private function result(array $rows): array
    {
        return [
            'count' => \count($rows),
            'ids' => \array_slice($rows, 0, self::MAX_IDS),
        ];
    }
}
