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
        private readonly LoggerInterface $logger,
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
        // Base64 bevorzugt: unabhängig von robots.txt/Bot-Blockern/Wartungsmodus.
        if ($request->imageB64 !== null && $request->imageB64 !== '') {
            $userContent = [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $request->imageMime ?? 'image/jpeg', 'data' => $request->imageB64]],
                ['type' => 'text', 'text' => $request->userPrompt],
            ];
        } elseif ($request->imageUrl !== null && $request->imageUrl !== '') {
            $userContent = [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => $request->imageUrl]],
                ['type' => 'text', 'text' => $request->userPrompt],
            ];
        } else {
            $userContent = $request->userPrompt;
        }

        // Prompt-Caching: Der stabile System-Prompt (Regelwerk je Texttyp/Sprache)
        // wird als Cache-Breakpoint markiert — innerhalb eines Laufs (TTL 5 Min)
        // lesen alle Folge-Requests ihn zu ~0,1x des Input-Preises aus dem Cache
        // (Write einmalig ~1,25x). Unterhalb der Modell-Mindestgröße (1024-4096
        // Tokens je nach Modell) ignoriert die API den Marker stillschweigend.
        // Variable Anteile (Fokus-Keyword/SERP-Briefing) kommen als eigener
        // Block DAHINTER, damit sie den Cache-Prefix nicht invalidieren.
        $systemBlocks = [
            ['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']],
        ];
        if ($request->systemSuffix !== null && trim($request->systemSuffix) !== '') {
            $systemBlocks[] = ['type' => 'text', 'text' => $request->systemSuffix];
        }

        $body = [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'system' => $systemBlocks,
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
                $this->getName(),
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
            model: $data['model'] ?? $model,
            cacheCreationTokens: (int) ($data['usage']['cache_creation_input_tokens'] ?? 0),
            cacheReadTokens: (int) ($data['usage']['cache_read_input_tokens'] ?? 0),
        );
    }

    private function apiKey(): string
    {
        return trim((string) $this->systemConfig->get('ContentCreator.config.anthropicApiKey'));
    }
}
