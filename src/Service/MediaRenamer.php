<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

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
        private readonly FileSaver $fileSaver,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    /**
     * Produktbilder mit nicht-beschreibenden Dateinamen + Namensvorschlag (Dry-Run).
     * Liefert max. MAX_SCAN pro Lauf (Wellen-Prinzip: umbenannte matchen nicht mehr)
     * plus die Gesamtzahl, damit klar ist, wie viele Läufe noch anstehen.
     *
     * @return array{items: list<array{mediaId: string, currentName: string, suggestedName: string, productName: string}>, total: int}
     */
    public function scan(string $languageId, ?string $productId = null): array
    {
        $productFilter = $productId !== null ? ' AND pm.product_id = UNHEX(:pid)' : '';
        $params = ['live' => Defaults::LIVE_VERSION];
        if ($productId !== null) {
            $params['pid'] = $productId;
        }

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT m.id)
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live){$productFilter}
             WHERE m.file_name REGEXP '^[0-9][0-9a-zA-Z_-]*$' OR m.file_name REGEXP '^[a-f0-9]{30,}$'",
            $params
        );

        // Gezielter Produkt-Scan: ALLE Bilder anbieten (auch bereits umbenannte
        // zur Korrektur) — der Anker kommt dann aus dem Umbenennungs-Protokoll.
        // Globaler Scan: nur Artikelnummer-/Hash-Namen (Wellen-Prinzip).
        $nameFilter = $productId !== null
            ? ''
            : "AND (m.file_name REGEXP '^[0-9][0-9a-zA-Z_-]*$' OR m.file_name REGEXP '^[a-f0-9]{30,}$')";

        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT LOWER(HEX(m.id)) AS media_id, m.file_name,
                    pt.name AS product_name, mt.alt,
                    (SELECT r.old_path FROM content_creator_media_rename r
                     WHERE r.media_id = m.id ORDER BY r.created_at ASC LIMIT 1) AS first_old_path
             FROM media m
             INNER JOIN product_media pm ON pm.media_id = m.id AND pm.product_version_id = UNHEX(:live){$productFilter}
             INNER JOIN product_translation pt
                ON pt.product_id = pm.product_id AND pt.product_version_id = pm.product_version_id AND pt.language_id = UNHEX(:lang)
             LEFT JOIN media_translation mt ON mt.media_id = m.id AND mt.language_id = UNHEX(:lang)
             WHERE pt.name IS NOT NULL {$nameFilter}
             LIMIT " . self::MAX_SCAN,
            $params + ['lang' => $languageId]
        );

        $items = [];
        $usedNames = [];
        $withoutAlt = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['alt'] ?? '')) === '') {
                $withoutAlt++;
            }
            // Anker = URSPRÜNGLICHER Name (aus dem Protokoll), falls schon mal umbenannt
            $anchorSource = (string) $row['file_name'];
            if (!empty($row['first_old_path'])) {
                $original = pathinfo((string) $row['first_old_path'], \PATHINFO_FILENAME);
                if ($original !== '') {
                    $anchorSource = $original;
                }
            }
            $suggested = $this->suggestName((string) $row['product_name'], (string) ($row['alt'] ?? ''), $anchorSource);
            // Bereits perfekte Namen nicht anbieten
            if ($suggested === (string) $row['file_name']) {
                continue;
            }
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
     * Schreibt die komplette Redirect-Datei (kumulativ) an den konfigurierten
     * Pfad — einmalig als nginx-Include einrichten, danach hält das Plugin die
     * Datei nach jedem Umbenennungs-Lauf automatisch aktuell. nginx liest
     * Includes erst beim Reload; ein täglicher reload-Cron genügt, da frisch
     * umbenannte Bilder noch nicht indexiert sind.
     *
     * @return string|null Geschriebener Pfad oder null (nicht konfiguriert)
     */
    public function writeRedirectFile(): ?string
    {
        $path = trim((string) $this->systemConfig->get('ContentCreator.config.redirectFile'));
        if ($path === '') {
            return null;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Redirect-Datei-Verzeichnis nicht beschreibbar: ' . $dir);
        }
        if (file_put_contents($path, $this->exportNginx(), \LOCK_EX) === false) {
            throw new \RuntimeException('Redirect-Datei konnte nicht geschrieben werden: ' . $path);
        }

        return $path;
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
     * Vorschlag: Slug aus dem Produktnamen + ALTEM Dateinamen als Zuordnungs-
     * Anker GARANTIERT am Ende (15601a -> folkmanis-handpuppe-schnecke-15601a).
     * Bewusst OHNE Alt-Text-Wörter: Produktnamen sind gepflegt, dadurch sind
     * die Vorschläge grammatisch sauber und ohne Einzelprüfung batch-tauglich
     * (die Beschreibungskraft liefert der Alt-Text, nicht der Dateiname).
     * Gekürzt wird nur wortweise; der Anker wird nie abgeschnitten.
     */
    private function suggestName(string $productName, string $alt, string $currentName = ''): string
    {
        // Alter Dateiname (i.d.R. die Artikelnummer) als Zuordnungs-Anker —
        // außer er ist ein nichtssagender Hash (30+ Hex-Zeichen)
        $anchor = $this->slugify($currentName);
        if ($anchor === '' || preg_match('/^[a-f0-9]{30,}$/', $anchor)) {
            $anchor = '';
        } else {
            $anchor = mb_substr($anchor, 0, 20);
        }
        $budget = self::MAX_NAME_LENGTH - ($anchor !== '' ? mb_strlen($anchor) + 1 : 0);

        // Füllwörter tragen im Dateinamen nichts bei
        $stopwords = ['der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einem', 'einen', 'und', 'mit', 'fuer', 'fuers', 'von', 'vom', 'aus', 'im', 'am', 'zum', 'zur', 'beim', 'ins', 'auf', 'bei', 'als', 'the', 'and', 'for', 'with', 'from'];

        $seen = [];
        $tokens = [];
        $length = 0;
        foreach (explode('-', $this->slugify($productName)) as $token) {
            if ($token === '' || isset($seen[$token]) || \in_array($token, $stopwords, true)) {
                continue;
            }
            $tokenLength = mb_strlen($token) + ($tokens === [] ? 0 : 1);
            if ($length + $tokenLength > $budget) {
                break;
            }
            $seen[$token] = true;
            $tokens[] = $token;
            $length += $tokenLength;
        }

        $name = implode('-', $tokens);

        return $anchor !== '' ? ($name !== '' ? $name . '-' . $anchor : $anchor) : $name;
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim((string) preg_replace('/-{2,}/', '-', $text), '-');
    }
}
