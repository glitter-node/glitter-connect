<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use RuntimeException;
use Glitter\Connect\Exceptions\InboundConflictException;

class InboundService
{
    public function accept(string $key, string $body, string $signature, string $timestamp, string $idempotencyKey): int
    {
        return DB::transaction(fn (): int => $this->acceptWithinTransaction($key, $body, $signature, $timestamp, $idempotencyKey));
    }

    private function acceptWithinTransaction(string $key, string $body, string $signature, string $timestamp, string $idempotencyKey): int
    {
        if (strlen($body) > 262144) throw new RuntimeException('Payload too large');
        if (! InboundSignature::timestampIsFresh($timestamp)) throw new RuntimeException('Invalid timestamp');
        $endpoint = $this->lockReceivingEndpoint($key);
        if (strlen($body) > (int) $endpoint->max_payload_bytes) throw new RuntimeException('Endpoint unavailable');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 160) throw new RuntimeException('Invalid idempotency key');
        $version = $endpoint->signature_version ?? 'v0';
        if (! in_array($version, ['v0', 'v1'], true)) throw new RuntimeException('Unsupported signature version');
        if ($version === 'v1' && ! preg_match('/\A[A-Za-z0-9._~-]{1,160}\z/D', $idempotencyKey)) throw new RuntimeException('Invalid idempotency key');
        $secret = Crypt::decryptString($endpoint->secret_encrypted);
        if (! InboundSignature::verify($version, $timestamp, $idempotencyKey, $body, $signature, $secret)) throw new RuntimeException('Invalid signature');

        $bodyHash = hash('sha256', $body);
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) throw new RuntimeException('Invalid payload');
        if ($version === 'v1' && ! InboundEventId::isValid($payload['event_id'] ?? null)) throw new RuntimeException('Invalid event id');

        $existing = DB::table('glitter_connect_inbound_receipts')
            ->select(['event_id', 'body_sha256'])
            ->where('endpoint_id', $endpoint->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) return $this->reuseReceipt($existing, $bodyHash, $version);

        $event = app(EventNormalizer::class)->normalize($payload, 'endpoint:'.$key);
        try {
            $eventId = DB::transaction(function () use ($endpoint, $idempotencyKey, $bodyHash, $event) {
                DB::table('glitter_connect_inbound_receipts')->insert(['endpoint_id' => $endpoint->id, 'idempotency_key' => $idempotencyKey, 'event_id' => null, 'body_sha256' => $bodyHash, 'received_at' => now()]);
                $eventId = app(EventRouter::class)->route($event);
                DB::table('glitter_connect_inbound_receipts')->where('endpoint_id', $endpoint->id)->where('idempotency_key', $idempotencyKey)->update(['event_id' => $eventId]);

                return $eventId;
            });
        } catch (QueryException $exception) {
            $existing = DB::table('glitter_connect_inbound_receipts')
                ->select(['event_id', 'body_sha256'])
                ->where('endpoint_id', $endpoint->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing === null) throw $exception;
            $eventId = $this->reuseReceipt($existing, $bodyHash, $version);
        }

        return $eventId;
    }

    private function lockReceivingEndpoint(string $key): object
    {
        $reference = DB::table('glitter_connect_endpoints')
            ->select(['id', 'connection_id'])
            ->where('endpoint_key', $key)
            ->first();
        if ($reference === null) throw new RuntimeException('Endpoint unavailable');

        $connectionId = $reference->connection_id === null ? null : (int) $reference->connection_id;
        $connectionStatus = null;
        if ($connectionId !== null) {
            $connection = DB::table('glitter_connect_connections')
                ->select(['status'])
                ->where('id', $connectionId)
                ->sharedLock()
                ->first();
            if ($connection === null) throw new RuntimeException('Endpoint unavailable');
            $connectionStatus = $connection->status;
        }

        $endpoint = DB::table('glitter_connect_endpoints')
            ->where('id', $reference->id)
            ->where('endpoint_key', $key)
            ->sharedLock()
            ->first();
        if ($endpoint === null
            || ($endpoint->connection_id === null ? null : (int) $endpoint->connection_id) !== $connectionId
            || ! InboundEndpointPolicy::allows($endpoint->status, $connectionId, $connectionStatus)
        ) {
            throw new RuntimeException('Endpoint unavailable');
        }

        return $endpoint;
    }

    private function reuseReceipt(object $receipt, string $bodyHash, string $version): int
    {
        if ($receipt->body_sha256 === null) {
            if ($version === 'v0' && $receipt->event_id !== null) return (int) $receipt->event_id;
            throw new InboundConflictException();
        }

        if (! hash_equals((string) $receipt->body_sha256, $bodyHash) || $receipt->event_id === null) throw new InboundConflictException();

        return (int) $receipt->event_id;
    }
}
