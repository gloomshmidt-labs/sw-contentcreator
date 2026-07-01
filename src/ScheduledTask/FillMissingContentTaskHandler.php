<?php declare(strict_types=1);

namespace ContentCreator\ScheduledTask;

use ContentCreator\Service\BatchDispatcher;
use ContentCreator\Service\PromptBuilder;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: FillMissingContentTask::class)]
class FillMissingContentTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly SystemConfigService $systemConfig,
        private readonly EntityRepository $productRepository,
        private readonly BatchDispatcher $batchDispatcher
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        if (!$this->systemConfig->get('ContentCreator.config.dailyFillEnabled')) {
            return;
        }

        $limit = (int) $this->systemConfig->get('ContentCreator.config.dailyFillLimit');
        $limit = $limit > 0 ? $limit : 25;

        $context = Context::createDefaultContext();

        // Kandidaten laden und in PHP auf fehlende Beschreibung filtern
        $criteria = new Criteria();
        $criteria->setLimit($limit * 5);
        $products = $this->productRepository->search($criteria, $context);

        $missing = [];
        foreach ($products as $product) {
            if (trim((string) ($product->getDescription() ?? '')) === '') {
                $missing[] = $product->getId();
            }
            if (\count($missing) >= $limit) {
                break;
            }
        }

        if ($missing === []) {
            return;
        }

        // Claude-Batch-Modell nur bei aktivem Claude-Provider (sonst OpenAI-Standardmodell).
        $providerName = (string) $this->systemConfig->get('ContentCreator.config.provider');
        $providerName = $providerName !== '' ? $providerName : 'claude';
        $model = null;
        if ($providerName === 'claude') {
            $batchModel = (string) $this->systemConfig->get('ContentCreator.config.batchModel');
            $model = $batchModel !== '' ? $batchModel : null;
        }

        $jobId = $this->batchDispatcher->dispatch(
            'product',
            $missing,
            [PromptBuilder::TYPE_PRODUCT_DESCRIPTION, PromptBuilder::TYPE_PRODUCT_META],
            null,
            null,
            $model,
            $context
        );

        $this->logger->info('ContentCreator daily fill dispatched', ['job' => $jobId, 'count' => \count($missing)]);
    }
}
