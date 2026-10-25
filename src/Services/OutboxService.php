<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use RuntimeException;

class OutboxService
{
    public function record(array $event): int
    {
        return DB::transaction(function () use ($event): int {
            $existing = DB::table('glitter_connect_events')->where('source', $event['source'])->where('event_id', $event['event_id'])->value('id');
            if ($existing) return (int) $existing;
            $inserted = DB::table('glitter_connect_events')->insertOrIgnore([
                'event_id' => $event['event_id'], 'event_type' => $event['event_type'], 'source' => $event['source'], 'subject' => $event['subject'], 'occurred_at' => $event['occurred_at'] ? Carbon::parse($event['occurred_at'])->toDateTimeString() : null, 'received_at' => Carbon::parse($event['received_at'])->toDateTimeString(), 'correlation_id' => $event['correlation_id'], 'schema_version' => $event['schema_version'], 'data' => json_encode($event['data'], JSON_THROW_ON_ERROR), 'metadata' => json_encode($event['metadata'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                $existing = DB::table('glitter_connect_events')
                    ->select('id')
                    ->where('source', $event['source'])
                    ->where('event_id', $event['event_id'])
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) throw new \RuntimeException('Unable to record event.');

                return (int) $existing->id;
            }
            $eventId = (int) DB::table('glitter_connect_events')->where('source', $event['source'])->where('event_id', $event['event_id'])->value('id');
            $subscriptions = DB::table('glitter_connect_subscriptions')->where('event_type', $event['event_type'])->where('status', 'active')->get();
            foreach ($subscriptions as $subscription) {
                $key = hash('sha256', $event['source'].'|'.$event['event_id'].'|'.$subscription->id);
                $delivery = ['event_id' => $eventId, 'subscription_id' => $subscription->id, 'outbound_signature_version' => $subscription->outbound_signature_version ?? 'v0', 'status' => 'pending', 'attempt_count' => 0, 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now()];

                try {
                    DB::transaction(fn () => DB::table('glitter_connect_deliveries')->insert($delivery));
                } catch (QueryException $exception) {
                    if (! UniqueConstraintViolation::matches($exception)) throw $exception;

                    $existingDelivery = DB::table('glitter_connect_deliveries')
                        ->where('idempotency_key', $key)
                        ->lockForUpdate()
                        ->first(['event_id', 'subscription_id']);

                    if ($existingDelivery === null
                        || (int) $existingDelivery->event_id !== $eventId
                        || (int) $existingDelivery->subscription_id !== (int) $subscription->id
                    ) {
                        throw new RuntimeException('Unable to persist event delivery.');
                    }
                }
            }

            return $eventId;
        });
    }
}
