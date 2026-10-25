<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\DB;

class ReplayService
{
    public function replay(int $deliveryId, ?int $actorId): void
    {
        DB::transaction(function () use ($deliveryId, $actorId) {
            $delivery = DB::table('glitter_connect_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            abort_if($delivery?->last_error === DeliveryService::INVALID_V1_EVENT_ID_ERROR, 409);
            abort_unless($delivery && in_array($delivery->status, ['dead_letter', 'retry_scheduled', 'delivered'], true), 404);
            DB::table('glitter_connect_replay_requests')->insert(['delivery_id' => $deliveryId, 'requested_by' => $actorId, 'requested_at' => now(), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('glitter_connect_deliveries')->where('id', $deliveryId)->update(['status' => 'pending', 'next_attempt_at' => null, 'last_error' => null, 'updated_at' => now()]);
        });
    }
}
