<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_id' => Course::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'video_url' => 'https://example.test/videos/lesson.mp4',
            'content' => fake()->paragraph(),
            'order' => 1,
            'is_preview' => false,
        ];
    }

    public function preview(): static
    {
        return $this->state(fn () => ['is_preview' => true]);
    }
}
