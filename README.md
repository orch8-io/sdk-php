# Orch8 PHP SDK

PHP client and worker for the [Orch8](https://orch8.io) durable workflow engine.

- **`Orch8\Client`**: a typed REST client for sequences, instances, signals and background jobs. It works with any PSR-18 client and uses Guzzle 7 when you don't pass one.
- **`Orch8\Worker\Worker`**: a long-poll worker. It implements the Orch8 worker wire protocol (contract version 1): claim epochs, heartbeats, checkpoint CAS, lease loss, idempotent acks and graceful shutdown.
- **`Orch8\Push\*`**: a push-dispatch signature verifier, a PSR-7 helper and an embeddable push listener.
- **`Orch8\Jobs\Orch8Job`**: Laravel-style dispatch, e.g. `SendWelcomeEmail::dispatch($id)->onQueue('emails')->delay(60)`. Laravel is not required.

Requirements: PHP ≥ 8.2 and ext-json. The worker needs `ext-pcntl` (and `ext-posix`) to run tasks concurrently and to shut down cleanly on SIGTERM/SIGINT. Without them it falls back to inline mode (see [Worker design](#worker-design)).

## Install

Not on Packagist yet. Today, add this repository as a VCS repository and require the tag:

```bash
composer config repositories.orch8 vcs https://github.com/orch8-io/sdk-php
composer require orch8/sdk:^0.1
```

which is equivalent to this `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/orch8-io/sdk-php" }
    ],
    "require": {
        "orch8/sdk": "^0.1"
    }
}
```

A source zip is also attached to the
[v0.1.0 GitHub release](https://github.com/orch8-io/sdk-php/releases/tag/v0.1.0).

Once the package is on Packagist:

```bash
composer require orch8/sdk
```

Packagist needs no CI secret: after the package is submitted once at
<https://packagist.org/packages/submit> (with the GitHub integration/webhook enabled), every pushed
`vX.Y.Z` tag becomes a new version automatically. The tag-triggered release workflow only builds the
GitHub Release.

`guzzlehttp/guzzle` is a hard dependency. The client uses it only when you don't inject a PSR-18 client. The worker always uses it, because its event loop depends on Guzzle's `curl_multi` async handler.

## Client

```php
use Orch8\Client;
use Orch8\Signal;

$orch8 = new Client('http://localhost:8080/api/v1', apiKey: getenv('ORCH8_API_KEY'), tenantId: 'acme');

// Sequences
$created = $orch8->sequences->create([
    'tenant_id' => 'acme', 'namespace' => 'default', 'name' => 'onboarding',
    'blocks' => [['type' => 'step', 'id' => 's1', 'handler' => 'send_email', 'params' => new stdClass()]],
]);
$seq  = $orch8->sequences->get($created->id);
$all  = $orch8->sequences->list(['namespace' => 'default', 'limit' => 50]);

// Instances
$inst = $orch8->instances->start($created->id, 'acme', 'default', ['data' => ['user_id' => 42]], idempotencyKey: 'signup-42');
$inst->deduplicated;                          // true when the idempotency key matched
$orch8->instances->signal($inst->id, Signal::custom('approve'), ['by' => 'alice']);
$orch8->instances->cancel($inst->id);         // = signal "cancel"
$running = $orch8->instances->list(['state' => 'running', 'limit' => 20]);

// Background jobs
$job = $orch8->jobs->enqueue('send_email', ['to' => 'a@example.com'],
    queue: 'emails', retry: ['max_attempts' => 5, 'initial_backoff_ms' => 1000], delayMs: 30_000,
    idempotencyKey: 'welcome-a');
$job = $orch8->jobs->waitFor($job->id, timeoutSeconds: 120);
$orch8->jobs->cancel($job->id);
```

Details:

- **Base URL.** Pass the versioned API base, `<origin>/api/v1`. Every request sends `x-api-key` and `x-tenant-id`, and JSON bodies are sent as `application/json`.
- **Path ids.** An id in a path is encoded as a single segment with `rawurlencode` (`a/b c` → `a%2Fb%20c`).
- **Retries.** `GET`/`HEAD` requests are retried on 408/425/429/5xx and on transport errors, with exponential backoff (`maxAttempts` defaults to 3, `retryBaseDelayMs` to 250). `POST`/`DELETE` requests are never replayed. For deduplication, use idempotency keys.
- **Unset options.** Optional fields you don't set are omitted from the request body, never sent as `null`. PHP can't tell `[]` from `{}`, so an empty `payload`, `metadata` or `context` array is sent as `{}`. Anywhere else, pass `new stdClass()` to get an empty object.
- **Responses.** Responses are readonly value objects (`Job`, `Instance`, `Sequence`, ...). Unknown fields stay available through `->raw`, `->get('field')` and `->toArray()`.
- **Custom HTTP stack.** Pass `httpClient`, `requestFactory` and `streamFactory` (PSR-18/PSR-17) to use your own stack.

### Errors

Every exception extends `Orch8\Exception\Orch8Exception`.

| Exception | When |
|---|---|
| `ApiException` (`->status`, `->errorCode`, `->getMessage()`, `->requestId`, `->details`) | The engine returned a 4xx/5xx response. The fields come from the error envelope `{"error":{"code","message","request_id"}}`. |
| `BadRequestException` 400, `UnauthorizedException` 401, `ForbiddenException` 403, `NotFoundException` 404, `ConflictException` 409, `PayloadTooLargeException` 413, `UnprocessableEntityException` 422, `RateLimitedException` 429, `ServerException` 5xx | Subclasses of `ApiException`, one per status. |
| `TransportException` | The request got no HTTP response (connection refused, timeout, ...). |
| `InvalidArgumentException` | The call was invalid before anything was sent. |

## Worker

```php
use Orch8\Exception\NonRetryableException;
use Orch8\Worker\{Worker, WorkerOptions, TaskContext};

$worker = new Worker('http://localhost:8080/api/v1', getenv('ORCH8_API_KEY'), 'acme', new WorkerOptions(
    concurrency: 8,              // tasks executing at once, across all handlers
    pollIntervalMs: 1000,        // the server's poll_after_ms wins when larger
    heartbeatIntervalMs: 15000,  // capped by the server's heartbeat_interval_secs
    queue: null,                 // set to poll /workers/tasks/poll/queue
    version: '1.4.2',            // sent on polls (version pins)
    shutdownTimeoutMs: 30000,    // drain timeout after SIGTERM/SIGINT
    // workerId: defaults to "<hostname>-<pid>"
));

$worker->handle('send_email', function (TaskContext $task): array {
    if (!isset($task->params['to'])) {
        throw new NonRetryableException('missing "to"');   // → fail, retryable: false
    }
    return ['message_id' => Mailer::send($task->params)];  // → complete, output merged into context.data
});

// Resumable work: start from the last checkpoint. checkpoint() tracks checkpoint_seq for you.
$worker->handle('import', function (TaskContext $task): array {
    $page = $task->resumeCheckpoint['page'] ?? 0;
    while (($rows = fetchPage(++$page)) !== []) {
        $task->throwIfCancelled();          // set on lease loss / local timeout
        importRows($rows);
        $task->checkpoint(['page' => $page]);
    }
    return ['pages' => $page - 1];
});

$worker->run();   // blocks; SIGTERM/SIGINT → stop polling, drain in-flight tasks, return
```

The handler receives a `TaskContext` with these properties: `id`, `instanceId`, `blockId`, `handlerName`, `queueName`, `params`, `context`, `attempt`, `timeoutMs`, `claimEpoch`, `resumeCheckpoint` and `raw`. It also has these methods: `checkpointSeq()`, `checkpoint($value)`, `isCancelled()`, `throwIfCancelled()`, `sleep($ms)` (returns early if the task is cancelled) and `param($key, $default)`.

How results are reported:

- **Return value.** What the handler returns becomes the task `output`. Returning `null` or `[]` sends `{}`.
- **`NonRetryableException`.** The task fails with `retryable: false`.
- **`RetryableException` or any other `Throwable`.** The task fails with `retryable: true`. The engine owns retry scheduling.
- **Unknown handler.** A task for a handler that isn't registered fails with `retryable: false`.
- **Local timeout.** When `timeout_ms` passes (measured from `created_at`), the handler is cancelled and the task fails with `retryable: true`.

Protocol behaviour:

- **Concurrency slots.** Slots are reserved before each poll is sent, so `limit` never exceeds free capacity. A worker with no free slots doesn't poll.
- **Poll pacing.** After an empty poll, the worker waits at least `poll_after_ms`. Poll errors back off exponentially from the poll interval, capped at 30 s.
- **Heartbeats.** Every in-flight task is heartbeated at `min(heartbeatIntervalMs, heartbeat_interval_secs, lease_secs/2)`.
- **Lease loss.** A 404/409 on a heartbeat or checkpoint marks the lease as lost. The worker stops heartbeating, cancels the handler (sending SIGUSR1 to the child, which sets `isCancelled()`), and never sends `complete`/`fail` for that task.
- **Acknowledgements.** `complete`/`fail` are retried with the identical body on transport errors and 408/425/429/5xx, up to 5 attempts. A 404/409 on an ack is never retried and never turned into a failure.

### Worker design

PHP has no threads. This SDK runs **one supervisor process** with a single-threaded event loop and **forks one child process per claimed task**.

- **Supervisor.** It does all protocol I/O with non-blocking HTTP (Guzzle's `CurlMultiHandler` / `curl_multi`): per-handler polls, heartbeats, checkpoint writes and acks. It waits on `curl_multi` and the task sockets in roughly 5 ms slices, and it reaps children with `pcntl_waitpid(WNOHANG)`.
- **Children.** Each child runs the handler synchronously, so the handler can block on `sleep`, a PDO query or a CPU loop without delaying heartbeats or other tasks. At most `concurrency` children run at once.
- **IPC.** Child and supervisor talk over a Unix `stream_socket_pair` using newline-delimited JSON. A child sends checkpoint requests (and blocks until the supervisor replies with the new `checkpoint_seq` or a lease-loss error) and then its result. The supervisor owns `claim_epoch`, the CAS sequence, and whether and how to acknowledge the task.
- **Child exit.** A child ignores SIGTERM/SIGINT; the supervisor decides when to shut down. When it finishes, it ends itself with `SIGKILL` so that no destructor shared with the parent runs (open curl handles, sockets, framework shutdown hooks). If a child dies without reporting (a fatal error, an OOM kill), the task fails with `retryable: true`.
- **Output fidelity.** Handler output and checkpoints are JSON-encoded in the child and embedded verbatim in the request body, so the retried `complete` body is byte-identical.
- **Shutdown.** SIGTERM or SIGINT (via `pcntl_async_signals`), or `$worker->stop()`, stops polling immediately. In-flight tasks keep heartbeating and are acknowledged. After `shutdownTimeoutMs`, the remaining children are killed and their tasks are left unacknowledged; the engine reclaims them once the lease expires. `run()` then returns.

What forking means for your handlers: each task runs in a copy of the worker process. Open database connections, sockets and similar resources are shared with the parent after the fork, so **open connections inside the handler**, or reconnect lazily. Static and global state changed inside a handler is not visible to later tasks.

**Inline mode** (`executionMode: ExecutionMode::Inline`) is the default fallback when `pcntl` is not available. It runs handlers in the worker process, one at a time, with concurrency forced to 1. While a handler runs, heartbeats are sent only when the handler calls `$task->sleep()`, `$task->heartbeat()` or `$task->checkpoint()`. Graceful signal handling needs `pcntl` as well.

## Push dispatch

With push dispatch, the engine POSTs a signed wake-up to your endpoint. The receiver verifies it and then claims the task through `POST /workers/tasks/poll/queue`; a push never carries a claim.

```php
use Orch8\Push\SignatureVerifier;

$ok = SignatureVerifier::verify($secret, $timestampHeader, $signatureHeader, $rawBody);   // ±300 s, hash_equals
$ok = SignatureVerifier::verifyRequest($psr7Request, $secret);
```

**Embedded listener.** For long-running workers, the listener runs inside the worker loop:

```php
use Orch8\Push\{PushListener, PushRequestHandler};

$worker = new Worker($url, $key, $tenant, new WorkerOptions(polling: false)); // push-only
$worker->handle('render', $render);
$listener = new PushListener(new PushRequestHandler($secret), '0.0.0.0', 8081);
$listener->listen();
$worker->addLoopHook($listener)->run();
```

The listener's responses:

- **401.** The signature is invalid or missing, or the timestamp is stale. No claim is made.
- **400.** The body is malformed.
- **202.** The push is valid. The worker then claims a task for the envelope's `queue_name`/`handler_name` and runs it like a polled task.

**PHP-FPM or a framework route.** Answer 202 quickly and run the task after the response:

```php
$result = (new PushRequestHandler($secret))->handle($request);     // PSR-7
http_response_code($result->status);
if ($result->envelope !== null) {
    fastcgi_finish_request();
    $worker->claimFromPush($result->envelope);
    $worker->runUntilIdle(timeoutSeconds: 300);                     // claim, execute, heartbeat, ack
}
```

## Jobs (Laravel-style)

```php
use Orch8\Jobs\{Orch8Job, Dispatcher};
use Orch8\Worker\TaskContext;

final class SendWelcomeEmail extends Orch8Job
{
    public function __construct(public readonly int $userId) {}

    public function handle(TaskContext $task): array
    {
        Mailer::welcome($this->userId);
        return ['sent' => true];
    }
}

// producer
Dispatcher::setClient($orch8);
SendWelcomeEmail::dispatch(42)->onQueue('emails')->delay(60);       // enqueued at end of statement
$job = SendWelcomeEmail::dispatch(43)->retry(5, 1000, 60_000)->send(); // explicit; returns Orch8\Model\Job

// consumer
$worker->registerJob(SendWelcomeEmail::class);   // handler "send_welcome_email", params → new SendWelcomeEmail(...$params)
```

By default the payload is the job's public properties, and the job is rebuilt from them with named constructor arguments. Override `toPayload()`/`fromPayload()` to change that, and `handlerName()`, `queue()`, `retry()` or `idempotencyKey()` to set per-class defaults. A job becomes a worker task whose `handler_name` is the job's handler name and whose `params` is the job payload.

`Orch8\Laravel\Orch8ServiceProvider` binds `Orch8\Client` from `config('services.orch8')` and wires up `Dispatcher`. **It is untested**: Laravel isn't a dependency and isn't installed in this package's test suite.

## Development

PHP and Composer run in a small Docker image (`docker/Dockerfile`: `php:8.3-cli`, `pcntl`, Composer 2):

```bash
docker build -t orch8-sdk-php-dev docker/
docker run --rm -v "$PWD:/app" -w /app orch8-sdk-php-dev composer install
docker run --rm -v "$PWD:/app" -w /app orch8-sdk-php-dev vendor/bin/phpunit
```

The unit tests use:

- a fake PSR-18 client, for the client;
- the shared signature vectors, for the verifier;
- an in-process fake engine plugged in as the Guzzle handler, for the worker, in both fork and inline mode.

### Conformance kit (local only)

The conformance kit is [orch8-io/sdk-contract](https://github.com/orch8-io/sdk-contract); clone it next to this repo to run it. CI runs the unit tests.

`conformance/adapter.php` implements the kit's adapter contract on the public API. `bin/conformance` runs it in Docker:

- it passes the `ORCH8_*` variables through;
- it adds `host.docker.internal`;
- it publishes `ORCH8_PUSH_PORT` when that is set;
- it `exec`s `docker run` without `--init`, so the docker CLI proxies the kit's SIGTERM to PHP (PID 1).

```bash
cd ../sdk-contract
node conformance/run.mjs --bind-host 0.0.0.0 --advertise-host host.docker.internal \
  --adapter "$PWD/../sdk-php/bin/conformance"
```

## License

MIT
