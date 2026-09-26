<?php

declare(strict_types=1);

namespace Orch8\Worker\Internal;

use Orch8\Exception\LeaseLostException;
use Orch8\Exception\NonRetryableException;
use Orch8\Exception\RetryableException;
use Orch8\Json;

/**
 * Runs a handler and turns its return value / exception into an IPC-ready
 * result message (WORKER_PROTOCOL F4 classification).
 *
 * @internal
 */
final class Outcome
{
    /**
     * @param callable(\Orch8\Worker\TaskContext): mixed $handler
     * @return array<string, mixed> {t: "result", ok: true, output: <json string>} |
     *                              {t: "result", ok: false, retryable: bool, message: string} |
     *                              {t: "result", lost: true}
     */
    public static function run(callable $handler, \Orch8\Worker\TaskContext $ctx): array
    {
        try {
            $output = $handler($ctx);
        } catch (LeaseLostException) {
            return ['t' => 'result', 'lost' => true];
        } catch (NonRetryableException $e) {
            return self::failure($e, false);
        } catch (RetryableException $e) {
            return self::failure($e, true);
        } catch (\Throwable $e) {
            // Any other uncaught exception is transient by default (F4).
            return self::failure($e, true);
        }
        try {
            $json = Json::encode(Json::object($output));
        } catch (\JsonException $e) {
            return ['t' => 'result', 'ok' => false, 'retryable' => false, 'message' => 'handler output is not JSON-encodable: ' . $e->getMessage()];
        }

        return ['t' => 'result', 'ok' => true, 'output' => $json];
    }

    /** @return array<string, mixed> */
    private static function failure(\Throwable $e, bool $retryable): array
    {
        $message = $e->getMessage();
        if ($message === '') {
            $message = $e::class;
        }
        // Keep the message valid UTF-8 so the fail body always encodes.
        if (!mb_check_encoding($message, 'UTF-8')) {
            $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        }

        return ['t' => 'result', 'ok' => false, 'retryable' => $retryable, 'message' => $message];
    }
}
