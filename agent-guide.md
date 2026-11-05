# Agent Guide — Laravel Online Course API

This document is a practical guide for AI coding agents and developers working in this repository. It describes the architecture, conventions, and workflows to follow when making changes.

## Project Overview

A Laravel 10 REST API for an online course platform (building an interview/demo project). It exposes JSON endpoints for authentication (Sanctum), courses, lessons, quizzes, questions, enrollments, lesson progress, payments (PayWay), and admin management.

## Stack

- **Framework:** Laravel 10 (PHP ^8.1)
- **Auth:** Laravel Sanctum (personal access tokens)
- **API:** Stateless JSON routes under `routes/api.php`
- **Testing:** PHPUnit 10
- **Linting:** Laravel Pint
- **Frontend tooling:** Vite (minimal, not central to this API)

## Directory Layout

- `app/Http/Controllers/Api/` — API controllers (one per resource)
- `app/Models/` — Eloquent models
- `app/Services/PayWayPaymentService.php` — PayWay checkout / payment-check / callback logic
- `app/Http/Middleware/EnsureUserIsAdmin.php` — admin guard (aliased internally)
- `database/migrations/` — schema (see `2026_04_30_000000_create_online_course_tables.php`)
- `config/services.php` — third-party service config (PayWay)
- `routes/api.php` — all API routes

## Key Conventions

