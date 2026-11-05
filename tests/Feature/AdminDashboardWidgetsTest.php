<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function enroll(User $user, Course $course, $at = null): Enrollment
    {
        return Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => $at ?? now(),
        ]);
    }

    public function test_guests_and_students_cannot_read_the_dashboard_widgets(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);

        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_the_dashboard_rejects_an_unknown_range(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/dashboard?range=forever')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['range']);
    }

    public function test_the_enrollment_overview_returns_the_requested_buckets(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create();

        $this->enroll(User::factory()->create(), $course, now());
        $this->enroll(User::factory()->create(), $course, now()->subDays(2));
        // Outside the 7 day window and must not be counted.
        $this->enroll(User::factory()->create(), $course, now()->subDays(20));

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard?range=7d')
            ->assertStatus(200)
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('enrollment_overview.range', '7d')
            ->assertJsonPath('enrollment_overview.bucket', 'day')
            ->assertJsonCount(7, 'enrollment_overview.data');

        $overview = $response->json('enrollment_overview');

        $this->assertSame(2, $overview['total']);
        $this->assertSame(2, collect($overview['data'])->sum('count'));
        $this->assertSame(1, collect($overview['data'])->last()['count']);
    }

    public function test_the_enrollment_overview_supports_the_longer_ranges(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create();
        $student = User::factory()->create();

        $this->enroll($student, $course, now());

        foreach (['30d' => 'day', '3m' => 'week', '1y' => 'month'] as $range => $bucket) {
            $this->actingAs($admin)
                ->getJson("/api/admin/dashboard?range={$range}")
                ->assertStatus(200)
                ->assertJsonPath('range', $range)
                ->assertJsonPath('enrollment_overview.bucket', $bucket)
                ->assertJsonPath('enrollment_overview.total', 1);
        }
    }

    public function test_courses_by_category_counts_uncategorized_courses(): void
    {
        $admin = $this->admin();
        $backend = Category::factory()->create(['name' => 'Backend']);
        $frontend = Category::factory()->create(['name' => 'Frontend']);

        Course::factory()->count(2)->create(['category_id' => $backend->id]);
        Course::factory()->create(['category_id' => $frontend->id]);
        Course::factory()->create(['category_id' => null]);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('courses_by_category.total_courses', 4);

        $distribution = collect($response->json('courses_by_category.data'));

        $this->assertSame(2, $distribution->firstWhere('name', 'Backend')['count']);
        $this->assertSame(25.0, (float) $distribution->firstWhere('name', 'Frontend')['percentage']);
        $this->assertSame(1, $distribution->firstWhere('name', 'Uncategorized')['count']);
        $this->assertSame(100.0, round($distribution->sum('percentage'), 1));
    }

    public function test_the_course_growth_metric_is_null_without_a_previous_month(): void
    {
        $admin = $this->admin();
        Course::factory()->create();

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.course_growth.previous_month', 0)
            ->assertJsonPath('summary.course_growth.percentage', null)
            ->assertJsonPath('summary.courses_this_month', 1);
    }

    public function test_recent_activity_is_built_from_real_rows_and_ignores_pending_payments(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $admin->id]);

        $this->enroll($student, $course);

        $paid = Payment::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $pending = Payment::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'pending',
        ]);

        $activity = collect($this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json('recent_activity'));

        $this->assertTrue($activity->isNotEmpty());
        $this->assertTrue($activity->contains(fn (array $event) => $event['type'] === 'enrollment'));
        $this->assertTrue($activity->contains(fn (array $event) => $event['type'] === 'payment'));
        $this->assertTrue($activity->contains(fn (array $event) => $event['type'] === 'user'));
        $this->assertTrue($activity->contains(fn (array $event) => $event['type'] === 'course_created'));
        $this->assertTrue($activity->contains(fn (array $event) => $event['id'] === 'payment-'.$paid->id));
        $this->assertFalse($activity->contains(fn (array $event) => $event['id'] === 'payment-'.$pending->id));
    }

    public function test_the_dashboard_exposes_safe_recent_courses_for_the_table(): void
    {
        $admin = $this->admin();

        Course::factory()->create([
            'user_id' => $admin->id,
            'title' => 'Safe course',
            'thumbnail' => '/storage/courses/safe.jpg',
        ]);

        $course = collect($this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json('recent_courses'))
            ->firstWhere('title', 'Safe course');

        $this->assertSame('/storage/courses/safe.jpg', $course['thumbnail']);
        $this->assertArrayHasKey('updated_at', $course);
        $this->assertArrayNotHasKey('description', $course);
    }

    public function test_admins_can_list_every_certificate(): void
    {
        $admin = $this->admin();
        Certificate::factory()->count(3)->create();

        $this->actingAs($admin)->getJson('/api/admin/certificates')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');

        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->getJson('/api/admin/certificates')
            ->assertStatus(403);
    }

    public function test_admins_can_list_every_review(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create(['title' => 'Reviewed course']);
        CourseReview::factory()->count(2)->create(['course_id' => $course->id]);

        $this->actingAs($admin)->getJson('/api/admin/reviews?search=Reviewed')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.course.title', 'Reviewed course')
            ->assertJsonPath('data.0.course.slug', $course->slug);

        $this->actingAs(User::factory()->create(['role' => 'student']))
            ->getJson('/api/admin/reviews')
            ->assertStatus(403);
    }
}
