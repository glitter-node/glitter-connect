<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\OutboundSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OutboundSignatureTest extends TestCase
{
    public function test_v0_fixed_hmac_vector_keeps_legacy_header_contract(): void
    {
        self::assertSame(
            'sha256=9c1aa71c0e60d6afaa2609ba4a069a4c52a30e4c6e3e3f604128a29693fbccdf',
            OutboundSignature::sign('v0', '1700000000', 'delivery-key-001', '{"type":"test","n":1}', 'test-secret'),
        );
    }

    public function test_v1_fixed_hmac_vector_uses_version_timestamp_key_and_raw_body(): void
    {
        self::assertSame(
            'glitter-connect-v1'."\n".'1700000000'."\n".'delivery-key-001'."\n".'{"type":"test","n":1}',
            OutboundSignature::v1Input('1700000000', 'delivery-key-001', '{"type":"test","n":1}'),
        );
        self::assertSame(
            'v1=336610fcc2defdc394eab49b3745a61cdac0870eb8fdda75315bf8466f0beaef',
            OutboundSignature::sign('v1', '1700000000', 'delivery-key-001', '{"type":"test","n":1}', 'test-secret'),
        );
    }

    public function test_unsupported_version_fails_without_falling_back_to_v0(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutboundSignature::sign('v2', '1700000000', 'delivery-key-001', '{}', 'test-secret');
    }
}
