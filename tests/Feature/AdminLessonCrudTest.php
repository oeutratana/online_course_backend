<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLessonCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $admin->createToken('test')->plainTextToken;
    }

    public function test_admin_can_create_update_and_delete_a_lesson(): void
    {
        $token = $this->adminToken();
        $course = Course::factory()->create();

        $create = $this->withToken($token)->postJson('/api/admin/lessons', [
            'course_id' => $course->id,
            'title' => 'Lesson One',
            'description' => 'First lesson',
            'video_url' => 'https://example.com/one.mp4',
            'content' => 'Body copy',
            'order' => 2,
            'is_preview' => true,
        ]);

        $create->assertCreated();
        $lessonId = $create->json('id');
        $this->assertSame('Lesson One', $create->json('title'));

        $update = $this->withToken($token)->putJson("/api/admin/lessons/{$lessonId}", [
            'title' => 'Lesson One Renamed',
            'order' => 5,
        ]);

        $update->assertOk();
        $this->assertSame('Lesson One Renamed', $update->json('title'));
        $this->assertSame(5, $update->json('order'));

        $delete = $this->withToken($token)->deleteJson("/api/admin/lessons/{$lessonId}");
        $delete->assertOk();
        $this->assertDatabaseMissing('lessons', ['id' => $lessonId]);
    }

    public function test_index_is_paginated_and_searchable(): void
    {
        $token = $this->adminToken();
        $course = Course::factory()->create();

        Lesson::factory()->count(3)->create(['course_id' => $course->id, 'title' => 'Alpha lesson']);
        Lesson::factory()->create(['course_id' => $course->id, 'title' => 'Beta lesson']);

        $all = $this->withToken($token)->getJson('/api/admin/lessons?per_page=2');
        $all->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertSame(4, $all->json('total'));

        $search = $this->withToken($token)->getJson('/api/admin/lessons?search=Alpha');
        $search->assertOk();
        $this->assertCount(3, $search->json('data'));
    }

    public function test_store_rejects_a_missing_course(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/admin/lessons', ['course_id' => 999999, 'title' => 'Nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('course_id');
    }

    public function test_guest_cannot_reach_admin_lesson_routes(): void
    {
        $this->getJson('/api/admin/lessons')->assertUnauthorized();
    }
}
