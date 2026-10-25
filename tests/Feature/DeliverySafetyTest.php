<?php

namespace Glitter\Connect\Tests\Feature;

use Glitter\Connect\Services\CredentialService;
use Glitter\Connect\Services\DeliveryService;
use Glitter\Connect\Services\OutboundTargetGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverySafetyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expired_event_with_unfinished_delivery_and_attempt_is_preserved(): void
    {
        foreach (['pending', 'processing', 'retry_scheduled'] as $status) {
            $fixture = $this->createFixture($status);
            DB::table('glitter_connect_attempts')->insert([
                'delivery_id' => $fixture['delivery'], 'attempt_number' => 1,
                'outcome' => $status, 'created_at' => now(),
            ]);
            DB::table('glitter_connect_events')->where('id', $fixture['event'])->update(['received_at' => now()->subDays(100)]);
        }

        $this->pruneExpiredEvents();

        self::assertSame(3, DB::table('glitter_connect_events')->count());
        self::assertSame(3, DB::table('glitter_connect_deliveries')->count());
        self::assertSame(3, DB::table('glitter_connect_attempts')->count());
    }

    public function test_event_with_only_terminal_deliveries_remains_eligible_for_retention_cleanup(): void
    {
        $fixtures = [];
        foreach (['delivered', 'cancelled', 'dead_letter'] as $status) {
            $fixture = $this->createFixture($status);
            $fixtures[] = $fixture;
            DB::table('glitter_connect_attempts')->insert([
                'delivery_id' => $fixture['delivery'], 'attempt_number' => 1,
                'outcome' => $status, 'created_at' => now(),
            ]);
            DB::table('glitter_connect_events')->where('id', $fixture['event'])->update(['received_at' => now()->subDays(100)]);
        }

        $this->pruneExpiredEvents();

        foreach ($fixtures as $fixture) {
            self::assertDatabaseMissing('glitter_connect_events', ['id' => $fixture['event']]);
            self::assertDatabaseMissing('glitter_connect_attempts', ['delivery_id' => $fixture['delivery']]);
        }
    }

    public function test_inactive_connection_cancels_delivery_without_http_request(): void
    {
        $fixture = $this->createFixture('pending');
        DB::table('glitter_connect_connections')->where('id', $fixture['connection'])->update(['status' => 'inactive']);
        Http::fake();

        self::assertTrue(app(DeliveryService::class)->deliver($fixture['delivery']));

        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $fixture['delivery'], 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_active_connection_without_configured_secret_keeps_unsigned_delivery_contract(): void
    {
        $fixture = $this->createFixture('pending');
        Http::fake(['https://delivery-test.invalid/hook' => Http::response('', 200)]);

        self::assertTrue(app(DeliveryService::class)->deliver($fixture['delivery']));

        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $fixture['delivery'], 'status' => 'delivered']);
        Http::assertSent(static fn ($request): bool => ! $request->hasHeader('X-Glitter-Signature'));
    }

    public function test_required_signature_with_missing_secret_is_dead_lettered_without_http_request(): void
    {
        $fixture = $this->createFixture('pending');
        DB::table('glitter_connect_connections')->where('id', $fixture['connection'])->update(['signature_required' => true]);
        Http::fake();

        self::assertTrue(app(DeliveryService::class)->deliver($fixture['delivery']));

        self::assertDatabaseHas('glitter_connect_deliveries', [
            'id' => $fixture['delivery'],
            'status' => 'dead_letter',
            'last_error' => 'Required signing secret is unavailable',
        ]);
        Http::assertNothingSent();
    }

    public function test_connection_deleted_during_missing_secret_lookup_does_not_send_unsigned_request(): void
    {
        $fixture = $this->createFixture('pending');
        Http::fake();
        $connectionId = $fixture['connection'];
        $this->instance(CredentialService::class, new class($connectionId) extends CredentialService {
            public function __construct(private readonly int $connectionId) {}

            public function get(int $connectionId, string $key): ?string
            {
                DB::table('glitter_connect_connections')->where('id', $this->connectionId)->delete();

                return null;
            }
        });

        self::assertTrue(app(DeliveryService::class)->deliver($fixture['delivery']));

        self::assertDatabaseHas('glitter_connect_deliveries', ['id' => $fixture['delivery'], 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    private function pruneExpiredEvents(): void
    {
        $method = new \ReflectionMethod(DeliveryService::class, 'pruneExpiredEvents');
        $method->invoke(app(DeliveryService::class), 90);
    }

    private function createFixture(string $deliveryStatus): array
    {
        $now = now();
        $connection = DB::table('glitter_connect_connections')->insertGetId([
            'name' => 'Delivery safety test', 'provider' => 'generic', 'status' => 'active',
            'config' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $subscription = DB::table('glitter_connect_subscriptions')->insertGetId([
            'connection_id' => $connection, 'event_type' => 'test.event',
            'target_url' => 'https://delivery-test.invalid/hook', 'status' => 'active',
            'config' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $event = DB::table('glitter_connect_events')->insertGetId([
            'event_id' => 'delivery-test-'.bin2hex(random_bytes(8)), 'event_type' => 'test.event',
            'source' => 'test', 'received_at' => $now, 'schema_version' => 1,
            'data' => '{}', 'metadata' => '{}', 'status' => 'accepted',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $delivery = DB::table('glitter_connect_deliveries')->insertGetId([
            'event_id' => $event, 'subscription_id' => $subscription, 'status' => $deliveryStatus,
            'attempt_count' => 0, 'idempotency_key' => bin2hex(random_bytes(32)),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->instance(OutboundTargetGuard::class, new class extends OutboundTargetGuard {
            public function resolvePublicTarget(string $url): array
            {
                return ['host' => 'delivery-test.invalid', 'ip' => '203.0.113.10', 'port' => 443];
            }
        });

        return ['connection' => $connection, 'event' => $event, 'delivery' => $delivery];
    }
}
