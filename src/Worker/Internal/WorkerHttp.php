<?php

declare(strict_types=1);

namespace Orch8\Worker\Internal;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Orch8\Exception\TransportException;
use Psr\Http\Message\ResponseInterface;

/**
 * Non-blocking JSON POSTs for the worker supervisor (Guzzle + curl_multi).
 * Promises resolve to `[status, body]`; only transport failures reject.
 *
 * @internal
 */
final class WorkerHttp
{
    private readonly GuzzleClient $async;
    private readonly GuzzleClient $sync;
    private readonly mixed $asyncHandler;
    private readonly string $baseUrl;

    /**
     * @param callable|null $handler Guzzle handler used for both async and sync
     *                               calls (tests inject a fake); default curl_multi + curl
     */
    public function __construct(
        string $baseUrl,
        private readonly ?string $apiKey,
        private readonly ?string $tenantId,
        ?callable $handler,
        float $timeoutSeconds,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->asyncHandler = $handler ?? new CurlMultiHandler(['select_timeout' => 0.005]);
        $syncHandler = $handler ?? new CurlHandler();
        $opts = [
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min(10.0, $timeoutSeconds),
        ];
        $this->async = new GuzzleClient(['handler' => HandlerStack::create($this->asyncHandler)] + $opts);
        $this->sync = new GuzzleClient(['handler' => HandlerStack::create($syncHandler)] + $opts);
    }

    /** @return PromiseInterface<array{int, string}> */
    public function post(string $path, string $json): PromiseInterface
    {
        return $this->async->requestAsync('POST', $this->baseUrl . $path, $this->options($json))->then(
            static fn (ResponseInterface $r): array => [$r->getStatusCode(), (string) $r->getBody()],
            static function (\Throwable $e): never {
                throw new TransportException($e->getMessage(), 0, $e);
            },
        );
    }

    /**
     * Blocking POST (inline execution mode only).
     *
     * @return array{int, string}
     */
    public function postSync(string $path, string $json): array
    {
        try {
            $r = $this->sync->request('POST', $this->baseUrl . $path, $this->options($json));
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        return [$r->getStatusCode(), (string) $r->getBody()];
    }

    /** Drive in-flight transfers; blocks at most ~5 ms when transfers are active. */
    public function tick(): void
    {
        if ($this->asyncHandler instanceof CurlMultiHandler) {
            $this->asyncHandler->tick();
        }
        Utils::queue()->run();
    }

    /** @return array<string, mixed> */
    private function options(string $json): array
    {
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers['x-api-key'] = $this->apiKey;
        }
        if ($this->tenantId !== null && $this->tenantId !== '') {
            $headers['x-tenant-id'] = $this->tenantId;
        }

        return ['headers' => $headers, 'body' => $json];
    }
}
