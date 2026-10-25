<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\InboundEndpointPolicy;
use PHPUnit\Framework\TestCase;

class InboundEndpointPolicyTest extends TestCase
{
    public function test_active_independent_endpoint_accepts_requests(): void
    {
        self::assertTrue(InboundEndpointPolicy::allows('active', null, null));
    }

    public function test_connected_endpoint_requires_both_endpoint_and_connection_active(): void
    {
        self::assertTrue(InboundEndpointPolicy::allows('active', 21, 'active'));
        self::assertFalse(InboundEndpointPolicy::allows('active', 21, 'inactive'));
        self::assertFalse(InboundEndpointPolicy::allows('active', 21, null));
        self::assertFalse(InboundEndpointPolicy::allows('inactive', 21, 'active'));
    }

    public function test_connection_reactivation_restores_only_an_active_endpoint(): void
    {
        self::assertTrue(InboundEndpointPolicy::allows('active', 21, 'active'));
        self::assertFalse(InboundEndpointPolicy::allows('inactive', 21, 'active'));
    }
}
