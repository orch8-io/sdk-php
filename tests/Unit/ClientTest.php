<?php

declare(strict_types=1);

namespace Orch8\Tests\Unit;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Orch8\Client;
use Orch8\Exception\ApiException;
use Orch8\Exception\BadRequestException;
use Orch8\Exception\ConflictException;
use Orch8\Exception\ForbiddenException;
use Orch8\Exception\InvalidArgumentException;
use Orch8\Exception\NotFoundException;
use Orch8\Exception\Orch8Exception;
use Orch8\Exception\PayloadTooLargeException;
use Orch8\Exception\RateLimitedException;
use Orch8\Exception\ServerException;
use Orch8\Exception\TransportException;
use Orch8\Exception\UnauthorizedException;
use Orch8\Exception\UnprocessableEntityException;
use Orch8\Model\JobRetry;
use Orch8\Signal;
use Orch8\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const JOB = ['id' => 'job_1', 'instance_id' => 'inst_1', 'handler' => 'send_email', 'status' => 'scheduled',
        'created_at' => '2026-09-26T10:00:00Z', 'run_at' => '2026-09-26T10:05:00Z', 'extra_field' => ['x' => 1]];

    private function client(FakeHttpClient $http, int $maxAttempts = 3): Client
    {
        return new Client('http://engine.test/api/v1/', 'key-1', 'tenant-1', $http, maxAttempts: $maxAttempts, retryBaseDelayMs: 0);
    }

    public function testSendsAuthTenantAndJsonHeadersUnderVersionedBase(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, ['id' => 'seq-1', 'warnings' => ['w']])]);
        $created = $this->client($http)->sequences->create(['name' => 'onboarding', 'blocks' => []]);

        self::assertSame('seq-1', $created->id);
        self::assertSame(['w'], $created->warnings);
        $req = $http->last();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('http://engine.test/api/v1/sequences', (string) $req->getUri());
        self::assertSame('key-1', $req->getHeaderLine('x-api-key'));
        self::assertSame('tenant-1', $req->getHeaderLine('x-tenant-id'));
        self::assertSame('application/json', $req->getHeaderLine('content-type'));
        self::assertSame('{"name":"onboarding","blocks":[]}', (string) $req->getBody());
    }

    public function testGetRequestsCarryNoBodyOrContentType(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 's', 'name' => 'n', 'version' => 2, 'blocks' => [], 'unknown' => true])]);
        $seq = $this->client($http)->sequences->get('s');
        self::assertSame(2, $seq->version);
        self::assertTrue($seq->get('unknown'));
        self::assertSame('', (string) $http->last()->getBody());
        self::assertFalse($http->last()->hasHeader('content-type'));
    }

    public function testPathIdsAreEncodedAsOneSegment(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::JOB)]);
        $this->client($http)->jobs->get('a/b c');
        self::assertSame('/api/v1/jobs/a%2Fb%20c', $http->last()->getUri()->getPath());
    }

    public function testListQueryStringsDropNullsAndEncode(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [['id' => 'i1', 'state' => 'running']])]);
        $list = $this->client($http)->instances->list(['state' => 'running', 'limit' => 5, 'namespace' => null, 'sequence_id' => 'a b']);
        self::assertCount(1, $list);
        self::assertSame('running', $list[0]->state);
        self::assertSame('state=running&limit=5&sequence_id=a%20b', $http->last()->getUri()->getQuery());
    }

    public function testInstanceCreateSurfacesDeduplicatedAndOmitsNulls(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(201, ['id' => 'i1']),
            FakeHttpClient::json(200, ['id' => 'i1', 'deduplicated' => true]),
        ]);
        $c = $this->client($http);
        $first = $c->instances->start('seq', 'tenant-1', 'default', ['data' => ['u' => 1]]);
        $second = $c->instances->create(['sequence_id' => 'seq', 'tenant_id' => 't', 'namespace' => 'default', 'idempotency_key' => 'dup', 'context' => null]);
        self::assertFalse($first->deduplicated);
        self::assertTrue($second->deduplicated);
        self::assertSame(['sequence_id' => 'seq', 'tenant_id' => 'tenant-1', 'namespace' => 'default', 'context' => ['data' => ['u' => 1]]], $http->body(0));
        self::assertArrayNotHasKey('context', $http->body(1));
    }

    public function testSignalsAndCancel(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(201, ['signal_id' => 'sig-1']),
            FakeHttpClient::json(201, ['signal_id' => 'sig-2']),
            FakeHttpClient::json(201, ['signal_id' => 'sig-3']),
        ]);
        $c = $this->client($http);
        self::assertSame('sig-1', $c->instances->signal('i1', Signal::custom('approve'), ['by' => 'alice'])->signalId);
        $c->instances->cancel('i1');
        $c->instances->signal('i1', Signal::PAUSE);
        self::assertSame('{"signal_type":{"custom":"approve"},"payload":{"by":"alice"}}', (string) $http->requests[0]->getBody());
        self::assertSame('{"signal_type":"cancel"}', (string) $http->requests[1]->getBody());
        self::assertSame('{"signal_type":"pause"}', (string) $http->requests[2]->getBody());
        self::assertSame('/api/v1/instances/i1/signals', $http->requests[1]->getUri()->getPath());
    }

    public function testJobEnqueueMinimalBodyUsesObjectForEmptyPayload(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, self::JOB), FakeHttpClient::json(201, self::JOB)]);
        $c = $this->client($http);
        $job = $c->jobs->enqueue('send_email');
        $c->jobs->enqueue('send_email', ['to' => 'b@example.com']);
        self::assertSame('{"handler":"send_email","payload":{}}', (string) $http->requests[0]->getBody());
        self::assertSame('{"handler":"send_email","payload":{"to":"b@example.com"}}', (string) $http->requests[1]->getBody());
        self::assertSame('job_1', $job->id);
        self::assertSame('inst_1', $job->instanceId);
        self::assertSame('scheduled', $job->status);
        self::assertSame(['x' => 1], $job->get('extra_field'), 'unknown fields stay accessible');
        self::assertFalse($job->isDone());
    }

    public function testJobEnqueueFullBody(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, self::JOB), FakeHttpClient::json(201, self::JOB)]);
        $c = $this->client($http);
        $c->jobs->enqueue('send_email', ['to' => 'a'], queue: 'emails', priority: 5, retry: new JobRetry(4, 500, 10000),
            delayMs: 250, idempotencyKey: 'welcome-a', metadata: ['source' => 'signup']);
        $c->jobs->enqueue('send_email', [], runAt: new \DateTimeImmutable('2026-10-01T02:00:00+02:00'), retry: ['max_attempts' => 2, 'initial_backoff_ms' => 10], metadata: []);
        self::assertSame(
            '{"handler":"send_email","payload":{"to":"a"},"queue":"emails","priority":5,"retry":{"max_attempts":4,"initial_backoff_ms":500,"max_backoff_ms":10000},"delay_ms":250,"idempotency_key":"welcome-a","metadata":{"source":"signup"}}',
            (string) $http->requests[0]->getBody(),
        );
        self::assertSame(
            '{"handler":"send_email","payload":{},"retry":{"max_attempts":2,"initial_backoff_ms":10},"run_at":"2026-10-01T00:00:00Z","metadata":{}}',
            (string) $http->requests[1]->getBody(),
        );
    }

    public function testJobEnqueueRejectsDelayAndRunAtTogether(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeHttpClient())->jobs->enqueue('x', [], delayMs: 1, runAt: '2026-01-01T00:00:00Z');
    }

    /** @return iterable<string, array{mixed}> */
    public static function listShapes(): iterable
    {
        yield 'bare array' => [[self::JOB]];
        yield 'items' => [['items' => [self::JOB], 'next_cursor' => null]];
        yield 'jobs' => [['jobs' => [self::JOB]]];
    }

    #[DataProvider('listShapes')]
    public function testJobListToleratesShapes(mixed $body): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, $body)]);
        $jobs = $this->client($http)->jobs->list(['status' => 'scheduled', 'limit' => 20]);
        self::assertCount(1, $jobs);
        self::assertSame('job_1', $jobs[0]->id);
        self::assertSame('status=scheduled&limit=20', $http->last()->getUri()->getQuery());
    }

    public function testJobCancelAcceptsBodyOrEmpty(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['status' => 'cancelled'] + self::JOB), new Response(204)]);
        $c = $this->client($http);
        self::assertSame('cancelled', $c->jobs->cancel('job_1')?->status);
        self::assertNull($c->jobs->cancel('job_1'));
        self::assertSame('DELETE', $http->last()->getMethod());
    }

    /** @return iterable<string, array{int, string, class-string}> */
    public static function errorStatuses(): iterable
    {
        yield '400' => [400, 'invalid_argument', BadRequestException::class];
        yield '401' => [401, 'unauthorized', UnauthorizedException::class];
        yield '403' => [403, 'forbidden', ForbiddenException::class];
        yield '404' => [404, 'not_found', NotFoundException::class];
        yield '409' => [409, 'already_exists', ConflictException::class];
        yield '413' => [413, 'payload_too_large', PayloadTooLargeException::class];
        yield '422' => [422, 'unprocessable_entity', UnprocessableEntityException::class];
        yield '429' => [429, 'rate_limited', RateLimitedException::class];
        yield '503' => [503, 'unavailable', ServerException::class];
        yield '418' => [418, 'teapot', ApiException::class];
    }

    #[DataProvider('errorStatuses')]
    public function testTypedErrorsFromEnvelope(int $status, string $code, string $class): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error($status, $code, "msg {$status}")]);
        try {
            $this->client($http, 1)->jobs->enqueue('x');
            self::fail('expected exception');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertInstanceOf(Orch8Exception::class, $e);
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
            self::assertSame("msg {$status}", $e->getMessage());
            self::assertSame('req-1', $e->requestId);
        }
    }

    public function testErrorWithoutEnvelopeStillTyped(): void
    {
        $http = new FakeHttpClient([new Response(502, [], '<html>bad gateway</html>')]);
        try {
            $this->client($http, 1)->jobs->get('j');
            self::fail('expected exception');
        } catch (ServerException $e) {
            self::assertSame(502, $e->status);
            self::assertNull($e->errorCode);
            self::assertSame('HTTP 502', $e->getMessage());
        }
    }

    public function testSafeRequestsRetryTransientFailures(): void
    {
        $req = new Request('GET', 'http://engine.test');
        $http = new FakeHttpClient([
            FakeHttpClient::error(429, 'rate_limited', 'slow down'),
            FakeHttpClient::networkError($req),
            FakeHttpClient::json(200, self::JOB),
        ]);
        self::assertSame('job_1', $this->client($http)->jobs->get('job_1')->id);
        self::assertCount(3, $http->requests);
    }

    public function testSafeRequestsGiveUpAfterMaxAttempts(): void
    {
        $http = new FakeHttpClient(array_fill(0, 5, FakeHttpClient::error(503, 'unavailable', 'down')));
        try {
            $this->client($http, 3)->jobs->get('job_1');
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertCount(3, $http->requests);
        }
    }

    public function testSafeRequestsDoNotRetryOtherClientErrors(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error(404, 'not_found', 'nope'), FakeHttpClient::json(200, self::JOB)]);
        $this->expectException(NotFoundException::class);
        try {
            $this->client($http)->jobs->get('missing');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testUnsafeRequestsAreNeverReplayed(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error(503, 'unavailable', 'down'), FakeHttpClient::json(201, self::JOB)]);
        try {
            $this->client($http)->jobs->enqueue('x');
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testTransportFailureOnUnsafeRequest(): void
    {
        $http = new FakeHttpClient();
        $http->push(static fn ($r) => throw FakeHttpClient::networkError($r));
        $this->expectException(TransportException::class);
        $this->client($http)->jobs->enqueue('x');
    }

    public function testProtocolRelativePathIsRejected(): void
    {
        $http = new FakeHttpClient();
        try {
            $this->client($http)->request('GET', '//untrusted.test/path');
            self::fail('expected exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('invalid_path', $e->getMessage());
            self::assertCount(0, $http->requests);
        }
    }

    public function testEmptySuccessBodyMapsToNull(): void
    {
        $http = new FakeHttpClient([new Response(204)]);
        self::assertNull($this->client($http)->request('GET', '/anything'));
    }
}
