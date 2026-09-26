<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * Throw from a worker handler to report a permanent failure: the task is
 * failed with `retryable: false` (WORKER_PROTOCOL F3/F4).
 */
class NonRetryableException extends Orch8Exception
{
}
