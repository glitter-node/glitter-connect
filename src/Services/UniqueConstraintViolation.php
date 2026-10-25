<?php

namespace Glitter\Connect\Services;

use Illuminate\Database\QueryException;

class UniqueConstraintViolation
{
    public static function matches(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $state = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);

        if ($state === '23505') return true;
        if ($state !== '23000') return false;

        if (in_array($driverCode, [1062, 2601, 2627], true)) return true;

        return $driverCode === 19
            && str_contains(strtolower($exception->getPrevious()?->getMessage() ?? ''), 'unique constraint failed');
    }
}
