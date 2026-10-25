<?php

namespace Modules\Glitter\Connect\Console;

use Glitter\Connect\Services\DeliveryService;
use Illuminate\Console\Command;

class DeliveryCommand extends Command
{
    protected $signature = 'glitter-connect:deliver {--limit=25}';

    protected $description = 'Deliver pending Glitter Connect events';

    public function handle(DeliveryService $deliveryService): int
    {
        $this->info((string) $deliveryService->processPending(max(1, min(100, (int) $this->option('limit')))));

        return self::SUCCESS;
    }
}
