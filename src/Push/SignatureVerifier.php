<?php

declare(strict_types=1);

namespace Orch8\Push;

use Orch8\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * Verifies push-dispatch (and outbound webhook) signatures, WORKER_PROTOCOL §8.2:
 *
 *     X-Orch8-Signature: sha256=hex(HMAC-SHA256(secret, "<X-Orch8-Timestamp>." . rawBody))
 *
 * The MAC is computed over the exact raw body bytes and compared in constant
 * time; timestamps outside ±tolerance seconds are rejected.
 */
final class SignatureVerifier
{
    public const TIMESTAMP_HEADER = 'X-Orch8-Timestamp';
    public const SIGNATURE_HEADER = 'X-Orch8-Signature';
    public const DEFAULT_TOLERANCE_SECS = 300;

    /**
     * @param string      $secret     shared secret (UTF-8); empty is a configuration error
     * @param string|null $timestamp  X-Orch8-Timestamp header (null = absent)
     * @param string|null $signature  X-Orch8-Signature header (null = absent)
     * @param string      $rawBody    exact request body bytes
     * @param int|null    $now        Unix seconds (injectable clock); defaults to time()
     *
     * @throws InvalidArgumentException when $secret is empty
     */
    public static function verify(
        string $secret,
        ?string $timestamp,
        ?string $signature,
        string $rawBody,
        ?int $now = null,
        int $toleranceSecs = self::DEFAULT_TOLERANCE_SECS,
    ): bool {
        if ($secret === '') {
            throw new InvalidArgumentException('push signature secret must not be empty');
        }
        if ($timestamp === null || preg_match('/^-?\d{1,19}$/D', $timestamp) !== 1) {
            return false;
        }
        if ($signature === null || !str_starts_with($signature, 'sha256=')) {
            return false;
        }
        $hex = substr($signature, 7);
        if (preg_match('/^[0-9a-fA-F]{64}$/D', $hex) !== 1) {
            return false;
        }
        $ts = (int) $timestamp;
        $current = $now ?? time();
        if (abs($current - $ts) > $toleranceSecs) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, strtolower($hex));
    }

    /** Compute the header value `sha256=<hex>` (useful for tests and local tooling). */
    public static function sign(string $secret, string|int $timestamp, string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Verify a PSR-7 request. The body stream is read in full and rewound
     * when seekable.
     */
    public static function verifyRequest(
        RequestInterface $request,
        string $secret,
        ?int $now = null,
        int $toleranceSecs = self::DEFAULT_TOLERANCE_SECS,
    ): bool {
        return self::verify(
            $secret,
            self::header($request, self::TIMESTAMP_HEADER),
            self::header($request, self::SIGNATURE_HEADER),
            self::rawBody($request),
            $now,
            $toleranceSecs,
        );
    }

    /** @internal */
    public static function rawBody(RequestInterface $request): string
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = $stream->getContents();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $body;
    }

    private static function header(RequestInterface $request, string $name): ?string
    {
        return $request->hasHeader($name) ? $request->getHeaderLine($name) : null;
    }
}
