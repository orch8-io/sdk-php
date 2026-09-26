<?php

declare(strict_types=1);

namespace Orch8\Push;

use Orch8\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * Framework-neutral push receiver logic for PSR-7 stacks:
 *
 * ```php
 * $result = (new PushRequestHandler($secret))->handle($psrRequest);
 * // respond with $result->status (202 / 400 / 401) right away, then, if
 * // $result->envelope !== null, claim: $worker->claimFromPush($result->envelope)
 * ```
 */
final class PushRequestHandler
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSecs = SignatureVerifier::DEFAULT_TOLERANCE_SECS,
        /** @var (\Closure(): int)|null */
        private readonly ?\Closure $clock = null,
    ) {
        if ($secret === '') {
            throw new InvalidArgumentException('push signature secret must not be empty');
        }
    }

    public function handle(RequestInterface $request): PushResult
    {
        return $this->handleRaw(
            $request->hasHeader(SignatureVerifier::TIMESTAMP_HEADER) ? $request->getHeaderLine(SignatureVerifier::TIMESTAMP_HEADER) : null,
            $request->hasHeader(SignatureVerifier::SIGNATURE_HEADER) ? $request->getHeaderLine(SignatureVerifier::SIGNATURE_HEADER) : null,
            SignatureVerifier::rawBody($request),
        );
    }

    /** Same as {@see handle()} for non-PSR-7 servers. */
    public function handleRaw(?string $timestamp, ?string $signature, string $rawBody): PushResult
    {
        $now = $this->clock !== null ? ($this->clock)() : null;
        if (!SignatureVerifier::verify($this->secret, $timestamp, $signature, $rawBody, $now, $this->toleranceSecs)) {
            return new PushResult(401, null, 'invalid signature');
        }
        try {
            $envelope = PushEnvelope::fromJson($rawBody);
        } catch (InvalidArgumentException $e) {
            return new PushResult(400, null, $e->getMessage());
        }

        return new PushResult(202, $envelope, null);
    }
}
