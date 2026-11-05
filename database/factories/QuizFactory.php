<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'passing_score' => 60,
            'max_attempts' => 0,
            'is_published' => true,
            'is_required' => true,
        ];
    }
}
