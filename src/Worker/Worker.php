<?php

declare(strict_types=1);

namespace Orch8\Worker;

use Orch8\Exception\ApiException;
use Orch8\Exception\InvalidArgumentException;
use Orch8\Exception\LeaseLostException;
use Orch8\Exception\Orch8Exception;
use Orch8\Exception\TransportException;
use Orch8\Http\Transport;
use Orch8\Jobs\Orch8Job;
use Orch8\Json;
use Orch8\Push\PushEnvelope;
use Orch8\Worker\Internal\ChildProcess;
use Orch8\Worker\Internal\InFlightTask;
use Orch8\Worker\Internal\Outcome;
use Orch8\Worker\Internal\WorkerHttp;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orch8 external worker (WORKER_PROTOCOL.md).
 *
 * Design: one supervisor process runs a single-threaded event loop that does
 * all protocol I/O with non-blocking HTTP (Guzzle + curl_multi): per-handler
 * polls, heartbeats, checkpoint writes and complete/fail acknowledgements.
 * In the default fork mode every claimed task runs in its own forked child
 * process, so a handler may block (sleep, sync I/O, CPU) without stopping
 * heartbeats or other tasks. Children report checkpoints and results to the
 * supervisor over a Unix socket; the supervisor tracks `claim_epoch` and the
 * checkpoint CAS sequence. See {@see ExecutionMode} for the inline fallback.
 *
 * ```php
 * $worker = new Worker('http://localhost:8080/api/v1', apiKey: 'k', tenantId: 't');
 * $worker->handle('send_email', function (TaskContext $task) {
 *     return ['message_id' => send($task->params)];
 * });
 * $worker->run(); // until SIGTERM/SIGINT or stop()
 * ```
 */
final class Worker
{
    private const RETRYABLE_STATUSES = ApiException::RETRYABLE_STATUSES;
    private const MAX_POLL_BACKOFF_MS = 30000;
    private const KILL_GRACE_SECS = 2.0;

    private readonly WorkerOptions $options;
    private readonly WorkerHttp $http;
    private readonly LoggerInterface $logger;
    private readonly ExecutionMode $mode;
    private readonly int $concurrency;
    private readonly string $workerIdJson;

    /** @var array<string, callable(TaskContext): mixed> */
    private array $handlers = [];
    private int $free;
    private int $heartbeatMs;
    /** @var array<string, array{nextAt: float, failures: int, inFlight: bool}> */
    private array $pollers = [];
    private int $pollRotation = 0;
    private int $pollsInFlight = 0;
    /** @var array<string, InFlightTask> */
    private array $tasks = [];
    /** @var list<InFlightTask> */
    private array $ready = [];
    /** @var list<array{queue: string, handler: string}> */
    private array $pendingClaims = [];
    /** @var list<LoopHook> */
    private array $hooks = [];
    private bool $stopping = false;
    private ?float $drainDeadline = null;
    private bool $inLoop = false;

