<?php

declare(strict_types=1);

namespace Orch8\Model;

/** Result of `POST /instances/{id}/signals`: `{signal_id}`. */
final class SignalSent extends Model
{
    protected function __construct(array $raw, public readonly ?string $signalId)
    {
        parent::__construct($raw);
    }

    public static function fromArray(mixed $data): self
    {
        $raw = is_array($data) ? $data : [];

        return new self($raw, self::str($raw, 'signal_id'));
    }
}
