<?php

namespace App\Exceptions;

use RuntimeException;

class OfferProductConflictException extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly int $offerId,
    ) {
        parent::__construct('The selected product overlaps another enabled offer.');
    }
}
