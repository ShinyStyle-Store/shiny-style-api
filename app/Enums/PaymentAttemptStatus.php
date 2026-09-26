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
    case Submitting = 'submitting';

    public function isActive(): bool
    {
        return in_array($this, [self::Created, self::Pending], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Expired, self::RequiresReview], true);
    }

    public function isSubmitting(): bool
    {
        return $this === self::Submitting;
    }
}
