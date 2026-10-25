<?php

namespace Glitter\Connect\Jobs;

use Glitter\Connect\Services\DeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDeliveryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'glitter-connect:delivery:'.$this->deliveryId;
    }

    public function handle(DeliveryService $deliveryService): void
    {
        $deliveryService->deliver($this->deliveryId);
    }
}
