<?php

declare(strict_types=1);

namespace Orch8\Tests\Unit;

use GuzzleHttp\Psr7\ServerRequest;
use Orch8\Exception\InvalidArgumentException;
use Orch8\Push\PushEnvelope;
use Orch8\Push\PushRequestHandler;
use Orch8\Push\SignatureVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/push_signatures.json'), true);
        foreach ($data['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('vectors')]
    public function testSharedSignatureVectors(array $v): void
    {
        self::assertSame($v['valid'], SignatureVerifier::verify(
            $v['secret'], $v['timestamp'], $v['signature'], $v['body'], $v['now'], $v['tolerance_secs'] ?? 300,
        ));
    }

    public function testEmptySecretIsAConfigurationError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SignatureVerifier::verify('', '1', 'sha256=' . str_repeat('0', 64), '{}');
    }

    public function testSignRoundTripsWithDefaultClock(): void
    {
        $ts = (string) time();
        self::assertTrue(SignatureVerifier::verify('s', $ts, SignatureVerifier::sign('s', $ts, '{"a":1}'), '{"a":1}'));
    }

    private function request(string $body, ?string $ts, ?string $sig): ServerRequest
    {
        $headers = ['content-type' => 'application/json'];
        if ($ts !== null) {
            $headers['X-Orch8-Timestamp'] = $ts;
        }
        if ($sig !== null) {
            $headers['X-Orch8-Signature'] = $sig;
        }

        return new ServerRequest('POST', 'http://worker.test/orch8/push', $headers, $body);
    }

    public function testPsr7HelperVerifiesRawBodyAndRewinds(): void
    {
        $body = '{"task_id":"t1","handler_name":"echo","queue_name":"push-q","params":{"x":1},"attempt":2,"timeout_ms":null}';
        $req = $this->request($body, '1767225600', SignatureVerifier::sign('sec', '1767225600', $body));
        self::assertTrue(SignatureVerifier::verifyRequest($req, 'sec', 1767225600));
        self::assertSame($body, (string) $req->getBody(), 'body stream must remain readable');
        self::assertFalse(SignatureVerifier::verifyRequest($req, 'sec', 1767225600 + 301));
    }

    public function testRequestHandlerStatuses(): void
    {
        $body = '{"task_id":"t1","instance_id":"i1","block_id":"b","handler_name":"echo","queue_name":"push-q","params":{"x":1},"context":{},"attempt":2,"timeout_ms":5000}';
        $ts = '1767225600';
        $handler = new PushRequestHandler('sec', 300, static fn (): int => 1767225600);

        $ok = $handler->handle($this->request($body, $ts, SignatureVerifier::sign('sec', $ts, $body)));
        self::assertSame(202, $ok->status);
        self::assertTrue($ok->accepted());
        self::assertSame('echo', $ok->envelope?->handlerName);
        self::assertSame('push-q', $ok->envelope?->queueName);
        self::assertSame('t1', $ok->envelope?->taskId);
        self::assertSame(2, $ok->envelope?->attempt);
        self::assertSame(5000, $ok->envelope?->timeoutMs);
        self::assertSame(['x' => 1], $ok->envelope?->params);

        self::assertSame(401, $handler->handle($this->request($body, $ts, null))->status);
        self::assertSame(401, $handler->handle($this->request($body, null, SignatureVerifier::sign('sec', $ts, $body)))->status);
        self::assertSame(401, $handler->handle($this->request($body . ' ', $ts, SignatureVerifier::sign('sec', $ts, $body)))->status);
        $bad = '{"task_id":"t1"}';
        self::assertSame(400, $handler->handle($this->request($bad, $ts, SignatureVerifier::sign('sec', $ts, $bad)))->status);
    }

    public function testEnvelopeRequiresHandlerAndQueue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PushEnvelope::fromJson('{"handler_name":"echo"}');
    }
}
