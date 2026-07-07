<?php declare(strict_types=1);

namespace ContentCreator\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lese-Schicht für die Batch-Job-Historie im Admin: Job-Liste, offene
 * Dry-Run-Ergebnisse (inkl. Anzeigenamen) und Ablehnungsgründe. Bewusst
 * direktes SQL statt DAL — die Job-/Ergebnis-Tabellen sind rein intern und
 * die Aggregationen (Sub-Select, Payload-JSON) wären über Criteria teurer.
 */
class JobHistoryService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Die letzten 10 Jobs inkl. Zahl der offenen (bestandenen, noch nicht
     * übernommenen) Dry-Run-Ergebnisse.
     *
     * @return list<array<string, mixed>>
     */
    public function recentJobs(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(j.id)) AS id, j.status, j.entity_type AS entityType, j.types,
                    j.dry_run AS dryRun, j.total, j.processed, j.failed, j.rejected, j.created_at AS createdAt,
                    (SELECT COUNT(*) FROM content_creator_batch_result r
                      WHERE r.job_id = j.id AND r.passed = 1 AND r.applied = 0) AS openResults
             FROM content_creator_generation_job j
             ORDER BY j.created_at DESC
             LIMIT 10',
        );
        foreach ($rows as &$row) {
            $row['dryRun'] = (bool) $row['dryRun'];
            foreach (['total', 'processed', 'failed', 'rejected', 'openResults'] as $int) {
                $row[$int] = (int) $row[$int];
            }
        }

        return $rows;
    }

    /**
     * Zählt Ergebnis-ZEILEN (je Typ eine) — die Item-Zähler des Jobs zählen Objekte.
     */
    public function pendingResultCount(string $jobId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM content_creator_batch_result WHERE job_id = UNHEX(:job) AND passed = 1 AND applied = 0',
            ['job' => $jobId],
        );
    }

    /**
     * Bestandene Dry-Run-Ergebnisse zum Review/Editieren vor dem Übernehmen.
     *
     * @return list<array<string, mixed>>
     */
    public function batchResults(string $jobId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(entity_id)) AS entityId, content_type AS type, payload
             FROM content_creator_batch_result
             WHERE job_id = UNHEX(:job) AND passed = 1 AND applied = 0
             ORDER BY created_at ASC LIMIT 200',
            ['job' => $jobId],
        );

        // Anzeigenamen in der Sprache des Jobs (nicht in einer beliebigen)
        $jobLanguageId = (string) $this->connection->fetchOne(
            'SELECT LOWER(HEX(language_id)) FROM content_creator_generation_job WHERE id = UNHEX(:job)',
            ['job' => $jobId],
        );

        // Anzeigedaten gebündelt vorladen — pro Zeile einzeln wären es bei
        // 200 Ergebnissen mehrere hundert Queries (N+1)
        $mediaIds = [];
        $entityIds = [];
        foreach ($rows as $row) {
            if ($row['type'] === PromptBuilder::TYPE_MEDIA_ALT) {
                $mediaIds[] = (string) $row['entityId'];
            } else {
                $entityIds[] = (string) $row['entityId'];
            }
        }
        $mediaInfo = $this->mediaInfo($mediaIds);
        $displayNames = $this->displayNames($entityIds, $jobLanguageId ?: null);

        $results = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true) ?: [];
            $entityId = (string) $row['entityId'];
            $isMedia = $row['type'] === PromptBuilder::TYPE_MEDIA_ALT;
            $results[] = [
                'id' => (string) $row['id'],
                'entityId' => $entityId,
                'type' => (string) $row['type'],
                'content' => $payload['content'] ?? null,
                'meta' => $payload['meta'] ?? null,
                'feed' => $payload['feed'] ?? null,
                'score' => $payload['quality']['score'] ?? null,
                'name' => $isMedia
                    ? (string) (($mediaInfo[$entityId]['fileName'] ?? '') ?: '')
                    : (string) ($displayNames[$entityId] ?? ''),
                'imagePath' => $isMedia
                    ? (string) (($mediaInfo[$entityId]['path'] ?? '') ?: '')
                    : '',
            ];
        }

        return $results;
    }

    /**
     * Gründe der nicht bestandenen Ergebnis-Zeilen (Gate-Ablehnung oder Fehler)
     * für die Anzeige im Admin — beantwortet das "Warum?" nach einem Lauf.
     *
     * @return list<array{entityId: string, type: string, reason: string}>
     */
    public function jobIssues(string $jobId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(entity_id)) AS entityId, content_type AS type, payload
             FROM content_creator_batch_result
             WHERE job_id = UNHEX(:job) AND passed = 0
             ORDER BY created_at ASC LIMIT 20',
            ['job' => $jobId],
        );

        $issues = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true) ?: [];
            if (\is_string($payload['error'] ?? null) && $payload['error'] !== '') {
                $reason = 'Fehler: ' . mb_substr($payload['error'], 0, 220);
            } else {
                $quality = $payload['quality'] ?? [];
                $parts = ['Score ' . ($quality['score'] ?? '?') . ' > Schwelle ' . ($quality['threshold'] ?? '?')];
                foreach (($quality['lengthIssues'] ?? []) as $issue) {
                    $parts[] = ($issue['field'] ?? '?') . ': ' . ($issue['length'] ?? '?') . ' Zeichen';
                }
                if (($quality['missingFacts'] ?? []) !== []) {
                    $parts[] = 'Fehlende Fakten: ' . implode(', ', \array_slice($quality['missingFacts'], 0, 5));
                }
                $reason = 'Gate: ' . implode(' | ', $parts);
            }
            $issues[] = ['entityId' => (string) $row['entityId'], 'type' => (string) $row['type'], 'reason' => $reason];
        }

        return $issues;
    }

    /**
     * Dateiname + Pfad aller Medien in EINER Query (fürs Review von media_alt).
     *
     * @param list<string> $mediaIds
     *
     * @return array<string, array{fileName: string|null, path: string|null}>
     */
    private function mediaInfo(array $mediaIds): array
    {
        $binary = $this->toBinaryIds($mediaIds);
        if ($binary === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, file_name, path FROM media WHERE id IN (:ids)',
            ['ids' => $binary],
            ['ids' => ArrayParameterType::BINARY],
        );

        $info = [];
        foreach ($rows as $row) {
            $info[(string) $row['id']] = ['fileName' => $row['file_name'], 'path' => $row['path']];
        }

        return $info;
    }

    /**
     * Anzeigenamen fürs Review gebündelt: je Übersetzungstabelle erst die
     * Zielsprache, dann beliebige (Vererbungs-Fallback) — gleiche Auflösungs-
     * Reihenfolge wie zuvor pro Zeile, nur als eine IN-Query je Stufe.
     *
     * @param list<string> $entityIds
     *
     * @return array<string, string> Entity-ID => Name (nur aufgelöste)
     */
    private function displayNames(array $entityIds, ?string $languageId): array
    {
        $remaining = $this->toBinaryIds($entityIds);
        if ($remaining === []) {
            return [];
        }

        $stages = [];
        foreach (['product_translation' => 'product_id', 'category_translation' => 'category_id', 'product_manufacturer_translation' => 'product_manufacturer_id'] as $table => $fk) {
            if ($languageId !== null) {
                $stages[] = [$table, $fk, true];
            }
            $stages[] = [$table, $fk, false];
        }

        $names = [];
        foreach ($stages as [$table, $fk, $langBound]) {
            if ($remaining === []) {
                break;
            }
            $params = ['ids' => array_values($remaining)];
            if ($langBound) {
                $params['lang'] = $languageId;
            }
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(' . $fk . ')) AS id, name FROM ' . $table
                . ' WHERE ' . $fk . ' IN (:ids) AND name IS NOT NULL'
                . ($langBound ? ' AND language_id = UNHEX(:lang)' : ''),
                $params,
                ['ids' => ArrayParameterType::BINARY],
            );
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                // Leere Namen wie zuvor überspringen (nächste Fallback-Stufe greift)
                if (!isset($remaining[$id]) || !$row['name']) {
                    continue;
                }
                $names[$id] = (string) $row['name'];
                unset($remaining[$id]);
            }
        }

        return $names;
    }

    /**
     * Hex-IDs dedupliziert nach BINARY(16) wandeln (ungültige fliegen raus).
     *
     * @param list<string> $ids
     *
     * @return array<string, string> Hex-ID => Binär-ID
     */
    private function toBinaryIds(array $ids): array
    {
        $binary = [];
        foreach ($ids as $id) {
            if (ctype_xdigit($id) && \strlen($id) === 32) {
                $binary[$id] = (string) hex2bin($id);
            }
        }

        return $binary;
    }
}
