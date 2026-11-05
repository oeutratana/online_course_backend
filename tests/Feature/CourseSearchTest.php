<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSearchTest extends TestCase
{
    use RefreshDatabase;

    private function review(Course $course, int $rating): CourseReview
    {
        $reviewer = User::factory()->create();

        Enrollment::create([
            'user_id' => $reviewer->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        return CourseReview::create([
            'user_id' => $reviewer->id,
            'course_id' => $course->id,
            'rating' => $rating,
            'review' => 'Review '.$rating,
        ]);
    }

    public function test_search_matches_title_and_description(): void
    {
        Course::factory()->create(['title' => 'Laravel from scratch', 'description' => 'PHP framework']);
        Course::factory()->create(['title' => 'Vue basics', 'description' => 'Frontend framework']);
        Course::factory()->create(['title' => 'Advanced PHP', 'description' => 'Deep dive']);

        $response = $this->getJson('/api/courses?search=Laravel');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laravel from scratch');

        $this->getJson('/api/courses?search=PHP')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_search_is_not_vulnerable_to_sql_injection(): void
    {
        Course::factory()->create(['title' => 'Laravel basics']);

        $this->getJson("/api/courses?search=' OR 1=1 --")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/courses?search=%25')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_unpublished_courses_are_hidden_from_the_public_list(): void
    {
        Course::factory()->create(['title' => 'Published course', 'is_published' => true]);
        Course::factory()->create(['title' => 'Draft course', 'is_published' => false]);

        $this->getJson('/api/courses')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published course');

        $draft = Course::where('title', 'Draft course')->first();

        // The public cannot open the draft.
        $this->getJson("/api/courses/{$draft->id}")->assertStatus(404);

        // Only an admin can preview a draft.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson("/api/courses/{$draft->id}")
            ->assertStatus(200);
    }

    public function test_filter_by_category(): void
    {
        $category = Category::create(['name' => 'Backend', 'slug' => 'backend']);
        $other = Category::create(['name' => 'Frontend', 'slug' => 'frontend']);

        Course::factory()->create(['category_id' => $category->id]);
        Course::factory()->create(['category_id' => $other->id]);

        $this->getJson("/api/courses?category_id={$category->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.id', $category->id);
    }

    public function test_filter_by_instructor(): void
    {
        $instructor = User::factory()->create();
        $other = User::factory()->create();

        Course::factory()->create(['user_id' => $instructor->id]);
        Course::factory()->create(['user_id' => $other->id]);

        $this->getJson("/api/courses?instructor_id={$instructor->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.instructor.id', $instructor->id);
    }

    public function test_filter_by_price_range(): void
    {
        Course::factory()->create(['price' => 5]);
        Course::factory()->create(['price' => 50]);
        Course::factory()->create(['price' => 500]);

        $this->getJson('/api/courses?min_price=10&max_price=100')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '50.00');

        $this->getJson('/api/courses?min_price=100')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '500.00');
    }

    public function test_filter_by_minimum_rating(): void
    {
        $loved = Course::factory()->create();
        $average = Course::factory()->create();
        $unrated = Course::factory()->create();

        $this->review($loved, 5);
        $this->review($loved, 4);
        $this->review($average, 2);

        $response = $this->getJson('/api/courses?rating=4');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $loved->id)
            ->assertJsonPath('data.0.reviews_count', 2)
            ->assertJsonPath('data.0.reviews_avg_rating', 4.5);

        $this->assertNotContains($unrated->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_sort_by_price_and_title(): void
    {
        Course::factory()->create(['title' => 'Bravo', 'price' => 30]);
        Course::factory()->create(['title' => 'Alpha', 'price' => 10]);
        Course::factory()->create(['title' => 'Charlie', 'price' => 20]);

        $this->getJson('/api/courses?sort_by=price&sort_order=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Alpha')
            ->assertJsonPath('data.2.title', 'Bravo');

        $this->getJson('/api/courses?sort_by=price&sort_order=desc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Bravo');

        $this->getJson('/api/courses?sort_by=title')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_sort_by_rating(): void
    {
        $loved = Course::factory()->create();
        $disliked = Course::factory()->create();
        $unrated = Course::factory()->create();

        $this->review($loved, 5);
        $this->review($disliked, 1);

        $response = $this->getJson('/api/courses?sort_by=rating&sort_order=desc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $loved->id);

        // Courses without reviews sort last instead of disappearing.
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame($unrated->id, end($ids));
    }

    public function test_invalid_sort_field_is_rejected(): void
    {
        $this->getJson('/api/courses?sort_by=price; DROP TABLE courses')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->getJson('/api/courses?sort_by=password')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->getJson('/api/courses?sort_order=sideways')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_order']);
    }

    public function test_invalid_query_parameters_are_rejected(): void
    {
        $this->getJson('/api/courses?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/courses?per_page=500')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/courses?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/courses?rating=9')->assertStatus(422)->assertJsonValidationErrors(['rating']);
        $this->getJson('/api/courses?category_id=abc')->assertStatus(422)->assertJsonValidationErrors(['category_id']);
        $this->getJson('/api/courses?min_price=-5')->assertStatus(422)->assertJsonValidationErrors(['min_price']);
        $this->getJson('/api/courses?min_price=100&max_price=10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_price']);
    }

    public function test_pagination_returns_meta_and_links(): void
    {
        Course::factory()->count(15)->create();

        $response = $this->getJson('/api/courses?per_page=10&page=2');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 15)
            ->assertJsonPath('last_page', 2)
            ->assertJsonStructure([
                'data',
                'links',
                'first_page_url',
                'last_page_url',
                'next_page_url',
                'prev_page_url',
            ]);

        // Laravel 10 sends `links` as the list of page links plus the
        // first/last/prev/next URLs at the root of the payload.
        $links = $response->json('links');
        $this->assertIsArray($links);
        $this->assertNotEmpty($links);
        $this->assertArrayHasKey('url', $links[0]);
        $this->assertArrayHasKey('label', $links[0]);
        $this->assertStringContainsString('page=1', $response->json('first_page_url'));
        $this->assertStringContainsString('page=2', $response->json('last_page_url'));
        $this->assertStringContainsString('page=1', $response->json('prev_page_url'));
        $this->assertNull($response->json('next_page_url'));
    }

    public function test_pagination_keeps_the_active_filters(): void
    {
        Course::factory()->count(8)->create(['title' => 'Laravel advanced']);
        Course::factory()->count(3)->create(['title' => 'Something else']);

        $response = $this->getJson('/api/courses?search=Laravel&per_page=5&page=1');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 8);

        // withQueryString() keeps the active filters in every generated URL.
        $this->assertStringContainsString('search=Laravel', $response->json('next_page_url'));
        $this->assertStringContainsString('per_page=5', $response->json('next_page_url'));
        $this->assertStringContainsString('search=Laravel', $response->json('first_page_url'));
    }

    public function test_combined_filters_work_together(): void
    {
        $category = Category::create(['name' => 'PHP', 'slug' => 'php']);
        $instructor = User::factory()->create();

        $match = Course::factory()->create([
            'title' => 'Laravel masterclass',
            'category_id' => $category->id,
            'user_id' => $instructor->id,
            'price' => 60,
        ]);
        Course::factory()->create(['title' => 'Laravel for beginners', 'category_id' => $category->id, 'price' => 60]);
        Course::factory()->create(['title' => 'Laravel pricing', 'user_id' => $instructor->id, 'price' => 60]);

        $this->review($match, 5);

        $response = $this->getJson(
            "/api/courses?search=Laravel&category_id={$category->id}&instructor_id={$instructor->id}"
            .'&min_price=50&max_price=70&rating=4&sort_by=rating&sort_order=desc&per_page=10'
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_empty_search_result_returns_a_valid_empty_page(): void
    {
        Course::factory()->create(['title' => 'Laravel basics']);

        $this->getJson('/api/courses?search=Rust')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_legacy_filters_remain_supported(): void
    {
        $instructor = User::factory()->create();
        Course::factory()->create(['price' => 0, 'user_id' => $instructor->id]);
        Course::factory()->create(['price' => 25, 'user_id' => $instructor->id]);

        $this->getJson('/api/courses?price=free')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/courses?price=paid')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/courses?user_id={$instructor->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/courses?sort=price_low')
            ->assertStatus(200)
            ->assertJsonPath('data.0.price', '0.00');
    }

    public function test_listing_keeps_the_existing_course_payload_fields(): void
    {
        $course = Course::factory()->create(['price' => 40, 'video_url' => 'https://example.test/v.mp4']);

        $this->getJson('/api/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id)
            ->assertJsonPath('data.0.has_access', false)
            ->assertJsonPath('data.0.is_enrolled', false)
            ->assertJsonPath('data.0.requires_payment', true)
            // Paid course without enrollment: the video must stay hidden.
            ->assertJsonPath('data.0.video_url', null);
    }

    public function test_my_courses_is_paginated_and_limited_to_active_enrollments(): void
    {
        $user = User::factory()->create();
        $enrolled = Course::factory()->create();
        $cancelled = Course::factory()->create();

        Enrollment::create(['user_id' => $user->id, 'course_id' => $enrolled->id, 'status' => 'active', 'enrolled_at' => now()]);
        Enrollment::create(['user_id' => $user->id, 'course_id' => $cancelled->id, 'status' => 'cancelled', 'enrolled_at' => now()]);

        $this->actingAs($user)->getJson('/api/my/courses')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $enrolled->id)
            ->assertJsonPath('total', 1);
    }
}
