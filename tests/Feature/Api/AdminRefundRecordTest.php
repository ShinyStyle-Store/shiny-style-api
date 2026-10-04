<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\OrderReturnKind;
use App\Enums\OrderReturnStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnReason;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\RefundRecordCorrection;
use App\Http\Resources\AdminRefundCorrectionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminRefundRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_cod_order_can_record_a_completed_transfer_with_mandatory_evidence(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn([
            'subtotal' => '180.00',
            'total' => '250.00',
            'payment_status' => PaymentStatus::Paid,
        ]);
        $token = $this->adminToken();

        $response = $this->record($token, $order, '250.00', (string) Str::uuid());

        $response->assertCreated()
            ->assertJsonPath('data.amount', '250.00')
            ->assertJsonPath('data.evidence.mime_type', 'image/png');
        $this->assertDatabaseHas('refund_records', ['order_id' => $order->id, 'amount' => '250.00']);
        $this->assertDatabaseCount('media_attachments', 1);
        $this->assertSame('delivered', $order->fresh()->status->value);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    public function test_confirmation_is_final_even_when_the_recorded_amount_is_lower_than_paid_amount(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $transferredAt = '2026-10-04T10:00:00Z';

        $first = $this->record($token, $order, '25.00', $key, $transferredAt)->assertCreated()
            ->assertJsonPath('data.version', 0)
            ->assertJsonMissingPath('data.revision');
        $recordId = $first->json('data.id');

        $this->record($token, $order, '26.00', (string) Str::uuid(), $transferredAt)
            ->assertConflict()
            ->assertJsonPath('code', 'refund_recording_already_confirmed');

        $this->assertDatabaseCount('refund_records', 1);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('media_attachments', 1);
        $this->assertCount(1, Storage::disk('refund-test')->allFiles());

        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.refund_recording.confirmed', true)
            ->assertJsonPath('data.refund_recording.eligible', false)
            ->assertJsonPath('data.refund_recording.blocker', 'refund_recording_already_confirmed')
            ->assertJsonPath('data.refund_recording.record_id', $recordId)
            ->assertJsonPath('data.refund_recording.remaining_balance', '145.00');
    }

    public function test_missing_evidence_does_not_record_a_refund(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);

        $this->withToken($this->adminToken())->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/admin/orders/'.$order->public_id.'/refund-records', [
                'amount' => '10.00', 'currency' => 'EGP', 'transfer_method' => 'cash',
                'transferred_at' => now()->toISOString(),
            ])->assertUnprocessable()->assertJsonValidationErrors(['evidence']);

        $this->assertDatabaseCount('refund_records', 0);
    }

    public function test_same_key_replays_without_creating_another_record(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $transferredAt = '2026-10-04T10:00:00Z';

        $first = $this->record($token, $order, '25.00', $key, $transferredAt)->assertCreated();
        $replay = $this->record($token, $order, '25.00', $key, $transferredAt)->assertOk();
        $this->record($token, $order, '26.00', $key, $transferredAt)
            ->assertConflict()
            ->assertJsonPath('code', 'idempotency_key_conflict');

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('refund_records', 1);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseCount('media_attachments', 1);
        $this->assertCount(1, Storage::disk('refund-test')->allFiles());
    }

    public function test_confirmation_replay_remains_available_after_a_correction_without_reopening_recording(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);
        $token = $this->adminToken();
        $key = (string) Str::uuid();
        $transferredAt = '2026-10-04T10:00:00Z';

        $first = $this->record($token, $order, '25.00', $key, $transferredAt)->assertCreated();
        $recordId = $first->json('data.id');
        $correctionKey = (string) Str::uuid();
        $correction = $this->withToken($token)->withHeader('Idempotency-Key', $correctionKey)->patch(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records/'.$recordId,
            [
                'expected_version' => 0,
                'amount' => '30.00',
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => $transferredAt,
                'reason' => 'Corrected transfer amount.',
                'evidence' => $this->evidence(),
            ],
        )->assertOk()
            ->assertJsonPath('data.record.version', 1)
            ->assertJsonMissingPath('data.record.revision')
            ->assertJsonPath('data.correction.expected_version', 0)
            ->assertJsonPath('data.correction.new_version', 1)
            ->assertJsonPath('data.correction.previous.version', 0)
            ->assertJsonPath('data.correction.new.version', 1)
            ->assertJsonPath('data.correction.previous.amount', '25.00')
            ->assertJsonPath('data.correction.new.amount', '30.00')
            ->assertJsonPath('data.correction.previous.currency', 'EGP')
            ->assertJsonPath('data.correction.new.currency', 'EGP')
            ->assertJsonPath('data.correction.previous.transfer_method', 'bank_transfer')
            ->assertJsonPath('data.correction.new.transfer_method', 'bank_transfer')
            ->assertJsonMissingPath('data.correction.expected_revision')
            ->assertJsonMissingPath('data.correction.new_revision')
            ->assertJsonMissingPath('data.correction.previous.revision')
            ->assertJsonMissingPath('data.correction.new.revision');

        $storedCorrection = DB::table('refund_record_corrections')
            ->where('id', $correction->json('data.correction.id'))
            ->first();
        $this->assertSame(0, json_decode((string) $storedCorrection->previous_snapshot, true, 512, JSON_THROW_ON_ERROR)['revision']);
        $this->assertSame(1, json_decode((string) $storedCorrection->new_snapshot, true, 512, JSON_THROW_ON_ERROR)['revision']);

        $correctionReplay = $this->withToken($token)->withHeader('Idempotency-Key', $correctionKey)->patch(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records/'.$recordId,
            [
                'expected_version' => 0,
                'amount' => '30.00',
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => $transferredAt,
                'reason' => 'Corrected transfer amount.',
                'evidence' => $this->evidence(),
            ],
        )->assertOk();

        $this->assertSame($correction->json('data'), $correctionReplay->json('data'));

        $replay = $this->record($token, $order, '25.00', $key, $transferredAt)->assertOk();

        $this->assertSame($recordId, $replay->json('data.id'));
        $this->assertSame($recordId, $correction->json('data.record.id'));
        $this->assertDatabaseCount('refund_records', 1);
        $this->assertDatabaseCount('refund_record_corrections', 1);
        $this->assertDatabaseCount('media_assets', 2);
        $this->assertDatabaseCount('media_attachments', 2);
        $this->assertCount(2, Storage::disk('refund-test')->allFiles());

        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.refund_recording.confirmed', true)
            ->assertJsonPath('data.refund_recording.eligible', false)
            ->assertJsonPath('data.refund_recording.blocker', 'refund_recording_already_confirmed');
    }

    public function test_stale_refund_correction_version_is_rejected(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);
        $token = $this->adminToken();
        $record = $this->record($token, $order, '25.00', (string) Str::uuid(), '2026-10-04T10:00:00Z')
            ->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->patch(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records/'.$record->json('data.id'),
            [
                'expected_version' => 0,
                'amount' => '30.00',
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => '2026-10-04T10:00:00Z',
                'reason' => 'First correction.',
                'evidence' => $this->evidence(),
            ],
        )->assertOk();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->patch(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records/'.$record->json('data.id'),
            [
                'expected_version' => 0,
                'amount' => '31.00',
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => '2026-10-04T10:00:00Z',
                'reason' => 'Stale correction.',
                'evidence' => $this->evidence(),
            ],
        )->assertConflict()->assertJsonPath('code', 'refund_revision_conflict');
    }

    public function test_historical_snapshots_without_revision_or_version_do_not_receive_a_default_version(): void
    {
        $correction = new RefundRecordCorrection([
            'expected_revision' => 0,
            'applied_revision' => 1,
            'previous_snapshot' => ['amount' => '25.00'],
            'new_snapshot' => ['amount' => '30.00', 'version' => 7],
        ]);

        $response = (new AdminRefundCorrectionResource($correction))->resolve(Request::create('/'));

        $this->assertArrayNotHasKey('version', $response['previous']);
        $this->assertSame(7, $response['new']['version']);
        $this->assertSame(['amount' => '25.00'], $response['previous']);
        $this->assertSame(['amount' => '30.00', 'version' => 7], $response['new']);
    }

    public function test_expected_revision_is_not_an_accepted_refund_api_field(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);
        $token = $this->adminToken();
        $record = $this->record($token, $order, '25.00', (string) Str::uuid(), '2026-10-04T10:00:00Z')
            ->assertCreated();

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())->patch(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records/'.$record->json('data.id'),
            [
                'expected_revision' => 0,
                'amount' => '30.00',
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => '2026-10-04T10:00:00Z',
                'reason' => 'Unsupported API field.',
                'evidence' => $this->evidence(),
            ],
        )->assertUnprocessable()->assertJsonValidationErrors(['expected_version', 'expected_revision']);
    }

    public function test_amount_cannot_exceed_the_paid_balance(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);

        $this->record($this->adminToken(), $order, '170.01', (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'refund_amount_exceeds_balance');
        $this->assertDatabaseCount('refund_records', 0);
    }

    public function test_order_without_return_history_is_rejected(): void
    {
        Storage::fake('refund-test');
        config(['media.disk' => 'refund-test']);
        $order = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $this->record($this->adminToken(), $order, '10.00', (string) Str::uuid())
            ->assertConflict()->assertJsonPath('code', 'return_not_recorded');
    }

    public function test_unauthenticated_refund_history_is_not_available(): void
    {
        $order = $this->orderWithReturn(['payment_status' => PaymentStatus::Paid]);

        $response = $this->getJson('/api/v1/admin/orders/'.$order->public_id.'/refund-records');

        $response->assertUnauthorized();
    }

    private function record(string $token, Order $order, string $amount, string $key, ?string $transferredAt = null)
    {
        return $this->withToken($token)->withHeader('Idempotency-Key', $key)->post(
            '/api/v1/admin/orders/'.$order->public_id.'/refund-records',
            [
                'amount' => $amount,
                'currency' => 'EGP',
                'transfer_method' => 'bank_transfer',
                'transferred_at' => $transferredAt ?? now()->subMinute()->toISOString(),
                'evidence' => $this->evidence(),
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function orderWithReturn(array $attributes = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::Delivered,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
        ], $attributes));
        OrderReturn::query()->create([
            'order_id' => $order->id,
            'kind' => OrderReturnKind::ReturnAfterDelivery,
            'status' => OrderReturnStatus::WaitingForReturn,
            'reason' => ReturnReason::ChangedMind,
            'recorded_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $order->public_id),
        ]);

        return $order;
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create();
        AdminMembership::query()->create([
            'user_id' => $admin->id,
            'status' => AdminMembershipStatus::Active,
            'activated_at' => now(),
        ]);

        return $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    private function evidence(): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent('refund.png', $bytes);
    }
}
