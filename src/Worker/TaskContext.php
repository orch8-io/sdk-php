<?php

declare(strict_types=1);

namespace Orch8\Worker;

use Orch8\Exception\LeaseLostException;
use Orch8\Exception\TaskCancelledException;

/**
 * What a handler receives for one claimed task.
 *
 * Resumable work: start from {@see $resumeCheckpoint} when it is not null and
 * persist progress with {@see checkpoint()} (the compare-and-swap
 * `checkpoint_seq` is tracked for you, WORKER_PROTOCOL C2/C3).
 *
 * Cancellation (lease loss, local timeout, forced shutdown) is cooperative:
 * poll {@see isCancelled()} / {@see throwIfCancelled()} or wait with
 * {@see sleep()}, which returns early when the task is cancelled.
 */
final class TaskContext
{
    private int $checkpointSeq;

    /**
     * @internal constructed by the worker
     *
     * @param array<string, mixed> $raw the task exactly as decoded from the poll response
     * @param \Closure(string, int): int $checkpointer (json, expectedSeq) → new seq
     * @param \Closure(): bool $cancelled
     * @param \Closure(): void $idle called periodically while sleeping (inline mode heartbeats)
     */
    public function __construct(
        public readonly array $raw,
        private readonly \Closure $checkpointer,
        private readonly \Closure $cancelled,
        private readonly \Closure $idle,
    ) {
        $this->checkpointSeq = is_int($raw['checkpoint_seq'] ?? null) ? $raw['checkpoint_seq'] : 0;
        $this->id = (string) ($raw['id'] ?? '');
        $this->instanceId = (string) ($raw['instance_id'] ?? '');
        $this->blockId = (string) ($raw['block_id'] ?? '');
        $this->handlerName = (string) ($raw['handler_name'] ?? '');
        $this->queueName = isset($raw['queue_name']) ? (string) $raw['queue_name'] : null;
        $this->params = $raw['params'] ?? null;
        $this->context = $raw['context'] ?? null;
        $this->attempt = (int) ($raw['attempt'] ?? 0);
        $this->timeoutMs = isset($raw['timeout_ms']) ? (int) $raw['timeout_ms'] : null;
        $this->claimEpoch = (int) ($raw['claim_epoch'] ?? 0);
        $this->resumeCheckpoint = $raw['resume_checkpoint'] ?? null;
    }

    public readonly string $id;
    public readonly string $instanceId;
    public readonly string $blockId;
    public readonly string $handlerName;
    public readonly ?string $queueName;
    /** Step parameters (templates already resolved); for jobs, the job payload. */
    public readonly mixed $params;
    /** Serialized execution context (`{data, config, ...}`). */
    public readonly mixed $context;
    /** 0 on the first dispatch, incremented by engine-driven retries. */
    public readonly int $attempt;
    public readonly ?int $timeoutMs;
    public readonly int $claimEpoch;
    /** Last durable checkpoint, or null when none was written. */
    public readonly mixed $resumeCheckpoint;

    /** Read one key of an object-shaped `params`. */
    public function param(string $key, mixed $default = null): mixed
    {
        return is_array($this->params) && array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    /** The current expected checkpoint sequence (from the poll, then from each checkpoint response). */
    public function checkpointSeq(): int
    {
        return $this->checkpointSeq;
    }

    /**
     * Durably store `$value` as this task's checkpoint (≤ 256 KiB of JSON).
     * An empty array is stored as `{}`.
     *
     * @throws LeaseLostException when the engine rejects it with 404/409 (stop working)
     * @throws \Orch8\Exception\Orch8Exception on other failures
     * @return int the new checkpoint sequence
     */
    public function checkpoint(mixed $value): int
    {
        $json = \Orch8\Json::encode(\Orch8\Json::object($value));
        $this->checkpointSeq = ($this->checkpointer)($json, $this->checkpointSeq);

        return $this->checkpointSeq;
    }

    public function isCancelled(): bool
    {
        return ($this->cancelled)();
    }

    /** @throws TaskCancelledException */
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new TaskCancelledException(sprintf('task %s was cancelled', $this->id));
        }
    }

    /**
     * Sleep up to `$ms` milliseconds, waking early on cancellation.
     *
     * @return bool true when the full duration elapsed, false when cancelled
     */
    public function sleep(int $ms): bool
    {
        $deadline = hrtime(true) + $ms * 1_000_000;
        while (true) {
            if ($this->isCancelled()) {
                return false;
            }
            ($this->idle)();
            $left = $deadline - hrtime(true);
            if ($left <= 0) {
                return true;
            }
            usleep((int) min($left / 1000, 50_000));
        }
    }

    /** Give the worker a chance to heartbeat (only needed in inline mode during long CPU work). */
    public function heartbeat(): void
    {
        ($this->idle)();
    }
}
