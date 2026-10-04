<?php

namespace Database\Factories;

use App\Enums\RefundTransferMethod;
use App\Models\Order;
use App\Models\RefundRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RefundRecord> */
class RefundRecordFactory extends Factory
{
    protected $model = RefundRecord::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'public_id' => (string) Str::ulid(),
            'amount' => '10.00',
            'currency' => 'EGP',
            'transfer_method' => RefundTransferMethod::BankTransfer,
            'transferred_at' => now(),
            'transaction_reference' => null,
            'note' => null,
            'recorded_by_user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'revision' => 0,
        ];
    }
}
