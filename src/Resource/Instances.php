<?php

declare(strict_types=1);

namespace Orch8\Resource;

use Orch8\Http\Transport;
use Orch8\Json;
use Orch8\Model\Instance;
use Orch8\Model\InstanceCreated;
use Orch8\Model\SignalSent;
use Orch8\Signal;

final class Instances
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * `POST /instances`. Body: `{sequence_id, tenant_id, namespace, context?,
     * idempotency_key?, ...}`; null values are omitted.
     *
     * @param array<string, mixed>|object $request
     */
    public function create(array|object $request): InstanceCreated
    {
        if (is_array($request)) {
            $request = array_filter($request, static fn ($v) => $v !== null);
        }

        return InstanceCreated::fromArray($this->transport->request('POST', '/instances', $request));
    }

    /**
     * Convenience wrapper around {@see create()}.
     *
     * @param array<string, mixed>|object|null $context e.g. `['data' => [...]]`
     * @param array<string, mixed> $extra any other CreateInstanceRequest fields
     */
    public function start(
        string $sequenceId,
        string $tenantId,
        string $namespace = 'default',
        array|object|null $context = null,
        ?string $idempotencyKey = null,
        array $extra = [],
    ): InstanceCreated {
        return $this->create([
            'sequence_id' => $sequenceId,
            'tenant_id' => $tenantId,
            'namespace' => $namespace,
            'context' => $context === null ? null : Json::object($context),
            'idempotency_key' => $idempotencyKey,
        ] + $extra);
    }

    public function get(string $id): Instance
    {
        return Instance::fromArray($this->transport->request('GET', '/instances/' . Transport::segment($id)));
    }

    /**
     * `GET /instances?tenant_id&namespace&sequence_id&state&limit&offset`.
     *
     * @param array<string, scalar|null> $query
     * @return list<Instance>
     */
    public function list(array $query = []): array
    {
        $data = $this->transport->request('GET', '/instances', null, $query);

        return array_map(Instance::fromArray(...), ListShape::items($data, ['instances']));
    }

    /**
     * `POST /instances/{id}/signals`.
     *
     * @param string|array{custom: string} $signalType `pause|resume|cancel|update_context` or {@see Signal::custom()}
     * @param mixed $payload omitted when null
     */
    public function signal(string $id, string|array $signalType, mixed $payload = null): SignalSent
    {
        $body = ['signal_type' => $signalType];
        if ($payload !== null) {
            $body['payload'] = $payload;
        }

        return SignalSent::fromArray(
            $this->transport->request('POST', '/instances/' . Transport::segment($id) . '/signals', $body),
        );
    }

    public function cancel(string $id): SignalSent
    {
        return $this->signal($id, Signal::CANCEL);
    }

    public function pause(string $id): SignalSent
    {
        return $this->signal($id, Signal::PAUSE);
    }

    public function resume(string $id): SignalSent
    {
        return $this->signal($id, Signal::RESUME);
    }
}
