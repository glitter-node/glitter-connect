<?php

namespace Glitter\Connect\Tests\Unit;

use Glitter\Connect\Services\UniqueConstraintViolation;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class UniqueConstraintViolationTest extends TestCase
{
    public function test_known_unique_constraint_states_are_classified_separately_from_other_errors(): void
    {
        self::assertTrue(UniqueConstraintViolation::matches($this->queryException('23505', 0, 'duplicate')));
        self::assertTrue(UniqueConstraintViolation::matches($this->queryException('23000', 1062, 'Duplicate entry')));
        self::assertTrue(UniqueConstraintViolation::matches($this->queryException('23000', 19, 'UNIQUE constraint failed: table.key')));
        self::assertFalse(UniqueConstraintViolation::matches($this->queryException('23000', 1452, 'Cannot add or update a child row')));
        self::assertFalse(UniqueConstraintViolation::matches($this->queryException('23000', 19, 'FOREIGN KEY constraint failed')));
        self::assertFalse(UniqueConstraintViolation::matches($this->queryException('HY000', 1205, 'Lock wait timeout')));
    }

    private function queryException(string $state, int $driverCode, string $message): QueryException
    {
        $previous = new PDOException($message, $driverCode);
        $previous->errorInfo = [$state, $driverCode, $message];

        return new QueryException('test', 'insert into test_table', [], $previous);
    }
}
