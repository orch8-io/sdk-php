<?php

declare(strict_types=1);

namespace Orch8\Model;

/**
 * A background job (`POST /jobs`, `GET /jobs/{id}`, ...).
 * Status is one of scheduled | running | completed | failed | cancelled | dead_lettered
 * (kept as a string so new server statuses never break decoding).
 */
final class Job extends Model
{
    public const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'dead_lettered'];

    protected function __construct(
        array $raw,
        public readonly string $id,
        public readonly ?string $instanceId,
        public readonly ?string $handler,
        public readonly ?string $status,
        public readonly ?string $createdAt,
        public readonly ?string $runAt,
        public readonly mixed $output,
        public readonly mixed $error,
    ) {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = self::assertObject($data, 'job');

        return new self(
            $raw,
            (string) self::str($raw, 'id'),
            self::str($raw, 'instance_id'),
            self::str($raw, 'handler'),
            self::str($raw, 'status'),
            self::str($raw, 'created_at'),
            self::str($raw, 'run_at'),
            $raw['output'] ?? null,
            $raw['error'] ?? null,
        );
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
