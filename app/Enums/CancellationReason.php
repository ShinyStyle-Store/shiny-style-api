<?php

namespace App\Enums;

enum CancellationReason: string
{
    case CustomerCancelled = 'customer_cancelled';
    case InvalidPhone = 'invalid_phone';
    case NoResponse = 'no_response';
    case OutOfStock = 'out_of_stock';
    case Other = 'other';
}
