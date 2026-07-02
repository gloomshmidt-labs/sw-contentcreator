<?php declare(strict_types=1);

namespace ContentCreator\Controller;

use ContentCreator\Service\BatchDispatcher;
use ContentCreator\Service\CannibalizationScanner;
use ContentCreator\Service\ContentBackupService;
use ContentCreator\Service\ContentGenerator;
use ContentCreator\Service\ContentWriter;
use ContentCreator\Service\FactLoader;
use ContentCreator\Service\FreshnessScanner;
use ContentCreator\Service\GapScanner;
use ContentCreator\Service\LineBreakScanner;
use ContentCreator\Service\MediaRenamer;
use ContentCreator\Service\Provider\AiRequest;
use ContentCreator\Service\ProviderRegistry;
use ContentCreator\Service\QualityChecker;
use ContentCreator\Service\QualityReport;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ContentCreatorController extends AbstractController
{
    public function __construct(
        private readonly ContentGenerator $generator,
        private readonly ProviderRegistry $providerRegistry,
        private readonly FactLoader $factLoader,
        private readonly BatchDispatcher $batchDispatcher,
        private readonly EntityRepository $generationJobRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly ContentWriter $contentWriter,
        private readonly LineBreakScanner $lineBreakScanner,
        private readonly ContentBackupService $backupService,
        private readonly GapScanner $gapScanner,
        private readonly QualityReport $qualityReport,
        private readonly CannibalizationScanner $cannibalizationScanner,
        private readonly FreshnessScanner $freshnessScanner,
        private readonly MediaRenamer $mediaRenamer,
        private readonly Connection $connection
    ) {
    }

    /**
     * Bestandstext einer Entity, wie ihn auch die Generierung sieht (inkl.
     * Layout-Slots/Erlebniswelt) — Single Source of Truth für die Admin-Anzeige.
     */
    #[Route(path: '/api/content-creator/current-text', name: 'api.content-creator.current-text', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function currentText(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['entityType', 'id', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $langContext = $this->factLoader->context($fields['languageId']);
            $facts = match ($fields['entityType']) {
                'product' => $this->factLoader->loadProduct($fields['id'], $langContext),
                'category' => $this->factLoader->loadCategory($fields['id'], $langContext),
                'sales_channel' => $this->factLoader->loadSalesChannel($fields['id'], $langContext),
                'manufacturer' => $this->factLoader->loadManufacturer($fields['id'], $langContext),
                'media' => $this->factLoader->loadMedia($fields['id'], $langContext),
                default => throw new \InvalidArgumentException('Unbekannter Entity-Typ: ' . $fields['entityType']),
            };

            return new JsonResponse([
                'success' => true,
                'text' => (string) ($facts['existingHtml'] ?? $facts['existingText'] ?? ''),
                'teaser' => (string) ($facts['existingTeaser'] ?? ''),
                'metaTitle' => (string) ($facts['existingMetaTitle'] ?? ''),
                'metaDescription' => (string) ($facts['existingMetaDescription'] ?? ''),
                'keywords' => (string) ($facts['keywords'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/backup/latest', name: 'api.content-creator.backup.latest', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function latestBackup(Request $request, Context $context): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['entityType', 'id', 'type', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        return new JsonResponse([
            'success' => true,
            'backup' => $this->backupService->latest($fields['entityType'], $fields['id'], $fields['languageId'], $fields['type'], $context),
        ]);
    }

    #[Route(path: '/api/content-creator/backup/restore', name: 'api.content-creator.backup.restore', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function restoreBackup(Request $request, Context $context): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['backupId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $this->backupService->restore($fields['backupId'], $context);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/linebreaks/scan', name: 'api.content-creator.linebreaks.scan', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function scanLineBreaks(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $result = $this->lineBreakScanner->scan($fields['languageId'], $this->factLoader->context($fields['languageId']));

            return new JsonResponse(['success' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/linebreaks/fix', name: 'api.content-creator.linebreaks.fix', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function fixLineBreaks(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['categoryId', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            $fixed = $this->lineBreakScanner->fix($fields['categoryId'], $fields['languageId'], $this->factLoader->context($fields['languageId']));

            return new JsonResponse(['success' => true, 'fixed' => $fixed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Serverseitiges Übernehmen für Typen, deren Schreiblogik im Backend liegt
     * (Kategorie-Teaser → CMS-slotConfig-Merge, Startseiten-Meta).
     */
    #[Route(path: '/api/content-creator/apply', name: 'api.content-creator.apply', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $fields = $this->requireFields($data, ['entityType', 'id', 'type', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }
        $result = \is_array($data['result'] ?? null) ? $data['result'] : [];

        try {
            $langContext = $this->factLoader->context($fields['languageId']);
            $this->contentWriter->apply($fields['entityType'], $fields['id'], $fields['languageId'], $fields['type'], $result, $langContext);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/generate', name: 'api.content-creator.generate', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function generate(Request $request, Context $context): JsonResponse
    {
        $data = $this->jsonBody($request);
        $type = (string) ($data['type'] ?? '');
        $entityType = $data['entityType'] ?? null;
        $id = $data['id'] ?? null;
        $ctx = \is_array($data['context'] ?? null) ? $data['context'] : [];

        // Gewählte Sprache steuert Fakten-Sprache + Prompt-Sprache (mit System-Default-Fallback).
        $languageId = (\is_string($data['languageId'] ?? null) && $data['languageId'] !== '') ? $data['languageId'] : null;
        $factsContext = $languageId !== null ? $this->factLoader->context($languageId) : $context;

        try {
            if (\is_string($entityType) && \is_string($id) && $id !== '') {
                $facts = match ($entityType) {
                    'product' => $this->factLoader->loadProduct($id, $factsContext),
                    'category' => $this->factLoader->loadCategory($id, $factsContext),
                    'media' => $this->factLoader->loadMedia($id, $factsContext),
                    'sales_channel' => $this->factLoader->loadSalesChannel($id, $factsContext),
                    'manufacturer' => $this->factLoader->loadManufacturer($id, $factsContext),
                    default => [],
                };
                $ctx = array_merge($facts, $ctx);
            }

            $langCode = $languageId !== null
                ? $this->factLoader->langCode($languageId)
                : (isset($data['lang'])
                    ? (str_starts_with(strtolower((string) $data['lang']), 'en') ? 'en' : 'de')
                    : $this->factLoader->langCode($context->getLanguageId()));

            $result = $this->generator->generate(
                $type,
                $langCode,
                $ctx,
                $data['provider'] ?? null,
                $data['model'] ?? null,
                $this->modeFrom($data),
                $this->metaFieldsFrom($data)
            );

            return new JsonResponse(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/test-connection', name: 'api.content-creator.test-connection', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function testConnection(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);

        try {
            $provider = $this->providerRegistry->get($data['provider'] ?? null);
            $result = $provider->generate(new AiRequest(
                system: 'Antworte ausschließlich mit dem Wort: OK',
                userPrompt: 'Bitte antworte mit OK.',
                maxTokens: 20
            ));

            return new JsonResponse([
                'success' => true,
                'provider' => $provider->getName(),
                'model' => $result->model,
                'reply' => $result->text,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/batch', name: 'api.content-creator.batch', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function batch(Request $request, Context $context): JsonResponse
    {
        $data = $this->jsonBody($request);
        $entityType = (string) ($data['entityType'] ?? '');
        $ids = array_values(array_filter((array) ($data['ids'] ?? [])));
        $types = array_values(array_filter((array) ($data['types'] ?? [])));

        if ($entityType === '' || $ids === [] || $types === []) {
            return $this->missingFieldsResponse(['entityType', 'ids', 'types']);
        }

        // Das Batch-Modell ist Claude-spezifisch – nur anwenden, wenn der aktive
        // Provider auch Claude ist. Bei OpenAI greift dessen Standardmodell (openaiModel).
        $providerName = $this->providerRegistry->activeProviderName($data['provider'] ?? null);
        $model = $data['model'] ?? null;
        if ($model === null && $providerName === 'claude') {
            $configuredBatchModel = (string) $this->systemConfig->get('ContentCreator.config.batchModel');
            $model = $configuredBatchModel !== '' ? $configuredBatchModel : null;
        }

        $jobId = $this->batchDispatcher->dispatch(
            $entityType,
            $ids,
            $types,
            $data['languageId'] ?? $context->getLanguageId(),
            $data['provider'] ?? null,
            $model,
            $context,
            $this->modeFrom($data),
            $this->metaFieldsFrom($data),
            (bool) ($data['dryRun'] ?? false)
        );

        return new JsonResponse(['success' => true, 'jobId' => $jobId, 'total' => \count($ids)]);
    }

    #[Route(path: '/api/content-creator/batch/{jobId}', name: 'api.content-creator.batch.status', defaults: ['_acl' => ['content_creator.viewer']], methods: ['GET'])]
    public function status(string $jobId, Context $context): JsonResponse
    {
        $job = $this->generationJobRepository->search(new Criteria([$jobId]), $context)->first();
        if ($job === null) {
            return new JsonResponse(['success' => false, 'error' => 'Job nicht gefunden.'], 404);
        }

        return new JsonResponse(['success' => true, 'job' => [
            'id' => $job->getId(),
            'status' => $job->getStatus(),
            'total' => $job->getTotal(),
            'processed' => $job->getProcessed(),
            'failed' => $job->getFailed(),
            'rejected' => $job->getRejected(),
            'inputTokens' => $job->getInputTokens(),
            'outputTokens' => $job->getOutputTokens(),
            'model' => $job->getModel(),
            'dryRun' => $job->getDryRun(),
            // Zählt Ergebnis-ZEILEN (je Typ eine) — die Item-Zähler oben zählen Objekte
            'pendingResults' => $job->getDryRun() ? (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM content_creator_batch_result WHERE job_id = UNHEX(:job) AND passed = 1 AND applied = 0',
                ['job' => $jobId]
            ) : 0,
        ]]);
    }

    /**
     * Dry-Run-Ergebnisse gesammelt übernehmen (nur Gate-bestandene, je 1x).
     */
    #[Route(path: '/api/content-creator/batch/{jobId}/commit', name: 'api.content-creator.batch.commit', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function commitBatch(string $jobId, Context $context): JsonResponse
    {
        $job = $this->generationJobRepository->search(new Criteria([$jobId]), $context)->first();
        if ($job === null || !$job->getDryRun()) {
            return new JsonResponse(['success' => false, 'error' => 'Dry-Run-Job nicht gefunden.'], 404);
        }

        $languageId = (string) ($job->getLanguageId() ?? '');
        $langContext = $this->factLoader->context($languageId);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) id, LOWER(HEX(entity_id)) entity_id, content_type, payload
             FROM content_creator_batch_result
             WHERE job_id = UNHEX(:job) AND passed = 1 AND applied = 0',
            ['job' => $jobId]
        );

        $applied = 0;
        $errors = 0;
        foreach ($rows as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true) ?: [];
                $this->contentWriter->apply(
                    $job->getEntityType(),
                    (string) $row['entity_id'],
                    $languageId,
                    (string) $row['content_type'],
                    $payload,
                    $langContext
                );
                $this->connection->executeStatement(
                    'UPDATE content_creator_batch_result SET applied = 1 WHERE id = UNHEX(:id)',
                    ['id' => $row['id']]
                );
                $applied++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return new JsonResponse(['success' => true, 'applied' => $applied, 'errors' => $errors]);
    }

    #[Route(path: '/api/content-creator/gaps', name: 'api.content-creator.gaps', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function gaps(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['entityType', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            return new JsonResponse(['success' => true, 'gaps' => $this->gapScanner->scan($fields['languageId'], $fields['entityType'])]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/media-rename/scan', name: 'api.content-creator.media-rename.scan', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function mediaRenameScan(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            return new JsonResponse(['success' => true] + $this->mediaRenamer->scan($fields['languageId']));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/media-rename/apply', name: 'api.content-creator.media-rename.apply', defaults: ['_acl' => ['content_creator.editor']], methods: ['POST'])]
    public function mediaRenameApply(Request $request, Context $context): JsonResponse
    {
        $data = $this->jsonBody($request);
        $items = \is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($items === []) {
            return new JsonResponse(['success' => false, 'error' => 'items sind erforderlich.'], 400);
        }

        $renamed = 0;
        $errors = [];
        foreach ($items as $item) {
            try {
                $this->mediaRenamer->rename((string) ($item['mediaId'] ?? ''), (string) ($item['newName'] ?? ''), $context);
                $renamed++;
            } catch (\Throwable $e) {
                $errors[] = ($item['currentName'] ?? $item['mediaId'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return new JsonResponse(['success' => true, 'renamed' => $renamed, 'errors' => \array_slice($errors, 0, 10)]);
    }

    #[Route(path: '/api/content-creator/media-rename/export', name: 'api.content-creator.media-rename.export', defaults: ['_acl' => ['content_creator.viewer']], methods: ['GET'])]
    public function mediaRenameExport(): Response
    {
        return new Response($this->mediaRenamer->exportNginx(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="contentcreator-media-redirects.conf"',
        ]);
    }

    #[Route(path: '/api/content-creator/freshness', name: 'api.content-creator.freshness', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function freshness(Request $request): JsonResponse
    {
        $fields = $this->requireFields($this->jsonBody($request), ['entityType', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            return new JsonResponse(['success' => true] + $this->freshnessScanner->scan($fields['entityType'], $fields['languageId']));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/cannibalization', name: 'api.content-creator.cannibalization', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function cannibalization(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $fields = $this->requireFields($data, ['entityType', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        try {
            if (\is_string($data['keyword'] ?? null) && trim($data['keyword']) !== '') {
                return new JsonResponse(['success' => true, 'usedBy' => $this->cannibalizationScanner->keywordUsage(
                    $fields['entityType'],
                    $fields['languageId'],
                    $data['keyword'],
                    \is_string($data['excludeId'] ?? null) ? $data['excludeId'] : null
                )]);
            }

            return new JsonResponse(['success' => true] + $this->cannibalizationScanner->scan($fields['entityType'], $fields['languageId']));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    #[Route(path: '/api/content-creator/quality-report', name: 'api.content-creator.quality-report', defaults: ['_acl' => ['content_creator.viewer']], methods: ['POST'])]
    public function qualityReport(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);
        $fields = $this->requireFields($data, ['entityType', 'languageId']);
        if ($fields instanceof JsonResponse) {
            return $fields;
        }

        $whitelist = QualityChecker::parseWhitelist(
            (string) $this->systemConfig->get('ContentCreator.config.qualityWhitelist')
        );

        try {
            $page = $this->qualityReport->page(
                $fields['entityType'],
                $fields['languageId'],
                $this->factLoader->langCode($fields['languageId']),
                max(0, (int) ($data['offset'] ?? 0)),
                $whitelist
            );

            return new JsonResponse(['success' => true] + $page);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        return json_decode($request->getContent(), true) ?: [];
    }

    /**
     * Pflichtfelder als nicht-leere Strings einsammeln; fehlt eines, kommt die
     * fertige 400-Antwort zurück (Wortlaut identisch zu den bisherigen Meldungen).
     *
     * @param array<string, mixed> $data
     * @param list<string> $fields
     *
     * @return array<string, string>|JsonResponse
     */
    private function requireFields(array $data, array $fields): array|JsonResponse
    {
        $values = [];
        foreach ($fields as $field) {
            $value = (string) ($data[$field] ?? '');
            if ($value === '') {
                return $this->missingFieldsResponse($fields);
            }
            $values[$field] = $value;
        }

        return $values;
    }

    /**
     * @param list<string> $fields
     */
    private function missingFieldsResponse(array $fields): JsonResponse
    {
        $message = \count($fields) === 1
            ? $fields[0] . ' ist erforderlich.'
            : implode(', ', \array_slice($fields, 0, -1)) . ' und ' . $fields[\count($fields) - 1] . ' sind erforderlich.';

        return new JsonResponse(['success' => false, 'error' => $message], 400);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function modeFrom(array $data): string
    {
        return \in_array($data['mode'] ?? null, ['create', 'optimize'], true) ? $data['mode'] : 'create';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>|null
     */
    private function metaFieldsFrom(array $data): ?array
    {
        return \is_array($data['metaFields'] ?? null) ? array_values(array_filter($data['metaFields'])) : null;
    }
}
