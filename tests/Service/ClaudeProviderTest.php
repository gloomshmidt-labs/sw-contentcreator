<?php declare(strict_types=1);

namespace ContentCreator\Tests\Service;

use ContentCreator\Service\Provider\AiRequest;
use ContentCreator\Service\Provider\ClaudeProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ClaudeProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $usage
     */
    private function makeProvider(?array &$capturedBody, array $usage = []): ClaudeProvider
    {
        $responseBody = json_encode([
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Antworttext']],
            'usage' => array_merge(['input_tokens' => 100, 'output_tokens' => 20], $usage),
        ], \JSON_THROW_ON_ERROR);

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody, $responseBody) {
            $capturedBody = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse($responseBody, ['http_code' => 200]);
        });

        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['ContentCreator.config.anthropicApiKey', null, 'sk-ant-test'],
            ['ContentCreator.config.anthropicModel', null, ''],
        ]);

        return new ClaudeProvider($client, $systemConfig, new NullLogger());
    }

    public function testSystemPromptTraegtCacheBreakpoint(): void
    {
        $captured = null;
        $provider = $this->makeProvider($captured);

        $provider->generate(new AiRequest(system: 'Stabiles Regelwerk', userPrompt: 'Fakten'));

        self::assertIsArray($captured['system']);
        self::assertCount(1, $captured['system']);
        self::assertSame('Stabiles Regelwerk', $captured['system'][0]['text']);
        self::assertSame(['type' => 'ephemeral'], $captured['system'][0]['cache_control']);
    }

    public function testSystemSuffixLandetHinterDemBreakpointOhneCacheControl(): void
    {
        $captured = null;
        $provider = $this->makeProvider($captured);

        $provider->generate(new AiRequest(
            system: 'Stabiles Regelwerk',
            userPrompt: 'Fakten',
            systemSuffix: 'FOKUS-KEYWORD: handpuppe kaufen',
        ));

        self::assertCount(2, $captured['system']);
        self::assertSame('FOKUS-KEYWORD: handpuppe kaufen', $captured['system'][1]['text']);
        self::assertArrayNotHasKey('cache_control', $captured['system'][1]);
        // Nur der stabile Block trägt den Breakpoint
        self::assertArrayHasKey('cache_control', $captured['system'][0]);
    }

    public function testLeererSuffixErzeugtKeinenZweitenBlock(): void
    {
        $captured = null;
        $provider = $this->makeProvider($captured);

        $provider->generate(new AiRequest(system: 'Regelwerk', userPrompt: 'Fakten', systemSuffix: '  '));

        self::assertCount(1, $captured['system']);
    }

    public function testCacheTokensWerdenAusDerUsageUebernommen(): void
    {
        $captured = null;
        $provider = $this->makeProvider($captured, [
            'cache_creation_input_tokens' => 1500,
            'cache_read_input_tokens' => 4200,
        ]);

        $result = $provider->generate(new AiRequest(system: 'Regelwerk', userPrompt: 'Fakten'));

        self::assertSame(100, $result->inputTokens);
        self::assertSame(20, $result->outputTokens);
        self::assertSame(1500, $result->cacheCreationTokens);
        self::assertSame(4200, $result->cacheReadTokens);
    }

    public function testFehlendeCacheFelderErgebenNull(): void
    {
        $captured = null;
        $provider = $this->makeProvider($captured);

        $result = $provider->generate(new AiRequest(system: 'Regelwerk', userPrompt: 'Fakten'));

        self::assertSame(0, $result->cacheCreationTokens);
        self::assertSame(0, $result->cacheReadTokens);
    }
}
