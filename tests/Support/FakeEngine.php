<?php

declare(strict_types=1);

namespace Orch8\Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * In-process fake of the engine's worker endpoints, used as a Guzzle
 * handler: claim-epoch ownership, checkpoint CAS and completion retry
 * idempotency like the real engine, plus per-request hooks for faults.
 */
final class FakeEngine
{
    /** @var list<array{method: string, path: string, body: mixed, raw: string, at: float, kind: string, taskId: ?string, status?: int, inFlight?: int, headers: array}> */
    public array $requests = [];
    /** @var list<array<string, mixed>> */
    public array $pending = [];
    /** @var array<string, array<string, mixed>> */
    public array $tasks = [];
    /** @var array<string, callable(array): ?Response> */
    public array $hooks = [];
    private int $counter = 0;

    public function __construct(
        public int $heartbeatIntervalSecs = 15,
        public int $leaseSecs = 60,
        public int $pollAfterMs = 50,
    ) {
    }

    /** @param array<string, mixed> $overrides */
    public function addTask(string $handler, array $overrides = [], ?string $queue = null): array
    {
        $this->counter++;
        $n = str_pad((string) $this->counter, 12, '0', STR_PAD_LEFT);
        $task = $overrides + [
            'id' => "00000000-0000-7000-8000-{$n}",
            'instance_id' => "00000000-0000-7000-9000-{$n}",
            'block_id' => "step_{$this->counter}",
            'handler_name' => $handler,
            'params' => ['n' => $this->counter],
            'context' => ['data' => [], 'config' => []],
            'attempt' => 0,
            'timeout_ms' => null,
            'claim_epoch' => 0,
            'checkpoint_seq' => 0,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $task['_queue'] = $queue;
        $this->pending[] = $task;

        return $task;
    }

    public function inFlight(): int
    {
        return count(array_filter($this->tasks, static fn ($t) => $t['state'] === 'claimed'));
    }

    /** @return list<array> */
    public function byKind(string $kind, ?string $taskId = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn ($r) => $r['kind'] === $kind && ($taskId === null || $r['taskId'] === $taskId),
        ));
    }

    /** @return list<array> complete/fail requests for a task */
    public function acks(string $taskId): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn ($r) => in_array($r['kind'], ['complete', 'fail'], true) && $r['taskId'] === $taskId,
        ));
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $raw = (string) $request->getBody();
        $path = preg_replace('#^/api/v1#', '', $request->getUri()->getPath());
        $rec = [
            'method' => $request->getMethod(),
            'path' => $path,
            'body' => $raw === '' ? null : json_decode($raw, true),
            'raw' => $raw,
            'at' => microtime(true),
            'kind' => 'other',
            'taskId' => null,
            'headers' => array_map(static fn ($v) => implode(',', $v), $request->getHeaders()),
        ];
        $idx = count($this->requests);
        $this->requests[] = $rec;
        $response = $this->dispatch($idx);
        $this->requests[$idx]['status'] = $response->getStatusCode();

        return Create::promiseFor($response);
    }

    private function dispatch(int $idx): Response
    {
        $r = &$this->requests[$idx];
        if ($r['method'] === 'POST' && in_array($r['path'], ['/workers/tasks/poll', '/workers/tasks/poll/queue'], true)) {
            $r['kind'] = 'poll';
            $r['inFlight'] = $this->inFlight();
            if (isset($this->hooks['poll']) && ($out = ($this->hooks['poll'])($r)) !== null) {
                return $out;
            }

            return $this->poll($r);
        }
        if ($r['method'] === 'POST' && preg_match('#^/workers/tasks/([^/]+)/(heartbeat|complete|fail)$#', $r['path'], $m) === 1) {
            $r['kind'] = $m[2];
            $r['taskId'] = rawurldecode($m[1]);
            if (isset($this->hooks[$m[2]]) && ($out = ($this->hooks[$m[2]])($r)) !== null) {
                if (isset($this->tasks[$r['taskId']]) && in_array($out->getStatusCode(), [404, 409], true)) {
                    $this->tasks[$r['taskId']]['state'] = 'lost';
                }

                return $out;
            }

            return $this->mutation($r, $m[2]);
        }

        return self::err(404, 'not_found', 'no route');
    }

    private function poll(array $r): Response
    {
        $b = $r['body'] ?? [];
        $queue = str_ends_with($r['path'], '/queue') ? ($b['queue_name'] ?? null) : null;
        $limit = is_int($b['limit'] ?? null) ? $b['limit'] : 1;
        $out = [];
        foreach ($this->pending as $i => $t) {
            if (count($out) >= $limit) {
                break;
            }
            if ($t['handler_name'] === ($b['handler_name'] ?? null) && $t['_queue'] === $queue) {
                unset($this->pending[$i]);
                unset($t['_queue']);
                $t['claim_epoch']++;
                $t['state'] = 'claimed';
                $t['worker_id'] = $b['worker_id'] ?? null;
                $t['claimedAt'] = microtime(true);
                $this->tasks[$t['id']] = $t;
                unset($t['claimedAt']);
                $out[] = $t;
            }
        }
        $this->pending = array_values($this->pending);

        return self::json(200, [
            'tasks' => $out,
            'lease_secs' => $this->leaseSecs,
            'heartbeat_interval_secs' => $this->heartbeatIntervalSecs,
            'poll_after_ms' => $out === [] ? $this->pollAfterMs : 0,
        ]);
    }

    private function mutation(array $r, string $kind): Response
    {
        if (!isset($this->tasks[$r['taskId']])) {
            return self::err(404, 'not_found', 'not found: worker_task');
        }
        $task = &$this->tasks[$r['taskId']];
        $b = $r['body'] ?? [];
        $owns = ($b['worker_id'] ?? null) === $task['worker_id'] && ($b['claim_epoch'] ?? null) === $task['claim_epoch'];
        if ($kind === 'complete' && $task['state'] === 'completed' && $owns) {
            return new Response(200);
        }
        if ($task['state'] !== 'claimed' || !$owns) {
            return self::err(409, 'conflict', 'conflict: worker task lease changed');
        }
        if ($kind === 'heartbeat') {
            if (array_key_exists('checkpoint', $b) && $b['checkpoint'] !== null) {
                if (!is_int($b['checkpoint_seq'] ?? null)) {
                    return self::err(400, 'invalid_argument', 'checkpoint_seq is required');
                }
                if ($b['checkpoint_seq'] !== $task['checkpoint_seq']) {
                    return self::err(409, 'conflict', 'conflict: checkpoint sequence changed');
                }
                $task['checkpoint_seq']++;
                $task['resume_checkpoint'] = $b['checkpoint'];
            }

            return self::json(200, ['checkpoint_seq' => $task['checkpoint_seq']]);
        }
        $task['state'] = $kind === 'complete' ? 'completed' : 'failed';
        $task['settledAt'] = microtime(true);

        return new Response(200);
    }

    public static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    public static function err(int $status, string $code, string $message): Response
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message, 'request_id' => null]]);
    }
}
