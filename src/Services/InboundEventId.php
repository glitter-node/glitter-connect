<?php

namespace Glitter\Connect\Services;

class InboundEventId
{
    public static function isValid(mixed $eventId): bool
    {
        if (! is_string($eventId) || preg_match('/\A[\s\p{Z}]*\z/u', $eventId) === 1) return false;

        return preg_match('/\A[^\p{Cc}]{1,100}\z/u', $eventId) === 1;
    }
}
