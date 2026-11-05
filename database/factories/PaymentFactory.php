<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course_id' => Course::factory(),
            'amount' => fake()->randomFloat(2, 1, 100),
            'method' => 'payway',
            'status' => 'pending',
            'transaction_id' => 'PC'.now()->format('mdHis').strtolower(fake()->bothify('????????')),
            'qr_url' => 'https://checkout-sandbox.payway.com.kh/some-checkout-qr-url',
        ];
    }
}
