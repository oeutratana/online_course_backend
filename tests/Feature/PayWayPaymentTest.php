<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayWayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK_SECRET = 'test-callback-secret';

    private function configPayWay(): void
    {
        config([
            'services.payway.env' => 'sandbox',
            'services.payway.base_url' => 'https://checkout-sandbox.payway.com.kh',
            'services.payway.merchant_id' => 'TEST_MERCHANT',
            'services.payway.api_key' => 'test-api-key',
            'services.payway.callback_secret' => self::CALLBACK_SECRET,
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

    private function qrResponse(string $tranId): array
    {
        return [
            'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => $tranId],
            'qr_string' => '00020101021230510016abaakhppxx@abaa0115325',
            'abapay_deeplink' => 'abamobilebank://ababank.com?type=payway&qrcode=0002010102123',
            'checkout_qr_url' => 'https://checkout-sandbox.payway.com.kh/checkout/'.$tranId,
        ];
    }

    private function approvedResponse(int $amount = 10): array
    {
        return [
            'data' => [
                'payment_status_code' => 0,
                'total_amount' => $amount,
                'original_amount' => $amount,
                'payment_amount' => $amount,
                'payment_currency' => 'USD',
                'payment_status' => 'APPROVED',
                'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => 'gateway-tran-id'],
            ],
        ];
    }

    private function pendingResponse(): array
    {
        return [
            'data' => [
                'payment_status_code' => 2,
                'payment_status' => 'PENDING',
                'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => 'gateway-tran-id'],
            ],
        ];
    }

    private function declinedResponse(): array
    {
        return [
            'data' => [
                'payment_status_code' => 3,
                'payment_status' => 'DECLINED',
                'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => 'gateway-tran-id'],
            ],
        ];
    }

    private function signedCallback(array $payload): string
    {
        ksort($payload);

        $b4hash = '';

        foreach ($payload as $value) {
            $b4hash .= is_array($value) ? json_encode($value) : (string) $value;
        }

        return base64_encode(hash_hmac('sha512', $b4hash, self::CALLBACK_SECRET, true));
    }

    // ─── CREATE PAYMENT ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_create_payment(): void
    {
        $course = Course::factory()->create(['price' => 10]);

        $response = $this->postJson('/api/payments', [
            'course_id' => $course->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_create_payway_payment_for_paid_course(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PC123'), 200),
        ]);

        $user = User::factory()->create(['name' => 'Chanrith Yim']);
        $course = Course::factory()->create(['price' => 25.50]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Payment created successfully.',
                'data' => [
                    'course_id' => $course->id,
                    'amount' => 25.50,
                    'currency' => 'USD',
                    'status' => 'pending',
                    'qr_url' => 'https://checkout-sandbox.payway.com.kh/checkout/PC123',
                    'qr_string' => '00020101021230510016abaakhppxx@abaa0115325',
                ],
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'payment_id',
                    'course_id',
                    'amount',
                    'currency',
                    'status',
                    'transaction_id',
                    'checkout_url',
                    'checkout_form',
                    'qr_url',
                    'qr_string',
                    'abapay_deeplink',
                    'paid_at',
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotNull($data['payment_id']);
        $this->assertNotNull($data['transaction_id']);
        $this->assertNull($data['paid_at']);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 25.50,
            'method' => 'payway',
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_user_can_create_payment_for_free_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 0]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Free course enrolled successfully.',
                'data' => [
                    'amount' => 0,
                    'status' => 'paid',
                ],
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_course_id_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => 99999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_missing_course_id_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/payments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_frontend_cannot_control_payment_amount(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PC123'), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 50]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
                'amount' => 1,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 50,
        ]);

        $this->assertDatabaseMissing('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1,
        ]);
    }

    public function test_payment_is_always_attached_to_the_authenticated_user(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PC123'), 200),
        ]);

        $other = User::factory()->create();
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
                'user_id' => $other->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        $this->assertDatabaseMissing('payments', [
            'user_id' => $other->id,
        ]);
    }

    public function test_returns_existing_pending_payment_if_fresh(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PC123'), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 100]);

        $first = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $first->assertStatus(201);
        $firstId = $first->json('data.payment_id');

        $second = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $second->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Existing pending payment returned.',
            ]);

        $this->assertEquals($firstId, $second->json('data.payment_id'));

        $this->assertCount(1, Payment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['pending', 'paid'])
            ->get());
    }

    public function test_pending_payment_without_qr_url_is_reused_for_deeplink_flow(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response([
                'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => 'PC123'],
                'qr_string' => '',
                'abapay_deeplink' => 'abamobilebank://ababank.com?type=payway&qrcode=0002010102123',
                'checkout_qr_url' => '',
            ], 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 100]);

        $first = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $first->assertStatus(201)
            ->assertJsonPath('data.abapay_deeplink', 'abamobilebank://ababank.com?type=payway&qrcode=0002010102123')
            ->assertJsonPath('data.qr_url', null);

        $firstId = $first->json('data.payment_id');

        $second = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $second->assertStatus(200)
            ->assertJsonPath('data.payment_id', $firstId);

        $this->assertCount(1, Payment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('status', ['pending', 'paid'])
            ->get());
    }

    public function test_failed_payment_is_replaced_with_a_fresh_checkout(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PCfresh'), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 100]);

        $failed = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 100,
            'status' => Payment::STATUS_FAILED,
            'transaction_id' => 'PColdfailed001',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Payment created successfully.',
                'data' => ['status' => 'pending'],
            ]);

        // The single-use transaction_id on the failed row is never reused.
        $this->assertNotSame($failed->id, $response->json('data.payment_id'));
        $this->assertNotSame($failed->transaction_id, $response->json('data.transaction_id'));

        $this->assertDatabaseHas('payments', [
            'id' => $failed->id,
            'status' => Payment::STATUS_FAILED,
        ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_expired_payment_is_replaced_with_a_fresh_checkout(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PCfresh'), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 100]);

        $expired = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 100,
            'status' => Payment::STATUS_EXPIRED,
            'transaction_id' => 'PColdexpired1',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $response->assertStatus(201);

        $this->assertNotSame($expired->id, $response->json('data.payment_id'));
        $this->assertNotSame($expired->transaction_id, $response->json('data.transaction_id'));

        $this->assertDatabaseHas('payments', [
            'id' => $expired->id,
            'status' => Payment::STATUS_EXPIRED,
        ]);
    }

    public function test_stale_pending_payment_is_expired_and_replaced_with_a_fresh_checkout(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->qrResponse('PCfresh'), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 100]);

        $stale = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 100,
            'status' => Payment::STATUS_PENDING,
            'transaction_id' => 'PColdpending1',
            'created_at' => now()->subMinutes(120),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', ['course_id' => $course->id]);

        $response->assertStatus(201);

        $this->assertNotSame($stale->id, $response->json('data.payment_id'));

        $this->assertDatabaseHas('payments', [
            'id' => $stale->id,
            'status' => Payment::STATUS_EXPIRED,
        ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_check_on_failed_payment_requires_a_new_checkout(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => Payment::STATUS_FAILED,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'This payment is no longer payable. Start a new payment to get a fresh checkout.',
                'data' => [
                    'status' => Payment::STATUS_FAILED,
                    'requires_new_checkout' => true,
                ],
            ]);
    }

    public function test_check_on_expired_payment_requires_a_new_checkout(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => Payment::STATUS_EXPIRED,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'status' => Payment::STATUS_EXPIRED,
                    'requires_new_checkout' => true,
                ],
            ]);
    }

    public function test_already_paid_payment_returns_200(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Payment already completed.',
                'data' => ['status' => 'paid'],
            ]);
    }

    public function test_payway_api_failure_during_create_marks_payment_failed(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response([
                'status' => ['code' => 1, 'message' => 'Wrong hash'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
            ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_payway_connection_timeout_during_create_is_handled(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $response = $this->actingAs($user)
            ->postJson('/api/payments', [
                'course_id' => $course->id,
            ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'failed',
        ]);
    }

    // ─── CHECK PAYMENT ───────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_check_payment(): void
    {
        $payment = Payment::factory()->create(['status' => 'pending']);

        $response = $this->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(401);
    }

    public function test_user_cannot_check_another_users_payment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($other)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'You can only check your own payment.',
            ]);
    }

    public function test_invalid_payment_id_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/payments/99999/check');

        $response->assertStatus(404);
    }

    public function test_admin_can_check_any_payment(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->pendingResponse(), 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'pending'],
            ]);
    }

    public function test_pending_payment_remains_pending_when_not_confirmed(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->pendingResponse(), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => ['status' => 'pending'],
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_successful_payment_becomes_paid_and_creates_enrollment(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->approvedResponse(), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Payment confirmed successfully.',
                'data' => [
                    'status' => 'paid',
                    'payment_id' => $payment->id,
                ],
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_failed_payment_does_not_activate_enrollment(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->declinedResponse(), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(202);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_already_paid_payment_check_returns_immediately(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Payment already completed.',
                'data' => ['status' => 'paid'],
            ]);
    }

    public function test_repeated_payment_check_does_not_create_duplicate_enrollment(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response($this->approvedResponse(), 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/check");
        $this->actingAs($user)->postJson("/api/payments/{$payment->id}/check");

        $this->assertCount(1, Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get());

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_payway_api_failure_during_check_returns_502(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    public function test_payway_connection_timeout_during_check_is_handled(): void
    {
        $this->configPayWay();

        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out.'),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/payments/{$payment->id}/check");

        $response->assertStatus(502);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    // ─── PAYWAY CALLBACK ─────────────────────────────────────────────

    public function test_callback_marks_payment_paid_and_creates_enrollment(): void
    {
        $this->configPayWay();

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $payload = [
            'tran_id' => $payment->transaction_id,
            'apv' => '544415',
            'status' => '0',
            'total_amount' => 10,
            'original_amount' => 10,
            'payment_amount' => 10,
            'payment_currency' => 'USD',
            'transaction_date' => '2026-09-18 12:00:00',
        ];

        $response = $this->postJson(
            '/api/payments/payway/callback',
            $payload,
            ['X-PAYWAY-HMAC-SHA512' => $this->signedCallback($payload)],
        );

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Payment confirmed.',
            ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_callback_does_not_create_duplicate_enrollment(): void
    {
        $this->configPayWay();

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $payload = [
            'tran_id' => $payment->transaction_id,
            'status' => '0',
            'total_amount' => 10,
        ];
        $signature = $this->signedCallback($payload);

        $this->postJson('/api/payments/payway/callback', $payload, ['X-PAYWAY-HMAC-SHA512' => $signature]);
        $this->postJson('/api/payments/payway/callback', $payload, ['X-PAYWAY-HMAC-SHA512' => $signature]);

        $this->assertCount(1, Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->get());

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_callback_with_invalid_signature_is_rejected(): void
    {
        $this->configPayWay();

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $payload = [
            'tran_id' => $payment->transaction_id,
            'status' => '0',
            'total_amount' => 10,
        ];

        $response = $this->postJson(
            '/api/payments/payway/callback',
            $payload,
            ['X-PAYWAY-HMAC-SHA512' => 'invalid-signature'],
        );

        $response->assertStatus(401);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_callback_with_unknown_tran_id_returns_404(): void
    {
        $this->configPayWay();

        $payload = [
            'tran_id' => 'PCunknown',
            'status' => '0',
        ];

        $response = $this->postJson(
            '/api/payments/payway/callback',
            $payload,
            ['X-PAYWAY-HMAC-SHA512' => $this->signedCallback($payload)],
        );

        $response->assertStatus(404);
    }

    public function test_callback_with_amount_mismatch_is_rejected(): void
    {
        $this->configPayWay();

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $payload = [
            'tran_id' => $payment->transaction_id,
            'status' => '0',
            'total_amount' => 9,
        ];

        $response = $this->postJson(
            '/api/payments/payway/callback',
            $payload,
            ['X-PAYWAY-HMAC-SHA512' => $this->signedCallback($payload)],
        );

        $response->assertStatus(400);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'pending',
        ]);
    }

    public function test_callback_with_declined_status_marks_payment_failed(): void
    {
        $this->configPayWay();

        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 10]);

        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10,
            'status' => 'pending',
        ]);

        $payload = [
            'tran_id' => $payment->transaction_id,
            'status' => '2',
            'total_amount' => 10,
        ];

        $response = $this->postJson(
            '/api/payments/payway/callback',
            $payload,
            ['X-PAYWAY-HMAC-SHA512' => $this->signedCallback($payload)],
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('enrollments', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }
}
