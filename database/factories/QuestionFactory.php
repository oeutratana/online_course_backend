<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'question' => fake()->sentence(4).'?',
            'option_a' => 'Alpha',
            'option_b' => 'Beta',
            'option_c' => 'Gamma',
            'option_d' => 'Delta',
            'correct_option' => 'option_'.fake()->randomElement(['a', 'b', 'c', 'd']),
        ];
    }

    public function correct(string $option): static
    {
        return $this->state(fn () => ['correct_option' => 'option_'.$option]);
    }
}
