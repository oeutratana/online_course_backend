<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    // ─── AUTHENTICATION ──────────────────────────────────────────────

    public function test_guests_cannot_reach_any_wishlist_endpoint(): void
    {
        $course = Course::factory()->create();

        $this->getJson('/api/wishlist')->assertStatus(401);
        $this->postJson('/api/wishlist', ['course_id' => $course->id])->assertStatus(401);
        $this->deleteJson("/api/wishlist/{$course->id}")->assertStatus(401);
        $this->getJson("/api/wishlist/check/{$course->id}")->assertStatus(401);
    }

    // ─── ADDING ──────────────────────────────────────────────────────

    public function test_student_can_add_a_course_to_the_wishlist(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/wishlist', ['course_id' => $course->id]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Course added to wishlist.')
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.course.title', $course->title);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_course_id_is_required_and_must_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_a_client_supplied_user_id_is_ignored(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', [
            'course_id' => $course->id,
            'user_id' => $victim->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $victim->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_adding_the_same_course_twice_returns_conflict(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(201);

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This course is already in your wishlist.')
            ->assertJsonPath('data.course_id', $course->id);

        // Still exactly one row: no duplicate was created.
        $this->assertSame(1, Wishlist::where('user_id', $user->id)->count());
    }

    public function test_unpublished_courses_cannot_be_added(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_published' => false]);

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This course is not available.');

        $this->assertDatabaseCount('wishlists', 0);
    }

    // ─── LISTING ─────────────────────────────────────────────────────

    public function test_wishlist_only_contains_the_own_courses(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Course::factory()->create();
        $theirs = Course::factory()->create();

        Wishlist::create(['user_id' => $user->id, 'course_id' => $mine->id]);
        Wishlist::create(['user_id' => $other->id, 'course_id' => $theirs->id]);

        $response = $this->actingAs($user)->getJson('/api/wishlist');

        $response->assertStatus(200)->assertJsonCount(1, 'data');

        $ids = collect($response->json('data'))->pluck('course_id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_wishlist_is_paginated_newest_first(): void
    {
        $user = User::factory()->create();
        $courses = Course::factory()->count(5)->create();

        foreach ($courses as $index => $course) {
            Wishlist::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'created_at' => now()->subDays(5 - $index),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/wishlist?per_page=2');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 5)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('last_page', 3)
            // Newest first.
            ->assertJsonPath('data.0.course_id', $courses->last()->id);

        $second = $this->actingAs($user)->getJson('/api/wishlist?per_page=2&page=2');
        $second->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_wishlist_pagination_validates_its_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/wishlist?per_page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($user)->getJson('/api/wishlist?per_page=1000')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($user)->getJson('/api/wishlist?page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['page']);
    }

    public function test_empty_wishlist_returns_a_valid_empty_page(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/wishlist')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1);
    }

    // ─── CHECK ───────────────────────────────────────────────────────

    public function test_check_reports_membership_for_the_current_user(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->getJson("/api/wishlist/check/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('course_id', $course->id)
            ->assertJsonPath('in_wishlist', false);

        Wishlist::create(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->actingAs($user)->getJson("/api/wishlist/check/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('in_wishlist', true);
    }

    public function test_check_is_scoped_to_the_current_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $course = Course::factory()->create();

        Wishlist::create(['user_id' => $other->id, 'course_id' => $course->id]);

        $this->actingAs($user)->getJson("/api/wishlist/check/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('in_wishlist', false);
    }

    public function test_check_rejects_an_unknown_course(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/wishlist/check/9999')
            ->assertStatus(404);
    }

    // ─── REMOVING ────────────────────────────────────────────────────

    public function test_student_can_remove_a_course_from_the_wishlist(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Wishlist::create(['user_id' => $user->id, 'course_id' => $course->id]);

        $this->actingAs($user)->deleteJson("/api/wishlist/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Course removed from wishlist.');

        $this->assertDatabaseCount('wishlists', 0);
    }

    public function test_removing_a_course_that_is_not_wishlisted_returns_404(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->deleteJson("/api/wishlist/{$course->id}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'This course is not in your wishlist.');
    }

    public function test_a_user_cannot_remove_another_users_wishlist_entry(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $course = Course::factory()->create();

        Wishlist::create(['user_id' => $other->id, 'course_id' => $course->id]);

        $this->actingAs($user)->deleteJson("/api/wishlist/{$course->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $other->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_a_course_can_be_wishlisted_again_after_removal(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(201);
        $this->actingAs($user)->deleteJson("/api/wishlist/{$course->id}")->assertStatus(200);
        $this->actingAs($user)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(201);

        $this->assertSame(1, Wishlist::where('user_id', $user->id)->count());
    }

    public function test_two_students_can_wishlist_the_same_course(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($first)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(201);
        $this->actingAs($second)->postJson('/api/wishlist', ['course_id' => $course->id])
            ->assertStatus(201);

        $this->assertDatabaseCount('wishlists', 2);
    }
}
