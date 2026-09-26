<?php

declare(strict_types=1);

namespace Orch8\Resource;

use Orch8\Exception\InvalidArgumentException;
use Orch8\Http\Transport;
use Orch8\Json;
use Orch8\Model\Job;
use Orch8\Model\JobRetry;

/**
 * Background jobs: a single durable handler invocation with retries, delay
 * and idempotency. Endpoints: `POST /jobs`, `GET /jobs/{id}`, `GET /jobs`,
 * `DELETE /jobs/{id}`. Workers see a job as a task whose `handler_name` is
 * the job handler and whose `params` is the job payload.
 */
final class Jobs
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * `POST /jobs`. Unset (null) options are omitted from the body; an empty
     * payload/metadata array is sent as `{}`.
     *
     * @param array<string, mixed>|object $payload
     * @param JobRetry|array{max_attempts: int, initial_backoff_ms: int, max_backoff_ms?: int|null}|null $retry
     * @param array<string, mixed>|object|null $metadata
     */
    public function enqueue(
        string $handler,
        array|object $payload = [],
        ?string $queue = null,
        ?int $priority = null,
        JobRetry|array|null $retry = null,
        ?int $delayMs = null,
        \DateTimeInterface|string|null $runAt = null,
        ?string $idempotencyKey = null,
        array|object|null $metadata = null,
    ): Job {
        if ($handler === '') {
            throw new InvalidArgumentException('handler must not be empty');
        }
        if ($delayMs !== null && $runAt !== null) {
            throw new InvalidArgumentException('pass either delayMs or runAt, not both');
        }
        if ($delayMs !== null && $delayMs < 0) {
            throw new InvalidArgumentException('delayMs must be >= 0');
        }
        $body = ['handler' => $handler, 'payload' => Json::object($payload)];
        if ($queue !== null) {
            $body['queue'] = $queue;
        }
        if ($priority !== null) {
            $body['priority'] = $priority;
        }
        if ($retry !== null) {
            $body['retry'] = $retry instanceof JobRetry ? $retry : JobRetry::fromArray($retry);
        }
        if ($delayMs !== null) {
            $body['delay_ms'] = $delayMs;
        }
        if ($runAt !== null) {
            $body['run_at'] = $runAt instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($runAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
                : $runAt;
        }
        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }
        if ($metadata !== null) {
            $body['metadata'] = Json::object($metadata);
        }

        return Job::fromArray($this->transport->request('POST', '/jobs', $body));
    }

    /**
     * Enqueue from a raw request array (`handler`, `payload`, `queue`, `priority`,
     * `retry`, `delay_ms`, `run_at`, `idempotency_key`, `metadata`).
     *
     * @param array<string, mixed> $request
     */
    public function enqueueRequest(array $request): Job
    {
        return $this->enqueue(
            handler: (string) ($request['handler'] ?? ''),
            payload: $request['payload'] ?? [],
            queue: $request['queue'] ?? null,
            priority: isset($request['priority']) ? (int) $request['priority'] : null,
            retry: $request['retry'] ?? null,
            delayMs: isset($request['delay_ms']) ? (int) $request['delay_ms'] : null,
            runAt: $request['run_at'] ?? null,
            idempotencyKey: $request['idempotency_key'] ?? null,
            metadata: $request['metadata'] ?? null,
        );
    }

    public function get(string $id): Job
    {
        return Job::fromArray($this->transport->request('GET', '/jobs/' . Transport::segment($id)));
    }

    /**
     * `GET /jobs?handler&status&limit&cursor...`. Accepts a JSON array as well
     * as `{"items": [...]}` / `{"jobs": [...]}`.
     *
     * @param array<string, scalar|null> $query
     * @return list<Job>
     */
    public function list(array $query = []): array
    {
        $data = $this->transport->request('GET', '/jobs', null, $query);

        return array_map(Job::fromArray(...), ListShape::items($data, ['jobs']));
    }

    /** `DELETE /jobs/{id}`. Returns the cancelled job, or null for a 204/empty body. */
    public function cancel(string $id): ?Job
    {
        $data = $this->transport->request('DELETE', '/jobs/' . Transport::segment($id));

        return is_array($data) && $data !== [] ? Job::fromArray($data) : null;
    }

    /**
     * Poll `GET /jobs/{id}` until the job reaches a terminal status.
     *
     * @throws \Orch8\Exception\Orch8Exception on timeout
     */
    public function waitFor(string $id, float $timeoutSeconds = 60.0, int $pollIntervalMs = 500, int $maxPollIntervalMs = 5000): Job
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $interval = $pollIntervalMs;
        while (true) {
            $job = $this->get($id);
            if ($job->isDone()) {
                return $job;
            }
            if (microtime(true) >= $deadline) {
                throw new \Orch8\Exception\Orch8Exception(sprintf('job %s still %s after %.1fs', $id, $job->status, $timeoutSeconds));
            }
            usleep($interval * 1000);
            $interval = min($interval * 2, $maxPollIntervalMs);
        }
    }
}
