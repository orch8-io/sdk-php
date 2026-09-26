<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * The request never produced an HTTP response (connection refused, DNS
 * failure, timeout, TLS error, ...). Safe requests are retried on this error.
 */
final class TransportException extends Orch8Exception
{
}
