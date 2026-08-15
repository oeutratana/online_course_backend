<?php

namespace Tests\Unit;

use App\Services\BakongPaymentService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BakongPaymentServiceTest extends TestCase
{
    public function test_local_khqr_uses_timestamp_template(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs(1700000000000));

        config([
            'services.bakong.bakong_id' => 'john_smith@devb',
            'services.bakong.merchant_name' => 'John Smith',
            'services.bakong.merchant_city' => 'Phnom Penh',
            'services.bakong.currency' => '840',
            'services.bakong.qr_mode' => 'local',
            'services.bakong.qr_ttl_minutes' => 60,
        ]);

        $qr = (new BakongPaymentService())->createQr(100);

        $this->assertSame(BakongPaymentService::LOCAL_QR_VERSION, $qr['version']);
        $this->assertStringContainsString('99340013170000000000001131700003600000', $qr['qr_string']);
        $this->assertStringNotContainsString('99131700000000000', $qr['qr_string']);
        $this->assertSame('2023-11-15T05:13:20+07:00', $qr['created_at']);
        $this->assertSame('2023-11-15T06:13:20+07:00', $qr['expires_at']);
        $this->assertMatchesRegularExpression('/6304[A-F0-9]{4}$/', $qr['qr_string']);

        Carbon::setTestNow();
    }
}
