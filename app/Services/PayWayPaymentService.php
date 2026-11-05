<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PayWay (ABA Bank) payment gateway integration.
 *
 * Implements the official PayWay Ecommerce Checkout API:
 *   - Purchase:           POST /api/payment-gateway/v1/payments/purchase
 *   - Check transaction:  POST /api/payment-gateway/v1/payments/check-transaction-2
 *
 * All request data is hashed with HMAC-SHA512 using the merchant API key.
 */
class PayWayPaymentService
{
    public const PURCHASE_PATH = '/api/payment-gateway/v1/payments/purchase';

    public const CHECK_PATH = '/api/payment-gateway/v1/payments/check-transaction-2';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_PRE_AUTH = 'PRE-AUTH';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_DECLINED = 'DECLINED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_REFUNDED = 'REFUNDED';

    public const PAYMENT_STATUS_CODE_APPROVED = 0;

    public const SUCCESS_RESPONSE_CODE = '00';

    /**
     * Field order used for the Purchase API hash, as documented by PayWay.
     * Empty optional fields still contribute an empty string to the hash.
     */
    private const PURCHASE_HASH_FIELDS = [
        'req_time',
        'merchant_id',
        'tran_id',
        'amount',
        'items',
        'shipping',
        'firstname',
        'lastname',
        'email',
        'phone',
        'type',
        'payment_option',
        'return_url',
        'cancel_url',
        'continue_success_url',
        'return_deeplink',
        'currency',
        'custom_fields',
        'return_params',
        'payout',
        'lifetime',
        'additional_params',
        'google_pay_token',
        'skip_success_page',
    ];

