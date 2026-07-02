<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * SEO-Dateinamen für Produktbilder: Artikelnummern-/Hash-Dateinamen (15601a.jpg)
 * werden zu beschreibenden Namen aus Produktname + Alt-Text. Jede Umbenennung
 * wird protokolliert (inkl. Thumbnail-Pfade) — daraus entsteht der nginx-301-
 * Export, damit alte Bild-URLs (Google Bilder, externe Links) erhalten bleiben.
 */
class MediaRenamer
{
    private const MAX_SCAN = 300;
    private const MAX_NAME_LENGTH = 70;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository,
        private readonly FileSaver $fileSaver
    ) {
    }

    /**
     * Produktbilder mit nicht-beschreibenden Dateinamen + Namensvorschlag (Dry-Run).
     * Liefert max. MAX_SCAN pro Lauf (Wellen-Prinzip: umbenannte matchen nicht mehr)
     * plus die Gesamtzahl, damit klar ist, wie viele Läufe noch anstehen.
     *
     * @return array{items: list<array{mediaId: string, currentName: string, suggestedName: string, productName: string}>, total: int}
     */
    public function scan(string $languageId): array
    {
        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT m.id)
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live)
             WHERE m.file_name REGEXP '^[0-9][0-9a-zA-Z_-]*$' OR m.file_name REGEXP '^[a-f0-9]{30,}$'",
            ['live' => Defaults::LIVE_VERSION]
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT LOWER(HEX(m.id)) AS media_id, m.file_name,
                    pt.name AS product_name, mt.alt
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live)
             INNER JOIN product_translation pt
                ON pt.product_id = pm.product_id AND pt.product_version_id = pm.product_version_id AND pt.language_id = UNHEX(:lang)
             LEFT JOIN media_translation mt ON mt.media_id = m.id AND mt.language_id = UNHEX(:lang)
             WHERE pt.name IS NOT NULL AND (
                    m.file_name REGEXP '^[0-9][0-9a-zA-Z_-]*$'
                    OR m.file_name REGEXP '^[a-f0-9]{30,}$'
               )
             LIMIT " . self::MAX_SCAN,
            ['lang' => $languageId, 'live' => Defaults::LIVE_VERSION]
        );

        $items = [];
        $usedNames = [];
        $withoutAlt = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['alt'] ?? '')) === '') {
                $withoutAlt++;
            }
            $suggested = $this->suggestName((string) $row['product_name'], (string) ($row['alt'] ?? ''));
            // Kollisionen innerhalb des Vorschlags-Sets deterministisch auflösen
            $base = $suggested;
            $i = 2;
            while (isset($usedNames[$suggested])) {
                $suggested = $base . '-' . $i;
                $i++;
            }
            $usedNames[$suggested] = true;

            $items[] = [
                'mediaId' => (string) $row['media_id'],
                'currentName' => (string) $row['file_name'],
                'suggestedName' => $suggested,
                'productName' => (string) $row['product_name'],
            ];
        }

        return ['items' => $items, 'total' => $total, 'withoutAlt' => $withoutAlt];
    }

    /**
     * Umbenennen + Protokollieren (alte/neue Pfade inkl. Thumbnails).
     *
     * @return array{oldPath: string, newPath: string}
     */
    public function rename(string $mediaId, string $newName, Context $context): array
    {
        $newName = $this->slugify($newName);
        if ($newName === '') {
            throw new \InvalidArgumentException('Leerer Zieldateiname.');
        }

        $before = $this->mediaSnapshot($mediaId, $context);

        try {
            $this->fileSaver->renameMedia($mediaId, $newName, $context);
        } catch (\Throwable) {
            // Namenskollision im Bestand: deterministisches Suffix und ein zweiter Versuch
            $newName .= '-' . substr($mediaId, 0, 4);
            $this->fileSaver->renameMedia($mediaId, $newName, $context);
        }

        $after = $this->mediaSnapshot($mediaId, $context);

        $thumbnailMap = [];
        foreach ($before['thumbnails'] as $size => $oldThumbPath) {
            if (isset($after['thumbnails'][$size]) && $after['thumbnails'][$size] !== $oldThumbPath) {
                $thumbnailMap[$oldThumbPath] = $after['thumbnails'][$size];
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO content_creator_media_rename (id, media_id, old_path, new_path, thumbnails, created_at)
             VALUES (UNHEX(:id), UNHEX(:media), :old, :new, :thumbs, NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'media' => $mediaId,
                'old' => $before['path'],
                'new' => $after['path'],
                'thumbs' => $thumbnailMap === [] ? null : json_encode($thumbnailMap, \JSON_THROW_ON_ERROR),
            ]
        );

        return ['oldPath' => $before['path'], 'newPath' => $after['path']];
    }

    /**
     * nginx-Redirects (exakte location-Blöcke, server-Kontext-tauglich für
     * Plesk "Additional nginx directives"). Verkettete Umbenennungen werden
     * auf das finale Ziel aufgelöst.
     */
    public function exportNginx(): string
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT old_path, new_path, thumbnails FROM content_creator_media_rename ORDER BY created_at ASC'
        );

        // a→b, b→c ⇒ a→c (und b→c)
        $redirects = [];
        foreach ($rows as $row) {
            $old = (string) $row['old_path'];
            $new = (string) $row['new_path'];
            foreach ($redirects as $source => $target) {
                if ($target === $old) {
                    $redirects[$source] = $new;
                }
            }
            $redirects[$old] = $new;
            foreach (json_decode((string) ($row['thumbnails'] ?? ''), true) ?: [] as $oldThumb => $newThumb) {
                $redirects[$oldThumb] = $newThumb;
            }
        }

        $lines = [
            '# ContentCreator: 301-Redirects fuer umbenannte Medien-Dateien',
            '# Generiert am ' . (new \DateTimeImmutable())->format('Y-m-d H:i') . ' — Einbindung: Plesk > Apache & nginx Einstellungen > Zusaetzliche nginx-Anweisungen',
        ];
        foreach ($redirects as $old => $new) {
            if ($old === $new || $old === '' || $new === '') {
                continue;
            }
            $lines[] = sprintf('location = %s { return 301 %s; }', $old, $new);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{path: string, thumbnails: array<string, string>}
     */
    private function mediaSnapshot(string $mediaId, Context $context): array
    {
        $criteria = new Criteria([$mediaId]);
        $criteria->addAssociation('thumbnails');
        $media = $this->mediaRepository->search($criteria, $context)->first();
        if ($media === null) {
            throw new \RuntimeException('Medium nicht gefunden: ' . $mediaId);
        }

        $thumbnails = [];
        foreach ($media->getThumbnails()?->getElements() ?? [] as $thumbnail) {
            $key = $thumbnail->getWidth() . 'x' . $thumbnail->getHeight();
            $thumbnails[$key] = $this->urlPath((string) $thumbnail->getUrl());
        }

        return [
            'path' => $this->urlPath((string) $media->getUrl()),
            'thumbnails' => $thumbnails,
        ];
    }

    private function urlPath(string $url): string
    {
        return (string) (parse_url($url, \PHP_URL_PATH) ?: $url);
    }

    /**
     * Vorschlag: Slug aus Produktname + unterscheidenden Alt-Wörtern.
     */
    private function suggestName(string $productName, string $alt): string
    {
        $base = $this->slugify($productName);

        // Unterscheidende Wörter aus dem Alt (ohne die Produktname-Wörter)
        $productTokens = array_flip(explode('-', $base));
        $altTokens = array_filter(
            explode('-', $this->slugify($alt)),
            static fn (string $t) => $t !== '' && mb_strlen($t) > 2 && !isset($productTokens[$t])
        );
        $suffix = implode('-', \array_slice(array_values($altTokens), 0, 3));

        $name = $suffix !== '' ? $base . '-' . $suffix : $base;

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim((string) preg_replace('/-{2,}/', '-', $text), '-');
    }
}
