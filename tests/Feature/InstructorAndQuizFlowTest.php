<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstructorAndQuizFlowTest extends TestCase
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

    private function quizWithQuestions(Course $course, array $attributes = [], int $questionCount = 2, ?Lesson $lesson = null): Quiz
    {
        $lesson ??= Lesson::factory()->for($course)->create();
        $quiz = Quiz::factory()->for($lesson, 'lesson')->create($attributes);

        foreach (range(1, $questionCount) as $index) {
            Question::factory()->for($quiz, 'quiz')->correct('a')->create();
        }

        return $quiz->fresh('questions');
    }

    // ─── ROLE ENFORCEMENT ────────────────────────────────────────────

    public function test_only_admins_can_create_courses(): void
    {
        $payload = [
            'title' => 'New Course',
            'description' => 'Description',
            'price' => 10,
        ];

        $this->actingAs($this->student())->postJson('/api/courses', $payload)
            ->assertStatus(403);

        $admin = $this->admin();
        $response = $this->actingAs($admin)->postJson('/api/courses', $payload);

        $response->assertCreated()
            ->assertJsonPath('user_id', $admin->id);
    }

    public function test_only_admins_can_update_courses(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($this->student())->putJson("/api/courses/{$course->id}", [
            'title' => 'Hijacked',
            'price' => 5,
        ])->assertStatus(403);

        $this->actingAs($admin)->putJson("/api/courses/{$course->id}", [
            'title' => 'Admin edit',
            'price' => 5,
        ])->assertOk()->assertJsonPath('title', 'Admin edit');
    }

    public function test_only_admins_can_manage_lessons_quizzes_and_questions(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create(['user_id' => $admin->id]);

        $lessonResponse = $this->actingAs($admin)->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Admin lesson',
        ])->assertCreated();

        $lessonId = $lessonResponse->json('id');

        $quizResponse = $this->actingAs($admin)->postJson('/api/quizzes', [
            'lesson_id' => $lessonId,
            'title' => 'Admin quiz',
        ])->assertCreated();

        $quizId = $quizResponse->json('id');

        $this->actingAs($admin)->postJson("/api/quizzes/{$quizId}/questions", [
            'quiz_id' => $quizId,
            'question' => 'Pick A',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_option' => 'a',
        ])->assertCreated()->assertJsonPath('correct_option', 'option_a');

        $student = $this->student();

        $this->actingAs($student)->postJson('/api/lessons', [
            'course_id' => $course->id,
            'title' => 'Student lesson',
        ])->assertStatus(403);

        $this->actingAs($student)->postJson('/api/quizzes', [
            'lesson_id' => $lessonId,
            'title' => 'Student quiz',
        ])->assertStatus(403);

        $this->actingAs($student)->postJson("/api/quizzes/{$quizId}/questions", [
            'quiz_id' => $quizId,
            'question' => 'Nope',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_option' => 'a',
        ])->assertStatus(403);
    }

    // ─── ANSWER KEY PROTECTION ───────────────────────────────────────

    public function test_students_never_receive_the_answer_key_before_submitting(): void
    {
        $course = Course::factory()->create(['price' => 0]);
        $quiz = $this->quizWithQuestions($course);

        $response = $this->getJson("/api/quizzes/{$quiz->id}");

        $response->assertOk()
            ->assertJsonMissingPath('questions.0.correct_option')
            ->assertJsonMissingPath('questions.1.correct_option')
            ->assertJsonPath('is_published', true);

        $this->getJson("/api/questions?quiz_id={$quiz->id}")
            ->assertOk()
            ->assertJsonMissingPath('0.correct_option');
    }

    public function test_answer_keys_are_visible_to_admins(): void
    {
        $admin = $this->admin();
        $course = Course::factory()->create(['user_id' => $admin->id, 'price' => 0]);
        $quiz = $this->quizWithQuestions($course);

        $this->actingAs($admin)->getJson("/api/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('questions.0.correct_option', 'option_a');
    }

    public function test_nested_course_and_lesson_payloads_do_not_leak_answers(): void
    {
        $course = Course::factory()->create(['price' => 0]);
        $lesson = Lesson::factory()->for($course)->create(['is_preview' => true]);
        $quiz = Quiz::factory()->for($lesson, 'lesson')->create();
        Question::factory()->for($quiz, 'quiz')->correct('a')->create();

        $this->getJson("/api/courses/{$course->id}")
            ->assertOk()
            ->assertJsonMissingPath('lessons.0.quizzes.0.questions.0.correct_option');

        $this->getJson("/api/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonMissingPath('quizzes.0.questions.0.correct_option');
    }

    public function test_unpublished_quizzes_are_hidden_from_students(): void
    {
        $course = Course::factory()->create(['price' => 0]);
        $quiz = $this->quizWithQuestions($course, ['is_published' => false]);

        $this->getJson("/api/quizzes/{$quiz->id}")->assertNotFound();
    }

    // ─── QUIZ ATTEMPTS ───────────────────────────────────────────────

    public function test_quiz_attempts_are_scored_and_passed_on_the_server(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $quiz = $this->quizWithQuestions($course, ['passing_score' => 60]);
        $questions = $quiz->questions;

        $response = $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", [
            'answers' => [
                $questions[0]->id => 'a',
                $questions[1]->id => 'option_a',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('score', '100.00')
            ->assertJsonPath('passed', true)
            ->assertJsonPath('attempt_number', 1)
            ->assertJsonPath('correct_answers', 2)
            ->assertJsonPath('total_questions', 2);

        $this->assertDatabaseHas('quiz_attempts', [
            'user_id' => $user->id,
            'quiz_id' => $quiz->id,
            'score' => 100,
            'passed' => true,
        ]);
        $this->assertDatabaseCount('quiz_attempt_answers', 2);
    }

    public function test_a_failed_attempt_is_recorded_with_the_wrong_answers(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $quiz = $this->quizWithQuestions($course);
        $questions = $quiz->questions;

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", [
            'answers' => [
                $questions[0]->id => 'b',
                $questions[1]->id => 'c',
            ],
        ])->assertCreated()
            ->assertJsonPath('score', '0.00')
            ->assertJsonPath('passed', false);

        $this->assertDatabaseHas('quiz_attempt_answers', [
            'selected_option' => 'b',
            'is_correct' => false,
        ]);
    }

    public function test_attempts_require_an_active_enrollment_and_all_answers(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $quiz = $this->quizWithQuestions($course);
        $question = $quiz->questions->first();

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", [
            'answers' => [$question->id => 'a'],
        ])->assertStatus(403);

        $this->enroll($user, $course);

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", [
            'answers' => [$question->id => 'a'],
        ])->assertStatus(422)->assertJsonValidationErrors('answers');
    }

    public function test_the_configured_attempt_limit_is_enforced(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $quiz = $this->quizWithQuestions($course, ['max_attempts' => 1]);
        $answers = $quiz->questions->mapWithKeys(fn (Question $question) => [$question->id => 'a'])->all();

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->assertCreated();

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_student_can_review_their_own_attempt_but_not_someone_elses(): void
    {
        $owner = $this->student();
        $stranger = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($owner, $course);
        $this->enroll($stranger, $course);
        $quiz = $this->quizWithQuestions($course);
        $answers = $quiz->questions->mapWithKeys(fn (Question $question) => [$question->id => 'a'])->all();

        $attemptId = $this->actingAs($owner)
            ->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->json('id');

        $this->actingAs($owner)->getJson("/api/quiz-attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('passed', true);

        $this->actingAs($stranger)->getJson("/api/quiz-attempts/{$attemptId}")
            ->assertStatus(403);
    }

    // ─── LESSON PROGRESS ─────────────────────────────────────────────

    public function test_a_student_can_mark_a_lesson_complete_and_see_course_progress(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lessons = Lesson::factory()->count(2)->for($course)->create();

        $this->actingAs($user)->postJson("/api/lessons/{$lessons[0]->id}/progress")
            ->assertCreated()
            ->assertJsonPath('is_completed', true);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $user->id,
            'lesson_id' => $lessons[0]->id,
            'is_completed' => true,
        ]);

        $this->actingAs($user)->getJson("/api/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonPath('completed_lessons', 1)
            ->assertJsonPath('total_lessons', 2)
            ->assertJsonPath('progress', 50)
            ->assertJsonPath('is_complete', false);

        $this->actingAs($user)->deleteJson("/api/lessons/{$lessons[0]->id}/progress")
            ->assertOk()
            ->assertJsonPath('is_completed', false);
    }

    public function test_progress_cannot_be_written_without_an_enrollment(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $lesson = Lesson::factory()->for($course)->create();

        $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress")
            ->assertStatus(403);

        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_course_progress_includes_quiz_status_without_answer_keys(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lesson = Lesson::factory()->for($course)->create(['title' => 'Quiz lesson']);
        $quiz = $this->quizWithQuestions($course, [
            'title' => 'Required quiz',
            'is_required' => true,
        ], 2, $lesson);

        $response = $this->actingAs($user)->getJson("/api/courses/{$course->id}/progress");

        $response->assertOk()
            ->assertJsonPath('lessons.0.title', 'Quiz lesson')
            ->assertJsonPath('lessons.0.quizzes.0.id', $quiz->id)
            ->assertJsonPath('lessons.0.quizzes.0.title', 'Required quiz')
            ->assertJsonPath('lessons.0.quizzes.0.questions_count', 2)
            ->assertJsonPath('lessons.0.quizzes.0.attempts_used', 0)
            ->assertJsonPath('lessons.0.quizzes.0.passed', false)
            ->assertJsonPath('lessons.0.can_complete', false)
            ->assertJsonPath('quizzes.required_total', 1)
            ->assertJsonPath('quizzes.required_passed', 0)
            ->assertJsonPath('quizzes.satisfied', false)
            ->assertJsonPath('certificate.eligible', false)
            ->assertJsonMissingPath('lessons.0.quizzes.0.correct_option');

        $answers = $quiz->questions->mapWithKeys(fn (Question $question) => [$question->id => 'a'])->all();
        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->assertCreated();

        $this->actingAs($user)->getJson("/api/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonPath('lessons.0.quizzes.0.attempts_used', 1)
            ->assertJsonPath('lessons.0.quizzes.0.best_score', 100)
            ->assertJsonPath('lessons.0.quizzes.0.passed', true)
            ->assertJsonPath('lessons.0.can_complete', true)
            ->assertJsonPath('quizzes.required_passed', 1)
            ->assertJsonPath('quizzes.satisfied', true)
            // Passing the quiz alone is not enough: the lesson still has to be completed.
            ->assertJsonPath('certificate.eligible', false);
    }

    public function test_a_required_quiz_blocks_lesson_completion_until_it_is_passed(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lesson = Lesson::factory()->for($course)->create();
        $quiz = $this->quizWithQuestions($course, ['is_required' => true], 2, $lesson);

        $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quiz');

        $this->assertDatabaseCount('lesson_progress', 0);

        $answers = $quiz->questions->mapWithKeys(fn (Question $question) => [$question->id => 'a'])->all();
        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->assertCreated();

        $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress")
            ->assertCreated()
            ->assertJsonPath('is_completed', true);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'is_completed' => true,
        ]);

        $this->actingAs($user)->getJson("/api/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonPath('certificate.eligible', true);
    }

    public function test_optional_or_unpublished_quizzes_do_not_block_lesson_completion(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lesson = Lesson::factory()->for($course)->create();

        $this->quizWithQuestions($course, ['is_required' => false], 2, $lesson);
        $this->quizWithQuestions($course, ['is_required' => true, 'is_published' => false], 2, $lesson);

        $this->actingAs($user)->postJson("/api/lessons/{$lesson->id}/progress")
            ->assertCreated()
            ->assertJsonPath('is_completed', true);
    }

    // ─── CERTIFICATES ────────────────────────────────────────────────

    public function test_certificates_require_every_required_quiz_to_be_passed(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lesson = Lesson::factory()->for($course)->create();
        $this->complete($user, $lesson);

        $quiz = $this->quizWithQuestions($course, [
            'is_required' => true,
            'is_published' => true,
            'max_attempts' => 0,
        ], 2, $lesson);
        $answers = $quiz->questions->mapWithKeys(fn (Question $question) => [$question->id => 'a'])->all();

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Pass every required quiz with at least 60% before claiming a certificate.');

        $this->actingAs($user)->postJson("/api/quizzes/{$quiz->id}/attempts", ['answers' => $answers])
            ->assertCreated();

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertCreated();
    }

    public function test_optional_or_unpublished_quizzes_do_not_block_certificates(): void
    {
        $user = $this->student();
        $course = Course::factory()->create(['price' => 0]);
        $this->enroll($user, $course);
        $lesson = Lesson::factory()->for($course)->create();
        $this->complete($user, $lesson);

        $this->quizWithQuestions($course, ['is_required' => false], 2, $lesson);
        $this->quizWithQuestions($course, ['is_required' => true, 'is_published' => false], 2, $lesson);

        $this->actingAs($user)->postJson('/api/certificates', ['course_id' => $course->id])
            ->assertCreated();
    }
}
