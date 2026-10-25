<?php

namespace Glitter\Connect\Services;

class InboundSignature
{
    public static function timestampIsFresh(string $timestamp, ?int $now = null): bool
    {
        if (! ctype_digit($timestamp)) return false;

        return abs(($now ?? time()) - (int) $timestamp) <= 300;
    }

    public static function v1Input(string $timestamp, string $idempotencyKey, string $body): string
    {
        return "glitter-connect-v1\n".$timestamp."\n".$idempotencyKey."\n".$body;
    }

    public static function verify(string $version, string $timestamp, string $idempotencyKey, string $body, string $signature, string $secret): bool
    {
        if ($version === 'v1') {
            if (! preg_match('/\Av1=[a-f0-9]{64}\z/D', $signature)) return false;
            $expected = 'v1='.hash_hmac('sha256', self::v1Input($timestamp, $idempotencyKey, $body), $secret);

            return hash_equals($expected, $signature);
        }

        if ($version !== 'v0') return false;
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return hash_equals($expected, preg_replace('/^sha256=/', '', $signature));
    }
}
