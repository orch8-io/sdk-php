<?php

declare(strict_types=1);

namespace Orch8\Jobs;

/**
 * Laravel-style static dispatch helpers. Used by {@see Orch8Job}; can also be
 * mixed into any class that provides the Orch8Job static API.
 */
trait Dispatchable
{
    /**
     * `SendWelcomeEmail::dispatch($userId)->onQueue('emails')->delay(60);`
     * The job is enqueued when the returned PendingDispatch is destroyed
     * (end of statement) or explicitly with ->send().
     */
    public static function dispatch(mixed ...$arguments): PendingDispatch
    {
        return new PendingDispatch(new static(...$arguments));
    }

    /** Enqueue only when `$condition` is truthy. */
    public static function dispatchIf(bool $condition, mixed ...$arguments): ?PendingDispatch
    {
        return $condition ? new PendingDispatch(new static(...$arguments)) : null;
    }
}
