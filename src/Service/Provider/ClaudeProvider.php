<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic Claude via Messages API (raw HTTP über Symfony HttpClient).
 *
 * Bewusst ohne Composer-SDK, damit das Plugin-ZIP kein vendor/ mitschleppt.
 */
class ClaudeProvider implements AiProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-4-8';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function getDefaultModel(): string
    {
        $model = (string) $this->systemConfig->get('ContentCreator.config.anthropicModel');

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    public function supportsWebSearch(): bool
    {
        return true;
    }

    public function generate(AiRequest $request): AiResult
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new ProviderException('Kein Anthropic API-Key hinterlegt.');
        }

        $model = $request->model ?? $this->getDefaultModel();

        // User-Content: reiner Text oder Text + Bild (Vision).
        if ($request->imageUrl !== null && $request->imageUrl !== '') {
            $userContent = [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => $request->imageUrl]],
                ['type' => 'text', 'text' => $request->userPrompt],
            ];
        } else {
            $userContent = $request->userPrompt;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        // Web-Recherche (Skills-Recherche-Pflicht): Anthropic-Server-Tool, max. 3 Suchen
        if ($request->allowWebSearch) {
            $body['tools'] = [
                ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3],
            ];
        }

        try {
            [$status, $data] = HttpRetry::sendJson(
                fn () => $this->httpClient->request('POST', self::API_URL, [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                    ],
                    'json' => $body,
                    'timeout' => 180,
                ]),
                $this->logger,
                $this->getName()
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('ContentCreator Claude transport error', ['exception' => $e->getMessage()]);
            throw new ProviderException('Verbindung zu Anthropic fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ('HTTP ' . $status);
            $this->logger->error('ContentCreator Claude API error', ['status' => $status, 'body' => $data]);
            throw new ProviderException('Anthropic API-Fehler: ' . $message);
        }

        $stopReason = $data['stop_reason'] ?? null;
        if ($stopReason === 'refusal') {
            throw new ProviderException('Die KI hat die Anfrage aus Sicherheitsgründen abgelehnt (refusal).');
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return new AiResult(
            text: trim($text),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            stopReason: $stopReason,
            model: $data['model'] ?? $model
        );
    }

    private function apiKey(): string
    {
        return trim((string) $this->systemConfig->get('ContentCreator.config.anthropicApiKey'));
    }
}
