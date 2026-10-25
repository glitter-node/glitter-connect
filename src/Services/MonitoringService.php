<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Facades\DB;

class MonitoringService
{
    public function overview(): array
    {
        $counts = DB::table('glitter_connect_deliveries')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();

        return ['deliveries' => $counts, 'pending' => (int) ($counts['pending'] ?? 0), 'retry_scheduled' => (int) ($counts['retry_scheduled'] ?? 0), 'dead_letter' => (int) ($counts['dead_letter'] ?? 0), 'delivered' => (int) ($counts['delivered'] ?? 0), 'recent_errors' => DB::table('glitter_connect_deliveries')->whereNotNull('last_error')->orderByDesc('updated_at')->limit(10)->get(['id', 'response_code', 'last_error', 'updated_at'])];
    }
}
