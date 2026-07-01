<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI via Responses API (raw HTTP über Symfony HttpClient) — wie im
 * Textoptimierung-Tool: instructions/input statt messages, output-Array statt
 * choices. Die Responses API ist Voraussetzung für das web_search-Tool und
 * für Reasoning-Modelle (o-Serie, gpt-5-mini/nano: reasoning.effort).
 */
class OpenAiProvider implements AiProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/responses';
    private const DEFAULT_MODEL = 'gpt-4o';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function getDefaultModel(): string
    {
        $model = trim((string) $this->systemConfig->get('ContentCreator.config.openaiModel'));

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
            throw new ProviderException('Kein OpenAI API-Key hinterlegt.');
        }

        $model = $request->model ?? $this->getDefaultModel();

        $content = [['type' => 'input_text', 'text' => $request->userPrompt]];
        if ($request->imageUrl !== null && $request->imageUrl !== '') {
            $content[] = ['type' => 'input_image', 'image_url' => $request->imageUrl];
        }

        $body = [
            'model' => $model,
            'max_output_tokens' => $request->maxTokens,
            'instructions' => $request->system,
            'input' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        // Reasoning-Modelle (Tool-Muster _isReasoningModelStr): Reasoning-Tokens
        // zählen gegen max_output_tokens — Budget anheben, sonst wird bei kleinen
        // Limits (Alt-Text 200, Meta 700) nur "gedacht" und nichts ausgegeben.
        if (preg_match('/^(o[134]|gpt-5-mini|gpt-5-nano)/', $model) === 1) {
            $body['reasoning'] = ['effort' => 'medium'];
            $body['max_output_tokens'] = max($request->maxTokens, 2000);
        }

        if ($request->allowWebSearch) {
            $body['tools'] = [['type' => 'web_search']];
        }

        try {
            [$status, $data] = HttpRetry::sendJson(
                fn () => $this->httpClient->request('POST', self::API_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'content-type' => 'application/json',
                    ],
                    'json' => $body,
                    'timeout' => 180,
                ]),
                $this->logger,
                $this->getName()
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('ContentCreator OpenAI transport error', ['exception' => $e->getMessage()]);
            throw new ProviderException('Verbindung zu OpenAI fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ('HTTP ' . $status);
            $this->logger->error('ContentCreator OpenAI API error', ['status' => $status, 'body' => $data]);
            throw new ProviderException('OpenAI API-Fehler: ' . $message);
        }

        // Refusal analog zum ClaudeProvider als Fehler behandeln (statt leerem Text)
        $refusal = $this->extractRefusal($data);
        if ($refusal !== null) {
            throw new ProviderException('Die KI hat die Anfrage abgelehnt (refusal): ' . $refusal);
        }

        return new AiResult(
            text: trim($this->extractText($data)),
            inputTokens: (int) ($data['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($data['usage']['output_tokens'] ?? 0),
            stopReason: $data['status'] ?? null,
            model: $data['model'] ?? $model
        );
    }

    /**
     * Text aus der Responses-Antwort ziehen (Tool-Muster _extractText):
     * output_text-Convenience → output[]-Array → Chat-Completions-Fallback.
     *
     * @param array<string, mixed> $data
     */
    private function extractText(array $data): string
    {
        if (\is_string($data['output_text'] ?? null) && $data['output_text'] !== '') {
            return $data['output_text'];
        }

        $text = '';
        foreach ($data['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'output_text') {
                    $text .= $part['text'] ?? '';
                }
            }
        }
        if ($text !== '') {
            return $text;
        }

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractRefusal(array $data): ?string
    {
        foreach ($data['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') === 'refusal') {
                    return (string) ($part['refusal'] ?? 'ohne Begründung');
                }
            }
        }

        return null;
    }

    private function apiKey(): string
    {
        return trim((string) $this->systemConfig->get('ContentCreator.config.openaiApiKey'));
    }
}
