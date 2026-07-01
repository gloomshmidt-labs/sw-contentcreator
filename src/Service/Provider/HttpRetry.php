<?php declare(strict_types=1);

namespace ContentCreator\Service\Provider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Wiederholt LLM-Requests bei HTTP 429/5xx und Transportfehlern mit
 * exponentiellem Backoff; ein Retry-After-Header der API hat Vorrang.
 * Portiert nach dem Muster _fetchWithRetry aus dem Textoptimierung-Tool.
 */
final class HttpRetry
{
    public const MAX_ATTEMPTS = 3;
    private const MAX_DELAY_SECONDS = 15;

    /**
     * @param callable(): ResponseInterface $send
     *
     * @throws TransportExceptionInterface wenn auch der letzte Versuch am Transport scheitert
     *
     * @return array{0: int, 1: array<string, mixed>} [HTTP-Status, dekodierter JSON-Body]
     */
    public static function sendJson(callable $send, LoggerInterface $logger, string $provider): array
    {
        for ($attempt = 1; ; $attempt++) {
            $retryAfter = null;

            try {
                $response = $send();
                $status = $response->getStatusCode();

                if ($status !== 429 && $status < 500) {
                    return [$status, self::decode($response)];
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    return [$status, self::decode($response)];
                }

                $headers = $response->getHeaders(false);
                $retryAfter = isset($headers['retry-after'][0]) ? (int) $headers['retry-after'][0] : null;
                $logger->warning('ContentCreator: API-Fehler, wiederhole', [
                    'provider' => $provider,
                    'status' => $status,
                    'attempt' => $attempt,
                ]);
            } catch (TransportExceptionInterface $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                $logger->warning('ContentCreator: Transportfehler, wiederhole', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
            }

            $delay = ($retryAfter !== null && $retryAfter > 0) ? $retryAfter : 2 ** ($attempt - 1);
            sleep(min($delay, self::MAX_DELAY_SECONDS));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            // z.B. HTML-Fehlerseite eines Proxys — Aufrufer meldet dann "HTTP <status>"
            return [];
        }
    }
}
