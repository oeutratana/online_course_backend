<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseReviewTest extends TestCase
{
    use RefreshDatabase;

    private function enroll(User $user, Course $course, string $status = 'active'): Enrollment
    {
        return Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => $status,
            'enrolled_at' => now(),
        ]);
    }

    private function review(User $user, Course $course, int $rating = 5, ?string $text = 'Great course.'): CourseReview
    {
        return CourseReview::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'rating' => $rating,
            'review' => $text,
        ]);
    }

    // ─── CREATING A REVIEW ───────────────────────────────────────────

    public function test_enrolled_student_can_review_a_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $response = $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", [
            'rating' => 5,
            'review' => 'Excellent content.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Review submitted.')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review', 'Excellent content.')
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('course_reviews', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'rating' => 5,
        ]);
    }

    public function test_the_review_text_is_optional(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 4])
            ->assertStatus(201)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review', null);
    }

    public function test_guests_cannot_review(): void
    {
        $course = Course::factory()->create();

        $this->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(401);
    }

    public function test_a_user_without_an_enrollment_cannot_review(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only enrolled students can review this course.');

        $this->assertDatabaseCount('course_reviews', 0);
    }

    public function test_a_pending_or_cancelled_enrollment_does_not_allow_reviewing(): void
    {
        $course = Course::factory()->create();

        foreach (['pending', 'cancelled', 'completed'] as $status) {
            $user = User::factory()->create();
            $this->enroll($user, $course, $status);

            $this->actingAs($user)
                ->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
                ->assertStatus(403);
        }

        $this->assertDatabaseCount('course_reviews', 0);
    }

    public function test_an_admin_can_review_without_enrolling(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create();

        $this->actingAs($admin)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 4])
            ->assertStatus(201);

        $this->assertDatabaseHas('course_reviews', [
            'user_id' => $admin->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_the_rating_is_validated(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", [])
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 6])
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 'five'])
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);
    }

    public function test_an_overlong_review_is_rejected(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", [
            'rating' => 5,
            'review' => str_repeat('a', 2001),
        ])->assertStatus(422)->assertJsonValidationErrors(['review']);
    }

    public function test_the_same_student_cannot_review_a_course_twice(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(201);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You have already reviewed this course.');

        $this->assertSame(1, CourseReview::where('course_id', $course->id)->count());
        $this->assertSame(5, CourseReview::where('course_id', $course->id)->first()->rating);
    }

    public function test_a_different_student_can_also_review_the_same_course(): void
    {
        $course = Course::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->enroll($first, $course);
        $this->enroll($second, $course);

        $this->actingAs($first)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(201);
        $this->actingAs($second)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 3])
            ->assertStatus(201);

        $this->assertSame(2, CourseReview::where('course_id', $course->id)->count());
    }

    public function test_reviews_cannot_be_created_on_a_draft_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['is_published' => false]);
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseCount('course_reviews', 0);
    }

    // ─── LISTING REVIEWS ─────────────────────────────────────────────

    public function test_anyone_can_read_the_reviews_of_a_published_course(): void
    {
        $course = Course::factory()->create();
        $reviewer = User::factory()->create(['name' => 'Jane Reviewer']);
        $this->review($reviewer, $course, 4, 'Solid material.');

        $response = $this->getJson("/api/courses/{$course->id}/reviews");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rating', 4)
            ->assertJsonPath('data.0.review', 'Solid material.')
            ->assertJsonPath('data.0.user.name', 'Jane Reviewer');
    }

    public function test_review_payload_never_leaks_private_user_fields(): void
    {
        $course = Course::factory()->create();
        $reviewer = User::factory()->create(['email' => 'private@example.test']);
        $this->review($reviewer, $course);

        $user = $this->getJson("/api/courses/{$course->id}/reviews")->json('data.0.user');

        $this->assertSame(['id', 'name'], array_keys($user));
        $this->assertArrayNotHasKey('email', $user);
    }

    public function test_reviews_are_listed_newest_first_and_paginated(): void
    {
        $course = Course::factory()->create();

        foreach (range(1, 5) as $index) {
            $review = $this->review(User::factory()->create(), $course, 5, 'Review number '.$index);

            // created_at is not fillable, so it is written explicitly to make
            // the "newest first" expectation deterministic.
            $review->forceFill(['created_at' => now()->subDays(10 - $index)])->save();
        }

        $response = $this->getJson("/api/courses/{$course->id}/reviews?per_page=2");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 5)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('data.0.review', 'Review number 5')
            ->assertJsonPath('data.1.review', 'Review number 4');
    }

    public function test_reviews_can_be_filtered_by_rating(): void
    {
        $course = Course::factory()->create();
        $this->review(User::factory()->create(), $course, 5);
        $this->review(User::factory()->create(), $course, 5);
        $this->review(User::factory()->create(), $course, 2);

        $response = $this->getJson("/api/courses/{$course->id}/reviews?rating=5");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rating', 5);
    }

    public function test_review_listing_validates_its_input(): void
    {
        $course = Course::factory()->create();

        $this->getJson("/api/courses/{$course->id}/reviews?per_page=0")
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->getJson("/api/courses/{$course->id}/reviews?rating=9")
            ->assertStatus(422)->assertJsonValidationErrors(['rating']);

        $this->getJson("/api/courses/{$course->id}/reviews?page=0")
            ->assertStatus(422)->assertJsonValidationErrors(['page']);
    }

    public function test_reviews_of_a_draft_course_are_hidden_from_the_public(): void
    {
        $author = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $author->id, 'is_published' => false]);
        $this->review(User::factory()->create(), $course);

        $this->getJson("/api/courses/{$course->id}/reviews")->assertStatus(404);

        // The author can still preview them.
        $this->actingAs($author)->getJson("/api/courses/{$course->id}/reviews")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_reviews_of_one_course_are_not_mixed_with_another(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();
        $this->review(User::factory()->create(), $course, 5, 'Mine');
        $this->review(User::factory()->create(), $other, 1, 'Theirs');

        $response = $this->getJson("/api/courses/{$course->id}/reviews");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.review', 'Mine');
    }

    // ─── RATING SUMMARY ──────────────────────────────────────────────

    public function test_rating_summary_reports_the_real_average_and_distribution(): void
    {
        $course = Course::factory()->create();
        $this->review(User::factory()->create(), $course, 5);
        $this->review(User::factory()->create(), $course, 4);
        $this->review(User::factory()->create(), $course, 4);

        $response = $this->getJson("/api/courses/{$course->id}/rating");

        $response->assertStatus(200)
            ->assertJsonPath('course_id', $course->id)
            ->assertJsonPath('average_rating', 4.33)
            ->assertJsonPath('total_reviews', 3)
            ->assertJsonPath('distribution.1', 0)
            ->assertJsonPath('distribution.2', 0)
            ->assertJsonPath('distribution.3', 0)
            ->assertJsonPath('distribution.4', 2)
            ->assertJsonPath('distribution.5', 1);
    }

    public function test_rating_summary_of_a_course_without_reviews_is_zeroed(): void
    {
        $course = Course::factory()->create();

        $this->getJson("/api/courses/{$course->id}/rating")
            ->assertStatus(200)
            ->assertJsonPath('average_rating', 0)
            ->assertJsonPath('total_reviews', 0)
            ->assertJsonPath('distribution.5', 0);
    }

    public function test_rating_summary_updates_after_a_review_is_deleted(): void
    {
        $course = Course::factory()->create();
        $keep = $this->review(User::factory()->create(), $course, 2);
        $remove = $this->review(User::factory()->create(), $course, 5);

        // JSON numbers come back as int|float, so compare loosely.
        $this->assertEqualsWithDelta(
            3.5,
            $this->getJson("/api/courses/{$course->id}/rating")->json('average_rating'),
            0.001
        );

        $this->actingAs($remove->user)->deleteJson("/api/courses/{$course->id}/reviews/{$remove->id}")
            ->assertStatus(200);

        $this->assertSame(1, CourseReview::count());
        $this->assertEqualsWithDelta(
            2.0,
            $this->getJson("/api/courses/{$course->id}/rating")->json('average_rating'),
            0.001
        );
        $this->assertDatabaseHas('course_reviews', ['id' => $keep->id]);
    }

    // ─── UPDATING A REVIEW ───────────────────────────────────────────

    public function test_the_author_can_update_their_review(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);
        $review = $this->review($user, $course, 3, 'First impression.');

        $this->actingAs($user)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", [
            'rating' => 5,
            'review' => 'Revised impression.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Review updated.')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review', 'Revised impression.');

        $this->assertDatabaseHas('course_reviews', [
            'id' => $review->id,
            'rating' => 5,
            'review' => 'Revised impression.',
        ]);
    }

    public function test_a_partial_update_only_changes_the_given_fields(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $review = $this->review($user, $course, 3, 'Keep this text.');

        $this->actingAs($user)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", ['rating' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review', 'Keep this text.');
    }

    public function test_another_student_cannot_update_a_review(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $course = Course::factory()->create();
        $review = $this->review($owner, $course, 3);

        $this->actingAs($other)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", ['rating' => 1])
            ->assertStatus(403);

        $this->assertDatabaseHas('course_reviews', ['id' => $review->id, 'rating' => 3]);
    }

    public function test_an_admin_can_update_any_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create();
        $review = $this->review(User::factory()->create(), $course, 1, 'Offensive content.');

        $this->actingAs($admin)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", [
            'rating' => 3,
            'review' => 'Edited by moderation.',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.rating', 3);

        $this->assertDatabaseHas('course_reviews', [
            'id' => $review->id,
            'rating' => 3,
            'review' => 'Edited by moderation.',
        ]);
    }

    public function test_update_validates_the_rating(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $review = $this->review($user, $course, 3);

        $this->actingAs($user)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", ['rating' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_a_review_of_another_course_cannot_be_updated_through_this_route(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $elsewhere = Course::factory()->create();
        $review = $this->review($user, $elsewhere, 3);

        $this->actingAs($user)->putJson("/api/courses/{$course->id}/reviews/{$review->id}", ['rating' => 5])
            ->assertStatus(404);

        $this->assertDatabaseHas('course_reviews', ['id' => $review->id, 'rating' => 3]);
    }

    public function test_guests_cannot_update_a_review(): void
    {
        $course = Course::factory()->create();
        $review = $this->review(User::factory()->create(), $course, 3);

        $this->putJson("/api/courses/{$course->id}/reviews/{$review->id}", ['rating' => 5])
            ->assertStatus(401);
    }

    // ─── DELETING A REVIEW ───────────────────────────────────────────

    public function test_the_author_can_delete_their_review(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $review = $this->review($user, $course);

        $this->actingAs($user)->deleteJson("/api/courses/{$course->id}/reviews/{$review->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Review deleted.');

        $this->assertDatabaseCount('course_reviews', 0);
    }

    public function test_another_student_cannot_delete_a_review(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $course = Course::factory()->create();
        $review = $this->review($owner, $course);

        $this->actingAs($other)->deleteJson("/api/courses/{$course->id}/reviews/{$review->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('course_reviews', ['id' => $review->id]);
    }

    public function test_an_admin_can_delete_any_review(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create();
        $review = $this->review(User::factory()->create(), $course, 1, 'Spam.');

        $this->actingAs($admin)->deleteJson("/api/courses/{$course->id}/reviews/{$review->id}")
            ->assertStatus(200);

        $this->assertDatabaseCount('course_reviews', 0);
    }

    public function test_a_deleted_review_can_be_written_again(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);
        $review = $this->review($user, $course, 1);

        $this->actingAs($user)->deleteJson("/api/courses/{$course->id}/reviews/{$review->id}")
            ->assertStatus(200);

        $this->actingAs($user)->postJson("/api/courses/{$course->id}/reviews", ['rating' => 5])
            ->assertStatus(201);

        $this->assertSame(1, CourseReview::where('course_id', $course->id)->count());
    }

    public function test_guests_cannot_delete_a_review(): void
    {
        $course = Course::factory()->create();
        $review = $this->review(User::factory()->create(), $course);

        $this->deleteJson("/api/courses/{$course->id}/reviews/{$review->id}")
            ->assertStatus(401);

        $this->assertDatabaseHas('course_reviews', ['id' => $review->id]);
    }

    // ─── COURSE PAYLOAD INTEGRATION ──────────────────────────────────

    public function test_course_listing_exposes_the_review_aggregates(): void
    {
        $course = Course::factory()->create(['price' => 20]);
        $this->review(User::factory()->create(), $course, 5);
        $this->review(User::factory()->create(), $course, 4);

        $this->getJson('/api/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id)
            ->assertJsonPath('data.0.reviews_count', 2)
            ->assertJsonPath('data.0.reviews_avg_rating', 4.5);
    }

    public function test_course_detail_exposes_the_review_aggregates(): void
    {
        $course = Course::factory()->create();
        $this->review(User::factory()->create(), $course, 5);

        $this->getJson("/api/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('reviews_count', 1)
            ->assertJsonPath('reviews_avg_rating', 5);
    }
}
