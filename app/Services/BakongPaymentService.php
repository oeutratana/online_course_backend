<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BakongPaymentService
{
    public const LOCAL_QR_VERSION = 'khqr-solo-v4';

    public function createQr(float $amount): array
    {
        $bakongId = config('services.bakong.bakong_id');
        $merchantName = config('services.bakong.merchant_name');

        if (! $bakongId || ! $merchantName) {
            throw new RuntimeException('Bakong merchant settings are missing.');
        }

        if (config('services.bakong.qr_mode') === 'local') {
            return $this->createLocalQr($amount, $bakongId, $merchantName);
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get(rtrim(config('services.bakong.base_url'), '/') . '/khqr/create', [
                    'amount' => number_format($amount, 2, '.', ''),
                    'bakongid' => $bakongId,
                    'merchantname' => $merchantName,
                ]);
        } catch (ConnectionException $exception) {
            return $this->createLocalQr($amount, $bakongId, $merchantName);
        }

        if (! $response->successful()) {
            return $this->createLocalQr($amount, $bakongId, $merchantName);
        }

        $data = $response->json();

        if (empty($data['qr']) || empty($data['md5'])) {
            return $this->createLocalQr($amount, $bakongId, $merchantName);
        }

        return $data;
    }

    public function checkPayment(Payment $payment): array
    {
        if (! $payment->bakong_md5) {
            throw new RuntimeException('This payment does not have a Bakong MD5 reference.');
        }

        if ($token = config('services.bakong.token')) {
            return $this->checkOfficialBakong($payment->bakong_md5, $token);
        }

        return $this->checkPublicBakong($payment->bakong_md5);
    }

    private function checkPublicBakong(string $md5): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get(rtrim(config('services.bakong.base_url'), '/') . '/check_by_md5', [
                    'md5' => $md5,
                    'bakongid' => config('services.bakong.bakong_id'),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to Bakong API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Bakong payment check failed.');
        }

        return $response->json();
    }

    private function checkOfficialBakong(string $md5, string $token): array
    {
        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->acceptJson()
                ->post(rtrim(config('services.bakong.official_base_url'), '/') . '/v1/check_transaction_by_md5', [
                    'md5' => $md5,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to Bakong API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Bakong payment check failed.');
        }

        return $response->json();
    }

    public function isPaid(array $payload): bool
    {
        if (($payload['responseCode'] ?? null) === 0) {
            return true;
        }

        $status = strtolower((string) data_get($payload, 'data.status', $payload['status'] ?? ''));

        return in_array($status, ['success', 'paid', 'completed'], true);
    }

    private function createLocalQr(float $amount, string $bakongId, string $merchantName): array
    {
        $billNumber = 'course-' . Str::lower(Str::random(10));
        $createdAt = now();
        $expiresAt = $createdAt->copy()->addMinutes(config('services.bakong.qr_ttl_minutes', 60));
        $qrString = $this->khqrPayload($amount, $bakongId, $merchantName, $billNumber, $createdAt, $expiresAt);

        return [
            'qr' => 'https://quickchart.io/qr?size=320&text=' . rawurlencode($qrString),
            'md5' => md5($qrString),
            'tran' => $billNumber,
            'qr_string' => $qrString,
            'mode' => 'local',
            'version' => self::LOCAL_QR_VERSION,
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function khqrPayload(float $amount, string $bakongId, string $merchantName, string $billNumber, $createdAt, $expiresAt): string
    {
        $merchantAccount = $this->field('00', $bakongId);
        $timestampData = $this->field('00', (string) $createdAt->getTimestampMs())
            . $this->field('01', (string) $expiresAt->getTimestampMs());

        $payload = $this->field('00', '01')
            . $this->field('01', '12')
            . $this->field('29', $merchantAccount)
            . $this->field('52', '5999')
            . $this->field('53', config('services.bakong.currency', '840'))
            . $this->field('54', $this->amountValue($amount))
            . $this->field('58', 'KH')
            . $this->field('59', Str::limit($merchantName, 25, ''))
            . $this->field('60', Str::limit(config('services.bakong.merchant_city', 'Phnom Penh'), 15, ''))
            . $this->field('62', $this->field('01', Str::limit($billNumber, 25, '')))
            . $this->field('99', $timestampData)
            . '6304';

        return $payload . $this->crc16($payload);
    }

    private function amountValue(float $amount): string
    {
        if (config('services.bakong.currency', '840') === '116') {
            return (string) (int) round($amount);
        }

        return number_format($amount, 2, '.', '');
    }

    private function field(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;

        for ($offset = 0; $offset < strlen($payload); $offset++) {
            $crc ^= ord($payload[$offset]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021)
                    : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
