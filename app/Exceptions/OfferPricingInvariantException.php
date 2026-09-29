<?php

namespace App\Exceptions;

use RuntimeException;

class OfferPricingInvariantException extends RuntimeException
{
    /** @param list<int> $offerIds */
    public function __construct(
        public readonly int $productId,
        public readonly array $offerIds,
    ) {
        parent::__construct('Multiple qualifying offers were found for one product.');
    }
}
