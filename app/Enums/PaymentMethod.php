<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CashOnDelivery = 'cash_on_delivery';
    case Card = 'card';
    case Wallet = 'wallet';

    public function isOnline(): bool
    {
        return $this !== self::CashOnDelivery;
    }
}
