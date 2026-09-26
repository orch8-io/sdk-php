<?php

declare(strict_types=1);

namespace Orch8\Resource;

use Orch8\Exception\Orch8Exception;

/** @internal Accepts a bare JSON array, or `{"items": [...]}` / `{"<name>": [...]}`. */
final class ListShape
{
    /**
     * @param list<string> $keys
     * @return list<mixed>
     */
    public static function items(mixed $data, array $keys): array
    {
        if ($data === null) {
            return [];
        }
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        if (is_array($data)) {
            foreach ([...$keys, 'items', 'data'] as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                    return $data[$key];
                }
            }
        }

        throw new Orch8Exception('unexpected list response shape');
    }
}
