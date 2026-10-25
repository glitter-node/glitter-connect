<?php

namespace Glitter\Connect\Services;

class EventRouter
{
    public function route(array $event): int
    {
        return app(OutboxService::class)->record($event);
    }
}
