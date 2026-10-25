<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Carbon;
use Throwable;

class RetryPolicyService
{
    public function nextAttempt(int $attempt, ?int $statusCode = null, ?string $retryAfter = null): ?Carbon
    {
        if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400) return null;
        if ($statusCode !== null && $statusCode >= 400 && $statusCode < 500 && ! in_array($statusCode, [408, 425, 429], true)) return null;
        if ($attempt >= (int) module_setting('glitter-connect', 'delivery.max_attempts', 12)) return null;
        if ($statusCode === 429 && $retryAfter !== null) {
            if (ctype_digit($retryAfter)) return now()->addSeconds(min(86400, (int) $retryAfter));
            try {
                $retryAt = Carbon::parse($retryAfter);
                if ($retryAt->isFuture()) return $retryAt->min(now()->addDay());
            } catch (Throwable) {
            }
        }

        return now()->addSeconds(min(21600, (2 ** min(10, $attempt)) * random_int(80, 120)));
    }
}
