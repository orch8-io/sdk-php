<?php

declare(strict_types=1);

namespace Orch8\Exception;

/**
 * The engine answered with a 4xx/5xx status. `code`, `message` and
 * `request_id` are taken from the engine's error envelope
 * `{"error": {"code", "message", "request_id", "details"}}`.
 *
 * Use {@see ApiException::fromResponse()} to get the most specific subclass.
 */
class ApiException extends Orch8Exception
{
    private const MAP = [
        400 => BadRequestException::class,
        401 => UnauthorizedException::class,
        403 => ForbiddenException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        413 => PayloadTooLargeException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitedException::class,
    ];

    /** Statuses a transport may retry (fixtures/transport.json). */
    public const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
        public readonly mixed $details = null,
        public readonly string $rawBody = '',
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(int $status, string $body, ?string $requestIdHeader = null): self
    {
        $code = null;
        $message = null;
        $requestId = $requestIdHeader;
        $details = null;
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                $err = $decoded['error'];
                $code = is_string($err['code'] ?? null) ? $err['code'] : null;
                $message = is_string($err['message'] ?? null) ? $err['message'] : null;
                $requestId = is_string($err['request_id'] ?? null) ? $err['request_id'] : $requestId;
                $details = $err['details'] ?? null;
            }
        }
        $message ??= sprintf('HTTP %d', $status);
        $class = self::MAP[$status] ?? ($status >= 500 ? ServerException::class : self::class);

        return new $class($status, $code, $message, $requestId, $details, $body);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, self::RETRYABLE_STATUSES, true);
    }

    /** 404/409 on a worker mutation means the lease is gone (WORKER_PROTOCOL §5). */
    public function isLeaseLoss(): bool
    {
        return $this->status === 404 || $this->status === 409;
    }
}
