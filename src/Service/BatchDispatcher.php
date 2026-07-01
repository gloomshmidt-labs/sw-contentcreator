<?php declare(strict_types=1);

namespace ContentCreator\Service;

use ContentCreator\MessageQueue\BatchGenerateMessage;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Legt einen Generation-Job an und verteilt pro Objekt eine async Nachricht.
 */
class BatchDispatcher
{
    public function __construct(
        private readonly EntityRepository $generationJobRepository,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    /**
     * @param string[] $ids
     * @param string[] $types
     * @param list<string>|null $metaFields
     */
    public function dispatch(
        string $entityType,
        array $ids,
        array $types,
        ?string $languageId,
        ?string $provider,
        ?string $model,
        Context $context,
        string $mode = 'create',
        ?array $metaFields = null,
        bool $dryRun = false
    ): string {
        $ids = array_values(array_unique(array_filter($ids)));
        $jobId = Uuid::randomHex();

        $this->generationJobRepository->create([[
            'id' => $jobId,
            'status' => 'running',
            'entityType' => $entityType,
            'types' => array_values($types),
            'itemIds' => $ids,
            'languageId' => $languageId,
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'metaFields' => $metaFields,
            'dryRun' => $dryRun,
            'total' => \count($ids),
            'processed' => 0,
            'failed' => 0,
            'rejected' => 0,
            'inputTokens' => 0,
            'outputTokens' => 0,
        ]], $context);

        foreach ($ids as $id) {
            $this->messageBus->dispatch(new BatchGenerateMessage($jobId, $id));
        }

        return $jobId;
    }
}
