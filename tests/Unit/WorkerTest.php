<?php

declare(strict_types=1);

namespace Orch8\Tests\Unit;

use Orch8\Exception\NonRetryableException;
use Orch8\Exception\RetryableException;
use Orch8\Push\PushEnvelope;
use Orch8\Tests\Support\FakeEngine;
use Orch8\Tests\Support\StopWhen;
use Orch8\Tests\Unit\Jobs\SendWelcomeEmail;
use Orch8\Worker\ExecutionMode;
use Orch8\Worker\TaskContext;
use Orch8\Worker\Worker;
use Orch8\Worker\WorkerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private function worker(FakeEngine $engine, array $options = []): Worker
    {
        $opts = new WorkerOptions(...array_merge([
            'workerId' => 'w-test',
            'concurrency' => 4,
            'pollIntervalMs' => 20,
            'shutdownTimeoutMs' => 5000,
            'handleSignals' => false,
        ], $options));
        $w = new Worker('http://engine.test/api/v1', 'key', 'tenant', $opts, null, $engine);
        $w->handle('echo', static fn (TaskContext $t) => ['echo' => $t->params])
            ->handle('nothing', static fn () => null)
            ->handle('fail_retryable', static fn (TaskContext $t) => throw new RetryableException('boom'))
            ->handle('fail_permanent', static fn () => throw new NonRetryableException('fatal'))
            ->handle('crash', static fn () => throw new \RuntimeException('crash'))
            ->handle('checkpoint', static function (TaskContext $t): array {
                $start = is_array($t->resumeCheckpoint) ? (int) $t->resumeCheckpoint['step'] : 0;
                for ($i = $start + 1; $i <= 3; $i++) {
                    $t->checkpoint(['step' => $i]);
                }

                return ['resumed_from' => $start, 'seq' => $t->checkpointSeq()];
            })
            ->handle('slow', static function (TaskContext $t): array {
                $completed = $t->sleep((int) $t->param('sleep_ms', 300));

                return ['completed' => $completed];
            });

        return $w;
    }

    /** @param list<array> $tasks */
    private function runUntilSettled(Worker $w, FakeEngine $engine, array $tasks, float $timeout = 10.0, float $extraSeconds = 0.0): void
    {
        $settledAt = null;
        $hook = new StopWhen(static function () use ($engine, $tasks, &$settledAt, $extraSeconds): bool {
            foreach ($tasks as $t) {
                if ($engine->acks($t['id']) === [] && ($engine->tasks[$t['id']]['state'] ?? 'pending') !== 'lost') {
                    return false;
                }
            }
            $settledAt ??= microtime(true);

            return microtime(true) - $settledAt >= $extraSeconds;
        }, $timeout);
        $w->addLoopHook($hook);
        $w->run();
        self::assertFalse($hook->timedOut, 'worker did not settle all tasks in time');
    }

    /** @return iterable<string, array{ExecutionMode}> */
    public static function modes(): iterable
    {
        yield 'fork' => [ExecutionMode::Fork];
        yield 'inline' => [ExecutionMode::Inline];
    }

    #[DataProvider('modes')]
    public function testPollsEveryHandlerAndCompletesWithClaimEpoch(ExecutionMode $mode): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('echo', ['params' => ['greeting' => 'hi'], 'claim_epoch' => 6]);
        $empty = $engine->addTask('nothing');
        $w = $this->worker($engine, ['concurrency' => 3, 'executionMode' => $mode]);
        $this->runUntilSettled($w, $engine, [$task, $empty], 10.0, 0.3);

        $polls = $engine->byKind('poll');
        $polled = array_unique(array_map(static fn ($p) => $p['body']['handler_name'], $polls));
        sort($polled);
        self::assertSame(['checkpoint', 'crash', 'echo', 'fail_permanent', 'fail_retryable', 'nothing', 'slow'], $polled);
        foreach ($polls as $p) {
            self::assertSame('/workers/tasks/poll', $p['path']);
            self::assertSame('w-test', $p['body']['worker_id']);
            self::assertGreaterThanOrEqual(1, $p['body']['limit']);
            self::assertLessThanOrEqual($mode === ExecutionMode::Inline ? 1 : 3, $p['body']['limit']);
            self::assertSame('key', $p['headers']['x-api-key']);
            self::assertSame('tenant', $p['headers']['x-tenant-id']);
        }
        $acks = $engine->acks($task['id']);
        self::assertCount(1, $acks);
        self::assertSame('complete', $acks[0]['kind']);
        self::assertSame('{"worker_id":"w-test","claim_epoch":7,"output":{"echo":{"greeting":"hi"}}}', $acks[0]['raw']);
        self::assertSame('{"worker_id":"w-test","claim_epoch":1,"output":{}}', $engine->acks($empty['id'])[0]['raw'], 'null output is sent as {}');
    }

    #[DataProvider('modes')]
    public function testFailureClassification(ExecutionMode $mode): void
    {
        $engine = new FakeEngine();
        $tasks = [
            'fail_retryable' => $engine->addTask('fail_retryable'),
            'fail_permanent' => $engine->addTask('fail_permanent'),
            'crash' => $engine->addTask('crash'),
        ];
        $w = $this->worker($engine, ['executionMode' => $mode]);
        $this->runUntilSettled($w, $engine, array_values($tasks));
        $expect = ['fail_retryable' => [true, 'boom'], 'fail_permanent' => [false, 'fatal'], 'crash' => [true, 'crash']];
        foreach ($tasks as $name => $task) {
            $acks = $engine->acks($task['id']);
            self::assertCount(1, $acks, $name);
            self::assertSame('fail', $acks[0]['kind']);
            self::assertSame($expect[$name][0], $acks[0]['body']['retryable'], $name);
            self::assertSame($expect[$name][1], $acks[0]['body']['message'], $name);
        }
    }

    public function testUnknownHandlerIsFailedPermanently(): void
    {
        $engine = new FakeEngine();
        $unknown = $engine->addTask('unknown');
        $w = $this->worker($engine, ['polling' => false]);
        $w->claim('unknown');
        self::assertTrue($w->runUntilIdle(5));
        $acks = $engine->acks($unknown['id']);
        self::assertCount(1, $acks);
        self::assertFalse($acks[0]['body']['retryable']);
        self::assertStringContainsString('no handler registered', $acks[0]['body']['message']);
    }

    #[DataProvider('modes')]
    public function testCheckpointCasSequence(ExecutionMode $mode): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('checkpoint', ['resume_checkpoint' => ['step' => 1], 'checkpoint_seq' => 5]);
        $w = $this->worker($engine, ['executionMode' => $mode]);
        $this->runUntilSettled($w, $engine, [$task]);
        $cps = array_values(array_filter($engine->byKind('heartbeat', $task['id']), static fn ($r) => isset($r['body']['checkpoint'])));
        self::assertCount(2, $cps);
        self::assertSame(['step' => 2], $cps[0]['body']['checkpoint']);
        self::assertSame(5, $cps[0]['body']['checkpoint_seq']);
        self::assertSame(['step' => 3], $cps[1]['body']['checkpoint']);
        self::assertSame(6, $cps[1]['body']['checkpoint_seq']);
        self::assertSame(['resumed_from' => 1, 'seq' => 7], $engine->acks($task['id'])[0]['body']['output']);
    }

    #[DataProvider('modes')]
    public function testHeartbeatsFollowServerHint(ExecutionMode $mode): void
    {
        $engine = new FakeEngine(heartbeatIntervalSecs: 1, leaseSecs: 4);
        $task = $engine->addTask('slow', ['params' => ['sleep_ms' => 2300]]);
        $w = $this->worker($engine, ['executionMode' => $mode]);
        $this->runUntilSettled($w, $engine, [$task]);
        $hbs = $engine->byKind('heartbeat', $task['id']);
        self::assertGreaterThanOrEqual(2, count($hbs));
        $times = [$engine->tasks[$task['id']]['claimedAt'], ...array_column($hbs, 'at')];
        for ($i = 1; $i < count($times); $i++) {
            self::assertLessThan(1.4, $times[$i] - $times[$i - 1]);
        }
        foreach ($hbs as $hb) {
            self::assertSame(['worker_id' => 'w-test', 'claim_epoch' => 1], $hb['body']);
        }
        self::assertSame(['completed' => true], $engine->acks($task['id'])[0]['body']['output']);
    }

    /** @return iterable<string, array{ExecutionMode, int}> */
    public static function leaseLossCases(): iterable
    {
        yield 'fork 409' => [ExecutionMode::Fork, 409];
        yield 'fork 404' => [ExecutionMode::Fork, 404];
        yield 'inline 409' => [ExecutionMode::Inline, 409];
    }

    #[DataProvider('leaseLossCases')]
    public function testLeaseLossStopsHeartbeatsAndAcks(ExecutionMode $mode, int $status): void
    {
        $engine = new FakeEngine(heartbeatIntervalSecs: 1, leaseSecs: 4);
        $lost = $engine->addTask('slow', ['params' => ['sleep_ms' => 3000]]);
        $lostAt = null;
        $engine->hooks['heartbeat'] = static function (array $r) use ($lost, $status, &$lostAt) {
            if ($r['taskId'] !== $lost['id']) {
                return null;
            }
            $lostAt ??= microtime(true);

            return FakeEngine::err($status, $status === 404 ? 'not_found' : 'conflict', 'lease changed');
        };
        $w = $this->worker($engine, ['executionMode' => $mode]);
        $start = microtime(true);
        $hook = new StopWhen(static function () use (&$lostAt): bool {
            return $lostAt !== null && microtime(true) - $lostAt > 1.5;
        }, 10);
        $w->addLoopHook($hook);
        $w->run();
        self::assertNotNull($lostAt);
        self::assertSame([], $engine->acks($lost['id']), 'no complete/fail after lease loss');
        self::assertCount(1, $engine->byKind('heartbeat', $lost['id']), 'no heartbeats after lease loss');
        self::assertLessThan(3.0, microtime(true) - $start, 'the cancelled handler must stop early');
    }

    public function testCompleteRetriedWithIdenticalBodyAndNotAfterConflict(): void
    {
        $engine = new FakeEngine();
        $flaky = $engine->addTask('echo', ['params' => ['which' => 'flaky']]);
        $stolen = $engine->addTask('echo', ['params' => ['which' => 'stolen']]);
        $calls = 0;
        $engine->hooks['complete'] = static function (array $r) use ($flaky, $stolen, &$calls) {
            if ($r['taskId'] === $flaky['id'] && ++$calls === 1) {
                return FakeEngine::err(503, 'unavailable', 'try again');
            }
            if ($r['taskId'] === $stolen['id']) {
                return FakeEngine::err(409, 'conflict', 'lease changed');
            }

            return null;
        };
        $w = $this->worker($engine);
        $this->runUntilSettled($w, $engine, [$flaky, $stolen], 10.0, 0.6);
        $f = $engine->acks($flaky['id']);
        self::assertCount(2, $f);
        self::assertSame($f[0]['raw'], $f[1]['raw']);
        self::assertSame([503, 200], array_column($f, 'status'));
        $s = $engine->acks($stolen['id']);
        self::assertCount(1, $s);
        self::assertSame('complete', $s[0]['kind']);
    }

    public function testConcurrencyIsBoundedAndParallel(): void
    {
        $engine = new FakeEngine();
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $engine->addTask('slow', ['params' => ['sleep_ms' => 400]]);
        }
        $w = $this->worker($engine, ['concurrency' => 2]);
        $started = microtime(true);
        $this->runUntilSettled($w, $engine, $tasks, 15.0);
        $intervals = array_map(static fn ($t) => [$engine->tasks[$t['id']]['claimedAt'], $engine->acks($t['id'])[0]['at']], $tasks);
        $peak = 0;
        foreach ($intervals as [$s]) {
            $peak = max($peak, count(array_filter($intervals, static fn ($iv) => $iv[0] <= $s && $iv[1] > $s)));
        }
        self::assertSame(2, $peak);
        foreach ($engine->byKind('poll') as $p) {
            self::assertLessThanOrEqual(2 - $p['inFlight'], $p['body']['limit']);
        }
        self::assertLessThan(2.0, microtime(true) - $started, '5 × 400 ms with concurrency 2 must overlap');
    }

    public function testPollErrorsBackOffAndRecover(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('echo');
        $first = null;
        $engine->hooks['poll'] = static function () use (&$first) {
            $first ??= microtime(true);

            return microtime(true) - $first < 0.5 ? FakeEngine::err(503, 'unavailable', 'down') : null;
        };
        $w = $this->worker($engine, ['pollIntervalMs' => 50]);
        $this->runUntilSettled($w, $engine, [$task]);
        $failing = array_filter($engine->byKind('poll'), static fn ($p) => $p['status'] === 503);
        self::assertNotEmpty($failing);
        self::assertLessThanOrEqual(4 * 7, count($failing));
        self::assertSame('complete', $engine->acks($task['id'])[0]['kind']);
    }

    public function testEmptyPollHonoursPollAfterMs(): void
    {
        $engine = new FakeEngine(pollAfterMs: 400);
        $w = $this->worker($engine, ['pollIntervalMs' => 10]);
        $w->addLoopHook(new StopWhen(static fn () => false, 1.3));
        $w->run();
        $byHandler = [];
        foreach ($engine->byKind('poll') as $p) {
            $byHandler[$p['body']['handler_name']][] = $p['at'];
        }
        foreach ($byHandler as $name => $times) {
            self::assertGreaterThanOrEqual(2, count($times), $name);
            for ($i = 1; $i < count($times); $i++) {
                self::assertGreaterThanOrEqual(0.39, $times[$i] - $times[$i - 1], $name);
            }
        }
    }

    public function testQueueAndVersionArePolled(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('echo', [], 'gpu');
        $w = $this->worker($engine, ['queue' => 'gpu', 'version' => '2.3.4']);
        $this->runUntilSettled($w, $engine, [$task]);
        foreach ($engine->byKind('poll') as $p) {
            self::assertSame('/workers/tasks/poll/queue', $p['path']);
            self::assertSame('gpu', $p['body']['queue_name']);
            self::assertSame('2.3.4', $p['body']['version']);
        }
    }

    public function testGracefulStopDrainsInFlightWithoutNewPolls(): void
    {
        $engine = new FakeEngine(heartbeatIntervalSecs: 1);
        $task = $engine->addTask('slow', ['params' => ['sleep_ms' => 800]]);
        $w = $this->worker($engine);
        $stopAt = null;
        $w->addLoopHook(new StopWhen(static function () use ($engine, $task, &$stopAt): bool {
            if (isset($engine->tasks[$task['id']]) && $stopAt === null) {
                $stopAt = microtime(true);

                return true;
            }

            return false;
        }));
        $w->run();
        $acks = $engine->acks($task['id']);
        self::assertCount(1, $acks);
        self::assertSame('complete', $acks[0]['kind']);
        $late = array_filter($engine->byKind('poll'), static fn ($p) => $p['at'] > $stopAt + 0.05);
        self::assertSame([], $late);
    }

    public function testDrainTimeoutAbandonsTasksUnacknowledged(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('slow', ['params' => ['sleep_ms' => 5000]]);
        $w = $this->worker($engine, ['shutdownTimeoutMs' => 300]);
        $w->addLoopHook(new StopWhen(static fn () => isset($engine->tasks[$task['id']])));
        $start = microtime(true);
        $w->run();
        self::assertLessThan(2.0, microtime(true) - $start);
        self::assertSame([], $engine->acks($task['id']));
        self::assertSame(0, $w->inFlightCount());
    }

    public function testLocalTimeoutFailsRetryable(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('slow', ['params' => ['sleep_ms' => 5000], 'timeout_ms' => 500]);
        $w = $this->worker($engine);
        $this->runUntilSettled($w, $engine, [$task]);
        $ack = $engine->acks($task['id'])[0];
        self::assertSame('fail', $ack['kind']);
        self::assertTrue($ack['body']['retryable']);
        self::assertStringContainsString('timed out', $ack['body']['message']);
    }

    public function testPushClaimUsesQueueEndpointAndRunsUntilIdle(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('echo', ['params' => ['via' => 'push']], 'push-q');
        $w = $this->worker($engine, ['polling' => false]);
        self::assertFalse($w->claimFromPush(PushEnvelope::fromArray(['handler_name' => 'unregistered', 'queue_name' => 'push-q'])));
        self::assertTrue($w->claimFromPush(PushEnvelope::fromArray(['task_id' => $task['id'], 'handler_name' => 'echo', 'queue_name' => 'push-q'])));
        self::assertTrue($w->runUntilIdle(5));
        $polls = $engine->byKind('poll');
        self::assertCount(1, $polls);
        self::assertSame('/workers/tasks/poll/queue', $polls[0]['path']);
        self::assertSame(['handler_name' => 'echo', 'worker_id' => 'w-test', 'limit' => 1, 'queue_name' => 'push-q'], $polls[0]['body']);
        self::assertSame(['echo' => ['via' => 'push']], $engine->acks($task['id'])[0]['body']['output']);
    }

    public function testRegisteredJobClassRunsFromPayload(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('send_welcome_email', ['params' => ['userId' => 42, 'locale' => 'uk'], 'attempt' => 2]);
        $w = $this->worker($engine, ['polling' => false]);
        $w->registerJob(SendWelcomeEmail::class);
        $w->claim('send_welcome_email');
        self::assertTrue($w->runUntilIdle(5));
        self::assertSame(['sent_to' => 42, 'locale' => 'uk', 'attempt' => 2], $engine->acks($task['id'])[0]['body']['output']);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testForkedHandlerCrashIsReportedRetryable(): void
    {
        $engine = new FakeEngine();
        $task = $engine->addTask('fatal');
        $w = $this->worker($engine, ['polling' => false, 'executionMode' => ExecutionMode::Fork]);
        $w->handle('fatal', static function (): never {
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        });
        $w->claim('fatal');
        self::assertTrue($w->runUntilIdle(5));
        $ack = $engine->acks($task['id'])[0];
        self::assertSame('fail', $ack['kind']);
        self::assertTrue($ack['body']['retryable']);
        self::assertStringContainsString('exited unexpectedly', $ack['body']['message']);
    }
}
