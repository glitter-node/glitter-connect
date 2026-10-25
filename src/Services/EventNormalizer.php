<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Str;

class EventNormalizer
{
    public function normalize(array $payload, string $source, ?string $eventType = null): array
    {
        return [
            'spec_version' => '1.0',
            'event_id' => (string) ($payload['event_id'] ?? Str::uuid()),
            'event_type' => $eventType ?? (string) ($payload['event_type'] ?? 'glitter-connect.event.received'),
            'source' => $source,
            'subject' => isset($payload['subject']) ? (string) $payload['subject'] : null,
            'occurred_at' => $payload['occurred_at'] ?? null,
            'received_at' => now()->toISOString(),
            'correlation_id' => (string) ($payload['correlation_id'] ?? Str::uuid()),
            'schema_version' => (int) ($payload['schema_version'] ?? 1),
            'data' => $payload['data'] ?? $payload,
            'metadata' => $payload['metadata'] ?? [],
        ];
    }
}
