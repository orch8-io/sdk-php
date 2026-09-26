<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * A caller-side usage error detected before any request is sent
 * (e.g. a protocol-relative path, conflicting options).
 */
final class InvalidArgumentException extends Orch8Exception
{
}
