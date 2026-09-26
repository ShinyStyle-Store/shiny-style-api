<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\PaymentExpirationService;
use Illuminate\Console\Command;
use Throwable;

final class ExpirePayments extends Command
{
    protected $signature = 'payments:expire {--batch= : Number of orders to inspect per batch}';

    protected $description = 'Expire unpaid online orders and release their reservations';

    public function handle(PaymentExpirationService $expiration): int
    {
        $batchSize = $this->batchSize();
        if ($batchSize === null) {
            $this->error('The batch size must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        $counts = ['expired' => 0, 'skipped' => 0, 'review-required' => 0, 'failed' => 0];
        $onlineMethods = array_map(
            static fn (PaymentMethod $method): string => $method->value,
            [PaymentMethod::Card, PaymentMethod::Wallet],
        );

        Order::query()
            ->whereIn('payment_method', $onlineMethods)
            ->whereNotIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->where('status', OrderStatus::PendingConfirmation->value)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById($batchSize, function ($orders) use ($expiration, &$counts): void {
                foreach ($orders as $order) {
                    try {
                        $status = $expiration->expire($order)->status;
                        $counts[$status] = ($counts[$status] ?? 0) + 1;
                    } catch (Throwable) {
                        $counts['failed']++;
                    }
                }
            });

        $this->table(['Result', 'Count'], [
            ['expired', $counts['expired']],
            ['skipped', $counts['skipped']],
            ['review-required', $counts['review-required']],
            ['failed', $counts['failed']],
        ]);

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function batchSize(): ?int
    {
        $value = $this->option('batch') ?? config('payments.expiration_batch_size', 100);
        $batchSize = filter_var($value, FILTER_VALIDATE_INT);

        return $batchSize !== false && $batchSize >= 1 && $batchSize <= 1000 ? $batchSize : null;
    }
}
