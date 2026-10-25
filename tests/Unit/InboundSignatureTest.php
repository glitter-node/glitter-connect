<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\InboundSignature;
use Glitter\Connect\Services\InboundEventId;
use PHPUnit\Framework\TestCase;

class InboundSignatureTest extends TestCase
{
    public function test_timestamp_window_keeps_the_inclusive_three_hundred_second_boundary(): void
    {
        self::assertTrue(InboundSignature::timestampIsFresh('1700000000', 1700000300));
        self::assertTrue(InboundSignature::timestampIsFresh('1700000000', 1699999700));
        self::assertFalse(InboundSignature::timestampIsFresh('1700000000', 1700000301));
        self::assertFalse(InboundSignature::timestampIsFresh('1700000000', 1699999699));
        self::assertFalse(InboundSignature::timestampIsFresh('not-a-timestamp', 1700000000));
    }

    public function test_v1_input_uses_the_exact_documented_byte_sequence(): void
    {
        self::assertSame("glitter-connect-v1\n1700000000\nrequest-42\n{\"event_id\":\"evt-1\"}", InboundSignature::v1Input('1700000000', 'request-42', '{"event_id":"evt-1"}'));
    }

    public function test_v1_accepts_only_a_lowercase_prefixed_sha256_hmac(): void
    {
        $timestamp = '1700000000';
        $key = 'request-42';
        $body = '{"event_id":"evt-1"}';
        $secret = 'unit-test-secret';
        $signature = 'v1='.hash_hmac('sha256', InboundSignature::v1Input($timestamp, $key, $body), $secret);

        self::assertTrue(InboundSignature::verify('v1', $timestamp, $key, $body, $signature, $secret));
        self::assertFalse(InboundSignature::verify('v1', $timestamp, 'request-43', $body, $signature, $secret));
        self::assertFalse(InboundSignature::verify('v1', $timestamp, $key, $body.' ', $signature, $secret));
        self::assertFalse(InboundSignature::verify('v1', $timestamp, $key, $body, strtoupper($signature), $secret));
        self::assertFalse(InboundSignature::verify('v1', $timestamp, $key, $body, substr($signature, 3), $secret));
        self::assertFalse(InboundSignature::verify('v1', $timestamp, $key, $body, 'sha256='.substr($signature, 3), $secret));
    }

    public function test_v1_does_not_fall_back_to_the_v0_signature_contract(): void
    {
        $timestamp = '1700000000';
        $body = '{"event_id":"evt-1"}';
        $v0Signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'unit-test-secret');

        self::assertFalse(InboundSignature::verify('v1', $timestamp, 'request-42', $body, $v0Signature, 'unit-test-secret'));
    }

    public function test_v0_signature_contract_remains_unchanged(): void
    {
        $timestamp = '1700000000';
        $body = '{"event_id":"evt-1"}';
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'unit-test-secret');

        self::assertTrue(InboundSignature::verify('v0', $timestamp, 'ignored-by-v0', $body, $signature, 'unit-test-secret'));
    }

    public function test_v1_event_id_accepts_uuid_ulid_and_general_sender_ids(): void
    {
        self::assertTrue(InboundEventId::isValid('550e8400-e29b-41d4-a716-446655440000'));
        self::assertTrue(InboundEventId::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertTrue(InboundEventId::isValid('partner/order-4821'));
        self::assertTrue(InboundEventId::isValid(str_repeat('x', 100)));
    }

    public function test_v1_event_id_rejects_blank_control_and_overlong_values(): void
    {
        self::assertFalse(InboundEventId::isValid(''));
        self::assertFalse(InboundEventId::isValid(" \t\n"));
        self::assertFalse(InboundEventId::isValid("event\nnext"));
        self::assertFalse(InboundEventId::isValid("event\0id"));
        self::assertFalse(InboundEventId::isValid(str_repeat('x', 101)));
        self::assertFalse(InboundEventId::isValid(42));
    }
}
