<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

interface AiProviderInterface
{
    /**
     * Eindeutiger Schlüssel des Providers ('claude' | 'openai').
     */
    public function getName(): string;

    /**
     * True, wenn ein API-Key hinterlegt ist.
     */
    public function isConfigured(): bool;

    /**
     * Standardmodell laut Konfiguration.
     */
    public function getDefaultModel(): string;

    /**
     * True, wenn der Provider das Web-Recherche-Tool unterstützt.
     */
    public function supportsWebSearch(): bool;

    /**
     * Führt den LLM-Aufruf aus.
     *
     * @throws ProviderException bei Konfigurations-, Transport- oder API-Fehlern
     */
    public function generate(AiRequest $request): AiResult;
}
