<?php

declare(strict_types=1);

namespace Orch8\Push;

/** Outcome of {@see PushRequestHandler}: the HTTP status to answer and, when accepted, the envelope. */
final class PushResult
{
    public function __construct(
        public readonly int $status,
        public readonly ?PushEnvelope $envelope,
        public readonly ?string $error,
    ) {
    }

    public function accepted(): bool
    {
        return $this->envelope !== null;
    }
}
