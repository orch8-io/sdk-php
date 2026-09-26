<?php

declare(strict_types=1);

namespace Orch8\Jobs;

use Orch8\Client;
use Orch8\Model\Job;
use Orch8\Model\JobRetry;

/**
 * Fluent job options. Sent once: explicitly via {@see send()} (returns the
 * created {@see Job}) or implicitly when the object is destroyed, Laravel
 * style. Errors from an implicit send surface as exceptions thrown from the
 * destructor, so prefer ->send() when you need the result or error handling.
 */
final class PendingDispatch
{
    private ?string $queue;
    private ?int $priority = null;
    private ?JobRetry $retry;
    private ?int $delayMs = null;
    private ?string $runAt = null;
    private ?string $idempotencyKey;
    /** @var array<string, mixed>|null */
    private ?array $metadata = null;
    private ?Client $client = null;
    private bool $sent = false;

    public function __construct(private readonly Orch8Job $job)
    {
        $this->queue = $job->queue();
        $this->retry = $job->retry();
        $this->idempotencyKey = $job->idempotencyKey();
    }

    public function onQueue(?string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /** Delay in seconds, or until a point in time. */
    public function delay(int|float|\DateInterval|\DateTimeInterface $delay): self
    {
        if ($delay instanceof \DateTimeInterface) {
            $this->runAt = \DateTimeImmutable::createFromInterface($delay)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $this->delayMs = null;
        } elseif ($delay instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $this->delayMs = max(0, (int) round(((float) $now->add($delay)->format('U.u') - (float) $now->format('U.u')) * 1000));
            $this->runAt = null;
        } else {
            $this->delayMs = max(0, (int) round($delay * 1000));
            $this->runAt = null;
        }

        return $this;
    }

    public function delayMs(int $ms): self
    {
        $this->delayMs = max(0, $ms);
        $this->runAt = null;

        return $this;
    }

    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function retry(int $maxAttempts, int $initialBackoffMs = 1000, ?int $maxBackoffMs = null): self
    {
        $this->retry = new JobRetry($maxAttempts, $initialBackoffMs, $maxBackoffMs);

        return $this;
    }

    public function idempotencyKey(?string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function via(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function send(): Job
    {
        $this->sent = true;

        return ($this->client ?? Dispatcher::client())->jobs->enqueue(
            handler: $this->job::handlerName(),
            payload: $this->job->toPayload(),
            queue: $this->queue,
            priority: $this->priority,
            retry: $this->retry,
            delayMs: $this->delayMs,
            runAt: $this->runAt,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
        );
    }

    /** Drop the dispatch without sending. */
    public function cancel(): void
    {
        $this->sent = true;
    }

    public function __destruct()
    {
        if (!$this->sent) {
            $this->send();
        }
    }
}
