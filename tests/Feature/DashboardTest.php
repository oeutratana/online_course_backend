<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function complete(User $user, Lesson $lesson): LessonProgress
    {
        return LessonProgress::create([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'is_completed' => true,
            'completed_at' => now(),
        ]);
    }

    // ─── STUDENT DASHBOARD ───────────────────────────────────────────

    public function test_unauthenticated_user_cannot_open_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_dashboard_returns_zero_values_for_a_fresh_student(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_enrolled_courses', 0)
            ->assertJsonPath('summary.completed_courses', 0)
            ->assertJsonPath('summary.in_progress_courses', 0)
            ->assertJsonPath('summary.total_lessons', 0)
            ->assertJsonPath('summary.completed_lessons', 0)
            ->assertJsonPath('summary.learning_progress', 0)
            ->assertJsonPath('summary.total_certificates', 0)
            ->assertJsonCount(0, 'courses')
            ->assertJsonCount(0, 'recent_activity');
    }

    public function test_dashboard_calculates_course_and_overall_progress(): void
    {
        $user = User::factory()->create();
        $inProgress = Course::factory()->create(['title' => 'In progress course']);
        $finished = Course::factory()->create(['title' => 'Finished course']);

        $lessonsA = Lesson::factory()->count(4)->for($inProgress)->create();
        Lesson::factory()->count(2)->for($finished)->create();

        $this->enroll($user, $inProgress);
        $this->enroll($user, $finished);

        $this->complete($user, $lessonsA[0]);
        $this->complete($user, $lessonsA[1]);
        foreach (Lesson::where('course_id', $finished->id)->get() as $lesson) {
            $this->complete($user, $lesson);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_enrolled_courses', 2)
            ->assertJsonPath('summary.completed_courses', 1)
            ->assertJsonPath('summary.in_progress_courses', 1)
            ->assertJsonPath('summary.total_lessons', 6)
            ->assertJsonPath('summary.completed_lessons', 4)
            // 4 of 6 lessons = 66.67%
            ->assertJsonPath('summary.learning_progress', 66.67)
            ->assertJsonPath('courses.0.title', 'Finished course')
            ->assertJsonPath('courses.0.is_completed', true)
            ->assertJsonPath('courses.0.progress', 100)
            ->assertJsonPath('courses.1.progress', 50)
            ->assertJsonPath('courses.1.is_completed', false);

        $this->assertCount(4, $response->json('recent_activity'));
    }

    public function test_dashboard_ignores_inactive_enrollments(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Lesson::factory()->count(2)->for($course)->create();

        $this->enroll($user, $course, 'cancelled');

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.total_enrolled_courses', 0)
            ->assertJsonPath('summary.total_lessons', 0);
    }

    public function test_course_without_lessons_reports_zero_progress_safely(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $this->enroll($user, $course);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.total_enrolled_courses', 1)
            ->assertJsonPath('summary.total_lessons', 0)
            ->assertJsonPath('summary.learning_progress', 0)
            ->assertJsonPath('summary.completed_courses', 0)
            ->assertJsonPath('courses.0.is_completed', false);
    }

    public function test_dashboard_only_returns_the_student_own_data(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $myCourse = Course::factory()->create(['title' => 'My course']);
        $otherCourse = Course::factory()->create(['title' => 'Someone else course']);

        Lesson::factory()->count(2)->for($myCourse)->create();
        Lesson::factory()->count(3)->for($otherCourse)->create();

        $this->enroll($me, $myCourse);
        $this->enroll($other, $otherCourse);
        $this->complete($other, Lesson::where('course_id', $otherCourse->id)->first());

        $response = $this->actingAs($me)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $me->id)
            ->assertJsonCount(1, 'courses')
            ->assertJsonPath('courses.0.course_id', $myCourse->id)
            ->assertJsonPath('summary.completed_lessons', 0)
            ->assertJsonCount(0, 'recent_activity');

        $this->assertStringNotContainsString('Someone else course', $response->getContent());
    }

    public function test_completed_courses_receive_a_certificate(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lessons = Lesson::factory()->count(2)->for($course)->create();

        $this->enroll($user, $course);
        foreach ($lessons as $lesson) {
            $this->complete($user, $lesson);
        }

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.completed_courses', 1)
            ->assertJsonPath('summary.total_certificates', 1);

        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseHas('certificates', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);

        // Reading the dashboard again must not issue a second certificate.
        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);
        $this->assertDatabaseCount('certificates', 1);
    }

    // ─── ADMIN DASHBOARD ─────────────────────────────────────────────

    public function test_non_admin_cannot_open_admin_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_open_admin_dashboard(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
    }

    public function test_admin_dashboard_returns_platform_statistics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create();
        $instructor = User::factory()->create();

        $course = Course::factory()->create(['user_id' => $instructor->id, 'is_published' => true]);
        Course::factory()->create(['user_id' => $instructor->id, 'is_published' => false]);
        Lesson::factory()->count(2)->for($course)->create();

        $this->enroll($student, $course);
        $this->complete($student, Lesson::where('course_id', $course->id)->first());

        Payment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id, 'amount' => 25.50, 'status' => 'paid', 'paid_at' => now()]);
        Payment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id, 'amount' => 99.99, 'status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('users.total_students', 2)
            ->assertJsonPath('users.total_admins', 1)
            ->assertJsonPath('users.total_instructors', 1)
            ->assertJsonPath('courses.total_courses', 2)
            ->assertJsonPath('courses.published_courses', 1)
            ->assertJsonPath('courses.draft_courses', 1)
            ->assertJsonPath('enrollments.total_enrollments', 1)
            // Only 1 of 2 lessons done, so the enrollment is not complete yet.
            ->assertJsonPath('enrollments.completed_enrollments', 0)
            ->assertJsonPath('payments.total_payments', 2)
            ->assertJsonPath('payments.successful_payments', 1)
            ->assertJsonPath('payments.pending_payments', 1)
            ->assertJsonPath('payments.successful_revenue', 25.5)
            ->assertJsonPath('payments.currency', 'USD')
            ->assertJsonCount(1, 'recent_enrollments')
            ->assertJsonCount(2, 'recent_payments');

        $this->assertSame(
            25.5,
            collect($response->json('recent_payments'))
                ->where('status', 'paid')
                ->first()['amount']
        );
    }

    public function test_admin_dashboard_counts_every_headline_counter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student']);

        // Ownership is pinned so the factories cannot create extra users behind
        // the counters under test.
        $published = Course::factory()->create(['user_id' => $admin->id, 'is_published' => true]);
        $draft = Course::factory()->create(['user_id' => $admin->id, 'is_published' => false]);

        Category::factory()->count(3)->create();

        $publishedLessons = Lesson::factory()->count(4)->for($published)->create();
        $draftLessons = Lesson::factory()->count(2)->for($draft)->create();

        $quiz = Quiz::factory()->for($publishedLessons->first(), 'lesson')->create();
        Quiz::factory()->for($draftLessons->first(), 'lesson')->create();
        Question::factory()->count(5)->for($quiz, 'quiz')->create();

        $this->enroll($student, $published);
        $this->enroll($student, $draft);

        Payment::factory()->create([
            'user_id' => $student->id,
            'course_id' => $published->id,
            'amount' => 40.00,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        Payment::factory()->create([
            'user_id' => $student->id,
            'course_id' => $draft->id,
            'amount' => 10.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_users', 2)
            ->assertJsonPath('summary.total_courses', 2)
            ->assertJsonPath('summary.total_categories', 3)
            ->assertJsonPath('summary.total_enrollments', 2)
            ->assertJsonPath('summary.total_payments', 2)
            ->assertJsonPath('summary.total_lessons', 6)
            ->assertJsonPath('summary.total_quizzes', 2)
            ->assertJsonPath('summary.total_questions', 5);

        // JSON encodes 40.0 as 40, so the revenue comparison is made as a float.
        $this->assertSame(40.0, (float) $response->json('summary.total_revenue'));
        $this->assertSame('USD', $response->json('summary.currency'));
    }

    public function test_the_summary_agrees_with_the_detail_blocks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create(['user_id' => $admin->id]);
        $lesson = Lesson::factory()->for($course)->create();

        Category::factory()->count(2)->create();
        Question::factory()->count(3)->for(Quiz::factory()->for($lesson, 'lesson'), 'quiz')->create();

        Payment::factory()->create([
            'user_id' => $admin->id,
            'course_id' => $course->id,
            'amount' => 75.50,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $dashboard = $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json();

        $summary = $dashboard['summary'];

        // The card row is derived from the detail blocks, so the two views can
        // never drift apart.
        $this->assertSame($dashboard['users']['total_users'], $summary['total_users']);
        $this->assertSame($dashboard['courses']['total_courses'], $summary['total_courses']);
        $this->assertSame($dashboard['categories']['total_categories'], $summary['total_categories']);
        $this->assertSame($dashboard['enrollments']['total_enrollments'], $summary['total_enrollments']);
        $this->assertSame($dashboard['payments']['total_payments'], $summary['total_payments']);
        $this->assertSame($dashboard['lessons']['total_lessons'], $summary['total_lessons']);
        $this->assertSame($dashboard['quizzes']['total_quizzes'], $summary['total_quizzes']);
        $this->assertSame($dashboard['questions']['total_questions'], $summary['total_questions']);
        $this->assertSame($dashboard['payments']['successful_revenue'], $summary['total_revenue']);
    }

    public function test_an_empty_platform_reports_zeroes_everywhere(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.total_users', 1)
            ->assertJsonPath('summary.total_courses', 0)
            ->assertJsonPath('summary.total_categories', 0)
            ->assertJsonPath('summary.total_enrollments', 0)
            ->assertJsonPath('summary.total_payments', 0)
            ->assertJsonPath('summary.total_lessons', 0)
            ->assertJsonPath('summary.total_quizzes', 0)
            ->assertJsonPath('summary.total_questions', 0)
            ->assertJsonPath('summary.total_revenue', 0);
    }

    public function test_draft_lessons_are_counted_separately(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $published = Course::factory()->create(['is_published' => true]);
        $draft = Course::factory()->create(['is_published' => false]);

        Lesson::factory()->count(3)->for($published)->create();
        Lesson::factory()->count(2)->for($draft)->create();

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('lessons.total_lessons', 5)
            ->assertJsonPath('lessons.draft_lessons', 2);
    }

    public function test_admin_dashboard_returns_recent_users_without_secrets(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $response->assertStatus(200)->assertJsonCount(4, 'recent_users');

        $recent = collect($response->json('recent_users'));
        $this->assertSame(
            ['id', 'name', 'email', 'role', 'created_at'],
            array_keys($recent->first())
        );

        // The password hash and remember_token must never reach the dashboard.
        $this->assertStringNotContainsString('password', json_encode($response->json('recent_users')));
        $this->assertStringNotContainsString('remember_token', json_encode($response->json('recent_users')));
    }

    public function test_recent_users_are_ordered_newest_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'created_at' => now()->subDays(10)]);
        $older = User::factory()->create(['created_at' => now()->subDays(3)]);
        $newer = User::factory()->create(['created_at' => now()]);

        $ids = collect($this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json('recent_users'))
            ->pluck('id')
            ->all();

        $this->assertSame([$newer->id, $older->id, $admin->id], $ids);
    }

    public function test_recent_records_are_capped(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $courses = Course::factory()->count(15)->create(['user_id' => $admin->id]);
        $students = User::factory()->count(15)->create();

        foreach ($courses->take(12) as $index => $course) {
            $this->enroll($students[$index], $course);
        }

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonCount(10, 'recent_users')
            ->assertJsonCount(10, 'recent_courses')
            ->assertJsonCount(10, 'recent_enrollments');
    }

    public function test_admin_dashboard_returns_recent_courses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $instructor = User::factory()->create();
        $category = Category::factory()->create(['name' => 'Backend']);

        Course::factory()->create([
            'user_id' => $instructor->id,
            'category_id' => $category->id,
            'title' => 'Laravel Mastery',
            'price' => 49.99,
            'is_published' => false,
        ]);

        $recent = $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json('recent_courses');

        $this->assertCount(1, $recent);
        $this->assertSame('Laravel Mastery', $recent[0]['title']);
        $this->assertSame(49.99, $recent[0]['price']);
        $this->assertFalse($recent[0]['is_published']);
        $this->assertSame($instructor->id, $recent[0]['instructor']['id']);
        $this->assertSame('Backend', $recent[0]['category']['name']);
    }

    public function test_recent_payments_never_expose_the_raw_payway_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create(['user_id' => $admin->id]);

        Payment::factory()->create([
            'user_id' => $admin->id,
            'course_id' => $course->id,
            'amount' => 30.00,
            'status' => 'paid',
            'paid_at' => now(),
            'provider_payload' => ['checkout_url' => 'https://checkout-sandbox.payway.test/x', 'qr_string' => 'secret-qr'],
        ]);

        $recent = $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->json('recent_payments');

        $this->assertArrayNotHasKey('provider_payload', $recent[0]);
        $this->assertStringNotContainsString('provider_payload', json_encode($recent));
        // The business transaction reference stays available for reconciliation,
        // exactly as the admin payment API already exposes it.
        $this->assertArrayHasKey('transaction_id', $recent[0]);
    }

    public function test_the_summary_breaks_payments_down_by_real_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create(['user_id' => $admin->id]);
        $student = User::factory()->create();

        // The stored statuses are paid / pending / failed / expired — not the
        // PayWay gateway strings (APPROVED, DECLINED, ...).
        $payments = [
            ['paid', 20.00],
            ['paid', 30.00],
            ['pending', 99.00],
            ['failed', 99.00],
            ['expired', 99.00],
        ];

        foreach ($payments as [$status, $amount]) {
            Payment::factory()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'amount' => $amount,
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('summary.total_payments', 5)
            ->assertJsonPath('summary.successful_payments', 2)
            ->assertJsonPath('summary.pending_payments', 1)
            ->assertJsonPath('summary.failed_payments', 1);

        // Only the two paid rows make up revenue.
        $this->assertSame(50.0, (float) $response->json('summary.total_revenue'));
    }

    public function test_admin_dashboard_counts_completed_enrollments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create();
        $course = Course::factory()->create();
        $lessons = Lesson::factory()->count(3)->for($course)->create();

        $this->enroll($student, $course);
        foreach ($lessons as $lesson) {
            $this->complete($student, $lesson);
        }

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('enrollments.completed_enrollments', 1);
    }

    public function test_admin_dashboard_never_counts_pending_revenue(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::factory()->create();

        Payment::factory()->create(['user_id' => $admin->id, 'course_id' => $course->id, 'amount' => 10, 'status' => 'pending']);
        Payment::factory()->create(['user_id' => $admin->id, 'course_id' => $course->id, 'amount' => 10, 'status' => 'expired']);
        Payment::factory()->create(['user_id' => $admin->id, 'course_id' => $course->id, 'amount' => 10, 'status' => 'failed']);

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('payments.total_payments', 3)
            ->assertJsonPath('payments.successful_payments', 0)
            ->assertJsonPath('payments.successful_revenue', 0);
    }

    public function test_admin_dashboard_on_empty_database_returns_zeroes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->getJson('/api/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('users.total_students', 0)
            ->assertJsonPath('users.total_instructors', 0)
            ->assertJsonPath('courses.total_courses', 0)
            ->assertJsonPath('enrollments.total_enrollments', 0)
            ->assertJsonPath('payments.total_payments', 0)
            ->assertJsonPath('payments.successful_revenue', 0)
            ->assertJsonPath('certificates.total_certificates', 0)
            ->assertJsonCount(0, 'recent_payments');
    }

    public function test_certificates_issued_by_the_dashboard_are_visible_in_the_certificate_list(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $lessons = Lesson::factory()->count(1)->for($course)->create();

        $this->enroll($user, $course);
        $this->complete($user, $lessons[0]);

        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);

        $this->actingAs($user)->getJson('/api/certificates')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame(
            Certificate::first()->user_id,
            $user->id
        );
    }
}
