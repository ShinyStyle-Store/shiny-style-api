<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\PaymentAttemptService;
use App\Services\PaymentReturnTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $token = app(PaymentReturnTokenService::class)->issue($order, $attempt);

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
        app(PaymentAttemptService::class)->create($otherOrder, PaymentMethod::Card, (string) Str::uuid());
        $wrongBindingToken = app(PaymentReturnTokenService::class)->issue($otherOrder, $attempt);

        $this->withToken($wrongBindingToken)->getJson('/api/v1/payments/return')
            ->assertUnauthorized();
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
