<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateTest extends TestCase
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

    /**
     * Enrolled student who finished every lesson of the course.
     */
    private function finishedStudent(int $lessonCount = 3): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $lessons = Lesson::factory()->count($lessonCount)->create(['course_id' => $course->id]);

        foreach ($lessons as $lesson) {
            $this->complete($user, $lesson);
        }

        return [$user, $course];
    }

    // ─── ELIGIBILITY ─────────────────────────────────────────────────

    public function test_a_finished_student_can_claim_a_certificate(): void
    {
        [$user, $course] = $this->finishedStudent();

        $response = $this->actingAs($user)->postJson('/api/certificates', [
            'course_id' => $course->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Certificate issued.')
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.student_name', $user->name);

        $this->assertNotEmpty($response->json('data.certificate_number'));
        $this->assertDatabaseHas('certificates', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_course_id_is_required_and_must_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/certificates', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_guests_cannot_claim_a_certificate(): void
    {
        $course = Course::factory()->create();

        $this->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(401);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_student_without_an_enrollment_is_refused(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        Lesson::factory()->count(2)->create(['course_id' => $course->id]);

        foreach (Lesson::where('course_id', $course->id)->get() as $lesson) {
            $this->complete($user, $lesson);
        }

        // Every lesson is done, but there is no enrollment: still not eligible.
        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You need an active enrollment for this course.');

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_student_with_an_inactive_enrollment_is_refused(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course, 'cancelled');

        foreach (Lesson::factory()->count(2)->create(['course_id' => $course->id]) as $lesson) {
            $this->complete($user, $lesson);
        }

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_partially_finished_course_is_refused(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $lessons = Lesson::factory()->count(3)->create(['course_id' => $course->id]);
        $this->complete($user, $lessons->first());

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Complete every lesson in the course before claiming a certificate.')
            ->assertJsonPath('progress.total_lessons', 3)
            ->assertJsonPath('progress.completed_lessons', 1);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_course_without_lessons_is_refused(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This course has no lessons yet, so it cannot be completed.');

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_client_cannot_force_a_certificate_without_progress(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);
        Lesson::factory()->count(2)->create(['course_id' => $course->id]);

        // Completion flags sent by the client must be ignored entirely.
        $this->actingAs($user)->postJson('/api/certificates', [
            'course_id' => $course->id,
            'is_complete' => true,
            'progress' => 100,
            'completed' => true,
        ])->assertStatus(403);

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_progress_rows_of_another_student_do_not_count(): void
    {
        $user = User::factory()->create();
        $cheater = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        foreach (Lesson::factory()->count(2)->create(['course_id' => $course->id]) as $lesson) {
            $this->complete($cheater, $lesson);
        }

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403);
    }

    public function test_another_course_completion_does_not_grant_a_certificate(): void
    {
        [$user, $finishedCourse] = $this->finishedStudent();
        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $finishedCourse->id])
            ->assertStatus(201);

        $otherCourse = Course::factory()->create();
        $this->enroll($user, $otherCourse);

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $otherCourse->id])
            ->assertStatus(403)
            ->assertJsonPath('progress.total_lessons', 0);

        $this->assertSame(1, Certificate::count());
    }

    // ─── IDEMPOTENCY ─────────────────────────────────────────────────

    public function test_claiming_twice_returns_the_same_certificate(): void
    {
        [$user, $course] = $this->finishedStudent();

        $first = $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(201);

        $second = $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(200)
            ->assertJsonPath('message', 'This certificate has already been issued.');

        $this->assertSame(
            $first->json('data.certificate_number'),
            $second->json('data.certificate_number')
        );
        $this->assertSame(1, Certificate::where('course_id', $course->id)->count());
    }

    public function test_a_certificate_can_be_claimed_again_after_deleting_it(): void
    {
        [$user, $course] = $this->finishedStudent();

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(201);

        Certificate::where('course_id', $course->id)->delete();

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(201);

        $this->assertSame(1, Certificate::where('course_id', $course->id)->count());
    }

    public function test_certificate_numbers_are_unique_across_students(): void
    {
        $numbers = [];

        foreach (range(1, 4) as $ignored) {
            [$user, $course] = $this->finishedStudent(2);

            $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
                ->assertStatus(201);

            $numbers[] = Certificate::where('course_id', $course->id)->first()->certificate_number;
        }

        $this->assertCount(4, array_unique($numbers));

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^CERT-\d{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $number);
        }
    }

    // ─── LISTING AND DETAIL ──────────────────────────────────────────

    public function test_certificates_are_listed_for_the_signed_in_student_only(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id]);

        $other = User::factory()->create();
        [$otherUser, $otherCourse] = $this->finishedStudent(2);
        $this->actingAs($otherUser)->postJson('/api/certificates', ['course_id' => $otherCourse->id]);

        $response = $this->actingAs($user)->getJson('/api/certificates');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course.id', $course->id);

        $ids = collect($response->json('data'))->pluck('course.id')->all();
        $this->assertNotContains($otherCourse->id, $ids);
    }

    public function test_certificate_listing_is_paginated(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $course = Course::factory()->create();
            $this->enroll($user, $course);

            foreach (Lesson::factory()->count(1)->create(['course_id' => $course->id]) as $lesson) {
                $this->complete($user, $lesson);
            }

            $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id]);
        }

        $response = $this->actingAs($user)->getJson('/api/certificates?per_page=2');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('last_page', 2);

        $this->actingAs($user)->getJson('/api/certificates')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_certificate_listing_validates_its_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/certificates?per_page=0')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);

        $this->actingAs($user)->getJson('/api/certificates?per_page=500')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_empty_certificate_list_is_a_valid_page(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/certificates')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_guests_cannot_list_certificates(): void
    {
        $this->getJson('/api/certificates')->assertStatus(401);
    }

    public function test_the_owner_can_open_their_certificate(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $this->actingAs($user)->getJson("/api/certificates/{$certificate->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $certificate->id)
            ->assertJsonPath('certificate_number', 'CERT-2026-AAAA-BBBB')
            ->assertJsonPath('course.title', $course->title);
    }

    public function test_another_student_cannot_open_the_certificate(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/certificates/{$certificate->id}")
            ->assertStatus(403);
    }

    public function test_an_admin_can_open_any_certificate(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson("/api/certificates/{$certificate->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $certificate->id);
    }

    public function test_guests_cannot_open_a_certificate_by_id(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $this->getJson("/api/certificates/{$certificate->id}")->assertStatus(401);
    }

    // ─── PDF DOWNLOAD ────────────────────────────────────────────────

    public function test_the_owner_can_download_the_pdf(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($user)->get("/api/certificates/{$certificate->id}/download");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(1000, strlen($content));
        $this->assertStringContainsString(
            $certificate->certificate_number,
            $response->headers->get('content-disposition')
        );
    }

    public function test_the_owner_can_view_the_pdf_inline(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $response = $this->actingAs($user)->get("/api/certificates/{$certificate->id}/view");

        $response->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith(
            'inline;',
            (string) $response->headers->get('content-disposition')
        );
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_another_student_cannot_download_the_pdf(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/api/certificates/{$certificate->id}/download")
            ->assertStatus(403);
    }

    public function test_guests_cannot_download_a_pdf(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        // JSON clients get a 401; a plain browser request is redirected to login.
        $this->getJson("/api/certificates/{$certificate->id}/download")->assertStatus(401);
        $this->get("/api/certificates/{$certificate->id}/download")->assertStatus(302);
    }

    // ─── PUBLIC VERIFICATION ─────────────────────────────────────────

    public function test_anyone_can_verify_a_certificate_by_number(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $response = $this->getJson("/api/certificates/verify/{$certificate->certificate_number}");

        $response->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('certificate_number', 'CERT-2026-AAAA-BBBB')
            ->assertJsonPath('student_name', $user->name)
            ->assertJsonPath('course.title', $course->title);
    }

    public function test_verification_never_exposes_private_data(): void
    {
        $user = User::factory()->create(['email' => 'private@example.test', 'password' => 'secret-hash']);
        $course = Course::factory()->create();
        $certificate = Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        $payload = $this->getJson("/api/certificates/verify/{$certificate->certificate_number}")->json();

        $this->assertSame(
            ['valid', 'certificate_number', 'student_name', 'course', 'issued_at'],
            array_keys($payload)
        );
        $this->assertStringNotContainsString('private@example.test', json_encode($payload));
        $this->assertStringNotContainsString('secret-hash', json_encode($payload));
        $this->assertStringNotContainsString('user_id', json_encode($payload));
        $this->assertSame(['id', 'title', 'slug'], array_keys($payload['course']));
    }

    public function test_verifying_an_unknown_number_is_not_found(): void
    {
        $this->getJson('/api/certificates/verify/CERT-2026-ZZZZ-ZZZZ')
            ->assertStatus(404)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('certificate_number', 'CERT-2026-ZZZZ-ZZZZ');
    }

    public function test_the_verify_route_is_not_shadowed_by_the_id_route(): void
    {
        [$user, $course] = $this->finishedStudent(2);
        Certificate::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_number' => 'CERT-2026-AAAA-BBBB',
            'issued_at' => now(),
        ]);

        // `verify` must be routed to the public verification endpoint, not to
        // CertificateController::show() with the literal string "verify".
        $this->getJson('/api/certificates/verify/CERT-2026-AAAA-BBBB')
            ->assertStatus(200)
            ->assertJsonPath('valid', true);
    }

    // ─── DASHBOARD INTEGRATION ───────────────────────────────────────

    public function test_the_dashboard_issues_a_certificate_for_a_finished_course(): void
    {
        [$user, $course] = $this->finishedStudent(2);

        $this->assertDatabaseCount('certificates', 0);

        $response = $this->actingAs($user)->getJson('/api/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('summary.completed_courses', 1)
            ->assertJsonPath('summary.total_certificates', 1);

        $this->assertDatabaseHas('certificates', [
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
    }

    public function test_the_dashboard_does_not_duplicate_certificates(): void
    {
        [$user] = $this->finishedStudent(2);

        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);
        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);
        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);

        $this->assertSame(1, Certificate::where('user_id', $user->id)->count());
    }

    public function test_the_dashboard_does_not_issue_a_certificate_for_unfinished_courses(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $this->enroll($user, $course);

        $lessons = Lesson::factory()->count(3)->create(['course_id' => $course->id]);
        $this->complete($user, $lessons->first());

        $this->actingAs($user)->getJson('/api/dashboard')->assertStatus(200);

        $this->assertDatabaseCount('certificates', 0);
    }
}
