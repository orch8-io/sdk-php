<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * The worker lost ownership of the task (404/409 on a heartbeat or
 * checkpoint, WORKER_PROTOCOL §5). Thrown from TaskContext::checkpoint();
 * the worker never acknowledges a task after this.
 */
final class LeaseLostException extends Orch8Exception
{
}
