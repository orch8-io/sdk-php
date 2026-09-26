<?php

declare(strict_types=1);

namespace Orch8\Resource;

use Orch8\Http\Transport;
use Orch8\Model\Sequence;
use Orch8\Model\SequenceCreated;

final class Sequences
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * `POST /sequences`. The definition is sent as-is (pass `\stdClass` for
     * nested empty objects such as `"params": {}`).
     *
     * @param array<string, mixed>|object $definition
     */
    public function create(array|object $definition): SequenceCreated
    {
        return SequenceCreated::fromArray($this->transport->request('POST', '/sequences', $definition));
    }

    public function get(string $id): Sequence
    {
        return Sequence::fromArray($this->transport->request('GET', '/sequences/' . Transport::segment($id)));
    }

    /**
     * `GET /sequences?tenant_id&namespace&limit&offset` (any extra query keys are passed through).
     *
     * @param array<string, scalar|null> $query
     * @return list<Sequence>
     */
    public function list(array $query = []): array
    {
        $data = $this->transport->request('GET', '/sequences', null, $query);

        return array_map(Sequence::fromArray(...), ListShape::items($data, ['sequences']));
    }
}
