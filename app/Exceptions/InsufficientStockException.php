<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $sellableItemId,
        public readonly int $requestedQuantity,
        public readonly int $availableQuantity,
    ) {
        parent::__construct('The requested quantity exceeds the current available stock.');
    }
}
