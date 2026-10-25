<?php

namespace Glitter\Connect\Services;

class InboundEndpointPolicy
{
    public static function allows(string $endpointStatus, ?int $connectionId, ?string $connectionStatus): bool
    {
        if ($endpointStatus !== 'active') return false;

        return $connectionId === null || $connectionStatus === 'active';
    }
}
