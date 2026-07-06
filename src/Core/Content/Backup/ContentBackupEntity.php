<?php declare(strict_types=1);

namespace ContentCreator\Core\Content\Backup;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ContentBackupEntity extends Entity
{
    use EntityIdTrait;

    protected string $entityType = '';

    protected string $entityId = '';

    protected string $languageId = '';

    protected string $contentType = '';

    /** @var array<string, mixed>|null */
    protected ?array $payload = null;

    protected ?\DateTimeInterface $restoredAt = null;

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): void
    {
        $this->entityType = $entityType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function setEntityId(string $entityId): void
    {
        $this->entityId = $entityId;
    }

    public function getLanguageId(): string
    {
        return $this->languageId;
    }

    public function setLanguageId(string $languageId): void
    {
        $this->languageId = $languageId;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): void
    {
        $this->payload = $payload;
    }

    public function getRestoredAt(): ?\DateTimeInterface
    {
        return $this->restoredAt;
    }

    public function setRestoredAt(?\DateTimeInterface $restoredAt): void
    {
        $this->restoredAt = $restoredAt;
    }
}
