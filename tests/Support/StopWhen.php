<?php

declare(strict_types=1);

namespace Orch8\Tests\Support;

use Orch8\Worker\LoopHook;
use Orch8\Worker\Worker;

/** Loop hook that stops the worker once a condition holds (or a safety timeout elapses). */
final class StopWhen implements LoopHook
{
    private readonly float $deadline;
    public bool $timedOut = false;

    /** @param \Closure(): bool $condition */
    public function __construct(private readonly \Closure $condition, float $timeoutSeconds = 10.0)
    {
        $this->deadline = microtime(true) + $timeoutSeconds;
    }

    public function streams(): array
    {
        return [];
    }

    public function tick(Worker $worker): void
    {
        if ($worker->isStopping()) {
            return;
        }
        if (($this->condition)()) {
            $worker->stop();
        } elseif (microtime(true) > $this->deadline) {
            $this->timedOut = true;
            $worker->stop();
        }
    }

    public function close(): void
    {
    }
}
