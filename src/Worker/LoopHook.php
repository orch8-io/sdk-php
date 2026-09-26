<?php

declare(strict_types=1);

namespace Orch8\Worker;

/**
 * Something that runs inside the worker's event loop (e.g. the embedded push
 * listener). The loop waits on `streams()` together with task IPC sockets and
 * calls `tick()` once per iteration. `tick()` must never block.
 */
interface LoopHook
{
    /** @return list<resource> streams to wait on for readability */
    public function streams(): array;

    public function tick(Worker $worker): void;

    /** Called once when the worker loop exits. */
    public function close(): void;
}
