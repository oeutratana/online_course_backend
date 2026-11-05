<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LessonAccessTest extends TestCase
{
    use RefreshDatabase;

    private function paidCourse(): Course
    {
        return Course::factory()->create(['price' => 25.00]);
    }

    public function test_unauthenticated_user_cannot_view_locked_lesson(): void
    {
        $lesson = Lesson::factory()->for($this->paidCourse())->create();

        $response = $this->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(403);
    }

    public function test_non_paying_user_cannot_view_locked_lesson(): void
    {
        $user = User::factory()->create();
        $course = $this->paidCourse();
        $lesson = Lesson::factory()->for($course)->create(['video_url' => 'https://example.test/videos/private.mp4']);

        $response = $this->actingAs($user)->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(403);
    }

    public function test_paid_enrolled_user_can_view_lesson_with_video(): void
    {
        $user = User::factory()->create();
        $course = $this->paidCourse();
        $lesson = Lesson::factory()->for($course)->create(['video_url' => 'https://example.test/videos/private.mp4']);

        Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $lesson->id)
            ->assertJsonPath('video_url', 'https://example.test/videos/private.mp4');
    }

    public function test_course_instructor_can_view_lesson(): void
    {
        $instructor = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $instructor->id, 'price' => 25.00]);
        $lesson = Lesson::factory()->for($course)->create(['video_url' => 'https://example.test/videos/private.mp4']);

        $response = $this->actingAs($instructor)->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(200)
            ->assertJsonPath('video_url', 'https://example.test/videos/private.mp4');
    }

    public function test_admin_can_view_lesson(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lesson = Lesson::factory()->for($this->paidCourse())->create(['video_url' => 'https://example.test/videos/private.mp4']);

        $response = $this->actingAs($admin)->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(200)
            ->assertJsonPath('video_url', 'https://example.test/videos/private.mp4');
    }

    public function test_preview_lesson_is_visible_without_paying(): void
    {
        $lesson = Lesson::factory()->for($this->paidCourse())->preview()->create(['video_url' => 'https://example.test/videos/trailer.mp4']);

        $response = $this->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(200)
            ->assertJsonPath('video_url', 'https://example.test/videos/trailer.mp4');
    }

    public function test_free_course_lesson_is_visible_without_paying(): void
    {
        $course = Course::factory()->create(['price' => 0]);
        $lesson = Lesson::factory()->for($course)->create(['video_url' => 'https://example.test/videos/free.mp4']);

        $response = $this->getJson("/api/lessons/{$lesson->id}");

        $response->assertStatus(200)
            ->assertJsonPath('video_url', 'https://example.test/videos/free.mp4');
    }

    public function test_locked_lesson_listing_hides_video_url(): void
    {
        $course = $this->paidCourse();
        Lesson::factory()->for($course)->create(['video_url' => 'https://example.test/videos/private.mp4']);

        $response = $this->getJson('/api/lessons?course_id='.$course->id);

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.video_url', null);
    }
}
