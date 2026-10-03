<?php

namespace App\Enums;

enum OrderReturnKind: string
{
    case DeliveryRefusal = 'delivery_refusal';
    case ReturnAfterDelivery = 'return_after_delivery';
}
