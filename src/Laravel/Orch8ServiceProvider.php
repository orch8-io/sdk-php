<?php

declare(strict_types=1);

namespace Orch8\Laravel;

use Illuminate\Support\ServiceProvider;
use Orch8\Client;
use Orch8\Jobs\Dispatcher;

/**
 * OPTIONAL Laravel integration — UNTESTED (Laravel is not a dependency of
 * this package and is not installed in its test suite). Only loaded when you
 * register it; the rest of the SDK never references Laravel.
 *
 * config/services.php:
 *   'orch8' => ['url' => env('ORCH8_BASE_URL'), 'key' => env('ORCH8_API_KEY'), 'tenant' => env('ORCH8_TENANT_ID')],
 */
final class Orch8ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static function ($app): Client {
            $cfg = $app['config']->get('services.orch8', []);

            return new Client(
                (string) ($cfg['url'] ?? 'http://localhost:8080/api/v1'),
                $cfg['key'] ?? null,
                $cfg['tenant'] ?? null,
            );
        });
    }

    public function boot(): void
    {
        Dispatcher::setClient($this->app->make(Client::class));
    }
}
