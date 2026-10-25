<?php

namespace Glitter\Connect\Services;

use InvalidArgumentException;

class OutboundSignature
{
    public static function sign(string $version, string $timestamp, string $idempotencyKey, string $rawBody, string $secret): string
    {
        return match ($version) {
            'v0' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret),
            'v1' => 'v1='.hash_hmac('sha256', self::v1Input($timestamp, $idempotencyKey, $rawBody), $secret),
            default => throw new InvalidArgumentException('Unsupported outbound signature version'),
        };
    }

    public static function v1Input(string $timestamp, string $idempotencyKey, string $rawBody): string
    {
        return "glitter-connect-v1\n".$timestamp."\n".$idempotencyKey."\n".$rawBody;
    }
}
