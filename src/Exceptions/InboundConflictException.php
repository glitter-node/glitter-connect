<?php

namespace Glitter\Connect\Exceptions;

use RuntimeException;

class InboundConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Inbound request conflict.');
    }
}
