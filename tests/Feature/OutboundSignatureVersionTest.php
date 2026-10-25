<?php

namespace Glitter\Connect\Tests\Feature;

use Glitter\Connect\Http\Controllers\ConnectController;
use Glitter\Connect\Services\CredentialService;
use Glitter\Connect\Services\DeliveryService;
use Glitter\Connect\Services\OutboundSignature;
use Glitter\Connect\Services\OutboundTargetGuard;
use Glitter\Connect\Services\OutboxService;
use Glitter\Connect\Services\ReplayService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OutboundSignatureVersionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(OutboundTargetGuard::class, new class extends OutboundTargetGuard {
            public function resolvePublicTarget(string $url): array
            {
                return ['host' => 'receiver.test.invalid', 'ip' => '203.0.113.10', 'port' => 443];
            }
        });
    }

    public function test_subscription_and_delivery_schema_defaults_preserve_v0_rows(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, null);
        $eventId = $this->createEvent();
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, null, 'pending');

        self::assertSame('v0', DB::table('glitter_connect_subscriptions')->where('id', $subscriptionId)->value('outbound_signature_version'));
        self::assertSame('v0', DB::table('glitter_connect_deliveries')->where('id', $deliveryId)->value('outbound_signature_version'));
    }

    public function test_new_subscription_api_defaults_to_v1_and_allows_explicit_v0(): void
    {
        $connectionId = $this->createConnection();
        $controller = app(ConnectController::class);

        $defaultResponse = $controller->createSubscription(
            Request::create('/', 'POST', ['connection_id' => $connectionId, 'event_type' => 'default.event', 'target_url' => 'https://receiver.test.invalid/default']),
            app(OutboundTargetGuard::class),
        );
        $legacyResponse = $controller->createSubscription(
            Request::create('/', 'POST', ['connection_id' => $connectionId, 'event_type' => 'legacy.event', 'target_url' => 'https://receiver.test.invalid/legacy', 'outbound_signature_version' => 'v0']),
            app(OutboundTargetGuard::class),
        );

        self::assertSame(201, $defaultResponse->getStatusCode());
        self::assertSame(201, $legacyResponse->getStatusCode());
        self::assertSame(1, DB::table('glitter_connect_subscriptions')->where('event_type', 'default.event')->where('outbound_signature_version', 'v1')->count());
        self::assertSame(1, DB::table('glitter_connect_subscriptions')->where('event_type', 'legacy.event')->where('outbound_signature_version', 'v0')->count());
    }

    public function test_subscription_update_accepts_v0_or_v1_and_rejects_other_versions(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v1');
        $controller = app(ConnectController::class);

        $controller->updateSubscription(
            Request::create('/', 'PATCH', ['outbound_signature_version' => 'v0']),
            $subscriptionId,
            app(OutboundTargetGuard::class),
        );
        self::assertSame('v0', DB::table('glitter_connect_subscriptions')->where('id', $subscriptionId)->value('outbound_signature_version'));

        try {
            $controller->updateSubscription(
                Request::create('/', 'PATCH', ['outbound_signature_version' => 'v2']),
                $subscriptionId,
                app(OutboundTargetGuard::class),
            );
            self::fail('Unsupported signature version was accepted.');
        } catch (ValidationException) {
            self::assertSame('v0', DB::table('glitter_connect_subscriptions')->where('id', $subscriptionId)->value('outbound_signature_version'));
        }
    }

    public function test_outbox_snapshots_subscription_version_and_existing_delivery_does_not_change(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v1');
        $eventId = app(OutboxService::class)->record($this->eventPayload('snapshot-event'));
        $delivery = DB::table('glitter_connect_deliveries')->where('event_id', $eventId)->where('subscription_id', $subscriptionId)->first();

        self::assertSame('v1', $delivery->outbound_signature_version);
        DB::table('glitter_connect_subscriptions')->where('id', $subscriptionId)->update(['outbound_signature_version' => 'v0']);
        self::assertSame('v1', DB::table('glitter_connect_deliveries')->where('id', $delivery->id)->value('outbound_signature_version'));
    }

    public function test_replay_keeps_the_delivery_signature_snapshot(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v0');
        $eventId = $this->createEvent();
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, 'v1', 'delivered');

        app(ReplayService::class)->replay($deliveryId, null);

        self::assertDatabaseHas('glitter_connect_deliveries', [
            'id' => $deliveryId,
            'status' => 'pending',
            'outbound_signature_version' => 'v1',
        ]);
    }

    public function test_retry_sends_exact_same_body_bytes_that_v1_signs_without_v0_fallback(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v1');
        $eventId = $this->createEvent();
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, 'v1', 'pending');
        app(CredentialService::class)->put($connectionId, 'api_secret', 'delivery-test-secret');
        Http::fake(['https://receiver.test.invalid/hook' => Http::sequence()->push('', 500)->push('', 200)]);

        app(DeliveryService::class)->deliver($deliveryId);
        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $deliveryId, 'status' => 'retry_scheduled', 'outbound_signature_version' => 'v1']);
        app(DeliveryService::class)->deliver($deliveryId);

        Http::assertSent(function ($request): bool {
            $signature = $request->header('X-Glitter-Signature')[0] ?? '';
            $timestamp = $request->header('X-Glitter-Timestamp')[0] ?? '';
            $idempotencyKey = $request->header('Idempotency-Key')[0] ?? '';
            $body = $request->body();

            return str_starts_with($signature, 'v1=')
                && ! str_starts_with($signature, 'sha256=')
                && hash_equals($signature, OutboundSignature::sign('v1', $timestamp, $idempotencyKey, $body, 'delivery-test-secret'));
        });
        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $deliveryId, 'status' => 'delivered', 'outbound_signature_version' => 'v1']);
    }

    public function test_v1_invalid_event_id_is_dead_lettered_without_request_or_retry(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v1');
        $eventId = $this->createEvent('');
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, 'v1', 'pending');
        app(CredentialService::class)->put($connectionId, 'api_secret', 'delivery-test-secret');
        Http::fake();

        self::assertTrue(app(DeliveryService::class)->deliver($deliveryId));

        self::assertDatabaseHas('glitter_connect_deliveries', [
            'id' => $deliveryId,
            'status' => 'dead_letter',
            'attempt_count' => 1,
            'last_error' => 'Event ID is invalid for outbound v1 delivery',
        ]);
        self::assertDatabaseHas('glitter_connect_attempts', [
            'delivery_id' => $deliveryId,
            'attempt_number' => 1,
            'outcome' => 'dead_letter',
            'error_redacted' => 'Event ID is invalid for outbound v1 delivery',
        ]);
        Http::assertNothingSent();
    }

    public function test_permanent_v1_event_id_error_cannot_be_manually_replayed(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v1');
        $eventId = $this->createEvent('');
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, 'v1', 'dead_letter');
        DB::table('glitter_connect_deliveries')->where('id', $deliveryId)->update([
            'last_error' => DeliveryService::INVALID_V1_EVENT_ID_ERROR,
        ]);

        try {
            app(ReplayService::class)->replay($deliveryId, null);
            self::fail('A permanent v1 event ID error was replayed.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        self::assertDatabaseHas('glitter_connect_deliveries', [
            'id' => $deliveryId,
            'status' => 'dead_letter',
            'last_error' => DeliveryService::INVALID_V1_EVENT_ID_ERROR,
        ]);
        self::assertDatabaseMissing('glitter_connect_replay_requests', ['delivery_id' => $deliveryId]);
    }

    public function test_v0_outbound_preserves_legacy_event_id_payload_behavior(): void
    {
        $connectionId = $this->createConnection();
        $subscriptionId = $this->createSubscription($connectionId, 'v0');
        $eventId = $this->createEvent('');
        $deliveryId = $this->createDelivery($eventId, $subscriptionId, 'v0', 'pending');
        app(CredentialService::class)->put($connectionId, 'api_secret', 'delivery-test-secret');
        Http::fake(['https://receiver.test.invalid/hook' => Http::response('', 200)]);

        self::assertTrue(app(DeliveryService::class)->deliver($deliveryId));

        Http::assertSent(static function ($request): bool {
            $payload = json_decode($request->body(), true);
            $signature = $request->header('X-Glitter-Signature')[0] ?? '';

            return is_array($payload)
                && ($payload['event_id'] ?? null) === ''
                && str_starts_with($signature, 'sha256=');
        });
        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $deliveryId, 'status' => 'delivered']);
    }

    public function test_connection_and_endpoint_update_responses_include_their_policy_fields(): void
    {
        $controller = app(ConnectController::class);
        $connectionResponse = $controller->createConnection(
            Request::create('/', 'POST', ['name' => 'Response contract', 'signature_required' => false]),
            app(\Glitter\Connect\Services\ConnectionService::class),
        );
        $connection = $connectionResponse->getData(true)['data'];

        self::assertArrayHasKey('signature_required', $connection);
        self::assertSame(0, (int) $connection['signature_required']);
        $connectionList = $controller->connections(Request::create('/', 'GET'), app(\Glitter\Connect\Services\ConnectionService::class))->getData(true)['data']['data'];
        self::assertArrayHasKey('signature_required', collect($connectionList)->firstWhere('id', $connection['id']));

        $connectionUpdate = $controller->updateConnection(
            Request::create('/', 'PATCH', ['name' => 'Response contract updated']),
            (int) $connection['id'],
            app(\Glitter\Connect\Services\ConnectionService::class),
        )->getData(true)['data'];
        self::assertArrayHasKey('signature_required', $connectionUpdate);
        self::assertSame(0, (int) $connectionUpdate['signature_required']);

        $endpointCreate = $controller->createEndpoint(Request::create('/', 'POST', [
            'connection_id' => $connection['id'], 'name' => 'Response endpoint',
        ]))->getData(true)['data'];
        self::assertSame('v1', $endpointCreate['signature_version']);
        $endpointList = $controller->endpoints(Request::create('/', 'GET'))->getData(true)['data']['data'];
        self::assertSame('v1', collect($endpointList)->firstWhere('id', $endpointCreate['id'])['signature_version']);
        $endpointUpdate = $controller->updateEndpoint(
            Request::create('/', 'PATCH', ['status' => 'inactive']),
            (int) $endpointCreate['id'],
        )->getData(true)['data'];

        self::assertArrayHasKey('signature_version', $endpointUpdate);
        self::assertSame('v1', $endpointUpdate['signature_version']);
    }

    private function createConnection(): int
    {
        return DB::table('glitter_connect_connections')->insertGetId([
            'name' => 'Signature version test', 'provider' => 'generic', 'status' => 'active',
            'signature_required' => true, 'config' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createSubscription(int $connectionId, ?string $version): int
    {
        $row = [
            'connection_id' => $connectionId, 'event_type' => 'signature.test',
            'target_url' => 'https://receiver.test.invalid/hook', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($version !== null) $row['outbound_signature_version'] = $version;

        return DB::table('glitter_connect_subscriptions')->insertGetId($row);
    }

    private function createEvent(?string $eventId = null): int
    {
        return DB::table('glitter_connect_events')->insertGetId([
            'event_id' => $eventId ?? 'test-event-'.bin2hex(random_bytes(8)), 'event_type' => 'signature.test',
            'source' => 'test', 'received_at' => now(), 'schema_version' => 1,
            'data' => '{"value":1}', 'metadata' => '{}', 'status' => 'accepted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createDelivery(int $eventId, int $subscriptionId, ?string $version, string $status): int
    {
        $row = [
            'event_id' => $eventId, 'subscription_id' => $subscriptionId, 'status' => $status,
            'attempt_count' => 0, 'idempotency_key' => bin2hex(random_bytes(32)),
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($version !== null) $row['outbound_signature_version'] = $version;

        return DB::table('glitter_connect_deliveries')->insertGetId($row);
    }

    private function eventPayload(string $eventId): array
    {
        return [
            'event_id' => $eventId, 'event_type' => 'signature.test', 'source' => 'test',
            'subject' => null, 'occurred_at' => null, 'received_at' => now(),
            'correlation_id' => null, 'schema_version' => 1,
            'data' => ['value' => 1], 'metadata' => [],
        ];
    }
}
