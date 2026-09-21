<?php

namespace App\Exceptions;

use RuntimeException;

final class ProductMediaMigrationFailure extends RuntimeException
{
    public function __construct(public readonly string $safeReason)
    {
        parent::__construct($safeReason);
    }
}
