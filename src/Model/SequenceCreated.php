<?php

declare(strict_types=1);

namespace Orch8\Model;

/** Result of `POST /sequences`: `{id, warnings?}`. */
final class SequenceCreated extends Model
{
    /** @param list<mixed> $warnings */
    protected function __construct(array $raw, public readonly string $id, public readonly array $warnings)
    {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = self::assertObject($data, 'sequence create');
        $warnings = $raw['warnings'] ?? [];

        return new self($raw, (string) self::str($raw, 'id'), is_array($warnings) ? array_values($warnings) : []);
    }
}
