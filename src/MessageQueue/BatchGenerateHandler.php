<?php declare(strict_types=1);

namespace ContentCreator\MessageQueue;

use ContentCreator\Core\Content\GenerationJob\GenerationJobCollection;
use ContentCreator\Service\ContentGenerator;
use ContentCreator\Service\ContentWriter;
use ContentCreator\Service\FactLoader;
use ContentCreator\Service\PromptBuilder;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: BatchGenerateMessage::class)]
class BatchGenerateHandler
{
    /**
     * Welche Texttypen im Batch je Entity-Typ zurückgeschrieben werden.
     * (Teaser fehlt bewusst – siehe ContentWriter.)
     */
    private const WRITABLE = [
        'product' => [PromptBuilder::TYPE_PRODUCT_DESCRIPTION, PromptBuilder::TYPE_PRODUCT_META, PromptBuilder::TYPE_FAQ, PromptBuilder::TYPE_MEDIA_ALT, PromptBuilder::TYPE_PRODUCT_FEED],
        'category' => [PromptBuilder::TYPE_CATEGORY_TEASER, PromptBuilder::TYPE_CATEGORY_DETAIL, PromptBuilder::TYPE_CATEGORY_META, PromptBuilder::TYPE_FAQ],
        'media' => [PromptBuilder::TYPE_MEDIA_ALT],
        'sales_channel' => [PromptBuilder::TYPE_HOME_META],
        'manufacturer' => [PromptBuilder::TYPE_MANUFACTURER_DESCRIPTION],
    ];

    /**
     * @param EntityRepository<GenerationJobCollection> $generationJobRepository
     */
    public function __construct(
        private readonly ContentGenerator $generator,
        private readonly FactLoader $factLoader,
        private readonly ContentWriter $writer,
        private readonly EntityRepository $generationJobRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BatchGenerateMessage $message): void
    {
        $jobId = $message->getJobId();
        $itemId = $message->getItemId();

        $job = $this->generationJobRepository->search(new Criteria([$jobId]), Context::createDefaultContext())->first();
        if ($job === null) {
            return;
        }

        $entityType = $job->getEntityType();
        $requestedTypes = $job->getTypes() ?? [];
        $languageId = $job->getLanguageId();
        $mode = $job->getMode() ?? PromptBuilder::MODE_CREATE;
        $metaFields = $job->getMetaFields();
        $langContext = $this->factLoader->context($languageId ?? '');
        $langCode = $this->factLoader->langCode($languageId);

        $written = 0;
        $rejected = 0;
        $failed = false;
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            $facts = match ($entityType) {
                'product' => $this->factLoader->loadProduct($itemId, $langContext),
                'category' => $this->factLoader->loadCategory($itemId, $langContext),
                'media' => $this->factLoader->loadMedia($itemId, $langContext),
                'sales_channel' => $this->factLoader->loadSalesChannel($itemId, $langContext),
                'manufacturer' => $this->factLoader->loadManufacturer($itemId, $langContext),
                default => throw new \RuntimeException('Unbekannter Entity-Typ: ' . $entityType),
            };

            $writable = self::WRITABLE[$entityType] ?? [];
            foreach ($requestedTypes as $type) {
                if (!\in_array($type, $writable, true)) {
                    continue;
                }

                // Produkt-Workflow: "Alt-Texte" am Produkt verarbeitet automatisch
                // ALLE Bilder des Produkts (kein Medien-Picker nötig)
                if ($entityType === 'product' && $type === PromptBuilder::TYPE_MEDIA_ALT) {
                    $mediaIds = $this->connection->fetchFirstColumn(
                        'SELECT LOWER(HEX(media_id)) FROM product_media
                         WHERE product_id = UNHEX(:id) AND product_version_id = UNHEX(:live)
                         ORDER BY position ASC',
                        ['id' => $itemId, 'live' => \Shopware\Core\Defaults::LIVE_VERSION],
                    );
                    foreach ($mediaIds as $mediaId) {
                        // Ein defektes Bild darf die übrigen nicht mitreißen
                        try {
                            $mediaFacts = $this->factLoader->loadMedia($mediaId, $langContext);
                            $result = $this->generator->generate(
                                $type,
                                $langCode,
                                $mediaFacts,
                                $job->getProvider(),
                                $job->getModel(),
                                $this->effectiveMode($mode, $type, $mediaFacts),
                                null,
                            );
                            $inputTokens += (int) ($result['usage']['input'] ?? 0);
                            $outputTokens += (int) ($result['usage']['output'] ?? 0);
                            $passedGate = (bool) ($result['quality']['passed'] ?? false);

                            if ($job->getDryRun()) {
                                $this->storeDryRunResult($jobId, $mediaId, $type, $result, $passedGate);
                                $passedGate ? $written++ : $rejected++;
                                continue;
                            }
                            if (!$passedGate) {
                                $rejected++;
                                $this->storeDryRunResult($jobId, $mediaId, $type, $result, false);
                                continue;
                            }
                            $this->writer->apply('media', $mediaId, (string) ($languageId ?? ''), $type, $result, $langContext);
                            $written++;
                        } catch (\Throwable $e) {
                            $rejected++;
                            $this->storeDryRunResult($jobId, $mediaId, $type, ['error' => $e->getMessage()], false);
                        }
                    }

                    continue;
                }

                // Lücken füllen: Optimieren ohne Bestand fällt auf Erstellen zurück.
                $effectiveMode = $this->effectiveMode($mode, $type, $facts);

                $result = $this->generator->generate(
                    $type,
                    $langCode,
                    $facts,
                    $job->getProvider(),
                    $job->getModel(),
                    $effectiveMode,
                    $metaFields,
                );
                $inputTokens += (int) ($result['usage']['input'] ?? 0);
                $outputTokens += (int) ($result['usage']['output'] ?? 0);
                $passedGate = (bool) ($result['quality']['passed'] ?? false);

                // Dry-Run: Ergebnis speichern statt schreiben — Übernahme nach Review
                if ($job->getDryRun()) {
                    $this->storeDryRunResult($jobId, $itemId, $type, $result, $passedGate);
                    $passedGate ? $written++ : $rejected++;
                    continue;
                }

                // Vertrauens-Garantie: Nur Gate-bestandener Content wird geschrieben.
                if (!$passedGate) {
                    $rejected++;
                    $this->logger->warning('ContentCreator batch item rejected by quality gate', [
                        'job' => $jobId,
                        'item' => $itemId,
                        'type' => $type,
                        'score' => $result['quality']['score'] ?? null,
                        'threshold' => $result['quality']['threshold'] ?? null,
                        'lengthIssues' => $result['quality']['lengthIssues'] ?? [],
                        'missingFacts' => $result['quality']['missingFacts'] ?? [],
                    ]);
                    continue;
                }

                $this->writer->apply($entityType, $itemId, (string) ($languageId ?? ''), $type, $result, $langContext);
                $written++;
            }
        } catch (\Throwable $e) {
            $failed = true;
            $this->logger->error('ContentCreator batch item failed', [
                'job' => $jobId,
                'item' => $itemId,
                'error' => $e->getMessage(),
            ]);
            // Grund im Admin sichtbar machen (Diagnose-Zeile, wird nie übernommen)
            try {
                $this->storeDryRunResult($jobId, $itemId, $type ?? ($requestedTypes[0] ?? '-'), ['error' => $e->getMessage()], false);
            } catch (\Throwable) {
            }
        }

