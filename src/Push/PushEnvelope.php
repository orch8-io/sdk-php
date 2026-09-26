<?php

declare(strict_types=1);

namespace Orch8\Push;

use Orch8\Exception\InvalidArgumentException;

/**
 * The push-dispatch body (WORKER_PROTOCOL §8.1). It carries no claim epoch:
 * a push is only a wake-up. Claim the work with
 * {@see \Orch8\Worker\Worker::claimFromPush()} (`POST /workers/tasks/poll/queue`).
 */
final class PushEnvelope
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $taskId,
        public readonly string $handlerName,
        public readonly string $queueName,
        public readonly ?string $instanceId = null,
        public readonly ?string $blockId = null,
        public readonly mixed $params = null,
        public readonly mixed $context = null,
        public readonly int $attempt = 0,
        public readonly ?int $timeoutMs = null,
        public readonly array $raw = [],
    ) {
    }

    /** @throws InvalidArgumentException on malformed JSON or missing handler/queue */
    public static function fromJson(string $rawBody): self
    {
        try {
            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('push body is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('push body must be a JSON object');
        }

        return self::fromArray($data);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $handler = $data['handler_name'] ?? null;
        $queue = $data['queue_name'] ?? null;
        if (!is_string($handler) || $handler === '' || !is_string($queue) || $queue === '') {
            throw new InvalidArgumentException('push envelope requires handler_name and queue_name');
        }

        return new self(
            taskId: (string) ($data['task_id'] ?? ''),
            handlerName: $handler,
            queueName: $queue,
            instanceId: isset($data['instance_id']) ? (string) $data['instance_id'] : null,
            blockId: isset($data['block_id']) ? (string) $data['block_id'] : null,
            params: $data['params'] ?? null,
            context: $data['context'] ?? null,
            attempt: (int) ($data['attempt'] ?? 0),
            timeoutMs: isset($data['timeout_ms']) ? (int) $data['timeout_ms'] : null,
            raw: $data,
        );
    }
}
