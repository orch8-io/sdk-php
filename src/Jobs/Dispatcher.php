<?php

declare(strict_types=1);

namespace Orch8\Jobs;

use Orch8\Client;
use Orch8\Exception\Orch8Exception;

/**
 * Holds the client used by `SomeJob::dispatch(...)`. Set it once at boot:
 * `Orch8\Jobs\Dispatcher::setClient($client)` (the optional Laravel service
 * provider does this for you).
 */
final class Dispatcher
{
    private static ?Client $client = null;

    public static function setClient(?Client $client): void
    {
        self::$client = $client;
    }

    public static function client(): Client
    {
        if (self::$client === null) {
            throw new Orch8Exception('no Orch8 client configured for job dispatch; call Orch8\Jobs\Dispatcher::setClient()');
        }

        return self::$client;
    }
}
