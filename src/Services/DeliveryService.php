<?php

namespace Glitter\Connect\Services;

use Glitter\Connect\Jobs\ProcessDeliveryJob;
use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Throwable;

class DeliveryService
{
    public const INVALID_V1_EVENT_ID_ERROR = 'Event ID is invalid for outbound v1 delivery';

    public function processPending(int $limit = 25): int
    {
        DB::table('glitter_connect_deliveries')->where('status', 'processing')->where('lease_expires_at', '<', now())->update(['status' => 'retry_scheduled', 'next_attempt_at' => now(), 'lease_expires_at' => null, 'updated_at' => now()]);
        $retentionDays = max(1, (int) module_setting('glitter-connect', 'delivery.retention_days', 90));
        $this->pruneExpiredEvents($retentionDays);
        $ids = DB::table('glitter_connect_deliveries')->whereIn('status', ['pending', 'retry_scheduled'])->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->pluck('id');
        $processed = 0;
        $registry = app(QueueWorkerRegistry::class);
        $placement = $registry->resolve(null, 'glitter-connect');
        $connection = $placement['connection'] ?? $registry->defaultConnection();
        $queue = $placement['queue'] ?? 'default';
        $driver = $registry->driverOf($connection);
        $useQueue = $placement['fallback'] === [] && $driver !== null && ! in_array($driver, ['sync', 'null'], true);
        foreach ($ids as $id) {
            if ($useQueue) {
                Bus::dispatch((new ProcessDeliveryJob((int) $id))->onConnection($connection)->onQueue($queue));
                $processed++;
            } elseif ($this->deliver((int) $id)) {
                $processed++;
            }
        }

        return $processed;
    }

