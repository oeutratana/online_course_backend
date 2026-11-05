<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCourseListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function student(): User
    {
        return User::factory()->create(['role' => 'student']);
    }

    // ─── ACCESS ──────────────────────────────────────────────────────

    public function test_guests_cannot_list_the_admin_courses(): void
    {
        Course::factory()->create();

        $this->getJson('/api/admin/courses')->assertStatus(401);
    }

    public function test_a_non_admin_cannot_list_the_admin_courses(): void
    {
        Course::factory()->create();

        $this->actingAs($this->student())->getJson('/api/admin/courses')->assertStatus(403);
    }

    public function test_an_admin_can_list_the_admin_courses(): void
    {
        Course::factory()->create();

        $this->actingAs($this->admin())->getJson('/api/admin/courses')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    // ─── DRAFTS ARE REACHABLE ────────────────────────────────────────

    public function test_the_admin_listing_includes_drafts(): void
    {
        Course::factory()->create(['title' => 'Published course']);
        Course::factory()->create(['title' => 'Draft course', 'is_published' => false]);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/courses');

        $response->assertStatus(200)->assertJsonCount(2, 'data');

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Draft course', $titles);
        $this->assertContains('Published course', $titles);
    }

    public function test_the_admin_listing_can_be_narrowed_to_drafts_or_published(): void
    {
        Course::factory()->create(['title' => 'Published course']);
        Course::factory()->create(['title' => 'Draft course', 'is_published' => false]);

        $admin = $this->admin();

        $drafts = $this->actingAs($admin)->getJson('/api/admin/courses?is_published=0');
        $drafts->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft course')
            ->assertJsonPath('data.0.is_published', false);

        $published = $this->actingAs($admin)->getJson('/api/admin/courses?is_published=1');
        $published->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published course');
    }

    public function test_drafts_are_still_hidden_from_the_public_listing(): void
    {
        Course::factory()->create(['title' => 'Published course']);
        Course::factory()->create(['title' => 'Draft course', 'is_published' => false]);

        $this->getJson('/api/courses')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published course');
    }

    // ─── MANAGEMENT COUNTERS ─────────────────────────────────────────

    public function test_each_row_carries_the_management_counters(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create();

        Enrollment::create([
            'user_id' => $this->student()->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Wishlist::create(['user_id' => $this->student()->id, 'course_id' => $course->id]);

        CourseReview::create([
            'user_id' => $this->student()->id,
            'course_id' => $course->id,
            'rating' => 4,
            'review' => 'Good.',
        ]);

        $this->actingAs($admin)->getJson('/api/admin/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id)
            ->assertJsonPath('data.0.enrollments_count', 1)
            ->assertJsonPath('data.0.wishlists_count', 1)
            ->assertJsonPath('data.0.reviews_count', 1)
            ->assertJsonPath('data.0.reviews_avg_rating', 4)
            ->assertJsonPath('data.0.certificates_count', 0)
            ->assertJsonPath('data.0.user_id', $course->user_id);
    }

    // ─── FILTERS AND SORTING ─────────────────────────────────────────

    public function test_the_admin_listing_supports_the_public_filters(): void
    {
        $admin = $this->admin();
        $category = Category::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $author = User::factory()->create();

        Course::factory()->create([
            'title' => 'Laravel advanced',
            'category_id' => $category->id,
            'user_id' => $author->id,
            'price' => 60,
        ]);
        Course::factory()->create(['title' => 'Unrelated', 'price' => 500]);

        $this->actingAs($admin)->getJson('/api/admin/courses?search=Laravel')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laravel advanced');

        $this->actingAs($admin)->getJson("/api/admin/courses?category_id={$category->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson("/api/admin/courses?instructor_id={$author->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin)->getJson('/api/admin/courses?min_price=50&max_price=70')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laravel advanced');
    }

    public function test_the_admin_listing_supports_the_public_sorting(): void
    {
        $admin = $this->admin();

        Course::factory()->create(['title' => 'Bravo', 'price' => 30]);
        Course::factory()->create(['title' => 'Alpha', 'price' => 10]);

        $this->actingAs($admin)->getJson('/api/admin/courses?sort_by=price&sort_order=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Alpha');

        $this->actingAs($admin)->getJson('/api/admin/courses?sort_by=title&sort_order=asc')
            ->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_the_admin_listing_rejects_a_sort_injection(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/courses?sort_by=price; DROP TABLE courses')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_by']);

        $this->actingAs($admin)->getJson('/api/admin/courses?sort_order=sideways')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sort_order']);
    }

    public function test_the_admin_listing_validates_its_pagination_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/courses?per_page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($admin)->getJson('/api/admin/courses?per_page=500')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($admin)->getJson('/api/admin/courses?page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['page']);

        $this->actingAs($admin)->getJson('/api/admin/courses?min_price=100&max_price=10')
            ->assertStatus(422)->assertJsonValidationErrors(['max_price']);
    }

    // ─── PAGINATION ──────────────────────────────────────────────────

    public function test_the_admin_listing_is_paginated_and_keeps_the_filters(): void
    {
        $admin = $this->admin();

        Course::factory()->count(8)->create(['title' => 'Laravel basics']);
        Course::factory()->count(3)->create(['title' => 'Something else']);

        $first = $this->actingAs($admin)->getJson('/api/admin/courses?search=Laravel&per_page=5&page=1');

        $first->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 8)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2);

        // withQueryString() keeps the active filter on the generated URLs, which
        // is what the console relies on when it walks to page 2.
        $this->assertStringContainsString('search=Laravel', $first->json('next_page_url'));

        $second = $this->actingAs($admin)->getJson('/api/admin/courses?search=Laravel&per_page=5&page=2');
        $second->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_the_admin_listing_returns_an_empty_page_when_there_is_nothing(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/courses')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1);
    }

    public function test_walking_every_page_returns_each_course_exactly_once(): void
    {
        $admin = $this->admin();
        $courses = Course::factory()->count(7)->create();

        // Mirrors what fetchAllRows() does in the console.
        $seen = [];
        $page = 1;
        $lastPage = 1;

        do {
            $payload = $this->actingAs($admin)
                ->getJson("/api/admin/courses?per_page=2&page={$page}")
                ->assertStatus(200)
                ->json();

            $seen = array_merge($seen, array_column($payload['data'], 'id'));
            $lastPage = $payload['last_page'];
            $page++;
        } while ($page <= $lastPage);

        $this->assertSame($courses->pluck('id')->sort()->values()->all(), collect($seen)->sort()->values()->all());
    }
}
