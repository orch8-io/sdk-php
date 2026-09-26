<?php

declare(strict_types=1);

namespace Orch8;

/**
 * Signal types for `POST /instances/{id}/signals`. Built-ins are plain
 * strings; a custom signal is `{"custom": "<name>"}`.
 */
final class Signal
{
    public const PAUSE = 'pause';
    public const RESUME = 'resume';
    public const CANCEL = 'cancel';
    public const UPDATE_CONTEXT = 'update_context';

    /** @return array{custom: string} */
    public static function custom(string $name): array
    {
        return ['custom' => $name];
    }
}
