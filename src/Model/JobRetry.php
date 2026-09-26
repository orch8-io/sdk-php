<?php

declare(strict_types=1);

namespace Orch8\Model;

/** Retry policy for a job: `{max_attempts, initial_backoff_ms, max_backoff_ms?}`. */
final class JobRetry implements \JsonSerializable
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $initialBackoffMs,
        public readonly ?int $maxBackoffMs = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \Orch8\Exception\InvalidArgumentException('retry.max_attempts must be >= 1');
        }
    }

    /** @param array{max_attempts: int, initial_backoff_ms: int, max_backoff_ms?: int|null} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['max_attempts'] ?? 1),
            (int) ($data['initial_backoff_ms'] ?? 0),
            isset($data['max_backoff_ms']) ? (int) $data['max_backoff_ms'] : null,
        );
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        $out = ['max_attempts' => $this->maxAttempts, 'initial_backoff_ms' => $this->initialBackoffMs];
        if ($this->maxBackoffMs !== null) {
            $out['max_backoff_ms'] = $this->maxBackoffMs;
        }

        return $out;
    }
}
