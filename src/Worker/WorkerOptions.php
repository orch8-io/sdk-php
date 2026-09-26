<?php

declare(strict_types=1);

namespace Orch8\Worker;

use Orch8\Exception\InvalidArgumentException;

final class WorkerOptions
{
    public readonly string $workerId;

    /**
     * @param string|null $workerId           unique per process; default `<hostname>-<pid>`
     * @param int         $concurrency        max tasks executing at once (across all handlers)
     * @param int         $pollIntervalMs     idle poll interval; the server's `poll_after_ms` still wins when larger,
     *                                         and poll errors back off exponentially from this base (cap 30 s)
     * @param int         $heartbeatIntervalMs heartbeat period; capped by the server's `heartbeat_interval_secs`
     * @param string|null $queue              named queue (polls `/workers/tasks/poll/queue`)
     * @param string|null $version            app/build version sent on polls (version pins, P4)
     * @param int         $shutdownTimeoutMs  graceful drain timeout after stop()/SIGTERM
     * @param bool        $polling            false = push-only: claims happen only through Worker::claimFromPush()
     * @param bool        $handleSignals      install SIGTERM/SIGINT handlers in run() (needs pcntl)
     * @param int         $maxAckAttempts     attempts for complete/fail on transport errors / retryable statuses
     */
    public function __construct(
        ?string $workerId = null,
        public readonly int $concurrency = 4,
        public readonly int $pollIntervalMs = 1000,
        public readonly int $heartbeatIntervalMs = 15000,
        public readonly ?string $queue = null,
        public readonly ?string $version = null,
        public readonly int $shutdownTimeoutMs = 30000,
        public readonly bool $polling = true,
        public readonly ExecutionMode $executionMode = ExecutionMode::Auto,
        public readonly bool $handleSignals = true,
        public readonly float $requestTimeoutSeconds = 30.0,
        public readonly int $maxAckAttempts = 5,
    ) {
        if ($concurrency < 1) {
            throw new InvalidArgumentException('concurrency must be >= 1');
        }
        if ($pollIntervalMs < 0 || $heartbeatIntervalMs < 100 || $shutdownTimeoutMs < 0 || $maxAckAttempts < 1) {
            throw new InvalidArgumentException('invalid worker timing options');
        }
        $this->workerId = ($workerId !== null && $workerId !== '')
            ? $workerId
            : ((gethostname() ?: 'php') . '-' . getmypid());
    }

    /**
     * Build from the conventional environment variables: ORCH8_WORKER_ID,
     * ORCH8_CONCURRENCY, ORCH8_POLL_INTERVAL_MS, ORCH8_HEARTBEAT_INTERVAL_MS,
     * ORCH8_QUEUE, ORCH8_WORKER_VERSION, ORCH8_SHUTDOWN_TIMEOUT_MS.
     */
    public static function fromEnv(): self
    {
        $env = static fn (string $k): ?string => ($v = getenv($k)) === false || $v === '' ? null : $v;

        return new self(
            workerId: $env('ORCH8_WORKER_ID'),
            concurrency: (int) ($env('ORCH8_CONCURRENCY') ?? 4),
            pollIntervalMs: (int) ($env('ORCH8_POLL_INTERVAL_MS') ?? 1000),
            heartbeatIntervalMs: (int) ($env('ORCH8_HEARTBEAT_INTERVAL_MS') ?? 15000),
            queue: $env('ORCH8_QUEUE'),
            version: $env('ORCH8_WORKER_VERSION'),
            shutdownTimeoutMs: (int) ($env('ORCH8_SHUTDOWN_TIMEOUT_MS') ?? 30000),
        );
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $current = [
            'workerId' => $this->workerId,
            'concurrency' => $this->concurrency,
            'pollIntervalMs' => $this->pollIntervalMs,
            'heartbeatIntervalMs' => $this->heartbeatIntervalMs,
            'queue' => $this->queue,
            'version' => $this->version,
            'shutdownTimeoutMs' => $this->shutdownTimeoutMs,
            'polling' => $this->polling,
            'executionMode' => $this->executionMode,
            'handleSignals' => $this->handleSignals,
            'requestTimeoutSeconds' => $this->requestTimeoutSeconds,
            'maxAckAttempts' => $this->maxAckAttempts,
        ];

        return new self(...array_merge($current, $changes));
    }
}
