<?php

declare(strict_types=1);

// Orch8 conformance adapter for the PHP SDK (sdk-contract/conformance/README.md).
// Usage: php conformance/adapter.php <worker|push|verify|client>
// Built only on the SDK's public API.

require __DIR__ . '/../vendor/autoload.php';

use Orch8\Client;
use Orch8\Exception\ApiException;
use Orch8\Exception\BadRequestException;
use Orch8\Exception\ConflictException;
use Orch8\Exception\ForbiddenException;
use Orch8\Exception\NonRetryableException;
use Orch8\Exception\NotFoundException;
use Orch8\Exception\PayloadTooLargeException;
use Orch8\Exception\RateLimitedException;
use Orch8\Exception\RetryableException;
use Orch8\Exception\ServerException;
use Orch8\Exception\TransportException;
use Orch8\Exception\UnauthorizedException;
use Orch8\Exception\UnprocessableEntityException;
use Orch8\Push\PushListener;
use Orch8\Push\PushRequestHandler;
use Orch8\Push\SignatureVerifier;
use Orch8\Worker\TaskContext;
use Orch8\Worker\Worker;
use Orch8\Worker\WorkerOptions;
use Psr\Log\AbstractLogger;

function env(string $name, ?string $default = null): ?string
{
    $v = getenv($name);

    return $v === false || $v === '' ? $default : $v;
}

final class StderrLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        fwrite(STDERR, sprintf("[%s] %s %s\n", $level, $message, $context === [] ? '' : json_encode($context)));
    }
}

function registerStandardHandlers(Worker $worker): void
{
    $worker
        ->handle('echo', static fn (TaskContext $t): array => ['echo' => $t->params])
        ->handle('fail_retryable', static function (TaskContext $t): never {
            throw new RetryableException((string) $t->param('message', 'boom'));
        })
        ->handle('fail_permanent', static function (): never {
            throw new NonRetryableException('fatal');
        })
        ->handle('crash', static function (): never {
            throw new \RuntimeException('crash');
        })
        ->handle('checkpoint', static function (TaskContext $t): array {
            $resume = $t->resumeCheckpoint;
            $start = is_array($resume) && isset($resume['step']) ? (int) $resume['step'] : 0;
            $steps = (int) $t->param('steps', 3);
            for ($i = $start + 1; $i <= $steps; $i++) {
                $t->checkpoint(['step' => $i]);
            }

            return ['resumed_from' => $start, 'final_step' => $steps];
        })
        ->handle('slow', static function (TaskContext $t): array {
            $ms = (int) $t->param('sleep_ms', 1000);
            $t->sleep($ms);

            return ['slept' => $ms];
        });
}

function workerOptions(array $overrides = []): WorkerOptions
{
    return new WorkerOptions(...array_merge([
        'workerId' => env('ORCH8_WORKER_ID'),
        'concurrency' => (int) env('ORCH8_CONCURRENCY', '4'),
        'pollIntervalMs' => (int) env('ORCH8_POLL_INTERVAL_MS', '100'),
        'queue' => env('ORCH8_QUEUE'),
        'version' => env('ORCH8_WORKER_VERSION'),
        'shutdownTimeoutMs' => (int) env('ORCH8_SHUTDOWN_TIMEOUT_MS', '10000'),
    ], $overrides));
}

function newWorker(WorkerOptions $options): Worker
{
    $worker = new Worker(
        (string) env('ORCH8_BASE_URL'),
        env('ORCH8_API_KEY'),
        env('ORCH8_TENANT_ID'),
        $options,
        env('ORCH8_ADAPTER_DEBUG') !== null ? new StderrLogger() : null,
    );
    registerStandardHandlers($worker);

    return $worker;
}

function runWorker(): int
{
    newWorker(workerOptions())->run();

    return 0;
}

function runPush(): int
{
    $worker = newWorker(workerOptions(['polling' => false]));
    $listener = new PushListener(
        new PushRequestHandler((string) env('ORCH8_PUSH_SECRET'), (int) env('ORCH8_PUSH_TOLERANCE_SECS', '300')),
        '0.0.0.0',
        (int) env('ORCH8_PUSH_PORT', '8081'),
    );
    $listener->listen();
    $worker->addLoopHook($listener);
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    $worker->run();

    return 0;
}

