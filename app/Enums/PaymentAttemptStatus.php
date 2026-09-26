<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case RequiresReview = 'requires_review';

    public function isActive(): bool
    {
        return in_array($this, [self::Created, self::Pending], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }
}
