# PayWay Payment API

## Overview

This API integrates with Cambodia's **ABA PayWay** payment gateway to process course payments. PayWay redirects the customer to its hosted checkout (or returns an ABA PAY / KHQR QR), and this backend verifies the transaction server-side before granting course access.

References: [PayWay Developer Docs](https://developer.payway.com.kh/) — sandbox `https://checkout-sandbox.payway.com.kh`, production `https://checkout.payway.com.kh`.

---

## Payment Flow

```
Frontend
   |
POST /api/payments (auth)
   |
PaymentController::store
   |
Creates local Payment record (status = pending, transaction_id = PayWay tran_id)
   |
PayWayPaymentService::createPayment()
   |   builds purchase request + HMAC-SHA512 hash
   |   POST /api/payment-gateway/v1/payments/purchase
   |
PayWay responds with QR data (abapay_khqr_deeplink) or a hosted checkout page
   |
Customer pays (scans QR or completes hosted checkout)
   |
PayWay POSTs result to /api/payments/payway/callback (X-PAYWAY-HMAC-SHA512 header)
   |
Laravel verifies signature, finds payment by tran_id, validates amount
   |
Payment status: pending -> paid (enrollment created)  OR  pending -> failed
   |
Student gets access to the course
```

Polls can also call `POST /api/payments/{payment}/check`, which queries PayWay's
`check-transaction-2` API instead of relying on the callback.

---

## Authentication

- All payment endpoints require a valid Sanctum Bearer token, **except** the callback:

```
Authorization: Bearer {token}
```

- `POST /api/payments/payway/callback` is public. PayWay authenticates itself with the

```
X-PAYWAY-HMAC-SHA512: {base64 hmac-sha512 signature}
```

header. See [Verify Callback Signature](#verify-callback-signature).

---

## Endpoints

### POST /api/payments

Create a new PayWay payment for a course.

**Request Body:**
```json
{
    "course_id": 5,
    "method": "payway"
}
```

**Important:** Do **not** send `amount` or `user_id`. The backend reads the amount from
`courses.price` and always attaches the payment to the authenticated user.

**Success Response (201 - new payment):**
```json
{
    "success": true,
    "message": "Payment created successfully.",
    "data": {
        "payment_id": 15,
        "course_id": 5,
        "amount": 10.00,
        "currency": "USD",
        "status": "pending",
        "transaction_id": "PC09011812a1b2c3d4",
        "checkout_url": null,
        "checkout_form": null,
        "qr_url": "https://checkout-sandbox.payway.com.kh/checkout/...",
        "qr_string": "00020101021230510016abaakhppxx@abaa...",
        "abapay_deeplink": "abamobilebank://ababank.com?type=payway&qrcode=...",
        "paid_at": null
    }
}
```

With the default `PAYWAY_PAYMENT_OPTION=abapay_khqr_deeplink`, PayWay returns `qr_string`,
`abapay_deeplink` and `checkout_qr_url` (exposed as `qr_url`). When a hosted checkout page is
returned (HTML), the response instead contains `checkout_url` and `checkout_form` so the
frontend can submit the form to PayWay.

**Other success responses:**

| Status | Condition |
|--------|-----------|
| 200 | Existing fresh pending payment returned |
| 200 | Payment already completed (idempotent) |
| 201 | Free course enrolled (amount 0), status already `paid` |
| 201 | `failed`/`expired` row was retired and a fresh checkout created |

A `failed` or `expired` row is never reused: its PayWay `transaction_id` is
single-use, so asking for a payment again creates a brand new row (and a new
`transaction_id`/checkout). A pending row older than `PAYWAY_LIFETIME_MINUTES`
is marked `expired` and replaced the same way.

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 401 | Not authenticated |
| 422 | Invalid or missing `course_id` |
| 502 | PayWay API failure or connection error (payment marked `failed`) |

---

### POST /api/payments/{payment}/check

Check a pending payment against PayWay's `check-transaction-2` API.

**Response (200 - confirmed):**
```json
{
    "success": true,
    "message": "Payment confirmed successfully.",
    "data": { "payment_id": 15, "status": "paid", ... }
}
```

**Response (202 - still pending):**
```json
{
    "success": true,
    "message": "Payment has not been confirmed yet.",
    "data": { "payment_id": 15, "status": "pending", ... }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 401 | Not authenticated |
| 403 | Payment belongs to another user |
| 404 | Invalid payment ID |
| 409 | Payment is `failed`/`expired` (single-use `transaction_id`) or has no PayWay transaction ID |
| 502 | PayWay API failure / connection error |

Only the payment owner (or an admin) can check a payment.

A `failed` or `expired` row can never be completed — checking it again is
pointless because its PayWay `transaction_id` is single-use. The 409 body
includes `data.requires_new_checkout: true`; the customer must call
`POST /api/payments` again to get a fresh transaction and checkout.

---

### POST /api/payments/payway/callback

Public server-to-server callback called by PayWay when a payment completes.

**Headers:**
```
Content-Type: application/json
X-PAYWAY-HMAC-SHA512: {base64 hmac-sha512 signature}
```

**Sample PayWay payload:**
```json
{
    "tran_id": "9065703303",
    "apv": "544415",
    "status": "0",
    "return_params": "{\"order_id\":\"123\",\"amount\":100}",
    "original_amount": 0.01,
    "original_currency": "USD",
    "payment_amount": 0.01,
    "payment_currency": "USD",
    "total_amount": 0.01,
    "discount_amount": 0,
    "transaction_date": "2026-08-03 13:57:20",
    "payment_type": "ABA Pay",
    "payer_account": "003471222"
}
```

The callback:
1. Verifies `total_amount` matches the stored payment amount (when present).
2. Finds the local payment by `tran_id`.
3. `status === "0"` → marks the payment `paid` and creates/activates the enrollment.
4. Any other status → marks the payment `failed` (no enrollment).
5. Is idempotent: re-delivered callbacks never duplicate payments or enrollments.

**Responses:**
- `200` `{"status":"success", ...}` — acknowledged
- `400` invalid payload / amount mismatch
- `401` invalid signature
- `404` unknown `tran_id`
- `422` missing required fields

### Verify Callback Signature

```php
$secret = config('services.payway.callback_secret');

ksort($payload);

$b4hash = '';
foreach ($payload as $value) {
    $b4hash .= is_array($value) ? json_encode($value) : (string) $value;
}

$signature = base64_encode(hash_hmac('sha512', $b4hash, $secret, true));

$valid = hash_equals($signature, $_SERVER['HTTP_X_PAYWAY_HMAC_SHA512'] ?? '');
```

---

## Payment Statuses

| Status | Meaning |
|--------|---------|
| `pending` | PayWay transaction created, waiting for the customer to pay |
| `paid` | PayWay confirmed the transaction, user enrolled |
| `failed` | PayWay rejected the payment or the purchase request failed |
| `expired` | Pending payment exceeded the lifetime without payment |

---

## Security Notes

- The **amount** is always read from the database, never from the frontend request.
- The **payment status** is set only after PayWay confirms it (callback signature verified
  and/or the `check-transaction-2` API), never from unauthenticated client input.
- The `PAYWAY_API_KEY` / `PAYWAY_CALLBACK_SECRET` are never exposed in API responses.
- Only the payment owner (or an admin) can check a payment.
- Duplicate enrollments are prevented by the unique constraint on `(user_id, course_id)` and by
  idempotent payment handling.

---

## Environment Variables

Required in `.env`:

```env
PAYWAY_ENV=sandbox
PAYWAY_BASE_URL=
PAYWAY_MERCHANT_ID=
PAYWAY_API_KEY=
PAYWAY_CALLBACK_SECRET=
PAYWAY_PURCHASE_URL=
PAYWAY_CHECK_URL=
PAYWAY_FRONTEND_RETURN_URL="${APP_URL}"
PAYWAY_CANCEL_URL="${APP_URL}"
PAYWAY_CALLBACK_URL="${APP_URL}/api/payments/payway/callback"
PAYWAY_CURRENCY=USD
PAYWAY_PAYMENT_OPTION=abapay_khqr_deeplink
PAYWAY_LIFETIME_MINUTES=60
```

**Getting sandbox credentials:** [register for a PayWay sandbox account](https://sandbox.payway.com.kh/register-sandbox/).
ABA sends the sandbox `merchant_id` and `api_key` by email, and the domain for
`PAYWAY_CALLBACK_URL` / `PAYWAY_FRONTEND_RETURN_URL` must be whitelisted for your merchant
profile.

---

## Testing without real credentials

The suite never calls real PayWay services. `tests/Feature/PayWayPaymentTest.php` and
`tests/Unit/PayWayPaymentServiceTest.php` use Laravel's `Http::fake()` to simulate:
successful purchase (QR JSON), hosted checkout (HTML), wrong-hash errors, HTTP 500s,
timeouts, approved/pending/declined check results, and signed/invalid callbacks.

Run the suite:

```bash
php artisan test
./vendor/bin/pint --test
```