    /**
     * Create a PayWay purchase for the given local Payment record.
     *
     * @param  array<string, string>  $buyer
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function createPayment(Payment $payment, array $buyer = []): array
    {
        $merchantId = config('services.payway.merchant_id');
        $apiKey = config('services.payway.api_key');
        $callbackUrl = config('services.payway.callback_url');

        if (! $merchantId || ! $apiKey) {
            throw new RuntimeException('PayWay merchant settings are missing.');
        }

        if (! $callbackUrl) {
            throw new RuntimeException('PayWay callback URL (PAYWAY_CALLBACK_URL) is not configured.');
        }

        if (! $payment->transaction_id) {
            throw new RuntimeException('The payment does not have a PayWay transaction ID yet.');
        }

        $reqTime = now()->utc()->format('YmdHis');
        $currency = strtoupper($this->currency());
        $amount = (float) $payment->amount;

        $values = [
            'req_time' => $reqTime,
            'merchant_id' => $merchantId,
            'tran_id' => $payment->transaction_id,
            'amount' => $this->formatAmount($amount, $currency),
            'items' => $this->encodeItems($payment),
            'shipping' => '0',
            'firstname' => $buyer['firstname'] ?? '',
            'lastname' => $buyer['lastname'] ?? '',
            'email' => $buyer['email'] ?? '',
            'phone' => $buyer['phone'] ?? '',
            'type' => 'purchase',
            'payment_option' => config('services.payway.payment_option', ''),
            'return_url' => base64_encode($callbackUrl),
            'cancel_url' => config('services.payway.cancel_url') ?? config('services.payway.frontend_return_url', ''),
            'continue_success_url' => config('services.payway.frontend_return_url', ''),
            'return_deeplink' => '',
            'currency' => $currency,
            'custom_fields' => $this->encodeCustomFields($payment),
            'return_params' => '',
            'payout' => '',
            'lifetime' => (string) $this->lifetimeMinutes(),
            'additional_params' => '',
            'google_pay_token' => '',
            'skip_success_page' => '1',
        ];

        $values['hash'] = base64_encode(hash_hmac(
            'sha512',
            $this->concatenateForHash($values),
            $apiKey,
            true,
        ));

        try {
            $response = Http::timeout(15)
                ->asMultipart()
                ->acceptJson()
                ->post($this->purchaseUrl(), $values);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to PayWay API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayWay payment creation failed.');
        }

        $payload = $response->json();

        // Hosted checkout response (HTML). The frontend can submit the form
        // parameters directly to PayWay to start the checkout flow.
        if ($payload === null) {
            return [
                'checkout_url' => $this->purchaseUrl(),
                'checkout_form' => $values,
                'tran_id' => $payment->transaction_id,
                'amount' => (float) $amount,
                'currency' => $currency,
            ];
        }

        $code = data_get($payload, 'status.code');
        $message = data_get($payload, 'status.message', 'Unknown error.');

        if ((string) $code !== self::SUCCESS_RESPONSE_CODE) {
            throw new RuntimeException("PayWay error {$code}: {$message}");
        }

        return [
            'tran_id' => data_get($payload, 'status.tran_id', $payment->transaction_id),
            'status' => (string) $code,
            'status_message' => $message,
            'qr_string' => $payload['qr_string'] ?? null,
            'abapay_deeplink' => $payload['abapay_deeplink'] ?? null,
            'checkout_qr_url' => $payload['checkout_qr_url'] ?? null,
            'amount' => (float) $amount,
            'currency' => $currency,
        ];
    }

    /**
     * Query PayWay for the current status of a local payment.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function checkPayment(Payment $payment): array
    {
        $merchantId = config('services.payway.merchant_id');
        $apiKey = config('services.payway.api_key');

        if (! $merchantId || ! $apiKey) {
            throw new RuntimeException('PayWay merchant settings are missing.');
        }

        if (! $payment->transaction_id) {
            throw new RuntimeException('This payment does not have a PayWay transaction ID.');
        }

        $reqTime = now()->utc()->format('YmdHis');
        $hash = base64_encode(hash_hmac('sha512', $reqTime.$merchantId.$payment->transaction_id, $apiKey, true));

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->post($this->checkUrl(), [
                    'req_time' => $reqTime,
                    'merchant_id' => $merchantId,
                    'tran_id' => $payment->transaction_id,
                    'hash' => $hash,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to PayWay API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayWay payment check failed.');
        }

        return $response->json();
    }

    public function isPaid(array $payload): bool
    {
        $data = $payload['data'] ?? $payload;

        if (($data['payment_status_code'] ?? null) === self::PAYMENT_STATUS_CODE_APPROVED) {
            return true;
        }

        $status = strtoupper((string) ($data['payment_status'] ?? ''));

        return in_array($status, [self::STATUS_APPROVED, self::STATUS_PRE_AUTH], true);
    }

    /**
     * Verify the X-PAYWAY-HMAC-SHA512 header of a callback payload.
     *
     * Follows the documented verification: sort the payload by key,
     * concatenate all of its values, and compare an HMAC-SHA512 digest.
     */
    public function verifyCallbackSignature(array $payload, string $signature): bool
    {
        $secret = config('services.payway.callback_secret');

        if (! $secret || $signature === '') {
            return false;
        }

        ksort($payload);

        $b4hash = '';

        foreach ($payload as $value) {
            $b4hash .= is_array($value) ? json_encode($value) : (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha512', $b4hash, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Generate a unique PayWay transaction ID (max 20 characters).
     */
    public function generateTransactionId(): string
    {
        return 'PC'.now()->format('mdHis').Str::lower(Str::random(8));
    }

    private function purchaseUrl(): string
    {
        return config('services.payway.purchase_url')
            ?: rtrim((string) config('services.payway.base_url'), '/').self::PURCHASE_PATH;
    }

    private function checkUrl(): string
    {
        return config('services.payway.check_url')
            ?: rtrim((string) config('services.payway.base_url'), '/').self::CHECK_PATH;
    }

    private function currency(): string
    {
        return (string) (config('services.payway.currency', 'USD') ?: 'USD');
    }

    private function formatAmount(float $amount, string $currency): string
    {
        if ($currency === 'KHR') {
            return (string) (int) round($amount);
        }

        return number_format($amount, 2, '.', '');
    }

    private function lifetimeMinutes(): int
    {
        return max(3, (int) config('services.payway.lifetime_minutes', 60));
    }

    private function encodeItems(Payment $payment): string
    {
        $title = $payment->course?->title ?? 'Course';

        return base64_encode(json_encode([
            ['name' => $title, 'quantity' => 1, 'price' => (float) $payment->amount],
        ]));
    }

    private function encodeCustomFields(Payment $payment): string
    {
        return base64_encode(json_encode([
            'payment_id' => $payment->id,
            'course_id' => $payment->course_id,
            'tran_id' => $payment->transaction_id,
        ]));
    }

    /**
     * Concatenate the values of the posted fields in the documented order.
     *
     * @param  array<string, mixed>  $values
     */
    private function concatenateForHash(array $values): string
    {
        $b4hash = '';

        foreach (self::PURCHASE_HASH_FIELDS as $field) {
            $b4hash .= (string) ($values[$field] ?? '');
        }

        return $b4hash;
    }
}
