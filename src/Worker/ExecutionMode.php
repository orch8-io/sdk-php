<?php

declare(strict_types=1);

namespace Orch8\Worker;

/**
 * How handlers are executed.
 *
 * - `Fork`: every claimed task runs in its own forked child process (needs
 *   ext-pcntl); the parent keeps polling, heartbeating and acknowledging with
 *   non-blocking HTTP. True concurrency up to `concurrency`.
 * - `Inline`: handlers run in the worker process itself, one at a time
 *   (concurrency is forced to 1). Heartbeats are only sent while the handler
 *   calls TaskContext::sleep()/heartbeat()/checkpoint(). For environments
 *   without pcntl.
 * - `Auto`: `Fork` when pcntl_fork() is available, otherwise `Inline`.
 */
enum ExecutionMode
{
    case Auto;
    case Fork;
    case Inline;
}
