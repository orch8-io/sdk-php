<?php

declare(strict_types=1);

namespace Orch8\Model;

/** A sequence (workflow definition) as returned by `GET /sequences[/{id}]`. */
final class Sequence extends Model
{
    /** @param list<mixed> $blocks */
    protected function __construct(
        array $raw,
        public readonly string $id,
        public readonly ?string $tenantId,
        public readonly ?string $namespace,
        public readonly ?string $name,
        public readonly ?int $version,
        public readonly array $blocks,
        public readonly ?string $createdAt,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = self::assertObject($data, 'sequence');
        $blocks = $raw['blocks'] ?? [];

        return new self(
            $raw,
            (string) self::str($raw, 'id'),
            self::str($raw, 'tenant_id'),
            self::str($raw, 'namespace'),
            self::str($raw, 'name'),
            self::int($raw, 'version'),
            is_array($blocks) ? array_values($blocks) : [],
            self::str($raw, 'created_at'),
        );
    }
}
