<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\PayWayPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::with(['user', 'course'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->course_id, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return response()->json($payments);
    }

    public function show(Payment $payment)
    {
        return response()->json($payment->load(['user', 'course']));
    }

    public function store(Request $request, PayWayPaymentService $payWay)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'method' => ['sometimes', 'string', 'in:payway'],
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $amount = (float) $course->price;
        $userId = $request->user()->id;

        $result = DB::transaction(function () use ($userId, $course, $amount, $validated, $payWay) {
            $existing = Payment::with(['user', 'course'])
                ->where('user_id', $userId)
                ->where('course_id', $course->id)
                ->whereIn('status', [Payment::STATUS_PAID, Payment::STATUS_PENDING])
                ->lockForUpdate()
                ->latest()
                ->first();

            if ($existing && $existing->isPaid()) {
                $this->enroll($userId, $course->id);

                return [
                    'payment' => $existing,
                    'status_code' => 200,
                    'message' => 'Payment already completed.',
                    'pending' => false,
                ];
            }

            if ($existing && $existing->isPending()) {
                if ($existing->isCheckoutFresh()) {
                    return [
                        'payment' => $existing,
                        'status_code' => 200,
                        'message' => 'Existing pending payment returned.',
                        'pending' => false,
                    ];
                }

                // The PayWay transaction_id is single-use, so a stale pending row
                // is retired and the customer gets a brand new checkout below.
                $existing->update(['status' => Payment::STATUS_EXPIRED]);
            }

            if ($amount <= 0) {
                $payment = Payment::create([
                    'user_id' => $userId,
                    'course_id' => $course->id,
                    'amount' => 0,
                    'method' => 'free',
                    'status' => 'paid',
                    'transaction_id' => (string) Str::uuid(),
                    'paid_at' => now(),
                ]);

                $this->enroll($userId, $course->id);

                return [
                    'payment' => $payment->load(['user', 'course']),
                    'status_code' => 201,
                    'message' => 'Free course enrolled successfully.',
                    'pending' => false,
                ];
            }

            $payment = Payment::create([
                'user_id' => $userId,
                'course_id' => $course->id,
                'amount' => $amount,
                'method' => $validated['method'] ?? 'payway',
                'status' => Payment::STATUS_PENDING,
                'transaction_id' => $payWay->generateTransactionId(),
            ]);

            return [
                'payment' => $payment->load(['user', 'course']),
                'status_code' => 201,
                'message' => 'Payment created successfully.',
                'pending' => true,
            ];
        });

        $payment = $result['payment'];

        if (! $result['pending']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $this->formatPaymentResponse($payment),
            ], $result['status_code']);
        }

        try {
            $payload = $payWay->createPayment($payment, [
                'firstname' => $this->firstName($request->user()->name),
                'lastname' => $this->lastName($request->user()->name),
                'email' => $request->user()->email,
            ]);
        } catch (RuntimeException $exception) {
            $payment->update(['status' => Payment::STATUS_FAILED]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }

        $payment->update([
            'qr_url' => isset($payload['checkout_qr_url']) && $payload['checkout_qr_url'] !== '' ? $payload['checkout_qr_url'] : null,
            'provider_payload' => $payload,
        ]);

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $this->formatPaymentResponse($payment->load(['user', 'course'])),
        ], $result['status_code']);
    }

    public function check(Request $request, Payment $payment, PayWayPaymentService $payWay)
    {
        if ($payment->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'You can only check your own payment.',
            ], 403);
        }

        if ($payment->isPaid()) {
            return response()->json([
                'success' => true,
                'message' => 'Payment already completed.',
                'data' => $this->formatPaymentResponse($payment->load(['user', 'course'])),
            ]);
        }

        // A failed or expired row can never be completed: the PayWay
        // transaction_id is single-use, so checking it again is pointless.
        if ($payment->needsNewCheckout()) {
            return response()->json([
                'success' => false,
                'message' => 'This payment is no longer payable. Start a new payment to get a fresh checkout.',
                'data' => $this->formatPaymentResponse($payment->load(['user', 'course'])),
            ], 409);
        }

        if (! $payment->transaction_id) {
            return response()->json([
                'success' => false,
                'message' => 'This payment does not have a PayWay transaction ID.',
            ], 409);
        }

        try {
            $payload = $payWay->checkPayment($payment);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 502);
        }

        $payment->forceFill([
            'provider_payload' => array_merge(
                (array) $payment->provider_payload,
                ['check' => $payload],
            ),
        ])->save();

        if (! $payWay->isPaid($payload)) {
            if ($payment->isPending() && ! $payment->isCheckoutFresh()) {
                $payment->forceFill(['status' => Payment::STATUS_EXPIRED])->save();
            }

            return response()->json([
                'success' => true,
                'message' => $this->checkMessage($payload) ?? 'Payment has not been confirmed yet.',
                'data' => $this->formatPaymentResponse($payment->load(['user', 'course'])),
            ], 202);
        }

        DB::transaction(function () use ($payment) {
            $payment->update([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
            ]);

            $this->enroll($payment->user_id, $payment->course_id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment confirmed successfully.',
            'data' => $this->formatPaymentResponse($payment->load(['user', 'course'])),
        ]);
    }

    /**
     * Server-to-server callback called by PayWay after the customer completes
     * (or cancels) a payment. Intentionally public: PayWay does not send a
     * Sanctum token. Authenticity is verified via the signature header.
     */
    public function paywayCallback(Request $request, PayWayPaymentService $payWay)
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload.'], 400);
        }

        $signature = (string) $request->header('X-PAYWAY-HMAC-SHA512', '');

        if (! $payWay->verifyCallbackSignature($payload, $signature)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        $tranId = $payload['tran_id'] ?? null;
        $status = $payload['status'] ?? null;

        if ($tranId === null || $status === null) {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.'], 422);
        }

        $payment = Payment::where('transaction_id', $tranId)->first();

        if (! $payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment not found.'], 404);
        }

        // Even a locally failed/expired row is honoured here: PayWay is the source of
        // truth about the money, and a late approval must still enrol the buyer.
        if ($payment->isPaid()) {
            return response()->json(['status' => 'success', 'message' => 'Already processed.']);
        }

        if (isset($payload['total_amount']) && (float) $payload['total_amount'] !== (float) $payment->amount) {
            return response()->json(['status' => 'error', 'message' => 'Amount mismatch.'], 400);
        }

        if ((string) $status !== '0') {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'provider_payload' => array_merge((array) $payment->provider_payload, ['callback' => $payload]),
            ]);

            return response()->json(['status' => 'success', 'message' => 'Payment not completed.']);
        }

        DB::transaction(function () use ($payment, $payload) {
            $payment->update([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'provider_payload' => array_merge((array) $payment->provider_payload, ['callback' => $payload]),
            ]);

            $this->enroll($payment->user_id, $payment->course_id);
        });

        return response()->json(['status' => 'success', 'message' => 'Payment confirmed.']);
    }

    private function enroll(int $userId, int $courseId): void
    {
        Enrollment::updateOrCreate(
            [
                'user_id' => $userId,
                'course_id' => $courseId,
            ],
            [
                'enrolled_at' => now(),
                'status' => 'active',
            ],
        );
    }

    private function formatPaymentResponse(Payment $payment): array
    {
        $provider = (array) ($payment->provider_payload ?? []);

        return [
            'payment_id' => $payment->id,
            'course_id' => $payment->course_id,
            'amount' => (float) $payment->amount,
            'currency' => $this->currencyCode(),
            'status' => $payment->status,
            'transaction_id' => $payment->transaction_id,
            'requires_new_checkout' => $payment->needsNewCheckout(),
            'checkout_url' => $provider['checkout_url'] ?? null,
            'checkout_form' => $provider['checkout_form'] ?? null,
            'qr_url' => ! empty($provider['checkout_qr_url']) ? $provider['checkout_qr_url'] : $payment->qr_url,
            'qr_string' => $provider['qr_string'] ?? null,
            'abapay_deeplink' => $provider['abapay_deeplink'] ?? null,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }

    private function checkMessage(array $payload): ?string
    {
        return data_get($payload, 'data.status.message')
            ?? data_get($payload, 'data.payment_status');
    }

    private function currencyCode(): string
    {
        return strtoupper((string) (config('services.payway.currency', 'USD') ?: 'USD'));
    }

    private function firstName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));

        return $parts[0] ?? '';
    }

    private function lastName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        array_shift($parts);

        return implode(' ', $parts);
    }
}