/** @param callable(mixed): array $fn */
function eachLine(callable $fn): int
{
    while (($line = fgets(STDIN)) !== false) {
        if (trim($line) === '') {
            continue;
        }
        fwrite(STDOUT, json_encode($fn(json_decode($line, false, 512, JSON_THROW_ON_ERROR)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        fflush(STDOUT);
    }

    return 0;
}

function runVerify(): int
{
    return eachLine(static fn (object $v): array => ['valid' => SignatureVerifier::verify(
        (string) $v->secret,
        $v->timestamp ?? null,
        $v->signature ?? null,
        (string) $v->body,
        isset($v->now) ? (int) $v->now : null,
        isset($v->tolerance_secs) ? (int) $v->tolerance_secs : 300,
    )]);
}

/** Convert a decoded-as-objects JSON value to arrays (objects → assoc arrays). */
function toArray(mixed $v): mixed
{
    return json_decode(json_encode($v, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

function errorKind(\Throwable $e): string
{
    return match (true) {
        $e instanceof BadRequestException => 'invalid_argument',
        $e instanceof UnauthorizedException => 'unauthorized',
        $e instanceof ForbiddenException => 'forbidden',
        $e instanceof NotFoundException => 'not_found',
        $e instanceof ConflictException => 'conflict',
        $e instanceof PayloadTooLargeException => 'payload_too_large',
        $e instanceof UnprocessableEntityException => 'unprocessable',
        $e instanceof RateLimitedException => 'rate_limited',
        $e instanceof ServerException => 'server',
        $e instanceof TransportException => 'transport',
        default => 'api',
    };
}

function clientOp(Client $c, string $op, object $a): mixed
{
    $query = isset($a->query) ? toArray($a->query) : [];

    return match ($op) {
        // Definitions and instance requests are passed through as decoded objects
        // so nested empty objects survive round-tripping.
        'sequences.create' => $c->sequences->create($a->body),
        'sequences.get' => $c->sequences->get((string) $a->id),
        'sequences.list' => $c->sequences->list($query),
        'instances.create' => $c->instances->create($a->body),
        'instances.get' => $c->instances->get((string) $a->id),
        'instances.list' => $c->instances->list($query),
        'instances.signal' => $c->instances->signal(
            (string) $a->id,
            is_string($a->signal_type) ? $a->signal_type : toArray($a->signal_type),
            property_exists($a, 'payload') ? $a->payload : null,
        ),
        'instances.cancel' => $c->instances->cancel((string) $a->id),
        'jobs.enqueue' => $c->jobs->enqueue(
            handler: (string) $a->body->handler,
            payload: $a->body->payload ?? [],
            queue: $a->body->queue ?? null,
            priority: $a->body->priority ?? null,
            retry: isset($a->body->retry) ? toArray($a->body->retry) : null,
            delayMs: $a->body->delay_ms ?? null,
            runAt: $a->body->run_at ?? null,
            idempotencyKey: $a->body->idempotency_key ?? null,
            metadata: $a->body->metadata ?? null,
        ),
        'jobs.get' => $c->jobs->get((string) $a->id),
        'jobs.list' => $c->jobs->list($query),
        'jobs.cancel' => $c->jobs->cancel((string) $a->id),
        default => throw new \InvalidArgumentException("unknown op {$op}"),
    };
}

function runClient(): int
{
    $client = new Client(
        (string) env('ORCH8_BASE_URL'),
        env('ORCH8_API_KEY'),
        env('ORCH8_TENANT_ID'),
        maxAttempts: (int) env('ORCH8_MAX_ATTEMPTS', '3'),
        retryBaseDelayMs: (int) env('ORCH8_RETRY_BASE_DELAY_MS', '20'),
    );

    return eachLine(static function (object $req) use ($client): array {
        $id = $req->id ?? null;
        try {
            $result = clientOp($client, (string) $req->op, $req->args ?? new \stdClass());

            return ['id' => $id, 'ok' => true, 'result' => $result];
        } catch (ApiException $e) {
            return ['id' => $id, 'ok' => false, 'error' => [
                'kind' => errorKind($e), 'status' => $e->status, 'code' => $e->errorCode, 'message' => $e->getMessage(),
            ]];
        } catch (\Throwable $e) {
            return ['id' => $id, 'ok' => false, 'error' => [
                'kind' => errorKind($e), 'status' => null, 'code' => null, 'message' => $e->getMessage(),
            ]];
        }
    });
}

$mode = $argv[1] ?? '';
$modes = ['worker' => 'runWorker', 'push' => 'runPush', 'verify' => 'runVerify', 'client' => 'runClient'];
if (!isset($modes[$mode])) {
    fwrite(STDERR, 'usage: adapter.php <' . implode('|', array_keys($modes)) . ">\n");
    exit(2);
}
exit($modes[$mode]());
