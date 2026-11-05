<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminCourseAnalyticsTest extends TestCase
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

    private function enroll(User $user, Course $course, string $status = 'active', ?string $at = null): Enrollment
    {
        return Enrollment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'status' => $status,
            'enrolled_at' => $at ?? now(),
        ]);
    }

    private function complete(User $user, Lesson $lesson): LessonProgress
    {
        return LessonProgress::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'is_completed' => true,
            'completed_at' => now(),
        ]);
    }

    private function pay(User $user, Course $course, string $status, float $amount, ?string $paidAt = null): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'method' => 'payway',
            'status' => $status,
            'transaction_id' => 'PC'.fake()->unique()->numerify('##########'),
            'paid_at' => $status === 'paid' ? ($paidAt ?? now()) : null,
        ]);
    }

    /**
     * JSON cannot tell 0 from 0.0, and assertJsonPath compares strictly, so
     * every decimal metric is compared as a float.
     */
    private function assertMetric(TestResponse $response, string $path, float $expected): void
    {
        $this->assertSame(
            $expected,
            round((float) $response->json($path), 2),
            "Failed asserting that {$path} is {$expected}."
        );
    }

    private function analyticsFor(Course $course): TestResponse
    {
        return $this->actingAs($this->admin())
            ->getJson("/api/admin/courses/{$course->id}/analytics")
            ->assertStatus(200);
    }

    // ─── ACCESS ──────────────────────────────────────────────────────

    public function test_guests_cannot_read_course_analytics(): void
    {
        Course::factory()->create();

        $this->getJson('/api/admin/courses/1/analytics')->assertStatus(401);
    }

    public function test_a_non_admin_cannot_read_course_analytics(): void
    {
        $course = Course::factory()->create();

        $this->actingAs($this->student())
            ->getJson("/api/admin/courses/{$course->id}/analytics")
            ->assertStatus(403);
    }

    public function test_an_admin_can_read_course_analytics(): void
    {
        $course = Course::factory()->create();

        $this->actingAs($this->admin())
            ->getJson("/api/admin/courses/{$course->id}/analytics")
            ->assertStatus(200)
            ->assertJsonStructure([
                'course' => ['id', 'title', 'price', 'is_published'],
                'enrollments' => ['total', 'active', 'completed', 'completion_rate'],
                'progress' => ['total_lessons', 'average_completion_percent', 'biggest_drop_off'],
                'reviews' => ['average_rating', 'total_reviews', 'distribution'],
                'revenue' => ['gross_revenue', 'successful_payments', 'average_order_value'],
                'demand' => ['wishlists', 'certificates'],
                'trend' => ['days', 'from', 'to', 'points'],
                'generated_at',
            ]);
    }

    public function test_analytics_are_scoped_to_the_requested_course(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();

        $this->enroll($this->student(), $other);
        $this->enroll($this->student(), $course);

        $this->analyticsFor($course)->assertJsonPath('enrollments.total', 1);
    }

    public function test_analytics_404_for_an_unknown_course(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/courses/9999/analytics')
            ->assertStatus(404);
    }

    public function test_analytics_work_for_a_draft_course(): void
    {
        $course = Course::factory()->create(['is_published' => false]);

        $this->actingAs($this->admin())
            ->getJson("/api/admin/courses/{$course->id}/analytics")
            ->assertStatus(200)
            ->assertJsonPath('course.is_published', false);
    }

    // ─── ENROLLMENT FUNNEL ───────────────────────────────────────────

    public function test_it_counts_enrollments_by_status(): void
    {
        $course = Course::factory()->create();

        $this->enroll($this->student(), $course);
        $this->enroll($this->student(), $course);
        $this->enroll($this->student(), $course, 'cancelled');
        $this->enroll($this->student(), $course, 'completed');

        $this->analyticsFor($course)
            ->assertJsonPath('enrollments.total', 4)
            ->assertJsonPath('enrollments.active', 2);
    }

    public function test_the_completion_rate_only_counts_active_enrollments(): void
    {
        $course = Course::factory()->create();
        $lessonA = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);
        $lessonB = Lesson::factory()->create(['course_id' => $course->id, 'order' => 2]);

        // One active enrollment, completed.
        $finisher = $this->student();
        $this->enroll($finisher, $course);
        $this->complete($finisher, $lessonA);
        $this->complete($finisher, $lessonB);

        // One active enrollment, unfinished.
        $this->enroll($this->student(), $course);

        // A cancelled enrollment that completed every lesson must not inflate
        // the rate: completion is defined on active enrollments only.
        $quitter = $this->student();
        $this->enroll($quitter, $course, 'cancelled');
        $this->complete($quitter, $lessonA);
        $this->complete($quitter, $lessonB);

        $response = $this->analyticsFor($course)
            ->assertJsonPath('enrollments.active', 2)
            ->assertJsonPath('enrollments.completed', 1)
            ->assertJsonPath('enrollments.in_progress', 1);

        $this->assertMetric($response, 'enrollments.completion_rate', 50.0);
    }

    public function test_a_course_with_no_lessons_can_never_be_complete(): void
    {
        $course = Course::factory()->create();
        $this->enroll($this->student(), $course);

        $response = $this->analyticsFor($course)
            ->assertJsonPath('enrollments.completed', 0);

        $this->assertMetric($response, 'enrollments.completion_rate', 0.0);
    }

    public function test_an_empty_course_reports_zeroes_instead_of_dividing_by_zero(): void
    {
        $course = Course::factory()->create();

        $response = $this->analyticsFor($course)
            ->assertJsonPath('enrollments.total', 0)
            ->assertJsonPath('progress.total_lessons', 0)
            ->assertJsonPath('reviews.total_reviews', 0)
            ->assertJsonPath('progress.biggest_drop_off', null)
            ->assertJsonPath('enrollments.last_enrolled_at', null);

        $this->assertMetric($response, 'enrollments.completion_rate', 0.0);
        $this->assertMetric($response, 'progress.average_completion_percent', 0.0);
        $this->assertMetric($response, 'revenue.gross_revenue', 0.0);
        $this->assertMetric($response, 'revenue.average_order_value', 0.0);
        $this->assertMetric($response, 'reviews.average_rating', 0.0);
        $this->assertMetric($response, 'revenue.failed_rate', 0.0);
    }

    // ─── LEARNING PROGRESS ───────────────────────────────────────────

    public function test_it_averages_how_far_students_got(): void
    {
        $course = Course::factory()->create();
        $lessons = collect(range(1, 4))
            ->map(fn (int $order) => Lesson::factory()->create([
                'course_id' => $course->id,
                'order' => $order,
            ]));

        // 100% done.
        $finisher = $this->student();
        $this->enroll($finisher, $course);
        $lessons->each(fn (Lesson $lesson) => $this->complete($finisher, $lesson));

        // 25% done.
        $starter = $this->student();
        $this->enroll($starter, $course);
        $this->complete($starter, $lessons->first());

        // 0% done.
        $this->enroll($this->student(), $course);

        // Average of 100, 25 and 0 is 41.67.
        $response = $this->analyticsFor($course)
            ->assertJsonPath('progress.total_lessons', 4)
            ->assertJsonPath('progress.tracked_students', 3)
            ->assertJsonPath('progress.fully_completed_students', 1);

        $this->assertMetric($response, 'progress.average_completion_percent', 41.67);
    }

    public function test_it_ignores_incomplete_progress_rows_when_averaging(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);

        $student = $this->student();
        $this->enroll($student, $course);

        // A row that exists but is not completed must count as 0 progress.
        LessonProgress::create([
            'user_id' => $student->id,
            'lesson_id' => $lesson->id,
            'is_completed' => false,
            'completed_at' => null,
        ]);

        $response = $this->analyticsFor($course)
            ->assertJsonPath('progress.fully_completed_students', 0);

        $this->assertMetric($response, 'progress.average_completion_percent', 0.0);
    }

    public function test_it_reports_the_lesson_students_stall_on(): void
    {
        $course = Course::factory()->create();
        $lessonA = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);
        $lessonB = Lesson::factory()->create(['course_id' => $course->id, 'order' => 2]);
        $lessonC = Lesson::factory()->create(['course_id' => $course->id, 'order' => 3]);

        // Two students are stuck before lesson 2, one has got as far as lesson 3.
        // The final lesson is unfinished for everyone who has not finished the
        // course, so it must not automatically be reported as the drop-off.
        $advanced = $this->student();
        $this->enroll($advanced, $course);
        $this->complete($advanced, $lessonA);
        $this->complete($advanced, $lessonB);

        foreach ([1, 2] as $ignored) {
            $beginner = $this->student();
            $this->enroll($beginner, $course);
            $this->complete($beginner, $lessonA);
        }

        $response = $this->analyticsFor($course)
            ->assertJsonPath('progress.biggest_drop_off.lesson_id', $lessonB->id)
            ->assertJsonPath('progress.biggest_drop_off.title', $lessonB->title)
            ->assertJsonPath('progress.biggest_drop_off.order', 2)
            ->assertJsonPath('progress.biggest_drop_off.students_incomplete', 2);

        $this->assertNotSame($lessonA->id, $response->json('progress.biggest_drop_off.lesson_id'));
        $this->assertNotSame($lessonC->id, $response->json('progress.biggest_drop_off.lesson_id'));
    }

    public function test_a_tie_in_the_drop_off_favours_the_earlier_lesson(): void
    {
        $course = Course::factory()->create();
        $lessonA = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);
        $lessonB = Lesson::factory()->create(['course_id' => $course->id, 'order' => 2]);

        // One student is stopped by lesson 2 and one by lesson 3, so both
        // lessons block exactly one student and the tie-break decides.
        Lesson::factory()->create(['course_id' => $course->id, 'order' => 3]);

        $first = $this->student();
        $this->enroll($first, $course);
        $this->complete($first, $lessonA);

        $second = $this->student();
        $this->enroll($second, $course);
        $this->complete($second, $lessonA);
        $this->complete($second, $lessonB);

        $this->analyticsFor($course)
            ->assertJsonPath('progress.biggest_drop_off.lesson_id', $lessonB->id)
            ->assertJsonPath('progress.biggest_drop_off.order', 2)
            ->assertJsonPath('progress.biggest_drop_off.students_incomplete', 1);
    }

    public function test_the_drop_off_counts_only_enrolled_students(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);

        $enrolled = $this->student();
        $this->enroll($enrolled, $course);

        // A cancelled enrollment must not appear as a drop-off.
        $quitter = $this->student();
        $this->enroll($quitter, $course, 'cancelled');

        $this->analyticsFor($course)
            ->assertJsonPath('progress.biggest_drop_off.students_incomplete', 1);
    }

    public function test_the_drop_off_is_null_when_every_student_finished_everything(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'order' => 1]);

        $student = $this->student();
        $this->enroll($student, $course);
        $this->complete($student, $lesson);

        $this->analyticsFor($course)
            ->assertJsonPath('progress.biggest_drop_off', null)
            ->assertJsonPath('progress.fully_completed_students', 1);
    }

    // ─── REVENUE ─────────────────────────────────────────────────────

    public function test_only_paid_payments_count_as_revenue(): void
    {
        $course = Course::factory()->create();
        $student = $this->student();

        $this->pay($student, $course, 'paid', 100.00);
        $this->pay($student, $course, 'paid', 50.00);
        $this->pay($student, $course, 'pending', 999.00);
        $this->pay($student, $course, 'failed', 999.00);
        $this->pay($student, $course, 'expired', 999.00);

        $response = $this->analyticsFor($course)
            ->assertJsonPath('revenue.successful_payments', 2)
            ->assertJsonPath('revenue.pending_payments', 1)
            ->assertJsonPath('revenue.failed_payments', 1)
            ->assertJsonPath('revenue.expired_payments', 1);

        $this->assertMetric($response, 'revenue.gross_revenue', 150.00);
        $this->assertMetric($response, 'revenue.average_order_value', 75.00);
        // 1 failed out of 5 attempts.
        $this->assertMetric($response, 'revenue.failed_rate', 20.0);
    }

    public function test_revenue_ignores_payments_for_other_courses(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();
        $student = $this->student();

        $this->pay($student, $course, 'paid', 25.00);
        $this->pay($student, $other, 'paid', 700.00);

        $response = $this->analyticsFor($course);

        $this->assertMetric($response, 'revenue.gross_revenue', 25.00);
    }

    public function test_the_course_price_is_reported_next_to_the_revenue(): void
    {
        $course = Course::factory()->create(['price' => 49.99]);

        $this->analyticsFor($course)
            ->assertJsonPath('course.price', 49.99);
    }

    // ─── RATINGS AND DEMAND ──────────────────────────────────────────

    public function test_it_reuses_the_shared_rating_summary(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();

        CourseReview::factory()->create(['course_id' => $course->id, 'rating' => 5]);
        CourseReview::factory()->create(['course_id' => $course->id, 'rating' => 3]);
        CourseReview::factory()->create(['course_id' => $other->id, 'rating' => 1]);

        $response = $this->analyticsFor($course)
            ->assertJsonPath('reviews.total_reviews', 2)
            ->assertJsonPath('reviews.distribution.5', 1)
            ->assertJsonPath('reviews.distribution.3', 1)
            ->assertJsonPath('reviews.distribution.1', 0);

        $this->assertMetric($response, 'reviews.average_rating', 4.0);
    }

    public function test_it_counts_wishlists_and_certificates(): void
    {
        $course = Course::factory()->create();

        Wishlist::factory()->count(2)->create(['course_id' => $course->id]);
        Certificate::factory()->create(['course_id' => $course->id]);

        $this->analyticsFor($course)
            ->assertJsonPath('demand.wishlists', 2)
            ->assertJsonPath('demand.certificates', 1);
    }

    public function test_demand_ignores_other_courses(): void
    {
        $course = Course::factory()->create();
        $other = Course::factory()->create();

        Wishlist::factory()->create(['course_id' => $course->id]);
        Wishlist::factory()->create(['course_id' => $other->id]);
        Certificate::factory()->create(['course_id' => $other->id]);

        $this->analyticsFor($course)
            ->assertJsonPath('demand.wishlists', 1)
            ->assertJsonPath('demand.certificates', 0);
    }

    // ─── TREND ───────────────────────────────────────────────────────

    public function test_the_trend_returns_one_zero_filled_point_per_day(): void
    {
        $course = Course::factory()->create();

        $response = $this->analyticsFor($course)
            ->assertJsonPath('trend.days', 30)
            ->assertJsonCount(30, 'trend.points')
            ->assertJsonPath('trend.points.0.enrollments', 0);

        $this->assertMetric($response, 'trend.points.0.revenue', 0.0);

        // The window ends today and every point is a real calendar date.
        $points = $response->json('trend.points');
        $this->assertSame(now()->toDateString(), end($points)['date']);
    }

    public function test_the_trend_buckets_activity_by_calendar_day(): void
    {
        $course = Course::factory()->create();

        // One enrollment per student per course, so each point needs its own.
        $this->enroll($this->student(), $course, 'active', now()->subDays(2)->setTime(9, 0));
        $this->enroll($this->student(), $course, 'active', now()->subDays(2)->setTime(18, 0));

        $payer = $this->student();
        $this->pay($payer, $course, 'paid', 40.00, now()->subDays(2)->setTime(10, 0));

        // Older than the window: excluded from the trend.
        $this->enroll($this->student(), $course, 'active', now()->subDays(45)->setTime(10, 0));
        $this->pay($payer, $course, 'paid', 500.00, now()->subDays(45)->setTime(10, 0));

        $response = $this->analyticsFor($course)
            ->assertJsonPath('enrollments.total', 3);

        $points = collect($response->json('trend.points'))->keyBy('date');
        $day = now()->subDays(2)->toDateString();

        // Same day, both enrollments land in one bucket.
        $this->assertSame(2, $points->get($day)['enrollments']);
        $this->assertEquals(40.0, $points->get($day)['revenue']);

        // Lifetime totals still include the old rows.
        $this->assertMetric($response, 'revenue.gross_revenue', 540.00);
        $this->assertMetric($response, 'trend.points.0.revenue', 0.0);
    }

    public function test_the_trend_excludes_unpaid_revenue(): void
    {
        $course = Course::factory()->create();
        $student = $this->student();

        $this->pay($student, $course, 'pending', 80.00, now()->subDays(1)->setTime(12, 0));

        $response = $this->analyticsFor($course)
            ->assertJsonPath('enrollments.total', 0);

        $points = collect($response->json('trend.points'));
        $this->assertEquals(0.0, $points->last()['revenue']);
    }

    public function test_last_30_days_counts_enrollments_without_a_timestamp_filter_gap(): void
    {
        $course = Course::factory()->create();

        $this->enroll($this->student(), $course, 'active', now());
        $this->enroll($this->student(), $course, 'active', now()->subDays(10));
        $this->enroll($this->student(), $course, 'active', now()->subDays(60));

        $this->analyticsFor($course)
            ->assertJsonPath('enrollments.total', 3)
            ->assertJsonPath('enrollments.last_30_days', 2);
    }

    public function test_the_last_enrollment_timestamp_is_reported(): void
    {
        $course = Course::factory()->create();
        $at = now()->subDays(3)->setTime(14, 30)->startOfSecond();

        $this->enroll($this->student(), $course, 'active', $at);

        $response = $this->analyticsFor($course);

        $this->assertSame(
            $at->toIso8601String(),
            $response->json('enrollments.last_enrolled_at')
        );
    }
}
