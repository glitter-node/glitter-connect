<?php

namespace Glitter\Connect\Services;

class OutboundSignaturePolicy
{
    public static function requiresSecret(bool $signatureRequired, ?string $secret): bool
    {
        return $signatureRequired && ($secret === null || $secret === '');
    }

    public static function shouldSign(?string $secret): bool
    {
        return $secret !== null;
    }
}
