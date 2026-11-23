<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\PaymobPaymentTransaction;
use App\Models\Product;
use App\Models\SellableItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanupLaunchDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_delete_any_records(): void
    {
        $category = $this->makeCategory('fake-cat-1');
        $product = $this->makeProduct('fake-prod-1');
        $product->categories()->attach($category->getKey());
        $order = Order::factory()->create();

        $this->artisan('maintenance:cleanup-launch-data', [
            '--all-orders' => true,
            '--product-ids' => (string) $product->getKey(),
            '--category-ids' => (string) $category->getKey(),
        ])
            ->expectsOutputToContain('DRY-RUN complete')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $order->getKey()]);
        $this->assertDatabaseHas('products', ['id' => $product->getKey()]);
        $this->assertDatabaseHas('categories', ['id' => $category->getKey()]);
    }

    public function test_execute_cleans_orders_payments_fake_products_and_fake_categories_while_releasing_real_reservations(): void
    {
        $realCategory = $this->makeCategory('real-cat');
        $fakeCategory1 = $this->makeCategory('fake-cat-1');
        $fakeCategory2 = $this->makeCategory('fake-cat-2');

        $realProduct = $this->makeProduct('real-prod');
        $realProduct->categories()->attach($realCategory->getKey());
        $realVariant = $this->makeVariant($realProduct, 'SKU-REAL-1', stock: 10, reserved: 2);

        $fakeProduct = $this->makeProduct('fake-prod');
        $fakeProduct->categories()->attach($fakeCategory1->getKey());
        $fakeVariant = $this->makeVariant($fakeProduct, 'SKU-FAKE-1', stock: 5, reserved: 1);

        $archivedFakeProduct = $this->makeProduct('archived-fake-prod');
        $archivedFakeProduct->categories()->attach($fakeCategory2->getKey());
        $archivedFakeProduct->delete();

        $order = Order::factory()->create([
            'status' => OrderStatus::PendingConfirmation,
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'total' => '200.00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $realProduct->getKey(),
            'sellable_item_id' => $realVariant->getKey(),
            'quantity' => 2,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $fakeProduct->getKey(),
            'sellable_item_id' => $fakeVariant->getKey(),
            'quantity' => 1,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'order_id' => $order->getKey(),
            'provider' => PaymentProvider::Paymob,
            'method' => PaymentMethod::Card,
            'status' => PaymentAttemptStatus::Pending,
            'amount_minor' => 20000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'test'),
            'provider_order_id' => '999001',
            'integration_id' => 456,
            'expires_at' => now()->addMinutes(30),
        ]);

        PaymobPaymentTransaction::query()->create([
            'order_id' => $order->getKey(),
            'payment_attempt_id' => $attempt->getKey(),
            'provider_transaction_id' => '888001',
            'provider_order_id' => '999001',
            'merchant_reference' => $attempt->merchant_reference,
            'amount_minor' => 20000,
            'currency' => 'EGP',
            'integration_id' => 456,
            'status' => PaymentAttemptStatus::Pending,
            'success' => false,
            'pending' => true,
            'error_occurred' => false,
            'is_refunded' => false,
            'is_voided' => false,
            'received_at' => now(),
        ]);

        $this->artisan('maintenance:cleanup-launch-data', [
            '--all-orders' => true,
            '--product-ids' => $fakeProduct->getKey().','.$archivedFakeProduct->getKey(),
            '--category-ids' => $fakeCategory1->getKey().','.$fakeCategory2->getKey(),
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('paymob_payment_transactions', 0);

        $this->assertDatabaseMissing('products', ['id' => $fakeProduct->getKey()]);
        $this->assertDatabaseMissing('products', ['id' => $archivedFakeProduct->getKey()]);
        $this->assertDatabaseMissing('sellable_items', ['id' => $fakeVariant->getKey()]);
        $this->assertDatabaseMissing('categories', ['id' => $fakeCategory1->getKey()]);
        $this->assertDatabaseMissing('categories', ['id' => $fakeCategory2->getKey()]);

        $this->assertDatabaseHas('categories', ['id' => $realCategory->getKey()]);
        $this->assertDatabaseHas('products', ['id' => $realProduct->getKey()]);
        $this->assertSame(0, $realVariant->fresh()->reserved_quantity);
        $this->assertSame(10, $realVariant->fresh()->stock_quantity);
    }

    public function test_it_blocks_deleting_a_category_that_still_has_a_real_product(): void
    {
        $category = $this->makeCategory('fake-cat');
        $realProduct = $this->makeProduct('real-prod');
        $realProduct->categories()->attach($category->getKey());

        $this->artisan('maintenance:cleanup-launch-data', [
            '--category-ids' => (string) $category->getKey(),
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Cleanup blocked due to safety checks')
            ->assertExitCode(1);

        $this->assertDatabaseHas('categories', ['id' => $category->getKey()]);
    }

    public function test_list_option_runs_cleanly_without_modifying_data(): void
    {
        $this->makeCategory('cat-list-test');
        $this->makeProduct('prod-list-test');
        Order::factory()->create();

        $this->artisan('maintenance:cleanup-launch-data', ['--list' => true])
            ->expectsOutputToContain('cat-list-test')
            ->expectsOutputToContain('prod-list-test')
            ->assertExitCode(0);
    }

    private function makeCategory(string $slug): Category
    {
        return Category::query()->create([
            'slug' => $slug,
            'name_ar' => $slug.' ar',
            'name_en' => $slug.' en',
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $slug): Product
    {
        return Product::query()->create([
            'slug' => $slug,
            'name_ar' => $slug.' ar',
            'name_en' => $slug.' en',
            'status' => 'published',
        ]);
    }

    private function makeVariant(Product $product, string $sku, int $stock, int $reserved): SellableItem
    {
        return SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => $sku,
            'price' => '100.00',
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'status' => 'active',
            'is_default' => true,
        ]);
    }
}
