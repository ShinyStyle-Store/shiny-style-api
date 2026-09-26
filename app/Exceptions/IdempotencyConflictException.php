<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $message = 'The idempotency key was already used for a different request.')
    {
        $this->errorCode = 'idempotency_conflict';

        parent::__construct($message);
    }
}
