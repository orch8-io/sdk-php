<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * Thrown by TaskContext::throwIfCancelled() once the worker has cancelled the
 * task (lease loss, local timeout, forced shutdown).
 */
final class TaskCancelledException extends Orch8Exception
{
}
