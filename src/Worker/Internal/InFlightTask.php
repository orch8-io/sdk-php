<?php

declare(strict_types=1);

namespace Orch8\Worker\Internal;

/**
 * Supervisor-side state of one claimed task.
 *
 * @internal
 */
final class InFlightTask
{
    public readonly string $id;
    public readonly int $claimEpoch;
    public int $seq;
    public bool $lost = false;
    public bool $started = false;
    public ?int $pid = null;
    /** @var resource|null parent end of the IPC socket */
    public $sock = null;
    public string $buffer = '';
    public bool $processDone = false;
    public float $lastBeatAt;
    public bool $heartbeatInFlight = false;
    public bool $cancelSent = false;
    public ?float $deadline = null;
    public bool $timedOut = false;
    public ?float $killAt = null;
    /** Pending checkpoint request from the child: raw JSON value. */
    public ?string $checkpointJson = null;
    public bool $checkpointInFlight = false;
    /** @var array{kind: string, body: string}|null */
    public ?array $ack = null;
    public int $ackAttempts = 0;
    public float $ackNextAt = 0.0;
    public bool $ackInFlight = false;
    public bool $ackDone = false;

    /** @param array<string, mixed> $task */
    public function __construct(public readonly array $task, float $now)
    {
        $this->id = (string) ($task['id'] ?? '');
        $this->claimEpoch = (int) ($task['claim_epoch'] ?? 0);
        $this->seq = is_int($task['checkpoint_seq'] ?? null) ? $task['checkpoint_seq'] : 0;
        $this->lastBeatAt = $now;
        $timeout = $task['timeout_ms'] ?? null;
        $created = $task['created_at'] ?? null;
        if (is_int($timeout) && $timeout > 0 && is_string($created)) {
            try {
                $createdAt = (float) (new \DateTimeImmutable($created))->format('U.u');
                $this->deadline = $createdAt + $timeout / 1000;
            } catch (\Exception) {
                $this->deadline = $now + $timeout / 1000;
            }
        }
    }

    public function handlerName(): string
    {
        return (string) ($this->task['handler_name'] ?? '');
    }

    /** Settled: the process is gone and nothing remains to acknowledge. */
    public function isFinished(): bool
    {
        return $this->processDone && ($this->lost || $this->ackDone || $this->ack === null) && !$this->ackInFlight && !$this->heartbeatInFlight && !$this->checkpointInFlight;
    }
}
