<?php

namespace Database\Factories;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'quiz_id' => Quiz::factory(),
            'attempt_number' => 1,
            'total_questions' => 1,
            'correct_answers' => 1,
            'score' => 100,
            'passed' => true,
            'submitted_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'correct_answers' => 0,
            'score' => 0,
            'passed' => false,
        ]);
    }
}