    /**
     * @param string        $baseUrl     `<origin>/api/v1`
     * @param callable|null $httpHandler Guzzle handler (for tests / custom transports);
     *                                   default: curl_multi for async + curl for sync calls
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        ?string $tenantId = null,
        ?WorkerOptions $options = null,
        ?LoggerInterface $logger = null,
        ?callable $httpHandler = null,
    ) {
        $this->options = $options ?? new WorkerOptions();
        $this->logger = $logger ?? new NullLogger();
        $this->http = new WorkerHttp($baseUrl, $apiKey, $tenantId, $httpHandler, $this->options->requestTimeoutSeconds);
        $canFork = function_exists('pcntl_fork') && function_exists('stream_socket_pair');
        $mode = $this->options->executionMode;
        if ($mode === ExecutionMode::Auto) {
            $mode = $canFork ? ExecutionMode::Fork : ExecutionMode::Inline;
        }
        if ($mode === ExecutionMode::Fork && !$canFork) {
            throw new InvalidArgumentException('ExecutionMode::Fork requires ext-pcntl');
        }
        $this->mode = $mode;
        $this->concurrency = $mode === ExecutionMode::Inline ? 1 : $this->options->concurrency;
        if ($mode === ExecutionMode::Inline && $this->options->concurrency > 1) {
            $this->logger->warning('orch8 worker: inline execution mode runs one task at a time; concurrency forced to 1');
        }
        $this->free = $this->concurrency;
        $this->heartbeatMs = $this->options->heartbeatIntervalMs;
        $this->workerIdJson = Json::encode($this->options->workerId);
    }

    public static function fromEnv(?WorkerOptions $options = null, ?LoggerInterface $logger = null): self
    {
        $env = static fn (string $k): ?string => ($v = getenv($k)) === false || $v === '' ? null : $v;

        return new self(
            $env('ORCH8_BASE_URL') ?? 'http://localhost:8080/api/v1',
            $env('ORCH8_API_KEY'),
            $env('ORCH8_TENANT_ID'),
            $options ?? WorkerOptions::fromEnv(),
            $logger,
        );
    }

    public function workerId(): string
    {
        return $this->options->workerId;
    }

    public function options(): WorkerOptions
    {
        return $this->options;
    }

    public function executionMode(): ExecutionMode
    {
        return $this->mode;
    }

    /**
     * Register a handler. It receives a {@see TaskContext}; its return value
     * (array/object/JsonSerializable; null → `{}`) becomes the task output.
     * Throw {@see \Orch8\Exception\NonRetryableException} for permanent failures;
     * anything else is reported as retryable.
     *
     * @param callable(TaskContext): mixed $handler
     */
    public function handle(string $handlerName, callable $handler): self
    {
        if ($handlerName === '') {
            throw new InvalidArgumentException('handler name must not be empty');
        }
        $this->handlers[$handlerName] = $handler;
        if ($this->inLoop && $this->options->polling && !isset($this->pollers[$handlerName])) {
            $this->pollers[$handlerName] = ['nextAt' => 0.0, 'failures' => 0, 'inFlight' => false];
        }

        return $this;
    }

    /**
     * Register an {@see Orch8Job} subclass: tasks for its handler name are
     * rebuilt with `fromPayload(params)` and run through `handle()`.
     *
     * @param class-string<Orch8Job> $jobClass
     */
    public function registerJob(string $jobClass): self
    {
        if (!is_subclass_of($jobClass, Orch8Job::class)) {
            throw new InvalidArgumentException(sprintf('%s must extend %s', $jobClass, Orch8Job::class));
        }
        if (!method_exists($jobClass, 'handle')) {
            throw new InvalidArgumentException(sprintf('%s must define a handle() method', $jobClass));
        }

        return $this->handle($jobClass::handlerName(), static function (TaskContext $task) use ($jobClass): mixed {
            $job = $jobClass::fromPayload(is_array($task->params) ? $task->params : []);

            return $job->handle($task);
        });
    }

    /** @return list<string> */
    public function handlerNames(): array
    {
        return array_keys($this->handlers);
    }

    public function addLoopHook(LoopHook $hook): self
    {
        $this->hooks[] = $hook;

        return $this;
    }

    /**
     * Run until {@see stop()} (or SIGTERM/SIGINT when signal handling is on),
     * then drain: no new polls, in-flight tasks keep heartbeating and are
     * acknowledged; after `shutdownTimeoutMs` the rest are abandoned
     * un-acked (the engine reclaims them after the lease expires).
     */
    public function run(): void
    {
        if ($this->handlers === []) {
            throw new InvalidArgumentException('register at least one handler before run()');
        }
        $this->stopping = false;
        $this->drainDeadline = null;
        if ($this->options->polling) {
            foreach (array_keys($this->handlers) as $name) {
                $this->pollers[$name] ??= ['nextAt' => 0.0, 'failures' => 0, 'inFlight' => false];
            }
        }
        $restore = $this->installSignalHandlers();
        $this->inLoop = true;
        $this->logger->info('orch8 worker started', [
            'worker_id' => $this->options->workerId,
            'handlers' => array_keys($this->handlers),
            'concurrency' => $this->concurrency,
            'mode' => $this->mode->name,
            'queue' => $this->options->queue,
        ]);
        try {
            while (true) {
                $this->iterate(true);
                if ($this->stopping) {
                    if ($this->isIdle(false)) {
                        break;
                    }
                    if (microtime(true) >= (float) $this->drainDeadline) {
                        $this->abandonInFlight();
                        break;
                    }
                }
            }
        } finally {
            $this->inLoop = false;
            $this->pollers = [];
            $restore();
            foreach ($this->hooks as $hook) {
                $hook->close();
            }
            $this->logger->info('orch8 worker stopped', ['worker_id' => $this->options->workerId]);
        }
    }

