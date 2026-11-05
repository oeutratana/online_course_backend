<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $quiz = $request->quiz_id
            ? Quiz::with('lesson.course')->find($request->quiz_id)
            : null;

        // Admins get the full answer keys for the admin console.
        if ($user?->isAdmin()) {
            return response()->json($this->query($request)->orderBy('order')->orderBy('id')->get());
        }

        if ($quiz !== null) {
            if (! $quiz->is_published) {
                abort(404);
            }

            if (! $this->canAccessCourse($quiz->lesson?->course, $user)) {
                abort(403, 'You must be enrolled in this course to view its questions.');
            }
        }

        // Students and guests only receive sanitized questions for content they
        // are allowed to see, and never the `correct_option` column.
        $questions = $this->query($request)
            ->whereHas('quiz', fn ($query) => $query->published())
            ->whereHas('quiz.lesson.course', fn ($query) => $this->scopeAccessibleCourses($query, $user))
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json(
            $questions->map(fn (Question $question) => $question->toStudentArray())->values()
        );
    }

    public function show(Request $request, Question $question)
    {
        $user = $request->user() ?: auth('sanctum')->user();
        $quiz = $question->quiz;
        $quiz?->loadMissing('lesson.course');

        if ($this->canManageQuiz($user, $quiz)) {
            return response()->json($question->load('quiz.lesson.course'));
        }

        if (! $quiz?->is_published) {
            abort(404);
        }

        if (! $this->canAccessCourse($quiz->lesson?->course, $user)) {
            abort(403, 'You must be enrolled in this course to view its questions.');
        }

        return response()->json($question->toStudentArray());
    }

    private function query(Request $request): Builder
    {
        return Question::with('quiz.lesson.course')
            ->when($request->quiz_id, fn ($query, $quizId) => $query->where('quiz_id', $quizId));
    }

    private function scopeAccessibleCourses(Builder $query, ?User $user): Builder
    {
        return $query->where(function (Builder $accessible) use ($user) {
            $accessible->where('price', '<=', 0);

            if ($user) {
                $accessible->orWhere('user_id', $user->id)
                    ->orWhereHas('enrollments', fn ($enrollmentQuery) => $enrollmentQuery
                        ->where('user_id', $user->id)
                        ->where('status', 'active'));
            }
        });
    }

    private function canManageQuiz(?User $user, ?Quiz $quiz): bool
    {
        if (! $user || ! $quiz) {
            return false;
        }

        $course = $quiz->lesson?->course;

        return $course !== null && $user->canManageCourse($course);
    }

    private function canAccessCourse(?Course $course, ?User $user): bool
    {
        if (! $course) {
            return false;
        }

        if ((float) $course->price <= 0) {
            return true;
        }

        return $this->hasEnrollment($course, $user);
    }

    private function hasEnrollment(?Course $course, ?User $user): bool
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
