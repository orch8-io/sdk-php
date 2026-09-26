<?php

declare(strict_types=1);

namespace Orch8\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Scripted PSR-18 client: responses are consumed in order; every request is recorded. */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var list<ResponseInterface|\Throwable|callable(RequestInterface): ResponseInterface> */
    private array $queue;

    /** @param list<ResponseInterface|\Throwable|callable(RequestInterface): ResponseInterface> $responses */
    public function __construct(array $responses = [])
    {
        $this->queue = $responses;
    }

    public function push(ResponseInterface|\Throwable|callable ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? new Response(500, [], '{"error":{"code":"internal","message":"no scripted response"}}');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return is_callable($next) ? $next($request) : $next;
    }

    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, ['content-type' => 'application/json'] + $headers, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    public static function error(int $status, string $code, string $message): Response
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message, 'request_id' => 'req-1']]);
    }

    public static function networkError(RequestInterface $request, string $message = 'connection refused'): NetworkExceptionInterface
    {
        return new \GuzzleHttp\Exception\ConnectException($message, $request);
    }

    public function last(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }

    /** @return mixed decoded JSON body of request $i (objects as arrays) */
    public function body(int $i): mixed
    {
        return json_decode((string) $this->requests[$i]->getBody(), true);
    }
}
