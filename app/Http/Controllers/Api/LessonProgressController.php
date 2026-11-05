<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CourseCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LessonProgressController extends Controller
{
    public function __construct(
        private readonly CourseCompletionService $completion,
        private readonly CertificateService $certificates,
    ) {}

    public function index(Request $request)
    {
        $progress = LessonProgress::with(['user', 'lesson.course'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->lesson_id, fn ($query, $lessonId) => $query->where('lesson_id', $lessonId))
            ->when($request->has('completed'), fn ($query) => $query->where('is_completed', $request->boolean('completed')))
            ->latest()
            ->get();

        return response()->json($progress);
    }

    public function show(LessonProgress $lessonProgress)
    {
        return response()->json($lessonProgress->load(['user', 'lesson.course']));
    }

    /**
     * Mark the authenticated student's lesson as completed. The user id is
     * always taken from the token, never from the request body.
     */
    public function store(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        $this->ensureEnrolled($user->id, $lesson);
        $this->ensureRequiredQuizzesPassed($user, $lesson);

        $progress = LessonProgress::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['is_completed' => true, 'completed_at' => now()]
        );

        return response()->json($progress->load('lesson.course'), 201);
    }

    public function destroy(Request $request, Lesson $lesson)
    {
        $user = $request->user();
        $this->ensureEnrolled($user->id, $lesson);

        $progress = LessonProgress::firstOrNew([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
        ]);

        $progress->is_completed = false;
        $progress->completed_at = null;
        $progress->save();

        return response()->json($progress->load('lesson.course'));
    }

    public function courseProgress(Request $request, Course $course)
    {
        $user = $request->user();

        if (! $user->canManageCourse($course)) {
            $this->ensureEnrolled($user->id, null, $course);
        }

        $summary = $this->completion->progressForCourse($user, $course);

        $completedLessonIds = LessonProgress::where('user_id', $user->id)
            ->where('is_completed', true)
            ->whereIn('lesson_id', $course->lessons()->select('id'))
            ->pluck('lesson_id')
            ->all();

        $lessons = $course->lessons()
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $quizSummaries = $this->quizSummaries($user, $lessons->pluck('id')->all());

        $lessonPayload = $lessons->map(function (Lesson $lesson) use ($completedLessonIds, $quizSummaries) {
            $quizzes = $quizSummaries->get($lesson->id, collect())->values();
            $requiredQuizzes = $quizzes->where('is_required', true);
            $requiredQuizzesPassed = $requiredQuizzes->every(fn (array $quiz) => $quiz['passed']);

            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'order' => $lesson->order,
                'is_completed' => in_array($lesson->id, $completedLessonIds, true),
                'quizzes' => $quizzes,
                'required_quizzes_passed' => $requiredQuizzesPassed,
                'can_complete' => $requiredQuizzesPassed,
            ];
        });

        $requiredQuizzes = $quizSummaries
            ->flatten(1)
            ->where('is_required', true)
            ->values();

        $eligibility = $this->certificates->eligibility($user, $course);

        return response()->json([
            ...$summary,
            'course_id' => $course->id,
            'lessons' => $lessonPayload,
            'quizzes' => [
                'required_total' => $requiredQuizzes->count(),
                'required_passed' => $requiredQuizzes->where('passed', true)->count(),
                'satisfied' => $requiredQuizzes->every(fn (array $quiz) => $quiz['passed']),
            ],
            'certificate' => [
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'],
            ],
        ]);
    }

    /**
     * Published quiz rows for a set of lessons, including the signed-in
     * student's attempt summary. Answer keys are never part of this payload.
     */
    private function quizSummaries(User $user, array $lessonIds): Collection
    {
        if ($lessonIds === []) {
            return collect();
        }

        $quizzes = Quiz::query()
            ->whereIn('lesson_id', $lessonIds)
            ->where('is_published', true)
            ->withCount('questions')
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        $attemptsByQuiz = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizzes->pluck('id'))
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('quiz_id');

        return $quizzes->groupBy('lesson_id')->map(function (Collection $lessonQuizzes) use ($attemptsByQuiz) {
            return $lessonQuizzes->map(function (Quiz $quiz) use ($attemptsByQuiz) {
                $attempts = $attemptsByQuiz->get($quiz->id, collect());
                $lastAttempt = $attempts->sortByDesc('attempt_number')->first();
                $bestScore = $attempts->max('score');

                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'passing_score' => max((int) $quiz->passing_score, Quiz::MINIMUM_PASSING_SCORE),
                    'max_attempts' => (int) $quiz->max_attempts,
                    'is_required' => (bool) $quiz->is_required,
                    'questions_count' => (int) $quiz->questions_count,
                    'attempts_used' => $attempts->count(),
                    'best_score' => $bestScore === null ? null : round((float) $bestScore, 2),
                    'passed' => $this->hasPassedQuiz($attempts),
                    'can_attempt' => $quiz->questions_count > 0
                        && $quiz->hasAttemptsRemaining($attempts->count()),
                    'last_attempt' => $lastAttempt === null ? null : [
                        'attempt_number' => (int) $lastAttempt->attempt_number,
                        'score' => round((float) $lastAttempt->score, 2),
                        'passed' => (bool) $lastAttempt->passed,
                        'submitted_at' => $lastAttempt->submitted_at?->toIso8601String(),
                    ],
                ];
            })->values();
        });
    }

    /**
     * A required quiz blocks lesson completion until the student has a passing
     * attempt that also clears the certificate-wide 60% minimum.
     */
    private function ensureRequiredQuizzesPassed(User $user, Lesson $lesson): void
    {
        $hasRequiredQuizzes = $lesson->quizzes()->published()->required()->exists();

        if (! $hasRequiredQuizzes) {
            return;
        }

        $quizzes = $this->quizSummaries($user, [$lesson->id])->get($lesson->id, collect());
        $missing = $quizzes->where('is_required', true)->where('passed', false);

        if ($missing->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'quiz' => [
                'Pass every required quiz before completing this lesson: '
                .$missing->pluck('title')->implode(', ').'.',
            ],
        ]);
    }

    private function hasPassedQuiz(Collection $attempts): bool
    {
        return $attempts->contains(
            fn (QuizAttempt $attempt) => $attempt->passed
                && (float) $attempt->score >= Quiz::MINIMUM_PASSING_SCORE
        );
    }

    private function ensureEnrolled(int $userId, ?Lesson $lesson, ?Course $course = null): void
    {
        $course ??= $lesson?->course;

        $enrolled = $course !== null && Enrollment::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        if (! $enrolled) {
            abort(403, 'You need an active enrollment for this course.');
        }
    }
}