        // Item-Status: Fehler > geschrieben > alles abgelehnt
        $column = 'processed';
        if ($failed) {
            $column = 'failed';
        } elseif ($written === 0 && $rejected > 0) {
            $column = 'rejected';
        }

        $this->bumpCounter($jobId, $column);
        if ($inputTokens > 0 || $outputTokens > 0) {
            $this->connection->executeStatement(
                'UPDATE content_creator_generation_job
                 SET input_tokens = input_tokens + :in, output_tokens = output_tokens + :out
                 WHERE id = UNHEX(:id)',
                ['in' => $inputTokens, 'out' => $outputTokens, 'id' => $jobId],
            );
        }
        $this->maybeComplete($jobId);
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function effectiveMode(string $mode, string $type, array $facts): string
    {
        if ($mode !== PromptBuilder::MODE_OPTIMIZE) {
            return $mode;
        }

        $hasExisting = match ($type) {
            PromptBuilder::TYPE_PRODUCT_DESCRIPTION, PromptBuilder::TYPE_CATEGORY_DETAIL,
            PromptBuilder::TYPE_MANUFACTURER_DESCRIPTION => (bool) ($facts['_hasDescription'] ?? false),
            PromptBuilder::TYPE_CATEGORY_TEASER => (bool) ($facts['_hasTeaser'] ?? false),
            PromptBuilder::TYPE_FAQ => trim((string) ($facts['existingFaq'] ?? '')) !== '',
            PromptBuilder::TYPE_MEDIA_ALT => (bool) ($facts['_hasAlt'] ?? false),
            default => true, // Meta: leerer Bestand ist als Basis zulässig
        };

        return $hasExisting ? PromptBuilder::MODE_OPTIMIZE : PromptBuilder::MODE_CREATE;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function storeDryRunResult(string $jobId, string $itemId, string $type, array $result, bool $passed): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content_creator_batch_result (id, job_id, entity_id, content_type, payload, passed, applied, created_at)
             VALUES (UNHEX(:id), UNHEX(:job), UNHEX(:entity), :type, :payload, :passed, 0, NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'job' => $jobId,
                'entity' => $itemId,
                'type' => $type,
                'payload' => json_encode([
                    'content' => $result['content'] ?? null,
                    'meta' => $result['meta'] ?? null,
                    'feed' => $result['feed'] ?? null,
                    'quality' => $result['quality'] ?? null,
                    'error' => $result['error'] ?? null,
                ], \JSON_THROW_ON_ERROR),
                'passed' => $passed ? 1 : 0,
            ],
        );
    }

    private function bumpCounter(string $jobId, string $column): void
    {
        if (!\in_array($column, ['processed', 'failed', 'rejected'], true)) {
            throw new \InvalidArgumentException('Unbekannte Zähler-Spalte: ' . $column);
        }

        $this->connection->executeStatement(
            "UPDATE content_creator_generation_job SET {$column} = {$column} + 1, updated_at = NOW(3) WHERE id = UNHEX(:id)",
            ['id' => $jobId],
        );
    }

    private function maybeComplete(string $jobId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT total, processed, failed, rejected FROM content_creator_generation_job WHERE id = UNHEX(:id)',
            ['id' => $jobId],
        );
        if ($row === false) {
            return;
        }

        $done = ((int) $row['processed'] + (int) $row['failed'] + (int) $row['rejected']) >= (int) $row['total'];
        if (!$done) {
            return;
        }

        $status = 'done';
        if ((int) $row['failed'] > 0 || (int) $row['rejected'] > 0) {
            $status = ((int) $row['processed'] > 0) ? 'done_with_errors' : 'failed';
        }

        $this->connection->executeStatement(
            'UPDATE content_creator_generation_job SET status = :status, updated_at = NOW(3) WHERE id = UNHEX(:id)',
            ['status' => $status, 'id' => $jobId],
        );
    }
}
