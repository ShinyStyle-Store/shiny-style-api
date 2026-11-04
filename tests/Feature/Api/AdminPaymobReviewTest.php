<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\User;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPaymobReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymob.api_key' => 'api-key-test-value',
            'services.paymob.is_live' => false,
            'services.paymob.card_integration_id' => 1234,
            'services.paymob.api_base_url' => 'https://accept.paymob.com',
        ]);
    }

    public function test_only_active_admin_can_list_and_request_payment_verification(): void
    {
        $attempt = $this->createAttempt();
        Http::preventStrayRequests();
        $this->getJson('/api/v1/admin/payment-attempts/requires-review')->assertUnauthorized();
        $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')->assertUnauthorized();
        Http::assertNothingSent();

        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $token = $user->createToken('admin', ['admin-access'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/admin/payment-attempts/requires-review')->assertForbidden();

        AdminMembership::factory()->create(['user_id' => $user->id]);
        $this->forgetAuthGuards();
        $this->withToken($token)->getJson('/api/v1/admin/payment-attempts/requires-review')
            ->assertOk()
            ->assertJsonPath('data.0.payment_attempt_id', $attempt->public_id)
            ->assertJsonPath('data.0.order_number', $attempt->order->order_number)
            ->assertJsonPath('data.0.amount', '100.00')
            ->assertJsonPath('data.0.currency', 'EGP')
            ->assertJsonPath('data.0.reason', 'malformed_provider_response')
            ->assertJsonPath('data.0.paymob_reference', $attempt->provider_order_id)
            ->assertJsonMissingPath('data.0.provider_client_secret')
            ->assertJsonMissingPath('data.0.source_data');

        $this->forgetAuthGuards();
        $restrictedToken = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ])->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($restrictedToken)
            ->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_matching_success_updates_payment_and_repeat_does_not_inquire_again(): void
    {
        $attempt = $this->createAttempt();
        $this->authenticateAdmin();
        $this->fakeInquiry($this->transactionResponse());

        $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.result', 'verified_success')
            ->assertJsonPath('data.status', 'paid');
        $this->assertSame(PaymentAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame('99001', $attempt->fresh()->provider_transaction_id);
        $this->assertSame(PaymentStatus::Paid, $attempt->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $attempt->order->fresh()->status);

        $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')->assertNotFound();
        Http::assertSentCount(2);
    }

    public function test_confirmed_failure_marks_attempt_and_only_latest_attempt_changes_order_payment(): void
    {
        $order = $this->createOrder();
        $oldAttempt = $this->createAttempt($order, ['provider_order_id' => '5001']);
        $newAttempt = $this->createAttempt($order, [
            'provider_order_id' => '5002',
            'status' => PaymentAttemptStatus::Pending,
        ]);
        $this->authenticateAdmin();
        $currentAttempt = $this->createAttempt(status: PaymentAttemptStatus::RequiresReview, providerOrderId: '5003');
        $this->fakeInquiries([
            $oldAttempt->merchant_reference => $this->transactionResponse([
                'id' => 99002,
                'pending' => false,
                'success' => false,
                'order' => ['id' => 5001, 'merchant_order_id' => $oldAttempt->merchant_reference],
            ]),
            $currentAttempt->merchant_reference => $this->transactionResponse([
                'id' => 99003,
                'pending' => false,
                'success' => false,
                'order' => ['id' => 5003, 'merchant_order_id' => $currentAttempt->merchant_reference],
            ]),
        ]);

        $this->postJson('/api/v1/admin/payment-attempts/'.$oldAttempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'verified_failure');
        $this->assertSame(PaymentAttemptStatus::Failed, $oldAttempt->fresh()->status);
        $this->assertSame(PaymentAttemptStatus::Pending, $newAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        $this->postJson('/api/v1/admin/payment-attempts/'.$currentAttempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'verified_failure');
        $this->assertSame(PaymentStatus::Failed, $currentAttempt->order->fresh()->payment_status);
        Http::assertSentCount(4);
    }

    public function test_pending_and_not_found_inquiries_leave_attempt_under_review(): void
    {
        $pendingAttempt = $this->createAttempt();
        $pendingAttempt->order->update(['payment_status' => PaymentStatus::Failed]);
        $missingAttempt = $this->createAttempt();
        $this->authenticateAdmin();
        $this->fakeInquiries([
            $pendingAttempt->merchant_reference => $this->transactionResponse(['pending' => true]),
            $missingAttempt->merchant_reference => null,
        ]);
        $this->postJson('/api/v1/admin/payment-attempts/'.$pendingAttempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'pending');
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $pendingAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $pendingAttempt->order->fresh()->payment_status);

        $this->postJson('/api/v1/admin/payment-attempts/'.$missingAttempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'not_found');
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $missingAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $missingAttempt->order->fresh()->payment_status);
        Http::assertSentCount(4);
    }

    public function test_amount_currency_or_transaction_identity_mismatch_stays_under_review(): void
    {
        $cases = [
            ['attempt' => [], 'response' => ['amount_cents' => 10001]],
            ['attempt' => [], 'response' => ['currency' => 'USD']],
            ['attempt' => ['provider_transaction_id' => '99001'], 'response' => ['id' => 99010]],
            ['attempt' => ['provider_order_id' => '5002'], 'response' => ['order' => ['id' => 5999]]],
        ];
        $this->authenticateAdmin();

        $attempts = [];
        $transactionsByMerchantReference = [];
        foreach ($cases as $case) {
            $attempt = $this->createAttempt(overrides: $case['attempt']);
            $attempts[] = $attempt;
            $transactionsByMerchantReference[$attempt->merchant_reference] = $this->transactionResponse($case['response']);
        }
        $this->fakeInquiries($transactionsByMerchantReference);

        foreach ($attempts as $attempt) {
            $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')
                ->assertOk()->assertJsonPath('data.result', 'unverified');
            $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
            $this->assertSame(PaymentStatus::Pending, $attempt->order->fresh()->payment_status);
        }
        Http::assertSentCount(8);
    }

    public function test_late_success_for_cancelled_order_does_not_reactivate_order_or_change_stock(): void
    {
        $product = Product::create([
            'slug' => 'paymob-review-'.Str::lower(Str::random(10)),
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Review Test Product',
            'status' => 'active',
            'published_at' => now(),
        ]);
        $sellable = SellableItem::create([
            'product_id' => $product->getKey(),
            'sku' => 'REVIEW-'.Str::upper(Str::random(10)),
            'price' => '100.00',
            'stock_quantity' => 8,
            'reserved_quantity' => 2,
            'status' => 'active',
            'is_default' => true,
        ]);
        $order = $this->createOrder([
            'status' => OrderStatus::Cancelled,
            'cancellation_reason' => 'customer_cancelled',
            'cancelled_at' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'sellable_item_id' => $sellable->getKey(),
        ]);
        $attempt = $this->createAttempt($order);
        $this->authenticateAdmin();
        $this->fakeInquiry($this->transactionResponse());

        $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'verified_success');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(8, $sellable->fresh()->stock_quantity);
        $this->assertSame(2, $sellable->fresh()->reserved_quantity);
    }

    public function test_environment_or_integration_mismatch_cannot_confirm_success(): void
    {
        $attempt = $this->createAttempt();
        $this->authenticateAdmin();
        $this->fakeInquiry($this->transactionResponse(['is_live' => true]));

        $this->postJson('/api/v1/admin/payment-attempts/'.$attempt->public_id.'/verify')
            ->assertOk()->assertJsonPath('data.result', 'unverified');
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
    }

    public function test_new_card_attempt_snapshots_its_expected_integration_and_environment(): void
    {
        $order = $this->createOrder();

        $attempt = app(PaymentAttemptService::class)->create($order, PaymentMethod::Card, (string) Str::uuid());

        $this->assertSame(1234, $attempt->integration_id);
        $this->assertFalse($attempt->provider_is_live);
    }

    private function authenticateAdmin(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        AdminMembership::factory()->create(['user_id' => $user->id]);
        $this->withToken($user->createToken('admin', ['admin-access'])->plainTextToken);
    }

    private function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function createOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'payment_expires_at' => now()->addMinutes(30),
            'total' => '100.00',
        ], $overrides));
    }

    private function createAttempt(?Order $order = null, array $overrides = [], ?string $providerOrderId = null, PaymentAttemptStatus $status = PaymentAttemptStatus::RequiresReview): PaymentAttempt
    {
        $order ??= $this->createOrder();

        return PaymentAttempt::create(array_merge([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => $status,
            'amount_minor' => 10000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(12)),
            'provider_order_id' => $providerOrderId ?? '5000',
            'integration_id' => 1234,
            'provider_is_live' => false,
            'failure_code' => 'malformed_provider_response',
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    private function transactionResponse(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99001,
            'pending' => false,
            'amount_cents' => 10000,
            'success' => true,
            'error_occured' => false,
            'integration_id' => 1234,
            'order' => ['id' => 5000, 'merchant_order_id' => null],
            'currency' => 'EGP',
            'is_live' => false,
            'is_refunded' => false,
            'is_voided' => false,
        ], $overrides);
    }

    private function fakeInquiry(array $transaction): void
    {
        $this->fakeInquiries(['*' => $transaction]);
    }

    /** @param array<string, array<string, mixed>|null> $transactionsByMerchantReference */
    private function fakeInquiries(array $transactionsByMerchantReference): void
    {
        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($transactionsByMerchantReference) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/auth/tokens') {
                return Http::response(['token' => 'short-lived-token'], 200);
            }

            if ($path !== '/api/ecommerce/orders/transaction_inquiry') {
                return Http::response(['detail' => 'unexpected request'], 500);
            }

            $merchantReference = $request->data()['merchant_order_id'] ?? null;
            $responseKey = is_string($merchantReference)
                && array_key_exists($merchantReference, $transactionsByMerchantReference)
                ? $merchantReference
                : '*';
            if (! array_key_exists($responseKey, $transactionsByMerchantReference)) {
                return Http::response(['detail' => 'unexpected inquiry'], 500);
            }

            $transaction = $transactionsByMerchantReference[$responseKey];
            if ($transaction === null) {
                return Http::response(['detail' => 'not found'], 404);
            }

            return Http::response($transaction, 200);
        });
    }
}
