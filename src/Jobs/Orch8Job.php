<?php

declare(strict_types=1);

namespace Orch8\Jobs;

/**
 * Base class for queue-style jobs backed by the Orch8 jobs API.
 *
 * ```php
 * final class SendWelcomeEmail extends Orch8Job
 * {
 *     public function __construct(public readonly int $userId) {}
 *
 *     public function handle(TaskContext $task): array
 *     {
 *         Mailer::welcome($this->userId);
 *         return ['sent' => true];
 *     }
 * }
 *
 * SendWelcomeEmail::dispatch(42)->onQueue('emails')->delay(60);   // producer
 * $worker->registerJob(SendWelcomeEmail::class);                   // consumer
 * ```
 *
 * Payload mapping: {@see toPayload()} defaults to the public properties;
 * {@see fromPayload()} rebuilds the job with named constructor arguments.
 * Override both for anything more elaborate. The handler name defaults to
 * the snake_cased short class name (`send_welcome_email`); override
 * {@see handlerName()} to pin it. A handler's return value becomes the job output.
 *
 * Subclasses must define `handle(TaskContext $task): mixed` (the argument may be omitted).
 */
abstract class Orch8Job
{
    use Dispatchable;

    public static function handlerName(): string
    {
        $short = substr(static::class, (int) strrpos('\\' . static::class, '\\'));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static
    {
        /** @phpstan-ignore-next-line new static with named args */
        return new static(...$payload);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $out = [];
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || !$prop->isInitialized($this)) {
                continue;
            }
            $out[$prop->getName()] = $prop->getValue($this);
        }

        return $out;
    }

    /** Default queue (null = the engine default). */
    public function queue(): ?string
    {
        return null;
    }

    /** Default retry policy, e.g. `new JobRetry(5, 1000, 60000)`. */
    public function retry(): ?\Orch8\Model\JobRetry
    {
        return null;
    }

    /** Default idempotency key (dedupe identical dispatches), or null. */
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
