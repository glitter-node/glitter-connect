<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\OutboundSignaturePolicy;
use PHPUnit\Framework\TestCase;

class OutboundSignaturePolicyTest extends TestCase
{
    public function test_required_signature_rejects_a_missing_or_empty_secret(): void
    {
        self::assertTrue(OutboundSignaturePolicy::requiresSecret(true, null));
        self::assertTrue(OutboundSignaturePolicy::requiresSecret(true, ''));
        self::assertFalse(OutboundSignaturePolicy::requiresSecret(true, 'configured-secret'));
    }

    public function test_optional_signature_preserves_unsigned_and_signed_legacy_behavior(): void
    {
        self::assertFalse(OutboundSignaturePolicy::requiresSecret(false, null));
        self::assertFalse(OutboundSignaturePolicy::requiresSecret(false, 'configured-secret'));
        self::assertFalse(OutboundSignaturePolicy::shouldSign(null));
        self::assertTrue(OutboundSignaturePolicy::shouldSign('configured-secret'));
    }
}