- **PSR-4 autoload:** `App\` → `app/`, `Tests\` → `tests/`
- **Route model binding** used for `{course}`, `{lesson}`, `{user}`, etc. via Laravel's implicit binding.
- **Admin routes** are gated by the `admin` middleware alias (backed by `EnsureUserIsAdmin.php`). When adding admin-only endpoints, wrap them in the existing `Route::middleware('admin')` group in `routes/api.php`.
- **Public read endpoints** (index/show for categories, courses, lessons, quizzes, questions) are intentionally unauthenticated.
- Payments rely on `App\Services\PayWayPaymentService`. Do not reimplement PayWay logic in controllers.
- Payment responses use a `{ success, message, data }` envelope (see `PaymentController`). Other controllers still return raw JSON.
- Tests for the payment flow live in `tests/Feature/PayWayPaymentTest.php`. They run against SQLite in-memory and mock PayWay HTTP calls with Laravel's HTTP fakes — never real credentials.
- `.env.testing` is git-ignored and used for local test runs; `.env` must never be committed or echoed.

## Routes (`routes/api.php`)

Public auth:
- `POST /register`, `POST /login`

Public callback (no Sanctum token; PayWay authenticates via the `X-PAYWAY-HMAC-SHA512` header):
- `POST /payments/payway/callback`

Authenticated (`auth:sanctum`):
- `GET /user`, `GET /user/profile`, `PUT /user/profile`, `POST /logout`
- `POST /courses`, `GET /my/courses`, `PUT /courses/{course}`, `DELETE /courses/{course}`
- `POST /payments`, `POST /payments/{payment}/check`

Admin (`auth:sanctum` + `admin`):
- `POST /categories`, `PUT /categories/{category}`, `DELETE /categories/{category}`
- `GET /users`, `POST /users`, `GET /users/{user}`
- `GET /enrollments`, `GET /enrollments/{enrollment}`
- `GET /lesson-progress`, `GET /lesson-progress/{lessonProgress}`
- `GET /payments`, `GET /payments/{payment}`

Public read:
- `GET /categories`, `GET /categories/{category}`
- `GET /courses`, `GET /courses/{course}`
- `GET /lessons`, `GET /lessons/{lesson}`
- `GET /quizzes`, `GET /quizzes/{quiz}`
- `GET /questions`, `GET /questions/{question}`

## Domain Model

- **Category** → has many **Course**
- **Course** → belongs to **Category** and a user (author); has lessons, quizzes, enrollments, payments
- **Lesson** → belongs to **Course**; trackable via **LessonProgress**
- **Quiz** → belongs to **Course**; has **Question**
- **Question** → belongs to **Quiz**
- **Enrollment** → joins **User** and **Course** (access control)
- **Payment** → belongs to **User** (and course); stores the PayWay transaction ID (`transaction_id`, PayWay calls it `tran_id`) and the raw PayWay response in `provider_payload`
- **User** → student or admin; content management is admin-only

Verify exact relationships and column names in the models and migrations before relying on them.

## Adding a New Feature

1. Add a migration, then update the related model with fillable/casts/relationships.
2. If exposing new endpoints, add a controller under `app/Http/Controllers/Api/`.
3. Register routes in `routes/api.php` following the existing grouping (public / auth / admin).
4. If it concerns payments, extend `PayWayPaymentService` rather than calling PayWay directly.
5. Keep responses as clean JSON (no views are used for these endpoints).

## Naming Conventions

- Controllers: `XController` under `Api`.
- Route parameters match implicit model binding (singular kebab/lowercase: `{course}`, `{lessonProgress}`).
- Migrations follow Laravel's snake_case table name conventions.

## Verification Commands

- Run the test suite: `php artisan test`
- Lint with Pint: `./vendor/bin/pint`
- List routes: `php artisan route:list`
- Inspect schema: `php artisan migrate:status`

## PayWay Merchant Settings

The following env vars **must** be set in `.env` for PayWay payments to work:

| Env Var | Required | Default | Description |
|---------|----------|---------|-------------|
| `PAYWAY_ENV` | Yes | `sandbox` | `sandbox` or `production` |
| `PAYWAY_BASE_URL` | No | `https://checkout-sandbox.payway.com.kh` (sandbox) or `https://checkout.payway.com.kh` (production) | PayWay checkout base URL |
| `PAYWAY_MERCHANT_ID` | Yes | — | PayWay merchant ID provided by ABA Bank |
| `PAYWAY_API_KEY` | Yes | — | PayWay API key used for HMAC-SHA512 hashing |
| `PAYWAY_CALLBACK_SECRET` | Yes* | — | Secret used to verify the `X-PAYWAY-HMAC-SHA512` callback signature (*required for callbacks) |
| `PAYWAY_PURCHASE_URL` | No | `${PAYWAY_BASE_URL}/api/payment-gateway/v1/payments/purchase` | Full purchase endpoint override |
| `PAYWAY_CHECK_URL` | No | `${PAYWAY_BASE_URL}/api/payment-gateway/v1/payments/check-transaction-2` | Full check endpoint override |
| `PAYWAY_FRONTEND_RETURN_URL` | Yes* | `${APP_URL}` | Where PayWay redirects the customer after a successful payment |
| `PAYWAY_CANCEL_URL` | No | `${PAYWAY_FRONTEND_RETURN_URL}` | Where PayWay redirects the customer on cancel |
| `PAYWAY_CALLBACK_URL` | Yes | — | Server-to-server callback URL PayWay posts results to (must be whitelisted) |
| `PAYWAY_CURRENCY` | No | `USD` | `USD` or `KHR` |
| `PAYWAY_PAYMENT_OPTION` | No | `abapay_khqr_deeplink` | PayWay payment option (e.g. `abapay_khqr_deeplink`, `cards`, empty for hosted selection) |
| `PAYWAY_LIFETIME_MINUTES` | No | `60` | Payment lifetime in minutes (min 3) |

> **Minimum for development:** `PAYWAY_MERCHANT_ID`, `PAYWAY_API_KEY` and `PAYWAY_CALLBACK_URL`. If any of these are missing, `PayWayPaymentService::createPayment()` throws `RuntimeException`.

## Gotchas

- The `.env` contains PayWay credentials and must **never** be committed or echoed.
- If you change `config/services.php` keys for PayWay, update `.env.example` accordingly (but never the real `.env`).
- The callback route (`POST /payments/payway/callback`) is intentionally public — PayWay does not send a Sanctum token. Authenticity is enforced via the `X-PAYWAY-HMAC-SHA512` header only.
- Admin middleware alias `admin` maps to `EnsureUserIsAdmin.php` — confirm the alias in `app/Http/Kernel.php` if adding new middleware groups.
- This is an API-only project; web routes are essentially unused.