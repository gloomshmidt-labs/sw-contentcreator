<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\GenerationJob;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class GenerationJobEntity extends Entity
{
    use EntityIdTrait;

    protected string $status = 'open';

    protected string $entityType = '';

    /** @var list<string>|null */
    protected ?array $types = null;

    /** @var list<string>|null */
    protected ?array $itemIds = null;

    protected ?string $languageId = null;

    protected ?string $provider = null;

    protected ?string $model = null;

    protected ?string $mode = null;

    /** @var list<string>|null */
    protected ?array $metaFields = null;

    protected bool $dryRun = false;

    protected int $total = 0;

    protected int $processed = 0;

    protected int $failed = 0;

    protected int $rejected = 0;

    protected int $inputTokens = 0;

    protected int $outputTokens = 0;

    protected ?string $errorMessage = null;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): void
    {
        $this->entityType = $entityType;
    }

    /**
     * @return list<string>|null
     */
    public function getTypes(): ?array
    {
        return $this->types;
    }

    /**
     * @param list<string>|null $types
     */
    public function setTypes(?array $types): void
    {
        $this->types = $types;
    }

    /**
     * @return list<string>|null
     */
    public function getItemIds(): ?array
    {
        return $this->itemIds;
    }

    /**
     * @param list<string>|null $itemIds
     */
    public function setItemIds(?array $itemIds): void
    {
        $this->itemIds = $itemIds;
    }

    public function getLanguageId(): ?string
    {
        return $this->languageId;
    }

    public function setLanguageId(?string $languageId): void
    {
        $this->languageId = $languageId;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function setProcessed(int $processed): void
    {
        $this->processed = $processed;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function setFailed(int $failed): void
    {
        $this->failed = $failed;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): void
    {
        $this->mode = $mode;
    }

    /**
     * @return list<string>|null
     */
    public function getMetaFields(): ?array
    {
        return $this->metaFields;
    }

    /**
     * @param list<string>|null $metaFields
     */
    public function setMetaFields(?array $metaFields): void
    {
        $this->metaFields = $metaFields;
    }

    public function getDryRun(): bool
    {
        return $this->dryRun;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function getRejected(): int
    {
        return $this->rejected;
    }

    public function setRejected(int $rejected): void
    {
        $this->rejected = $rejected;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function setInputTokens(int $inputTokens): void
    {
        $this->inputTokens = $inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function setOutputTokens(int $outputTokens): void
    {
        $this->outputTokens = $outputTokens;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }
}
