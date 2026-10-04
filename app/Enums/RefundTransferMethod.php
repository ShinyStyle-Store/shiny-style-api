<?php

namespace App\Enums;

enum RefundTransferMethod: string
{
    case BankTransfer = 'bank_transfer';
    case MobileWallet = 'mobile_wallet';
    case Cash = 'cash';
    case Other = 'other';
}
