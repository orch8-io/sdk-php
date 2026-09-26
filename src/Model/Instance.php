<?php

declare(strict_types=1);

namespace Orch8\Model;

/** A workflow instance as returned by `GET /instances[/{id}]`. */
final class Instance extends Model
{
    /** @param array<mixed> $context @param array<mixed> $metadata */
    protected function __construct(
        array $raw,
        public readonly string $id,
        public readonly ?string $sequenceId,
        public readonly ?string $tenantId,
        public readonly ?string $namespace,
        public readonly ?string $state,
        public readonly array $context,
        public readonly array $metadata,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = self::assertObject($data, 'instance');

        return new self(
            $raw,
            (string) self::str($raw, 'id'),
            self::str($raw, 'sequence_id'),
            self::str($raw, 'tenant_id'),
            self::str($raw, 'namespace'),
            self::str($raw, 'state'),
            is_array($raw['context'] ?? null) ? $raw['context'] : [],
            is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            self::str($raw, 'created_at'),
            self::str($raw, 'updated_at'),
        );
    }
}
