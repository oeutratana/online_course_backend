<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\BakongPaymentService;
use Illuminate\Http\Request;
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

    public function store(Request $request, BakongPaymentService $bakong)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'method' => ['sometimes', 'string', 'in:bakong'],
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $amount = (float) $course->price;

        $paidPayment = Payment::with(['user', 'course'])
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', 'paid')
            ->latest()
            ->first();

        if ($paidPayment) {
            $this->enroll($request->user()->id, $course->id);

            return response()->json($paidPayment, 200);
        }

        $pendingPayment = Payment::with(['user', 'course'])
            ->where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', 'pending')
            ->whereNotNull('qr_url')
            ->latest()
            ->first();

        if ($pendingPayment) {
            if ($this->isFreshPendingPayment($pendingPayment)) {
                return response()->json($pendingPayment, 200);
            }

            $pendingPayment->update([
                'status' => 'expired',
            ]);
        }

        if ($amount <= 0) {
            $payment = Payment::create([
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
                'amount' => 0,
                'method' => 'free',
                'status' => 'paid',
                'transaction_id' => (string) Str::uuid(),
                'paid_at' => now(),
            ]);

            $this->enroll($request->user()->id, $course->id);

            return response()->json($payment->load(['user', 'course']), 201);
        }

        try {
            $qr = $bakong->createQr($amount);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $payment = Payment::create([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'method' => $validated['method'] ?? 'bakong',
            'status' => 'pending',
            'transaction_id' => (string) Str::uuid(),
            'qr_url' => $qr['qr'],
            'bakong_md5' => $qr['md5'],
            'bakong_transaction_id' => $qr['tran'] ?? null,
            'provider_payload' => $qr,
        ]);

        return response()->json($payment->load(['user', 'course']), 201);
    }

    public function check(Request $request, Payment $payment, BakongPaymentService $bakong)
    {
        if ($payment->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            abort(403, 'You can only check your own payment.');
        }

        if ($payment->status === 'paid') {
            return response()->json($payment->load(['user', 'course']));
        }

        try {
            $payload = $bakong->checkPayment($payment);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 502);
        }

        $payment->provider_payload = [
            'create' => $payment->provider_payload,
            'check' => $payload,
        ];

        if (! $bakong->isPaid($payload)) {
            $payment->status = 'pending';
            $payment->save();

            return response()->json([
                'message' => $payload['responseMessage'] ?? 'Payment has not been confirmed yet.',
                'payment' => $payment->load(['user', 'course']),
                'provider_payload' => $payload,
            ], 202);
        }

        $payment->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->enroll($payment->user_id, $payment->course_id);

        return response()->json($payment->load(['user', 'course']));
    }

    private function isFreshPendingPayment(Payment $payment): bool
    {
        if (($payment->provider_payload['version'] ?? null) !== BakongPaymentService::LOCAL_QR_VERSION) {
            return false;
        }

        $ttlMinutes = max(1, (int) config('services.bakong.qr_ttl_minutes', 10));

        return $payment->created_at && $payment->created_at->gt(now()->subMinutes($ttlMinutes));
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
}
