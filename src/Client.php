<?php

declare(strict_types=1);

namespace Orch8;

use Orch8\Http\Transport;
use Orch8\Resource\Instances;
use Orch8\Resource\Jobs;
use Orch8\Resource\Sequences;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Typed Orch8 REST client.
 *
 * ```php
 * $orch8 = new Orch8\Client('http://localhost:8080/api/v1', apiKey: 'secret', tenantId: 'acme');
 * $job = $orch8->jobs->enqueue('send_email', ['to' => 'a@example.com']);
 * ```
 *
 * `$baseUrl` is the versioned API base (`<origin>/api/v1`). Any PSR-18
 * client + PSR-17 factories may be injected; Guzzle 7 is used otherwise.
 */
final class Client
{
    public readonly Sequences $sequences;
    public readonly Instances $instances;
    public readonly Jobs $jobs;
    private readonly Transport $transport;

    /**
     * @param array<string, string> $headers extra headers sent on every request
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?string $tenantId = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        int $maxAttempts = 3,
        int $retryBaseDelayMs = 250,
        float $timeoutSeconds = 30.0,
        array $headers = [],
    ) {
        $this->transport = new Transport(
            $baseUrl,
            $apiKey,
            $tenantId,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $maxAttempts,
            $retryBaseDelayMs,
            $timeoutSeconds,
            $headers,
        );
        $this->sequences = new Sequences($this->transport);
        $this->instances = new Instances($this->transport);
        $this->jobs = new Jobs($this->transport);
    }

    /**
     * Build from `ORCH8_BASE_URL`, `ORCH8_API_KEY`, `ORCH8_TENANT_ID`,
     * `ORCH8_MAX_ATTEMPTS`, `ORCH8_RETRY_BASE_DELAY_MS`.
     */
    public static function fromEnv(?ClientInterface $httpClient = null): self
    {
        $env = static fn (string $k): ?string => ($v = getenv($k)) === false || $v === '' ? null : $v;

        return new self(
            $env('ORCH8_BASE_URL') ?? 'http://localhost:8080/api/v1',
            $env('ORCH8_API_KEY'),
            $env('ORCH8_TENANT_ID'),
            $httpClient,
            maxAttempts: (int) ($env('ORCH8_MAX_ATTEMPTS') ?? 3),
            retryBaseDelayMs: (int) ($env('ORCH8_RETRY_BASE_DELAY_MS') ?? 250),
        );
    }

    /**
     * Escape hatch for endpoints without a typed wrapper. `$path` is relative
     * to the base URL (e.g. `/workers`). Safe methods are retried.
     *
     * @param array<string, scalar|null>|null $query
     */
    public function request(string $method, string $path, mixed $body = null, ?array $query = null): mixed
    {
        return $this->transport->request($method, $path, $body, $query);
    }
}
