<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Payment;
use App\Services\PayWayPaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayWayPaymentServiceTest extends TestCase
{
    private function configPayWay(): void
    {
        config([
            'services.payway.env' => 'sandbox',
            'services.payway.base_url' => 'https://checkout-sandbox.payway.com.kh',
            'services.payway.merchant_id' => 'TEST_MERCHANT',
            'services.payway.api_key' => 'test-api-key',
            'services.payway.callback_secret' => 'test-callback-secret',
            'services.payway.purchase_url' => null,
            'services.payway.check_url' => null,
            'services.payway.frontend_return_url' => 'https://example.test/success',
            'services.payway.cancel_url' => 'https://example.test/cancel',
            'services.payway.callback_url' => 'https://example.test/api/payments/payway/callback',
            'services.payway.currency' => 'USD',
            'services.payway.payment_option' => 'abapay_khqr_deeplink',
            'services.payway.lifetime_minutes' => 60,
        ]);
    }

    private function payment(): Payment
    {
        $payment = new Payment;

        $payment->forceFill([
            'amount' => 100.00,
            'status' => 'pending',
            'transaction_id' => 'PC1234567890',
        ]);

        $payment->course_id = 1;

        $payment->setRelation('course', (new Course)->forceFill(['title' => 'Test Course']));

        return $payment;
    }

    private function multipartFields(Request $request): array
    {
        $body = $request->body();
        $fields = [];

        if (! preg_match('/(?:^|\r\n)--([^\r\n]+)/', $body, $match)) {
            return $fields;
        }

        $boundary = $match[1];

        foreach (explode('--'.$boundary, $body) as $part) {
            if (! preg_match('/name="([^"]+)"/', $part, $nameMatch)) {
                continue;
            }

            $fields[$nameMatch[1]] = trim(explode("\r\n\r\n", $part, 2)[1] ?? '', "\r\n");
        }

        return $fields;
    }

    public function test_purchase_request_is_posted_with_valid_hash(): void
    {
        $this->configPayWay();

        $payment = $this->payment();
        $tranId = $payment->transaction_id;

        Http::fake([
            '*' => function ($request) use ($tranId) {
                $values = $this->multipartFields($request);
                $this->assertSame($tranId, $values['tran_id']);
                $this->assertSame('TEST_MERCHANT', $values['merchant_id']);
                $this->assertSame('purchase', $values['type']);
                $this->assertSame('abapay_khqr_deeplink', $values['payment_option']);

                $b4hash = implode('', array_map(
                    fn ($field) => (string) ($values[$field] ?? ''),
                    [
                        'req_time', 'merchant_id', 'tran_id', 'amount', 'items', 'shipping',
                        'firstname', 'lastname', 'email', 'phone', 'type', 'payment_option',
                        'return_url', 'cancel_url', 'continue_success_url', 'return_deeplink',
                        'currency', 'custom_fields', 'return_params', 'payout', 'lifetime',
                        'additional_params', 'google_pay_token', 'skip_success_page',
                    ]
                ));

                $this->assertSame(
                    base64_encode(hash_hmac('sha512', $b4hash, 'test-api-key', true)),
                    $values['hash']
                );

                return Http::response([
                    'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => $tranId],
                    'qr_string' => '0002010102123051',
                    'abapay_deeplink' => 'abamobilebank://ababank.com?type=payway&qrcode=0002010102123',
                    'checkout_qr_url' => 'https://checkout-sandbox.payway.com.kh/checkout/'.$tranId,
                ], 200);
            },
        ]);

        $result = (new PayWayPaymentService)->createPayment($payment, [
            'firstname' => 'Chanrith',
            'lastname' => 'Yim',
            'email' => 'chanrith@example.com',
        ]);

        $this->assertSame($tranId, $result['tran_id']);
        $this->assertSame('00', $result['status']);
        $this->assertSame('0002010102123051', $result['qr_string']);
        $this->assertSame('https://checkout-sandbox.payway.com.kh/checkout/'.$tranId, $result['checkout_qr_url']);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame('USD', $result['currency']);
    }

    public function test_purchase_rejects_payway_error_code(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response([
                'status' => ['code' => 1, 'message' => 'Wrong hash'],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayWay error 1: Wrong hash');

        (new PayWayPaymentService)->createPayment($this->payment());
    }

    public function test_purchase_hosted_html_response_returns_checkout_url_and_form(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response('<!DOCTYPE html><html><body>PayWay Checkout</body></html>', 200),
        ]);

        $result = (new PayWayPaymentService)->createPayment($this->payment());

        $this->assertArrayHasKey('checkout_url', $result);
        $this->assertArrayHasKey('checkout_form', $result);
        $this->assertArrayHasKey('hash', $result['checkout_form']);
        $this->assertStringContainsString('payments/purchase', $result['checkout_url']);
    }

    public function test_missing_merchant_settings_throws(): void
    {
        config([
            'services.payway.merchant_id' => null,
            'services.payway.api_key' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayWay merchant settings are missing.');

        (new PayWayPaymentService)->createPayment($this->payment());
    }

    public function test_missing_callback_url_throws(): void
    {
        $this->configPayWay();
        config(['services.payway.callback_url' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PAYWAY_CALLBACK_URL');

        (new PayWayPaymentService)->createPayment($this->payment());
    }

    public function test_check_transaction_request_is_posted_with_valid_hash(): void
    {
        $this->configPayWay();

        $payment = $this->payment();
        $tranId = $payment->transaction_id;

        Http::fake([
            '*' => function ($request) use ($tranId) {
                $values = $request->data();
                $this->assertSame($tranId, $values['tran_id']);

                $this->assertSame(
                    base64_encode(hash_hmac('sha512', $values['req_time'].'TEST_MERCHANT'.$tranId, 'test-api-key', true)),
                    $values['hash']
                );

                return Http::response([
                    'data' => [
                        'payment_status_code' => 0,
                        'payment_status' => 'APPROVED',
                        'status' => ['code' => '00', 'message' => 'Success!'],
                    ],
                ], 200);
            },
        ]);

        $result = (new PayWayPaymentService)->checkPayment($payment);

        $this->assertSame(0, $result['data']['payment_status_code']);
    }

    public function test_check_transaction_connection_error_throws(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not connect to PayWay API.');

        (new PayWayPaymentService)->checkPayment($this->payment());
    }

    public function test_check_transaction_http_failure_throws(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayWay payment check failed.');

        (new PayWayPaymentService)->checkPayment($this->payment());
    }

    public function test_is_paid_detects_approved_transactions(): void
    {
        $service = new PayWayPaymentService;

        $this->assertTrue($service->isPaid([
            'data' => ['payment_status_code' => 0],
        ]));

        $this->assertTrue($service->isPaid([
            'data' => ['payment_status' => 'APPROVED'],
        ]));

        $this->assertTrue($service->isPaid([
            'data' => ['payment_status' => 'PRE-AUTH'],
        ]));

        $this->assertFalse($service->isPaid([
            'data' => ['payment_status_code' => 2, 'payment_status' => 'PENDING'],
        ]));

        $this->assertFalse($service->isPaid([
            'data' => ['payment_status_code' => 3, 'payment_status' => 'DECLINED'],
        ]));

        $this->assertFalse($service->isPaid([
            'data' => ['payment_status_code' => 7, 'payment_status' => 'CANCELLED'],
        ]));
    }

    public function test_callback_signature_verification(): void
    {
        $this->configPayWay();

        $payload = [
            'tran_id' => 'PC123',
            'apv' => '544415',
            'status' => '0',
            'total_amount' => 10,
        ];

        ksort($payload);
        $b4hash = implode('', array_map(fn ($value) => (string) $value, $payload));
        $signature = base64_encode(hash_hmac('sha512', $b4hash, 'test-callback-secret', true));

        $service = new PayWayPaymentService;

        $this->assertTrue($service->verifyCallbackSignature($payload, $signature));
        $this->assertFalse($service->verifyCallbackSignature($payload, 'tampered'));
        $this->assertFalse($service->verifyCallbackSignature($payload, ''));
    }

    public function test_callback_signature_requires_configured_secret(): void
    {
        $this->configPayWay();
        config(['services.payway.callback_secret' => null]);

        $service = new PayWayPaymentService;

        $this->assertFalse($service->verifyCallbackSignature(['tran_id' => 'PC123'], 'anything'));
    }

    public function test_generate_transaction_id_is_unique_and_within_limit(): void
    {
        $service = new PayWayPaymentService;

        $first = $service->generateTransactionId();
        $second = $service->generateTransactionId();

        $this->assertLessThanOrEqual(20, strlen($first));
        $this->assertNotSame($first, $second);
    }
}