    /**
     * Process explicit claims ({@see claim()}, {@see claimFromPush()}) and the
     * tasks they return until nothing is in flight, without periodic polling.
     * Suited to push receivers running in a request/response SAPI, e.g. after
     * fastcgi_finish_request().
     *
     * @return bool false when the timeout elapsed first
     */
    public function runUntilIdle(float $timeoutSeconds = 300.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $this->inLoop = true;
        try {
            while (!$this->isIdle(true)) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                $this->iterate(false);
            }

            return true;
        } finally {
            $this->inLoop = false;
        }
    }

    /** Request a graceful stop (safe to call from a signal handler or a handler). */
    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->drainDeadline = microtime(true) + $this->options->shutdownTimeoutMs / 1000;
        $this->logger->info('orch8 worker stopping: draining in-flight tasks', ['in_flight' => count($this->tasks)]);
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /** Number of claimed tasks not yet settled. */
    public function inFlightCount(): int
    {
        return count($this->tasks);
    }

    /**
     * Queue one claim of `limit` 1 for `$handlerName` (from `$queue`, or the
     * default queue). Sent as soon as a concurrency slot is free.
     */
    public function claim(string $handlerName, ?string $queue = null): void
    {
        $this->pendingClaims[] = ['queue' => $queue ?? '', 'handler' => $handlerName];
    }

    /**
     * React to a verified push (WORKER_PROTOCOL D2): claim via
     * `POST /workers/tasks/poll/queue` with the envelope's queue/handler and
     * run whatever task the engine hands out. Unknown handlers are ignored.
     */
    public function claimFromPush(PushEnvelope $envelope): bool
    {
        if (!isset($this->handlers[$envelope->handlerName])) {
            $this->logger->warning('orch8 push for unregistered handler ignored', ['handler' => $envelope->handlerName]);

            return false;
        }
        $this->claim($envelope->handlerName, $envelope->queueName);

        return true;
    }

    // ------------------------------------------------------------------
    // Event loop
    // ------------------------------------------------------------------

    private function iterate(bool $periodicPolling): void
    {
        $now = microtime(true);
        if (!$this->stopping) {
            if ($periodicPolling && $this->options->polling) {
                $this->schedulePolls($now);
            }
            $this->schedulePendingClaims();
        }
        $this->startReady();
        $this->checkTimeouts($now);
        $this->sendHeartbeats($now);
        $this->sendCheckpoints();
        $this->sendAcks($now);
        $this->wait();
        foreach ($this->tasks as $task) {
            if ($task->sock !== null) {
                $this->readChild($task);
            }
        }
        $this->reapChildren();
        foreach ($this->hooks as $hook) {
            $hook->tick($this);
        }
        $this->finalize();
    }

    private function isIdle(bool $includePendingClaims): bool
    {
        return $this->tasks === []
            && $this->ready === []
            && $this->pollsInFlight === 0
            && (!$includePendingClaims || $this->pendingClaims === [] || $this->stopping);
    }

    private function wait(): void
    {
        $this->http->tick();
        $streams = [];
        foreach ($this->tasks as $task) {
            if ($task->sock !== null) {
                $streams[] = $task->sock;
            }
        }
        foreach ($this->hooks as $hook) {
            foreach ($hook->streams() as $s) {
                $streams[] = $s;
            }
        }
        if ($streams !== []) {
            $write = $except = null;
            @stream_select($streams, $write, $except, 0, 5000);
        } else {
            usleep(5000);
        }
        $this->http->tick();
    }

    private function schedulePolls(float $now): void
    {
        $names = array_keys($this->pollers);
        $n = count($names);
        if ($n === 0) {
            return;
        }
        $this->pollRotation = ($this->pollRotation + 1) % $n;
        for ($i = 0; $i < $n && $this->free > 0; $i++) {
            $name = $names[($this->pollRotation + $i) % $n];
            $p = $this->pollers[$name];
            if ($p['inFlight'] || $now < $p['nextAt']) {
                continue;
            }
            $this->sendPoll($name, $name, $this->options->queue, min($this->concurrency, $this->free));
        }
    }

    private function schedulePendingClaims(): void
    {
        while ($this->pendingClaims !== [] && $this->free > 0) {
            $c = array_shift($this->pendingClaims);
            $this->sendPoll(null, $c['handler'], $c['queue'] === '' ? null : $c['queue'], 1);
        }
    }

    /**
     * Slots are reserved BEFORE the poll is sent so concurrent per-handler
     * polls never ask for more tasks than the worker can start (P11).
     */
    private function sendPoll(?string $pollerKey, string $handlerName, ?string $queue, int $limit): void
    {
        $this->free -= $limit;
        $this->pollsInFlight++;
        if ($pollerKey !== null) {
            $this->pollers[$pollerKey]['inFlight'] = true;
        }
        $body = ['handler_name' => $handlerName, 'worker_id' => $this->options->workerId, 'limit' => $limit];
        if ($queue !== null) {
            $body['queue_name'] = $queue;
        }
        if ($this->options->version !== null) {
            $body['version'] = $this->options->version;
        }
        $path = $queue !== null ? '/workers/tasks/poll/queue' : '/workers/tasks/poll';

        $this->http->post($path, Json::encode($body))->then(
            function (array $res) use ($pollerKey, $limit): void {
                $this->pollsInFlight--;
                [$status, $text] = $res;
                if ($status >= 400) {
                    $this->free += $limit;
                    $this->onPollError($pollerKey, ApiException::fromResponse($status, $text));

                    return;
                }
                try {
                    $data = Json::decode($text);
                } catch (\JsonException $e) {
                    $this->free += $limit;
                    $this->onPollError($pollerKey, new Orch8Exception('invalid poll response: ' . $e->getMessage()));

                    return;
                }
                $this->onPollResponse($pollerKey, $limit, is_array($data) ? $data : []);
            },
            function (\Throwable $e) use ($pollerKey, $limit): void {
                $this->pollsInFlight--;
                $this->free += $limit;
                $this->onPollError($pollerKey, $e);
            },
        );
    }

    /** @param array<string, mixed> $data */
    private function onPollResponse(?string $pollerKey, int $limit, array $data): void
    {
        $now = microtime(true);
        $tasks = isset($data['tasks']) && is_array($data['tasks']) ? array_values($data['tasks']) : [];
        if (count($tasks) > $limit) {
            $this->logger->error('orch8 poll returned more tasks than requested; extra tasks are left to lease recovery', ['limit' => $limit, 'got' => count($tasks)]);
            $tasks = array_slice($tasks, 0, $limit);
        }
        $accepted = 0;
        foreach ($tasks as $raw) {
            if (!is_array($raw) || !isset($raw['id']) || isset($this->tasks[(string) $raw['id']])) {
                continue;
            }
            $task = new InFlightTask($raw, $now);
            $this->tasks[$task->id] = $task;
            $this->ready[] = $task;
            $accepted++;
        }
        $this->free += $limit - $accepted;

        $hint = $data['heartbeat_interval_secs'] ?? null;
        if (is_int($hint) || is_float($hint)) {
            $ms = min($this->options->heartbeatIntervalMs, (int) ($hint * 1000));
            $lease = $data['lease_secs'] ?? null;
            if ((is_int($lease) || is_float($lease)) && $lease > 0) {
                $ms = min($ms, (int) ($lease * 500));
            }
            $this->heartbeatMs = max(100, $ms);
        }

        if ($pollerKey !== null && isset($this->pollers[$pollerKey])) {
            $delayMs = 0;
            if ($tasks === []) {
                $after = $data['poll_after_ms'] ?? 0;
                $delayMs = max($this->options->pollIntervalMs, is_numeric($after) ? (int) $after : 0);
            }
            $this->pollers[$pollerKey] = ['nextAt' => $now + $delayMs / 1000, 'failures' => 0, 'inFlight' => false];
        }
    }

    private function onPollError(?string $pollerKey, \Throwable $e): void
    {
        $level = ($e instanceof ApiException && in_array($e->status, [400, 401, 403], true)) ? 'error' : 'warning';
        $this->logger->log($level, 'orch8 poll failed: ' . $e->getMessage(), ['handler' => $pollerKey]);
        if ($pollerKey === null || !isset($this->pollers[$pollerKey])) {
            return;
        }
        $failures = $this->pollers[$pollerKey]['failures'] + 1;
        $base = max($this->options->pollIntervalMs, 50);
        $delayMs = min($base * (2 ** min($failures, 20)), self::MAX_POLL_BACKOFF_MS);
        $this->pollers[$pollerKey] = ['nextAt' => microtime(true) + $delayMs / 1000, 'failures' => $failures, 'inFlight' => false];
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    private function startReady(): void
    {
        while ($this->ready !== []) {
            $task = array_shift($this->ready);
            if ($task->lost) {
                $task->processDone = true;
                continue;
            }
            $handler = $this->handlers[$task->handlerName()] ?? null;
            if ($handler === null) {
                $task->processDone = true;
                $this->settle($task, ['t' => 'result', 'ok' => false, 'retryable' => false,
                    'message' => sprintf('no handler registered for %s', $task->handlerName())]);
                continue;
            }
            if ($this->mode === ExecutionMode::Fork) {
                $this->spawn($task, $handler);
            } else {
                $this->runInline($task, $handler);
            }
        }
    }

    /** @param callable(TaskContext): mixed $handler */
    private function spawn(InFlightTask $task, callable $handler): void
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $task->processDone = true;
            $this->settle($task, ['t' => 'result', 'ok' => false, 'retryable' => true, 'message' => 'worker could not create an IPC socket']);

            return;
        }
        $parentPid = function_exists('posix_getpid') ? posix_getpid() : (int) getmypid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            $task->processDone = true;
            $this->settle($task, ['t' => 'result', 'ok' => false, 'retryable' => true, 'message' => 'worker could not fork a task process']);

            return;
        }
        if ($pid === 0) {
            fclose($pair[0]);
            ChildProcess::run($pair[1], $task->task, $handler, $parentPid);
        }
        fclose($pair[1]);
        stream_set_blocking($pair[0], false);
        $task->pid = $pid;
        $task->sock = $pair[0];
        $task->started = true;
    }

    /** @param callable(TaskContext): mixed $handler */
    private function runInline(InFlightTask $task, callable $handler): void
    {
        $task->started = true;
        $ctx = new TaskContext(
            $task->task,
            function (string $json, int $expectedSeq) use ($task): int {
                return $this->checkpointSync($task, $json);
            },
            fn (): bool => $task->lost || $task->timedOut
                || ($this->stopping && microtime(true) >= (float) $this->drainDeadline),
            function () use ($task): void {
                $this->heartbeatSync($task);
            },
        );
        $result = Outcome::run($handler, $ctx);
        $task->processDone = true;
        $this->settle($task, $result);
    }

    private function readChild(InFlightTask $task): void
    {
        while (true) {
            $chunk = @fread($task->sock, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $task->buffer .= $chunk;
        }
        while (($pos = strpos($task->buffer, "\n")) !== false) {
            $line = substr($task->buffer, 0, $pos);
            $task->buffer = substr($task->buffer, $pos + 1);
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                continue;
            }
            if (($msg['t'] ?? null) === 'cp') {
                if ($task->lost || $task->timedOut) {
                    $this->reply($task, ['t' => 'cp_err', 'lost' => true, 'message' => 'lease lost']);
                } else {
                    $task->checkpointJson = (string) ($msg['v'] ?? '{}');
                }
            } elseif (($msg['t'] ?? null) === 'result') {
                $this->settle($task, $msg);
            }
        }
        if (feof($task->sock)) {
            fclose($task->sock);
            $task->sock = null;
        }
    }

    private function reapChildren(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->pid === null || $task->processDone) {
                continue;
            }
            $status = 0;
            $r = pcntl_waitpid($task->pid, $status, WNOHANG);
            if ($r === 0) {
                continue;
            }
            if ($task->sock !== null) {
                stream_set_blocking($task->sock, true);
                stream_set_timeout($task->sock, 1);
                $this->readChild($task);
                if ($task->sock !== null) {
                    fclose($task->sock);
                    $task->sock = null;
                }
            }
            $task->processDone = true;
            if ($task->ack === null && !$task->lost) {
                $how = pcntl_wifsignaled($status) ? 'signal ' . pcntl_wtermsig($status) : 'status ' . pcntl_wexitstatus($status);
                $this->settle($task, ['t' => 'result', 'ok' => false, 'retryable' => true,
                    'message' => sprintf('task process exited unexpectedly (%s)', $how)]);
            }
        }
    }

    /** Turn a handler result into the acknowledgement to send (at most once per task). */
    private function settle(InFlightTask $task, array $msg): void
    {
        if ($task->ack !== null || $task->lost) {
            return;
        }
        if (($msg['lost'] ?? false) === true) {
            $task->lost = true;

            return;
        }
        $prefix = '{"worker_id":' . $this->workerIdJson . ',"claim_epoch":' . $task->claimEpoch;
        if (($msg['ok'] ?? false) === true) {
            $output = (string) ($msg['output'] ?? '{}');
            $task->ack = ['kind' => 'complete', 'body' => $prefix . ',"output":' . $output . '}'];
        } else {
            $message = (string) ($msg['message'] ?? '');
            $task->ack = ['kind' => 'fail', 'body' => $prefix
                . ',"message":' . Json::encode($message === '' ? 'error' : $message)
                . ',"retryable":' . (($msg['retryable'] ?? true) === false ? 'false' : 'true') . '}'];
        }
        $task->ackNextAt = 0.0;
    }

    private function checkTimeouts(float $now): void
    {
        foreach ($this->tasks as $task) {
            if ($task->processDone) {
                continue;
            }
            if (!$task->timedOut && $task->deadline !== null && $now >= $task->deadline) {
                $task->timedOut = true;
                $this->logger->warning('orch8 task exceeded timeout_ms; cancelling', ['task_id' => $task->id]);
                $this->settle($task, ['t' => 'result', 'ok' => false, 'retryable' => true, 'message' => 'task timed out (timeout_ms exceeded)']);
                $this->cancel($task);
            }
            if ($task->killAt !== null && $now >= $task->killAt && $task->pid !== null) {
                @posix_kill($task->pid, SIGKILL);
                $task->killAt = null;
            }
        }
    }

    private function cancel(InFlightTask $task): void
    {
        if ($task->cancelSent || $task->pid === null || $task->processDone) {
            return;
        }
        $task->cancelSent = true;
        if (function_exists('posix_kill')) {
            @posix_kill($task->pid, SIGUSR1);
        }
        $task->killAt = microtime(true) + self::KILL_GRACE_SECS;
    }

    private function leaseLost(InFlightTask $task, string $why): void
    {
        if ($task->lost) {
            return;
        }
        $task->lost = true;
        $this->logger->warning('orch8 lease lost; task will not be acknowledged', ['task_id' => $task->id, 'reason' => $why]);
        if ($task->pid !== null && !$task->processDone && !$task->cancelSent && function_exists('posix_kill')) {
            $task->cancelSent = true;
            @posix_kill($task->pid, SIGUSR1);
        }
    }

    // ------------------------------------------------------------------
    // Heartbeats and checkpoints
    // ------------------------------------------------------------------

    private function sendHeartbeats(float $now): void
    {
        foreach ($this->tasks as $task) {
            if ($task->lost || $task->ackDone || $task->ackInFlight || $task->heartbeatInFlight || $task->checkpointInFlight) {
                continue;
            }
            if (($now - $task->lastBeatAt) * 1000 < $this->heartbeatMs) {
                continue;
            }
            $task->heartbeatInFlight = true;
            $task->lastBeatAt = $now;
            $body = '{"worker_id":' . $this->workerIdJson . ',"claim_epoch":' . $task->claimEpoch . '}';
            $this->http->post($this->taskPath($task, 'heartbeat'), $body)->then(
                function (array $res) use ($task): void {
                    $task->heartbeatInFlight = false;
                    [$status, $text] = $res;
                    if ($status === 404 || $status === 409) {
                        $this->leaseLost($task, ApiException::fromResponse($status, $text)->getMessage());
                    } elseif ($status >= 400) {
                        $this->logger->warning('orch8 heartbeat failed', ['task_id' => $task->id, 'status' => $status]);
                    }
                },
                function (\Throwable $e) use ($task): void {
                    $task->heartbeatInFlight = false;
                    $this->logger->warning('orch8 heartbeat failed: ' . $e->getMessage(), ['task_id' => $task->id]);
                },
            );
        }
    }

    private function sendCheckpoints(): void
    {
        foreach ($this->tasks as $task) {
            if ($task->checkpointJson === null || $task->checkpointInFlight || $task->heartbeatInFlight) {
                continue;
            }
            $json = $task->checkpointJson;
            $task->checkpointJson = null;
            if ($task->lost) {
                $this->reply($task, ['t' => 'cp_err', 'lost' => true, 'message' => 'lease lost']);
                continue;
            }
            $this->postCheckpoint($task, $json, 1);
        }
    }

    private function postCheckpoint(InFlightTask $task, string $json, int $attempt): void
    {
        $task->checkpointInFlight = true;
        $task->lastBeatAt = microtime(true);
        $this->http->post($this->taskPath($task, 'heartbeat'), $this->checkpointBody($task, $json))->then(
            function (array $res) use ($task): void {
                $task->checkpointInFlight = false;
                [$status, $text] = $res;
                try {
                    $task->seq = $this->checkpointResult($task, $status, $text);
                    $this->reply($task, ['t' => 'cp_ok', 'seq' => $task->seq]);
                } catch (LeaseLostException $e) {
                    $this->reply($task, ['t' => 'cp_err', 'lost' => true, 'message' => $e->getMessage()]);
                } catch (\Throwable $e) {
                    $this->reply($task, ['t' => 'cp_err', 'lost' => false, 'message' => $e->getMessage()]);
                }
            },
            function (\Throwable $e) use ($task, $json, $attempt): void {
                $task->checkpointInFlight = false;
                if ($attempt < 2 && !$task->lost) {
                    // Ambiguous transport failure: retry once with the same seq (§9);
                    // a 409 then is treated as lease loss.
                    $this->postCheckpoint($task, $json, $attempt + 1);

                    return;
                }
                $this->reply($task, ['t' => 'cp_err', 'lost' => false, 'message' => 'checkpoint failed: ' . $e->getMessage()]);
            },
        );
    }

    private function checkpointBody(InFlightTask $task, string $json): string
    {
        return '{"worker_id":' . $this->workerIdJson . ',"claim_epoch":' . $task->claimEpoch
            . ',"checkpoint_seq":' . $task->seq . ',"checkpoint":' . $json . '}';
    }

    /** @return int the new sequence */
    private function checkpointResult(InFlightTask $task, int $status, string $text): int
    {
        if ($status === 404 || $status === 409) {
            $msg = ApiException::fromResponse($status, $text)->getMessage();
            $this->leaseLost($task, $msg);
            throw new LeaseLostException($msg);
        }
        if ($status >= 400) {
            throw ApiException::fromResponse($status, $text);
        }
        $data = Json::decode($text);
        $seq = is_array($data) ? ($data['checkpoint_seq'] ?? null) : null;

        return is_int($seq) ? $seq : $task->seq + 1;
    }

    private function checkpointSync(InFlightTask $task, string $json): int
    {
        if ($task->lost) {
            throw new LeaseLostException('lease lost');
        }
        $task->lastBeatAt = microtime(true);
        $body = $this->checkpointBody($task, $json);
        try {
            [$status, $text] = $this->http->postSync($this->taskPath($task, 'heartbeat'), $body);
        } catch (TransportException) {
            [$status, $text] = $this->http->postSync($this->taskPath($task, 'heartbeat'), $body);
        }
        $task->seq = $this->checkpointResult($task, $status, $text);

        return $task->seq;
    }

    private function heartbeatSync(InFlightTask $task): void
    {
        $now = microtime(true);
        if ($task->lost || ($now - $task->lastBeatAt) * 1000 < $this->heartbeatMs) {
            return;
        }
        $task->lastBeatAt = $now;
        try {
            [$status, $text] = $this->http->postSync(
                $this->taskPath($task, 'heartbeat'),
                '{"worker_id":' . $this->workerIdJson . ',"claim_epoch":' . $task->claimEpoch . '}',
            );
        } catch (TransportException $e) {
            $this->logger->warning('orch8 heartbeat failed: ' . $e->getMessage(), ['task_id' => $task->id]);

            return;
        }
        if ($status === 404 || $status === 409) {
            $this->leaseLost($task, ApiException::fromResponse($status, $text)->getMessage());
        }
    }

    /** @param array<string, mixed> $msg */
    private function reply(InFlightTask $task, array $msg): void
    {
        if ($task->sock === null) {
            return;
        }
        $line = Json::encode($msg) . "\n";
        stream_set_blocking($task->sock, true);
        @fwrite($task->sock, $line);
        stream_set_blocking($task->sock, false);
    }

    // ------------------------------------------------------------------
    // Acknowledgements
    // ------------------------------------------------------------------

    private function sendAcks(float $now): void
    {
        foreach ($this->tasks as $task) {
            if ($task->ack === null || $task->ackDone || $task->ackInFlight || $task->lost || $now < $task->ackNextAt) {
                continue;
            }
            $task->ackInFlight = true;
            $task->ackAttempts++;
            $kind = $task->ack['kind'];
            $this->http->post($this->taskPath($task, $kind), $task->ack['body'])->then(
                function (array $res) use ($task, $kind): void {
                    $task->ackInFlight = false;
                    [$status, $text] = $res;
                    if ($status < 400) {
                        $task->ackDone = true;
                    } elseif ($status === 404 || $status === 409) {
                        // L3: someone else owns it (or it is already settled). Never retried,
                        // never turned into a handler failure.
                        $task->ackDone = true;
                        $this->logger->warning(sprintf('orch8 %s rejected: lease lost', $kind), ['task_id' => $task->id, 'status' => $status]);
                    } elseif (in_array($status, self::RETRYABLE_STATUSES, true)) {
                        $this->scheduleAckRetry($task, sprintf('HTTP %d', $status));
                    } else {
                        $task->ackDone = true;
                        $this->logger->error(sprintf('orch8 %s rejected: %s', $kind, ApiException::fromResponse($status, $text)->getMessage()), ['task_id' => $task->id, 'status' => $status]);
                    }
                },
                function (\Throwable $e) use ($task): void {
                    $task->ackInFlight = false;
                    $this->scheduleAckRetry($task, $e->getMessage());
                },
            );
        }
    }

    private function scheduleAckRetry(InFlightTask $task, string $why): void
    {
        if ($task->ackAttempts >= $this->options->maxAckAttempts) {
            // L4: ambiguous — leave it to lease recovery.
            $task->ackDone = true;
            $this->logger->error('orch8 acknowledgement abandoned after retries', ['task_id' => $task->id, 'reason' => $why]);

            return;
        }
        $delay = min(0.2 * (2 ** ($task->ackAttempts - 1)), 5.0);
        $task->ackNextAt = microtime(true) + $delay;
    }

    // ------------------------------------------------------------------
    // Bookkeeping
    // ------------------------------------------------------------------

    private function finalize(): void
    {
        foreach ($this->tasks as $id => $task) {
            if ($task->started || $task->processDone) {
                if ($task->isFinished()) {
                    if ($task->sock !== null) {
                        fclose($task->sock);
                        $task->sock = null;
                    }
                    unset($this->tasks[$id]);
                    $this->free++;
                }
            }
        }
    }

    private function abandonInFlight(): void
    {
        if ($this->tasks === []) {
            return;
        }
        $this->logger->warning('orch8 drain timeout: abandoning in-flight tasks without acknowledgement', ['count' => count($this->tasks)]);
        foreach ($this->tasks as $task) {
            if ($task->pid !== null && !$task->processDone && function_exists('posix_kill')) {
                @posix_kill($task->pid, SIGKILL);
                $status = 0;
                pcntl_waitpid($task->pid, $status);
            }
            if ($task->sock !== null) {
                fclose($task->sock);
                $task->sock = null;
            }
        }
        $this->free += count($this->tasks);
        $this->tasks = [];
        $this->ready = [];
    }

    /** @return \Closure(): void restores the previous handlers */
    private function installSignalHandlers(): \Closure
    {
        if (!$this->options->handleSignals || !function_exists('pcntl_signal')) {
            return static function (): void {
            };
        }
        $previousAsync = pcntl_async_signals(true);
        $previous = [];
        foreach ([SIGTERM, SIGINT] as $sig) {
            $previous[$sig] = pcntl_signal_get_handler($sig);
            pcntl_signal($sig, function (): void {
                $this->stop();
            });
        }

        return static function () use ($previous, $previousAsync): void {
            foreach ($previous as $sig => $handler) {
                pcntl_signal($sig, $handler ?? SIG_DFL);
            }
            pcntl_async_signals($previousAsync);
        };
    }

    private function taskPath(InFlightTask $task, string $action): string
    {
        return '/workers/tasks/' . Transport::segment($task->id) . '/' . $action;
    }
}
