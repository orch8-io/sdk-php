<?php

declare(strict_types=1);

namespace Orch8\Tests\Unit;

use Orch8\Client;
use Orch8\Jobs\Dispatcher;
use Orch8\Tests\Support\FakeHttpClient;
use Orch8\Tests\Unit\Jobs\SendWelcomeEmail;
use PHPUnit\Framework\TestCase;

final class JobsTest extends TestCase
{
    private const JOB = ['id' => 'job_1', 'instance_id' => 'i', 'handler' => 'send_welcome_email', 'status' => 'scheduled', 'created_at' => 'now', 'run_at' => 'later'];

    protected function tearDown(): void
    {
        Dispatcher::setClient(null);
    }

    public function testHandlerNameDefaultsToSnakeCaseShortName(): void
    {
        self::assertSame('send_welcome_email', SendWelcomeEmail::handlerName());
    }

    public function testFluentDispatchEnqueuesOnDestruct(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, self::JOB)]);
        Dispatcher::setClient(new Client('http://engine.test/api/v1', 'k', 't', $http));

        SendWelcomeEmail::dispatch(42)->onQueue('emails')->delay(60)->priority(3)->idempotencyKey('welcome-42');

        self::assertCount(1, $http->requests);
        self::assertSame(
            '{"handler":"send_welcome_email","payload":{"userId":42,"locale":"en"},"queue":"emails","priority":3,"retry":{"max_attempts":3,"initial_backoff_ms":1000},"delay_ms":60000,"idempotency_key":"welcome-42"}',
            (string) $http->last()->getBody(),
        );
    }

    public function testExplicitSendReturnsJobAndDoesNotResend(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, self::JOB)]);
        $client = new Client('http://engine.test/api/v1', 'k', 't', $http);
        $pending = SendWelcomeEmail::dispatch(userId: 7, locale: 'uk')->via($client)->delay(new \DateTimeImmutable('2030-01-01T00:00:00Z'));
        $job = $pending->send();
        unset($pending);
        self::assertSame('job_1', $job->id);
        self::assertCount(1, $http->requests);
        self::assertSame('2030-01-01T00:00:00Z', $http->body(0)['run_at']);
        self::assertSame(['userId' => 7, 'locale' => 'uk'], $http->body(0)['payload']);
    }

    public function testPayloadRoundTrip(): void
    {
        $job = SendWelcomeEmail::fromPayload(['userId' => 5, 'locale' => 'pt']);
        self::assertSame(['userId' => 5, 'locale' => 'pt'], $job->toPayload());
    }

    public function testDispatchIfFalseSendsNothing(): void
    {
        $http = new FakeHttpClient();
        Dispatcher::setClient(new Client('http://engine.test/api/v1', 'k', 't', $http));
        self::assertNull(SendWelcomeEmail::dispatchIf(false, 1));
        self::assertCount(0, $http->requests);
    }
}
