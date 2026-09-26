<?php

declare(strict_types=1);

namespace Orch8\Model;

/**
 * Base of every response value object. Known fields are exposed as typed
 * readonly properties; the complete decoded response (including fields this
 * SDK version does not know about) stays available via {@see raw} /
 * {@see get()} / {@see toArray()}.
 */
abstract class Model implements \JsonSerializable
{
    /** @param array<string, mixed> $raw */
    protected function __construct(public readonly array $raw)
    {
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return array_key_exists($field, $this->raw) ? $this->raw[$field] : $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }

    public function jsonSerialize(): mixed
    {
        return $this->raw === [] ? new \stdClass() : $this->raw;
    }

    /** @param array<mixed> $raw */
    protected static function str(array $raw, string $key): ?string
    {
        $v = $raw[$key] ?? null;

        return is_scalar($v) ? (string) $v : null;
    }

    /** @param array<mixed> $raw */
    protected static function int(array $raw, string $key): ?int
    {
        $v = $raw[$key] ?? null;

        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    /** @return array<string, mixed> */
    protected static function assertObject(mixed $data, string $what): array
    {
        if (!is_array($data)) {
            throw new \Orch8\Exception\Orch8Exception(sprintf('unexpected %s response: expected a JSON object', $what));
        }

        return $data;
    }
}
