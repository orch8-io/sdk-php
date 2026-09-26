<?php

declare(strict_types=1);

namespace Orch8;

/**
 * JSON helpers. PHP cannot tell `[]` (empty list) from `{}` (empty object)
 * once decoded into arrays; the SDK therefore encodes an empty array in an
 * object position (payload, metadata, output, ...) as `{}` via {@see Json::object()}.
 * Pass a `\stdClass`/`\ArrayObject` yourself to force object encoding elsewhere.
 *
 * @internal
 */
final class Json
{
    public const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /** Decode to associative arrays; empty string → null. */
    public static function decode(string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Map an empty PHP array (or null) to `{}` so it serializes as a JSON object. */
    public static function object(mixed $value): mixed
    {
        if ($value === null || $value === []) {
            return new \stdClass();
        }

        return $value;
    }
}
