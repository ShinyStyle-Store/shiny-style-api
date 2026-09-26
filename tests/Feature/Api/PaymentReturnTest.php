<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentAttemptException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PaymentAttemptService;
use App\Services\PaymentReturnTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_return_token_is_required_and_public_id_alone_is_not_authorization(): void
    {
        [$order] = $this->cardOrder();

        $this->getJson('/api/v1/payments/return')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_payment_return_token');

        $this->getJson('/api/v1/orders/'.$order->public_id.'/payment-status')
            ->assertForbidden();
    }

    public function test_pending_and_paid_results_are_repeatable_and_bound_to_the_attempt_and_order(): void
    {
        [$order, $attempt] = $this->cardOrder();
        $this->assertSame($order->getKey(), $attempt->order_id);
        $this->assertSame(get_debug_type($order->getKey()), get_debug_type($attempt->order_id));
        $token = app(PaymentReturnTokenService::class)->issue($order, $attempt);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertSame(hash('sha256', $token), DB::table('payment_attempts')->where('id', $attempt->getKey())->value('payment_return_token_hash'));
        $this->assertNotNull(DB::table('payment_attempts')->where('id', $attempt->getKey())->value('payment_return_token_expires_at'));
        $this->assertStringNotContainsString($token, (string) DB::table('payment_attempts')->where('id', $attempt->getKey())->value('payment_return_token_hash'));

        $this->withToken($token)->getJson('/api/v1/payments/return')
            ->assertOk()
            ->assertJsonPath('data.payment.resultCode', 'payment_pending')
            ->assertJsonPath('data.order.orderNumber', $order->order_number)
            ->assertJsonPath('data.order.items.0.productName', 'Snapshot Product')
            ->assertJsonMissingPath('data.order.payment.initiateUrl')
            ->assertJsonMissingPath('data.order.customer.email');

        $attempt->update([
            'status' => PaymentAttemptStatus::Paid,
            'paid_at' => now(),
        ]);
        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->withToken($token)->getJson('/api/v1/payments/return')
            ->assertOk()
            ->assertJsonPath('data.payment.resultCode', 'paid')
            ->assertJsonPath('data.payment.status', 'paid');

        $otherOrder = Order::factory()->guest()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $otherAttempt = app(PaymentAttemptService::class)->create($otherOrder, PaymentMethod::Card, (string) Str::uuid());
        $this->assertSame($otherOrder->getKey(), $otherAttempt->order_id);
        $this->assertSame(get_debug_type($otherOrder->getKey()), get_debug_type($otherAttempt->order_id));
        $this->assertNotSame($otherOrder->getKey(), $attempt->order_id);

        try {
            app(PaymentReturnTokenService::class)->issue($otherOrder, $attempt);
            $this->fail('Issuing a return token for an attempt from another order must fail.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('payment_return_token_binding_failed', $exception->errorCode);
        }
    }

    public function test_tampered_and_expired_tokens_are_rejected(): void
    {
        [$order, $attempt] = $this->cardOrder();
        $tokens = app(PaymentReturnTokenService::class);
        $token = $tokens->issue($order, $attempt);

        $this->withToken(substr($token, 0, -1).'x')->getJson('/api/v1/payments/return')
            ->assertUnauthorized();

        config(['payments.return_token_minutes' => 5]);
        $token = $tokens->issue($order, $attempt);
        $this->travel(6)->minutes();

        $this->withToken($token)->getJson('/api/v1/payments/return')
            ->assertUnauthorized();
    }

    private function cardOrder(): array
    {
        $order = Order::factory()->guest()->create([
            'customer_name' => 'Snapshot Customer',
            'customer_phone' => '01000000000',
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        OrderItem::factory()->create(['order_id' => $order->id]);
        $attempt = app(PaymentAttemptService::class)->create($order, PaymentMethod::Card, (string) Str::uuid());

        return [$order->fresh(), $attempt->fresh()];
    }
}
