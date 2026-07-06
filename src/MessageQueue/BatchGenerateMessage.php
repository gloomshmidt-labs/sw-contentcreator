<?php declare(strict_types=1);

namespace ContentCreator\MessageQueue;

use Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface;

/**
 * Ein Batch-Element: generiere Inhalte für genau ein Objekt eines Jobs.
 */
class BatchGenerateMessage implements LowPriorityMessageInterface
{
    public function __construct(
        private readonly string $jobId,
        private readonly string $itemId,
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getItemId(): string
    {
        return $this->itemId;
    }
}
