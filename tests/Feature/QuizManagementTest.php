<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create(['role' => 'admin'])->createToken('test')->plainTextToken;
    }

    private function lesson(): Lesson
    {
        return Lesson::factory()->for(Course::factory())->create();
    }

    public function test_admin_can_create_update_publish_and_delete_a_quiz(): void
    {
        $token = $this->adminToken();
        $lesson = $this->lesson();

        $create = $this->withToken($token)->postJson('/api/quizzes', [
            'lesson_id' => $lesson->id,
            'title' => 'Lesson checkpoint',
            'description' => 'Check understanding',
            'passing_score' => 70,
            'max_attempts' => 3,
            'is_published' => false,
            'is_required' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('lesson_id', $lesson->id)
            ->assertJsonPath('passing_score', 70)
            ->assertJsonPath('max_attempts', 3)
            ->assertJsonPath('is_published', false);

        $quizId = $create->json('id');

        $this->assertDatabaseHas('quizzes', [
            'id' => $quizId,
            'lesson_id' => $lesson->id,
            'title' => 'Lesson checkpoint',
        ]);

        $this->withToken($token)->putJson("/api/quizzes/{$quizId}", [
            'is_published' => true,
            'passing_score' => 80,
        ])->assertOk()
            ->assertJsonPath('is_published', true)
            ->assertJsonPath('passing_score', 80);

        $this->withToken($token)->deleteJson("/api/quizzes/{$quizId}")->assertOk();

        $this->assertDatabaseMissing('quizzes', ['id' => $quizId]);
    }

    public function test_admin_can_manage_and_reorder_questions(): void
    {
        $token = $this->adminToken();
        $lesson = $this->lesson();
        $quiz = Quiz::factory()->for($lesson)->create();

        $first = $this->withToken($token)->postJson("/api/quizzes/{$quiz->id}/questions", [
            'question' => 'First question?',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_option' => 'b',
            'points' => 2,
            'explanation' => 'Because B is correct.',
        ]);

        $first->assertCreated()
            ->assertJsonPath('correct_option', 'option_b')
            ->assertJsonPath('points', 2);

        $second = $this->withToken($token)->postJson("/api/quizzes/{$quiz->id}/questions", [
            'question' => 'Second question?',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_option' => 'a',
        ]);

        $second->assertCreated();

        $firstId = $first->json('id');
        $secondId = $second->json('id');

        $this->withToken($token)->putJson("/api/quizzes/{$quiz->id}/questions/reorder", [
            'question_ids' => [$secondId, $firstId],
        ])->assertOk()
            ->assertJsonPath('questions.0.id', $secondId)
            ->assertJsonPath('questions.1.id', $firstId);

        $this->assertDatabaseHas('questions', ['id' => $secondId, 'order' => 0]);
        $this->assertDatabaseHas('questions', ['id' => $firstId, 'order' => 1]);

        $this->withToken($token)->putJson("/api/questions/{$firstId}", [
            'question' => 'First question edited?',
            'correct_option' => 'c',
        ])->assertOk()
            ->assertJsonPath('question', 'First question edited?')
            ->assertJsonPath('correct_option', 'option_c');

        $this->withToken($token)->deleteJson("/api/questions/{$secondId}")->assertOk();

        $this->assertDatabaseMissing('questions', ['id' => $secondId]);
    }

    public function test_admin_quiz_index_filters_and_reports_stats(): void
    {
        $token = $this->adminToken();
        $lesson = $this->lesson();
        $otherLesson = $this->lesson();

        Quiz::factory()->for($lesson)->create(['title' => 'Published quiz', 'is_published' => true]);
        Quiz::factory()->for($lesson)->create(['title' => 'Draft quiz', 'is_published' => false]);
        Quiz::factory()->for($otherLesson)->create(['title' => 'Other lesson quiz']);

        $this->withToken($token)->getJson('/api/admin/quizzes?status=draft')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Draft quiz')
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.draft', 1);

        $this->withToken($token)->getJson("/api/admin/quizzes?lesson_id={$lesson->id}&search=Published")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Published quiz');
    }

    public function test_admin_question_bank_lists_new_questions_and_filters_by_quiz(): void
    {
        $token = $this->adminToken();
        $htmlQuiz = Quiz::factory()->create(['title' => 'HTML quiz']);
        $cssQuiz = Quiz::factory()->create(['title' => 'CSS quiz']);

        $this->withToken($token)->postJson("/api/quizzes/{$htmlQuiz->id}/questions", [
            'question' => 'What does HTML stand for?',
            'option_a' => 'Hyper Text Markup Language',
            'option_b' => 'Home Tool Markup Language',
            'option_c' => 'Hyperlinks and Text Markup Language',
            'option_d' => 'None of the above',
            'correct_option' => 'a',
            'points' => 2,
        ])->assertCreated()
            ->assertJsonPath('correct_option', 'option_a')
            ->assertJsonPath('points', 2);

        Question::factory()->for($cssQuiz, 'quiz')->create([
            'question' => 'Which property changes text color?',
            'correct_option' => 'option_c',
            'points' => 3,
            'order' => 1,
        ]);

        $this->withToken($token)->getJson('/api/admin/questions')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.quiz.title', 'HTML quiz')
            ->assertJsonPath('0.correct_option', 'option_a')
            ->assertJsonPath('0.points', 2)
            ->assertJsonPath('1.quiz.title', 'CSS quiz')
            ->assertJsonPath('1.correct_option', 'option_c')
            ->assertJsonPath('1.points', 3);

        $this->withToken($token)->getJson("/api/admin/questions?quiz_id={$cssQuiz->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.question', 'Which property changes text color?')
            ->assertJsonPath('0.quiz.title', 'CSS quiz');

        $this->withToken($token)->getJson('/api/admin/quizzes?search=HTML quiz')
            ->assertOk()
            ->assertJsonPath('data.0.questions_count', 1);
    }

    public function test_students_cannot_read_the_admin_question_bank(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->getJson('/api/admin/questions')->assertUnauthorized();

        $this->withToken($student->createToken('test')->plainTextToken)
            ->getJson('/api/admin/questions')
            ->assertForbidden();
    }

    public function test_students_cannot_manage_quizzes_or_questions(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $quiz = Quiz::factory()->create();

        $this->actingAs($student)->postJson('/api/quizzes', [
            'lesson_id' => $quiz->lesson_id,
            'title' => 'Nope',
        ])->assertForbidden();

        $this->actingAs($student)->postJson("/api/quizzes/{$quiz->id}/questions", [
            'question' => 'Nope?',
            'option_a' => 'A',
            'option_b' => 'B',
            'option_c' => 'C',
            'option_d' => 'D',
            'correct_option' => 'a',
        ])->assertForbidden();

        $this->actingAs($student)->putJson("/api/quizzes/{$quiz->id}", [
            'title' => 'Nope',
        ])->assertForbidden();
    }

    public function test_students_only_see_published_quizzes_while_admin_index_includes_drafts(): void
    {
        $adminToken = $this->adminToken();
        $lesson = $this->lesson();

        Quiz::factory()->for($lesson)->create(['title' => 'Visible', 'is_published' => true]);
        Quiz::factory()->for($lesson)->create(['title' => 'Hidden', 'is_published' => false]);

        $this->getJson('/api/quizzes')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'Visible');

        $this->withToken($adminToken)->getJson('/api/admin/quizzes')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
