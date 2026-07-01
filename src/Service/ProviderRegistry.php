<?php declare(strict_types=1);

namespace ContentCreator\Service;

use ContentCreator\Service\Provider\AiProviderInterface;
use ContentCreator\Service\Provider\ClaudeProvider;
use ContentCreator\Service\Provider\OpenAiProvider;
use ContentCreator\Service\Provider\ProviderException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ProviderRegistry
{
    public function __construct(
        private readonly ClaudeProvider $claudeProvider,
        private readonly OpenAiProvider $openAiProvider,
        private readonly SystemConfigService $systemConfig
    ) {
    }

    /**
     * Liefert den Provider anhand des übergebenen Namens oder – wenn null –
     * anhand der Plugin-Konfiguration ('claude' als Default).
     *
     * @throws ProviderException wenn der gewählte Provider nicht konfiguriert ist
     */
    public function get(?string $name = null): AiProviderInterface
    {
        $provider = match ($this->activeProviderName($name)) {
            'openai' => $this->openAiProvider,
            default => $this->claudeProvider,
        };

        if (!$provider->isConfigured()) {
            throw new ProviderException(sprintf(
                'Der Provider "%s" ist nicht konfiguriert (kein API-Key hinterlegt).',
                $provider->getName()
            ));
        }

        return $provider;
    }

    /**
     * Name des aktiven Providers ('claude'|'openai') – optional per Override,
     * sonst laut Konfiguration (Default 'claude').
     */
    public function activeProviderName(?string $override = null): string
    {
        $name = $override ?? (string) $this->systemConfig->get('ContentCreator.config.provider');

        return $name !== '' ? $name : 'claude';
    }

    /**
     * @return AiProviderInterface[]
     */
    public function all(): array
    {
        return [$this->claudeProvider, $this->openAiProvider];
    }
}
