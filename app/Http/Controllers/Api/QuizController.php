<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user() ?: auth('sanctum')->user();

        $quizzes = Quiz::with('lesson.course')
            ->withCount('questions')
            ->when($request->lesson_id, fn ($query, $lessonId) => $query->where('lesson_id', $lessonId))
            ->when($request->course_id, fn ($query, $courseId) => $query->whereHas(
                'lesson',
                fn ($lessonQuery) => $lessonQuery->where('course_id', $courseId)
            ))
            ->when(! $this->isAdmin($user), fn ($query) => $query->published())
            ->latest()
            ->get();

        return response()->json($quizzes);
    }

    public function show(Request $request, Quiz $quiz)
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $manage = $this->canManageQuiz($user, $quiz);

        if (! $manage) {
            if (! $quiz->is_published) {
                abort(404);
            }

            $this->ensureCourseAccess($quiz->lesson?->course, $user);
        }

        $quiz->load(['lesson.course', 'questions']);

        if ($manage) {
            return response()->json($quiz);
        }

        return response()->json($this->serializeQuiz($quiz, $user));
    }

    private function serializeQuiz(Quiz $quiz, ?User $user): array
    {
        $data = $quiz->toArray();

        // Answer keys are only returned after a submission is graded, never
        // while a student is still looking at the quiz.
        $data['questions'] = $quiz->questions
            ->map(fn ($question) => $question->toStudentArray())
            ->values()
            ->all();

        $data['can_attempt'] = $user !== null && $this->ensureEnrollment($quiz->lesson?->course, $user);

        return $data;
    }

    private function canManageQuiz(?User $user, Quiz $quiz): bool
    {
        if (! $user) {
            return false;
        }

        $course = $quiz->lesson?->course;

        return $course !== null && $user->canManageCourse($course);
    }

    private function isAdmin(?User $user): bool
    {
        return $user?->isAdmin() ?? false;
    }

    private function ensureCourseAccess(?Course $course, ?User $user): void
    {
        if (! $course) {
            abort(404);
        }

        if ((float) $course->price <= 0) {
            return;
        }

        if (! $this->ensureEnrollment($course, $user)) {
            abort(403, 'You must be enrolled in this course to view its quizzes.');
        }
    }

    private function ensureEnrollment(?Course $course, ?User $user): bool
    {
        if (! $course || ! $user) {
            return false;
        }

        if ($user->canManageCourse($course)) {
            return true;
        }

        return $course->enrollments()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
