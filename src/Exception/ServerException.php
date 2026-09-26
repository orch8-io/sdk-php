<?php

declare(strict_types=1);

namespace Orch8\Exception;

/** HTTP 5xx (internal, bad_gateway, unavailable, ...). */
class ServerException extends ApiException
{
}
