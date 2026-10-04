<?php

namespace App\Services;

use App\Enums\MediaRole;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\RefundRecordConflictException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RefundRecord;
use App\Models\RefundRecordCorrection;
use App\Models\User;
use App\Support\ExactMoney;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;
use Throwable;

final class RefundRecordService
{
    public function __construct(private readonly MediaService $media) {}

    /** @return array{record: RefundRecord, replayed: bool} */
    public function create(Order $order, array $data, UploadedFile $evidence, int $actorId, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint('create', $order->getKey(), $data, $evidence);
        $existing = RefundRecord::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertFingerprint($existing, $order, $fingerprint);

            return ['record' => $this->load($existing), 'replayed' => true];
        }
        if ($order->refundRecords()->exists()) {
            throw new RefundRecordConflictException(
                'refund_recording_already_confirmed',
                'Refund recording has already been confirmed for this order.',
            );
        }

        $asset = null;
        try {
            $asset = $this->media->uploadImage($evidence, $this->actor($actorId));

            $result = DB::transaction(function () use ($order, $data, $asset, $actorId, $idempotencyKey, $fingerprint): array {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $existing = RefundRecord::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertFingerprint($existing, $lockedOrder, $fingerprint);

                    return ['record' => $existing, 'replayed' => true];
                }
                if (RefundRecord::query()->where('order_id', $lockedOrder->getKey())->lockForUpdate()->exists()) {
                    throw new RefundRecordConflictException(
                        'refund_recording_already_confirmed',
                        'Refund recording has already been confirmed for this order.',
                    );
                }

                $paid = $this->paidAmount($lockedOrder);
                $this->assertReturnHistory($lockedOrder);
                $amount = $this->amount($data['amount']);
                $refunded = $this->recordedAmount($lockedOrder);
                if ($this->compare($amount, $this->subtract($paid['amount'], $refunded)) > 0) {
                    throw new RefundRecordConflictException('refund_amount_exceeds_balance', 'The refund amount exceeds the remaining recorded refundable balance.');
                }

                $currency = strtoupper((string) $data['currency']);
                if ($currency !== $paid['currency']) {
                    throw new RefundRecordConflictException('refund_currency_mismatch', 'The refund currency must match the paid order currency.');
                }

                $record = RefundRecord::query()->create([
                    'order_id' => $lockedOrder->getKey(),
                    'amount' => ExactMoney::formatMinorUnits($amount),
                    'currency' => $currency,
                    'transfer_method' => $data['transfer_method'],
                    'transferred_at' => $data['transferred_at'],
                    'transaction_reference' => $data['transaction_reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'recorded_by_user_id' => $actorId,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'revision' => 0,
                ]);
                $this->media->attach($asset, $record, MediaRole::REFUND_EVIDENCE_IMAGE);

                return ['record' => $record, 'replayed' => false];
            });

            if ($result['replayed']) {
                $this->media->deleteOrphanedAsset($asset);
            }

            return ['record' => $this->load($result['record']), 'replayed' => $result['replayed']];
        } catch (Throwable $exception) {
            if ($asset !== null) {
                try {
                    $this->media->deleteOrphanedAsset($asset);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            if ($exception instanceof QueryException) {
                $existing = RefundRecord::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $this->assertFingerprint($existing, $order, $fingerprint);

                    return ['record' => $this->load($existing), 'replayed' => true];
                }
                if (RefundRecord::query()->where('order_id', $order->getKey())->exists()) {
                    throw new RefundRecordConflictException(
                        'refund_recording_already_confirmed',
                        'Refund recording has already been confirmed for this order.',
                    );
                }
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        $record = $order->refundRecords()->latest('id')->first();

        try {
            $paid = $this->paidAmount($order);
        } catch (RefundRecordConflictException $exception) {
            return [
                'paid_amount' => '0.00', 'refunded_amount' => (string) $this->recordedAmount($order),
                'remaining_balance' => '0.00', 'currency' => strtoupper((string) $order->currency),
                'source' => 'undetermined', 'eligible' => false, 'blocker' => $exception->errorCode,
                'confirmed' => $record !== null,
                'confirmed_at' => $record?->created_at?->toISOString(),
                'confirmed_by_user_id' => $record?->recorded_by_user_id === null ? null : (int) $record->recorded_by_user_id,
                'record_id' => $record?->getKey(),
                'record_public_id' => $record?->public_id,
            ];
        }

        $refunded = $this->recordedAmount($order);
        $remaining = $this->compare($paid['amount'], $refunded) < 0 ? '0' : $this->subtract($paid['amount'], $refunded);

        try {
            $this->assertReturnHistory($order);
        } catch (RefundRecordConflictException $exception) {
            return $this->summaryPayload($paid, $refunded, $remaining, false, $exception->errorCode, $record);
        }

        if ($record !== null) {
            return $this->summaryPayload($paid, $refunded, $remaining, false, 'refund_recording_already_confirmed', $record);
        }

        return $this->summaryPayload($paid, $refunded, $remaining, $this->compare($remaining, '0') > 0, $this->compare($remaining, '0') > 0 ? null : 'refund_balance_exhausted', null);
    }

    /** @return array{record: RefundRecord, correction: RefundRecordCorrection, replayed: bool} */
    public function correct(RefundRecord $record, array $data, UploadedFile $evidence, int $actorId, string $idempotencyKey): array
    {
        $fingerprint = $this->fingerprint('correction', $record->getKey(), $data, $evidence);
        $existing = RefundRecordCorrection::query()
            ->where('refund_record_id', $record->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            $this->assertCorrectionFingerprint($existing, $fingerprint);

            return ['record' => $this->load($record), 'correction' => $existing, 'replayed' => true];
        }

        $asset = null;
        try {
            $asset = $this->media->uploadImage($evidence, $this->actor($actorId));
            $result = DB::transaction(function () use ($record, $data, $asset, $actorId, $idempotencyKey, $fingerprint): array {
                $lockedRecord = RefundRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                $order = Order::query()->whereKey($lockedRecord->order_id)->lockForUpdate()->firstOrFail();
                $existing = RefundRecordCorrection::query()
                    ->where('refund_record_id', $lockedRecord->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertCorrectionFingerprint($existing, $fingerprint);

                    return ['record' => $lockedRecord, 'correction' => $existing, 'replayed' => true];
                }
                if ((int) $data['expected_revision'] !== (int) $lockedRecord->revision) {
                    throw new RefundRecordConflictException('refund_revision_conflict', 'The refund record has changed. Refresh it and retry the correction.');
                }

                $paid = $this->paidAmount($order);
                $amount = $this->amount($data['amount']);
                $otherRefunds = $this->recordedAmount($order, $lockedRecord->getKey());
                if ($this->compare($amount, $this->subtract($paid['amount'], $otherRefunds)) > 0) {
                    throw new RefundRecordConflictException('refund_amount_exceeds_balance', 'The corrected amount exceeds the remaining recorded refundable balance.');
                }
                $currency = strtoupper((string) $data['currency']);
                if ($currency !== $paid['currency']) {
                    throw new RefundRecordConflictException('refund_currency_mismatch', 'The refund currency must match the paid order currency.');
                }

                $oldAttachment = $lockedRecord->mediaAttachments()->latest('id')->first();
                $previous = $this->snapshot($lockedRecord);
                $lockedRecord->forceFill([
                    'amount' => ExactMoney::formatMinorUnits($amount), 'currency' => $currency,
                    'transfer_method' => $data['transfer_method'], 'transferred_at' => $data['transferred_at'],
                    'transaction_reference' => $data['transaction_reference'] ?? null, 'note' => $data['note'] ?? null,
                    'revision' => (int) $lockedRecord->revision + 1,
                ])->saveQuietly();
                $newAttachment = $this->media->attach($asset, $lockedRecord, MediaRole::REFUND_EVIDENCE_IMAGE);
                $correction = RefundRecordCorrection::query()->create([
                    'refund_record_id' => $lockedRecord->getKey(), 'expected_revision' => $data['expected_revision'],
                    'applied_revision' => $lockedRecord->revision, 'reason' => $data['reason'],
                    'recorded_by_user_id' => $actorId, 'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint, 'previous_snapshot' => $previous,
                    'new_snapshot' => $this->snapshot($lockedRecord),
                    'previous_media_attachment_id' => $oldAttachment?->getKey(),
                    'new_media_attachment_id' => $newAttachment->getKey(),
                ]);

                return ['record' => $lockedRecord, 'correction' => $correction, 'replayed' => false];
            });

            if ($result['replayed']) {
                $this->media->deleteOrphanedAsset($asset);
            }

            return ['record' => $this->load($result['record']), 'correction' => $result['correction'], 'replayed' => $result['replayed']];
        } catch (Throwable $exception) {
            if ($asset !== null) {
                try {
                    $this->media->deleteOrphanedAsset($asset);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }
    }

    private function paidAmount(Order $order): array
    {
        if ($order->payment_method === PaymentMethod::CashOnDelivery) {
            if ($order->payment_status !== PaymentStatus::Paid || $order->status->value === 'delivery_refused') {
                throw new RefundRecordConflictException('paid_amount_unavailable', 'This COD order has no recorded paid amount under the full-collection policy.');
            }

            return ['amount' => ExactMoney::toMinorUnits((string) $order->total), 'currency' => strtoupper((string) $order->currency), 'source' => 'cod_full_collection_policy'];
        }

        $attempts = PaymentAttempt::query()->where('order_id', $order->getKey())->get();
        if ($attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status === PaymentAttemptStatus::RequiresReview)) {
            throw new RefundRecordConflictException('paid_amount_requires_review', 'A payment attempt requires review before a refund can be recorded.');
        }
        $paid = $attempts->filter(fn (PaymentAttempt $attempt): bool => $attempt->status === PaymentAttemptStatus::Paid);
        if ($paid->count() !== 1) {
            throw new RefundRecordConflictException('paid_amount_ambiguous', 'The successful payment amount cannot be determined safely.');
        }
        $attempt = $paid->first();

        return ['amount' => (string) $attempt->amount_minor, 'currency' => strtoupper((string) $attempt->currency), 'source' => 'successful_payment_attempt'];
    }

    private function assertReturnHistory(Order $order): void
    {
        if (! $order->orderReturn()->exists() && ! $order->returnReceipts()->exists()) {
            throw new RefundRecordConflictException('return_not_recorded', 'The order has no recorded return history.');
        }
    }

    private function recordedAmount(Order $order, ?int $except = null): string
    {
        $query = $order->refundRecords();
        if ($except !== null) {
            $query->where($query->getModel()->getKeyName(), '<>', $except);
        }

        $total = '0';
        foreach ($query->get(['amount']) as $record) {
            $total = ExactMoney::add($total, ExactMoney::toMinorUnits((string) $record->amount));
        }

        return $total;
    }

    private function amount(string $amount): string
    {
        try {
            $minor = ExactMoney::toMinorUnits($amount);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['amount' => 'The refund amount must be a valid monetary value.']);
        }
        if ($minor === '0') {
            throw ValidationException::withMessages(['amount' => 'The refund amount must be greater than zero.']);
        }

        return $minor;
    }

    private function fingerprint(string $operation, int $subjectId, array $data, UploadedFile $file): string
    {
        return hash('sha256', json_encode([
            'operation' => $operation, 'subject_id' => $subjectId, 'amount' => $data['amount'],
            'currency' => strtoupper((string) $data['currency']), 'transfer_method' => $data['transfer_method'],
            'transferred_at' => $data['transferred_at'], 'transaction_reference' => $data['transaction_reference'] ?? null,
            'note' => $data['note'] ?? null, 'reason' => $data['reason'] ?? null,
            'evidence_digest' => hash_file('sha256', $file->getRealPath()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function assertFingerprint(RefundRecord $record, Order $order, string $fingerprint): void
    {
        if ($record->order_id !== $order->getKey()) {
            throw new RefundRecordConflictException('idempotency_key_conflict', 'The idempotency key was already used for another order.');
        }
        if ($record->request_fingerprint !== $fingerprint) {
            throw new RefundRecordConflictException('idempotency_key_conflict', 'The idempotency key was already used for a different refund request.');
        }
    }

    private function assertCorrectionFingerprint(RefundRecordCorrection $correction, string $fingerprint): void
    {
        if ($correction->request_fingerprint !== $fingerprint) {
            throw new RefundRecordConflictException('idempotency_key_conflict', 'The idempotency key was already used for a different correction request.');
        }
    }

    private function load(RefundRecord $record): RefundRecord
    {
        return $record->load(['mediaAttachments.mediaAsset']);
    }

    private function actor(int $actorId): ?User
    {
        return User::query()->find($actorId);
    }

    private function compare(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private function subtract(string $left, string $right): string
    {
        $left = str_pad($left, max(strlen($left), strlen($right)), '0', STR_PAD_LEFT);
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';
        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] - $borrow - (int) $right[$index];
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $digit.$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    /** @param array{amount: string, currency: string, source: string} $paid */
    private function summaryPayload(array $paid, string $refunded, string $remaining, bool $eligible, ?string $blocker, ?RefundRecord $record): array
    {
        return [
            'paid_amount' => ExactMoney::formatMinorUnits($paid['amount']),
            'refunded_amount' => ExactMoney::formatMinorUnits($refunded),
            'remaining_balance' => ExactMoney::formatMinorUnits($remaining),
            'currency' => $paid['currency'],
            'source' => $paid['source'],
            'eligible' => $eligible,
            'blocker' => $blocker,
            'confirmed' => $record !== null,
            'confirmed_at' => $record?->created_at?->toISOString(),
            'confirmed_by_user_id' => $record?->recorded_by_user_id === null ? null : (int) $record->recorded_by_user_id,
            'record_id' => $record?->getKey(),
            'record_public_id' => $record?->public_id,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(RefundRecord $record): array
    {
        return ['amount' => (string) $record->amount, 'currency' => $record->currency, 'transfer_method' => $record->transfer_method->value, 'transferred_at' => $record->transferred_at?->toISOString(), 'transaction_reference' => $record->transaction_reference, 'note' => $record->note, 'revision' => (int) $record->revision];
    }
}
