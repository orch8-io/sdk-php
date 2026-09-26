<?php

declare(strict_types=1);

namespace Orch8\Model;

/**
 * Result of `POST /instances`: `{id, deduplicated?}`. `deduplicated` is true
 * when an `idempotency_key` matched an existing instance (HTTP 200 instead of 201).
 */
final class InstanceCreated extends Model
{
    protected function __construct(array $raw, public readonly string $id, public readonly bool $deduplicated)
    {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = self::assertObject($data, 'instance create');

        return new self($raw, (string) self::str($raw, 'id'), ($raw['deduplicated'] ?? false) === true);
    }
}
