<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * Throw from a worker handler to report a transient failure: the task is
 * failed with `retryable: true` and the engine re-dispatches it when the
 * step's retry policy has attempts left. Any other uncaught Throwable is
 * treated the same way (WORKER_PROTOCOL F4).
 */
class RetryableException extends Orch8Exception
{
}