    public function deliver(int $id): bool
    {
        $delivery = DB::transaction(function () use ($id) {
            $eventId = DB::table('glitter_connect_deliveries')->where('id', $id)->value('event_id');
            if ($eventId === null) return null;

            // Retention takes the Event lock before checking Delivery states.
            // Use the same order so cleanup cannot remove an Event as a Worker claims it.
            $event = DB::table('glitter_connect_events')->where('id', $eventId)->lockForUpdate()->first(['id']);
            if (! $event) return null;

            $row = DB::table('glitter_connect_deliveries')->where('id', $id)->whereIn('status', ['pending', 'retry_scheduled'])->lockForUpdate()->first();
            if (! $row || (int) $row->event_id !== (int) $eventId) return null;
            DB::table('glitter_connect_deliveries')->where('id', $id)->update(['status' => 'processing', 'lease_expires_at' => now()->addMinutes(2), 'updated_at' => now()]);

            return $row;
        });
        if (! $delivery) return false;
        $subscription = DB::table('glitter_connect_subscriptions')->find($delivery->subscription_id);
        $event = DB::table('glitter_connect_events')->find($delivery->event_id);
        $context = $subscription && $event ? $this->activeConnectionContext($subscription) : null;
        if (! $context) return $this->cancel($id);
        $attempt = (int) $delivery->attempt_count + 1;
        $started = microtime(true);
        if (OutboundSignaturePolicy::requiresSecret($context['signature_required'], $context['secret'])) {
            return $this->failPermanent($id, $attempt, $started, 'Required signing secret is unavailable');
        }
        $signatureVersion = $delivery->outbound_signature_version ?? 'v0';
        if ($signatureVersion === 'v1' && ! InboundEventId::isValid((string) $event->event_id)) {
            return $this->failPermanent($id, $attempt, $started, self::INVALID_V1_EVENT_ID_ERROR);
        }
        $responseCode = null;
        $error = null;
        $retryAfter = null;
        try {
            $target = app(OutboundTargetGuard::class)->resolvePublicTarget($subscription->target_url);
            $payload = json_encode([
                'spec_version' => '1.0',
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'source' => $event->source,
                'subject' => $event->subject,
                'occurred_at' => $event->occurred_at,
                'received_at' => $event->received_at,
                'correlation_id' => $event->correlation_id,
                'schema_version' => $event->schema_version,
                'data' => json_decode($event->data, true, 64, JSON_THROW_ON_ERROR),
                'metadata' => json_decode($event->metadata ?? '[]', true, 64, JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($payload) > 262144) throw new \RuntimeException('Outbound event too large');
            $timestamp = (string) time();
            $headers = ['Idempotency-Key' => $delivery->idempotency_key, 'X-Glitter-Event' => $event->event_type, 'X-Glitter-Timestamp' => $timestamp];
            $secret = $context['secret'];
            if (OutboundSignaturePolicy::shouldSign($secret)) {
                $headers['X-Glitter-Signature'] = OutboundSignature::sign(
                    $signatureVersion,
                    $timestamp,
                    $delivery->idempotency_key,
                    $payload,
                    $secret,
                );
            }
            $resolve = $target['host'].':'.$target['port'].':'.$target['ip'];
            // Recheck after DNS and credential lookup. A completed deletion or deactivation
            // must not turn a signed delivery into an unsigned request.
            if (! $this->connectionIsActive($subscription)) return $this->cancel($id);
            $response = Http::timeout(10)->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => [$resolve]],
                'on_headers' => static function ($response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (int) $length > 262144) throw new RequestException('Response body too large');
                },
            ])->withHeaders($headers)->withBody($payload, 'application/json')->post($subscription->target_url);
            $stream = $response->toPsrResponse()->getBody();
            $body = $stream->read(262145);
            $stream->close();
            if (strlen($body) > 262144) throw new RequestException('Response body too large');
            $responseCode = $response->status();
            $retryAfter = $response->header('Retry-After');
            if (! $response->successful()) $error = 'Remote endpoint returned HTTP '.$responseCode;
        } catch (Throwable $exception) {
            $error = substr(preg_replace('/https?:\/\/[^\s]+/i', '[redacted-url]', $exception->getMessage()) ?? 'Delivery failed', 0, 1000);
        }
        $next = app(RetryPolicyService::class)->nextAttempt($attempt, $responseCode, $retryAfter);
        $status = $error === null ? 'delivered' : ($next ? 'retry_scheduled' : 'dead_letter');
        DB::transaction(function () use ($id, $attempt, $responseCode, $error, $status, $next, $started) {
            DB::table('glitter_connect_attempts')->insert(['delivery_id' => $id, 'attempt_number' => $attempt, 'response_code' => $responseCode, 'outcome' => $status, 'error_redacted' => $error, 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'created_at' => now()]);
            DB::table('glitter_connect_deliveries')->where('id', $id)->update(['status' => $status, 'attempt_count' => $attempt, 'next_attempt_at' => $next, 'lease_expires_at' => null, 'response_code' => $responseCode, 'last_error' => $error, 'delivered_at' => $status === 'delivered' ? now() : null, 'updated_at' => now()]);
        });

        return true;
    }

    private function pruneExpiredEvents(int $retentionDays): void
    {
        DB::transaction(function () use ($retentionDays): void {
            $events = DB::table('glitter_connect_events')
                ->where('received_at', '<', now()->subDays($retentionDays))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('glitter_connect_deliveries')
                        ->whereColumn('glitter_connect_deliveries.event_id', 'glitter_connect_events.id')
                        ->whereIn('glitter_connect_deliveries.status', ['pending', 'processing', 'retry_scheduled']);
                })
                ->orderBy('id')
                ->limit(500)
                ->lockForUpdate()
                ->get(['id']);

            foreach ($events as $event) {
                // This is a locking/current read. Worker claims lock Event first, so a
                // concurrent claim either becomes visible here or waits until deletion.
                $hasUnfinishedDelivery = DB::table('glitter_connect_deliveries')
                    ->where('event_id', $event->id)
                    ->whereIn('status', ['pending', 'processing', 'retry_scheduled'])
                    ->lockForUpdate()
                    ->exists();

                if (! $hasUnfinishedDelivery) {
                    DB::table('glitter_connect_events')->where('id', $event->id)->delete();
                }
            }
        });
    }

    private function activeConnectionContext(object $subscription): ?array
    {
        return DB::transaction(function () use ($subscription): ?array {
            // ConnectionService::delete locks the Connection before cascading its
            // Subscription, so preserve that order here to avoid an inverse lock order.
            $connection = DB::table('glitter_connect_connections')
                ->where('id', $subscription->connection_id)
                ->lockForUpdate()
                ->first(['id', 'status']);
            if (! $connection || $connection->status !== 'active') return null;

            $currentSubscription = DB::table('glitter_connect_subscriptions')
                ->where('id', $subscription->id)
                ->where('connection_id', $connection->id)
                ->lockForUpdate()
                ->first(['id', 'status']);
            if (! $currentSubscription || $currentSubscription->status !== 'active') return null;

            return [
                'signature_required' => (bool) $connection->signature_required,
                'secret' => app(CredentialService::class)->get((int) $connection->id, 'api_secret'),
            ];
        });
    }

    private function connectionIsActive(object $subscription): bool
    {
        return DB::table('glitter_connect_subscriptions')
            ->join('glitter_connect_connections', 'glitter_connect_connections.id', '=', 'glitter_connect_subscriptions.connection_id')
            ->where('glitter_connect_subscriptions.id', $subscription->id)
            ->where('glitter_connect_subscriptions.status', 'active')
            ->where('glitter_connect_connections.status', 'active')
            ->exists();
    }

    private function cancel(int $id): bool
    {
        DB::table('glitter_connect_deliveries')->where('id', $id)->update([
            'status' => 'cancelled',
            'lease_expires_at' => null,
            'updated_at' => now(),
        ]);

        return true;
    }

    private function failPermanent(int $id, int $attempt, float $started, string $error): bool
    {
        DB::transaction(function () use ($id, $attempt, $started, $error): void {
            DB::table('glitter_connect_attempts')->insert([
                'delivery_id' => $id,
                'attempt_number' => $attempt,
                'response_code' => null,
                'outcome' => 'dead_letter',
                'error_redacted' => $error,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'created_at' => now(),
            ]);
            DB::table('glitter_connect_deliveries')->where('id', $id)->update([
                'status' => 'dead_letter',
                'attempt_count' => $attempt,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'response_code' => null,
                'last_error' => $error,
                'delivered_at' => null,
                'updated_at' => now(),
            ]);
        });

        return true;
    }
}
