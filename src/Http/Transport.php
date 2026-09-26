<?php

declare(strict_types=1);

namespace Orch8\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Orch8\Exception\ApiException;
use Orch8\Exception\InvalidArgumentException;
use Orch8\Exception\TransportException;
use Orch8\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Synchronous JSON transport on top of any PSR-18 client.
 *
 * - Sends `x-api-key`, `x-tenant-id`, `accept` and (with a body) `content-type: application/json`.
 * - Safe methods (GET/HEAD) are retried on 408/425/429/5xx and transport
 *   errors with exponential backoff (`retryBaseDelayMs * 2^(attempt-1)`),
 *   up to `maxAttempts`. Unsafe methods are never replayed
 *   (sdk-contract fixtures/transport.json).
 * - A 4xx/5xx becomes a typed {@see ApiException}; a connection failure a
 *   {@see TransportException}. An empty success body maps to `null`.
 *
 * @internal Use {@see \Orch8\Client}.
 */
final class Transport
{
    private const SAFE_METHODS = ['GET', 'HEAD'];

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;
    private readonly string $baseUrl;
    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(
        string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly ?string $tenantId = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly int $maxAttempts = 3,
        private readonly int $retryBaseDelayMs = 250,
        float $timeoutSeconds = 30.0,
        /** @var array<string, string> */
        private readonly array $defaultHeaders = [],
        ?\Closure $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be >= 1');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $httpClient ?? new GuzzleClient([
            'http_errors' => false,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => min(10.0, $timeoutSeconds),
            'allow_redirects' => false,
        ]);
        $factory = null;
        if ($requestFactory === null || $streamFactory === null) {
            $factory = new HttpFactory();
        }
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, scalar|null>|null $query  null values are dropped
     * @return mixed decoded JSON (associative arrays) or null for an empty body
     */
    public function request(string $method, string $path, mixed $body = null, ?array $query = null, bool $hasBody = false): mixed
    {
        return Json::decode($this->requestRaw($method, $path, $body, $query, $hasBody || $body !== null));
    }

    /** Same as {@see request()} but returns the raw response body. */
    public function requestRaw(string $method, string $path, mixed $body = null, ?array $query = null, bool $hasBody = false): string
    {
        $method = strtoupper($method);
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException(sprintf('invalid_path: request path must be absolute and not protocol-relative, got "%s"', $path));
        }
        $url = $this->baseUrl . $path . self::queryString($query ?? []);
        $payload = ($hasBody || $body !== null) ? Json::encode($body) : null;
        $attempts = in_array($method, self::SAFE_METHODS, true) ? $this->maxAttempts : 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->send($method, $url, $payload);
            } catch (TransportException|ApiException $e) {
                $retryable = $e instanceof TransportException || $e->isRetryable();
                if (!$retryable || $attempt >= $attempts) {
                    throw $e;
                }
                ($this->sleeper)($this->retryBaseDelayMs * (2 ** ($attempt - 1)));
            }
        }
    }

    private function send(string $method, string $url, ?string $payload): string
    {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('accept', 'application/json');
        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeader('x-api-key', $this->apiKey);
        }
        if ($this->tenantId !== null && $this->tenantId !== '') {
            $request = $request->withHeader('x-tenant-id', $this->tenantId);
        }
        if ($payload !== null) {
            $request = $request
                ->withHeader('content-type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();
        if ($status >= 400) {
            $rid = $response->getHeaderLine('x-request-id');
            throw ApiException::fromResponse($status, $text, $rid === '' ? null : $rid);
        }

        return $status === 204 ? '' : $text;
    }

    /** Percent-encode one path segment (`a/b c` → `a%2Fb%20c`). */
    public static function segment(string $id): string
    {
        return rawurlencode($id);
    }

    /** @param array<string, mixed> $query */
    public static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value instanceof \BackedEnum) {
                $value = (string) $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::RFC3339);
            } elseif (!is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('query parameter "%s" must be scalar', $key));
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }
}
