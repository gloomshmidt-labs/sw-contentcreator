<?php declare(strict_types=1);

namespace ContentCreator\Service;

use ContentCreator\MessageQueue\BatchGenerateMessage;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Legt einen Generation-Job an und verteilt pro Objekt eine async Nachricht.
 */
class BatchDispatcher
{
    public function __construct(
        private readonly EntityRepository $generationJobRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly Connection $connection
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
        $ids = $this->filterExistingIds($entityType, $ids);
        if ($ids === []) {
            throw new \InvalidArgumentException(sprintf(
                'Keine der ausgewählten IDs existiert als "%s" — vermutlich stammt die Auswahl aus einem Scan eines anderen Objekt-Typs. Bitte neu scannen/auswählen.',
                $entityType
            ));
        }
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

    /**
     * Schutznetz: nur IDs behalten, die als Ziel-Entity wirklich existieren —
     * fremde IDs (z.B. Produkt-IDs in einem Medien-Batch) fliegen raus.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function filterExistingIds(string $entityType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $table = match ($entityType) {
            'product' => 'product',
            'category' => 'category',
            'media' => 'media',
            'sales_channel' => 'sales_channel',
            'manufacturer' => 'product_manufacturer',
            default => null,
        };
        if ($table === null) {
            return $ids;
        }

        $binary = array_map('hex2bin', array_filter($ids, static fn (string $id) => ctype_xdigit($id) && \strlen($id) === 32));
        if ($binary === []) {
            return [];
        }

        $existing = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM ' . $table . ' WHERE id IN (:ids)',
            ['ids' => $binary],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::BINARY]
        );

        return array_values(array_intersect($ids, $existing));
    }
}
