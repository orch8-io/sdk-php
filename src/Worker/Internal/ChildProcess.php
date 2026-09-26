<?php

declare(strict_types=1);

namespace Orch8\Worker\Internal;

use Orch8\Exception\LeaseLostException;
use Orch8\Exception\Orch8Exception;
use Orch8\Json;
use Orch8\Worker\TaskContext;

/**
 * Code that runs inside a forked task process. It talks to the supervisor
 * over a Unix socket with newline-delimited JSON:
 *
 *   child → parent  {"t":"cp","v":"<checkpoint json>"}         (blocks for the reply)
 *   parent → child  {"t":"cp_ok","seq":6} | {"t":"cp_err","lost":bool,"message":"..."}
 *   child → parent  {"t":"result", ...}                         (see Outcome)
 *
 * Cancellation arrives as SIGUSR1. SIGTERM/SIGINT are ignored: the
 * supervisor owns shutdown and kills children only after the drain timeout.
 * The process ends with SIGKILL on itself so no destructor or shutdown
 * function shared with the parent (curl handles, sockets, PHPUnit) runs.
 *
 * @internal
 */
final class ChildProcess
{
    private static bool $cancelled = false;

    /**
     * @param resource $sock
     * @param array<string, mixed> $task
     * @param callable(TaskContext): mixed $handler
     */
    public static function run($sock, array $task, callable $handler, int $parentPid): never
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        pcntl_signal(SIGINT, SIG_IGN);
        pcntl_signal(SIGUSR1, static function (): void {
            self::$cancelled = true;
        });
        stream_set_blocking($sock, true);

        $hasPosix = function_exists('posix_getppid');
        $cancelled = static fn (): bool => self::$cancelled || ($hasPosix && posix_getppid() !== $parentPid);
        $checkpointer = static function (string $json, int $expectedSeq) use ($sock): int {
            self::send($sock, ['t' => 'cp', 'v' => $json]);
            $line = fgets($sock);
            if ($line === false) {
                throw new LeaseLostException('worker supervisor went away');
            }
            $reply = json_decode($line, true);
            if (($reply['t'] ?? null) === 'cp_ok') {
                return (int) $reply['seq'];
            }
            if (($reply['lost'] ?? false) === true) {
                self::$cancelled = true;
                throw new LeaseLostException((string) ($reply['message'] ?? 'lease lost'));
            }
            throw new Orch8Exception((string) ($reply['message'] ?? 'checkpoint failed'));
        };
        $ctx = new TaskContext($task, $checkpointer, $cancelled, static function (): void {
        });

        $result = Outcome::run($handler, $ctx);
        try {
            self::send($sock, $result);
        } catch (\Throwable) {
            // Parent gone; nothing to report to.
        }
        @fclose($sock);
        self::terminate();
    }

    /** @param resource $sock @param array<string, mixed> $msg */
    private static function send($sock, array $msg): void
    {
        $line = Json::encode($msg) . "\n";
        $len = strlen($line);
        for ($written = 0; $written < $len; ) {
            $n = fwrite($sock, substr($line, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('ipc write failed');
            }
            $written += $n;
        }
    }

    private static function terminate(): never
    {
        if (defined('STDOUT')) {
            @fflush(STDOUT);
        }
        if (defined('STDERR')) {
            @fflush(STDERR);
        }
        if (function_exists('posix_kill')) {
            posix_kill(getmypid(), SIGKILL);
        }
        exit(0);
    }
}
