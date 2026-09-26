<?php

namespace Tests\Unit\Services;

use App\Exceptions\PaymobWebhookException;
use App\Services\PaymobTransactionHmacVerifier;
use Tests\TestCase;

class PaymobTransactionHmacVerifierTest extends TestCase
{
    // Independent official transaction-vector regression fixture; this is not produced by the verifier.
    private const HMAC = '6cd0fcacc32842fb1fd6fdea6e02896788163053c1b80196e2d95927209ad53f9608a0fd1f55e507a565389a55995e6c52f89df778c58f416121662749143518';
    private const SIGNED = '170002026-09-26T12:00:00EGPfalsefalse123456falsefalsefalsefalsefalsefalse987654false321MasterCardcardtrue';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymob.hmac_secret' => 'test-hmac-secret']);
    }

    public function test_it_verifies_the_documented_transaction_field_order(): void
    {
        $payload = $this->payload();
        $signed = implode('', [
            (string) $payload['amount_cents'],
            (string) $payload['created_at'],
            (string) $payload['currency'],
            $payload['error_occured'] ? 'true' : 'false',
            $payload['has_parent_transaction'] ? 'true' : 'false',
            (string) $payload['id'],
            (string) $payload['integration_id'],
            $payload['is_3d_secure'] ? 'true' : 'false',
            $payload['is_auth'] ? 'true' : 'false',
            $payload['is_capture'] ? 'true' : 'false',
            $payload['is_refunded'] ? 'true' : 'false',
            $payload['is_standalone_payment'] ? 'true' : 'false',
            $payload['is_voided'] ? 'true' : 'false',
            (string) $payload['order']['id'],
            (string) $payload['owner'],
            $payload['pending'] ? 'true' : 'false',
            (string) $payload['source_data']['pan'],
            (string) $payload['source_data']['sub_type'],
            (string) $payload['source_data']['type'],
            $payload['success'] ? 'true' : 'false',
        ]);

        $this->assertSame(self::SIGNED, $signed);
        $this->assertSame(self::HMAC, hash_hmac('sha512', $signed, 'test-hmac-secret'));
        app(PaymobTransactionHmacVerifier::class)->verify($payload, self::HMAC);
    }

    public function test_a_changed_signed_value_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['amount_cents'] = 17001;

        $this->expectException(PaymobWebhookException::class);
        app(PaymobTransactionHmacVerifier::class)->verify($payload, self::HMAC);
    }

    public function test_missing_signed_field_and_hmac_are_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['source_data']['pan']);

        try {
            app(PaymobTransactionHmacVerifier::class)->verify($payload, self::HMAC);
            $this->fail('A missing signed field must be rejected.');
        } catch (PaymobWebhookException $exception) {
            $this->assertSame('invalid_hmac', $exception->errorCode);
        }

        $this->expectException(PaymobWebhookException::class);
        app(PaymobTransactionHmacVerifier::class)->verify($this->payload(), null);
    }

    public function test_wrong_secret_is_rejected_without_exposing_secret_material(): void
    {
        config(['services.paymob.hmac_secret' => 'wrong-secret']);

        try {
            app(PaymobTransactionHmacVerifier::class)->verify($this->payload(), self::HMAC);
            $this->fail('A signature made with another secret must be rejected.');
        } catch (PaymobWebhookException $exception) {
            $this->assertSame('invalid_hmac', $exception->errorCode);
            $this->assertStringNotContainsString('wrong-secret', $exception->getMessage());
            $this->assertStringNotContainsString(self::HMAC, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id' => 123,
            'pending' => false,
            'amount_cents' => 17000,
            'success' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_standalone_payment' => false,
            'is_voided' => false,
            'is_refunded' => false,
            'is_3d_secure' => false,
            'integration_id' => 456,
            'has_parent_transaction' => false,
            'order' => ['id' => 987],
            'created_at' => '2026-09-26T12:00:00',
            'currency' => 'EGP',
            'owner' => 654,
            'error_occured' => false,
            'source_data' => ['pan' => '321', 'sub_type' => 'MasterCard', 'type' => 'card'],
        ];
    }
}
