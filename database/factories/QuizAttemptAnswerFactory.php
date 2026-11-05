<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttemptAnswer>
 */
class QuizAttemptAnswerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_attempt_id' => QuizAttempt::factory(),
            'question_id' => Question::factory(),
            'selected_option' => 'a',
            'is_correct' => true,
        ];
    }
}
