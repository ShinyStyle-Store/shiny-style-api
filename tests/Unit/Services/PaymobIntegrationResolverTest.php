<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\PaymobConfigurationException;
use App\Services\PaymobIntegrationResolver;
use Tests\TestCase;

class PaymobIntegrationResolverTest extends TestCase
{
    public function test_card_resolves_only_the_card_integration(): void
    {
        config([
            'services.paymob.card_integration_id' => '12345',
            'services.paymob.wallet_integration_id' => '67890',
        ]);

        $this->assertSame(12345, app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::Card));
    }

    public function test_wallet_resolves_only_the_wallet_integration(): void
    {
        config([
            'services.paymob.card_integration_id' => '12345',
            'services.paymob.wallet_integration_id' => '67890',
        ]);

        $this->assertSame(67890, app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::Wallet));
    }

    public function test_missing_wallet_does_not_fall_back_to_card(): void
    {
        config([
            'services.paymob.card_integration_id' => '12345',
            'services.paymob.wallet_integration_id' => null,
        ]);

        try {
            app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::Wallet);
            $this->fail('A missing wallet integration must be rejected.');
        } catch (PaymobConfigurationException $exception) {
            $this->assertSame('missing_wallet_integration_id', $exception->errorCode);
            $this->assertStringNotContainsString('12345', $exception->getMessage());
        }
    }

    public function test_missing_card_integration_is_rejected(): void
    {
        config(['services.paymob.card_integration_id' => null]);

        $this->expectException(PaymobConfigurationException::class);
        app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::Card);
    }

    public function test_non_positive_and_malformed_integration_ids_are_rejected(): void
    {
        foreach (['0', '-1', '1.5', 'abc', '999999999999999999999999'] as $value) {
            config(['services.paymob.card_integration_id' => $value]);

            try {
                app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::Card);
                $this->fail("Integration ID {$value} should be rejected.");
            } catch (PaymobConfigurationException $exception) {
                $this->assertSame('invalid_card_integration_id', $exception->errorCode);
            }
        }
    }

    public function test_cash_on_delivery_is_not_a_paymob_method(): void
    {
        try {
            app(PaymobIntegrationResolver::class)->resolve(PaymentMethod::CashOnDelivery);
            $this->fail('Cash on delivery must not resolve a Paymob integration.');
        } catch (PaymobConfigurationException $exception) {
            $this->assertSame('unsupported_payment_method', $exception->errorCode);
        }
    }

    public function test_cod_does_not_require_paymob_credentials(): void
    {
        config([
            'services.paymob.secret_key' => null,
            'services.paymob.card_integration_id' => null,
            'services.paymob.wallet_integration_id' => null,
        ]);

        $this->assertSame(PaymentMethod::CashOnDelivery, PaymentMethod::from('cash_on_delivery'));
        $this->assertTrue(PaymentMethod::CashOnDelivery !== PaymentMethod::Card);
    }
}
