<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function index(Request $request)
    {
        $query = Quiz::with('lesson.course')
            ->withCount('questions')
            ->when($request->lesson_id, fn ($builder, $lessonId) => $builder->where('lesson_id', $lessonId))
            ->when($request->course_id, fn ($builder, $courseId) => $builder->whereHas(
                'lesson',
                fn ($lessonQuery) => $lessonQuery->where('course_id', $courseId)
            ))
            ->when($request->search, function ($builder, $search) {
                $builder->where(function ($builder) use ($search) {
                    $builder->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->status === 'published', fn ($builder) => $builder->where('is_published', true))
            ->when($request->status === 'draft', fn ($builder) => $builder->where('is_published', false));

        $stats = [
            'total' => Quiz::count(),
            'published' => Quiz::where('is_published', true)->count(),
            'draft' => Quiz::where('is_published', false)->count(),
            'required' => Quiz::where('is_required', true)->count(),
        ];

        $quizzes = $query
            ->latest()
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        return response()->json(array_merge($quizzes->toArray(), ['stats' => $stats]));
    }

    public function show(Request $request, Quiz $quiz)
    {
        $this->authorizeQuiz($request, $quiz);

        return response()->json($quiz->load(['lesson.course', 'questions']));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedData($request, true);

        $lesson = Lesson::findOrFail($validated['lesson_id']);
        $this->authorizeCourse($request, $lesson->course);

        $quiz = Quiz::create($validated);

        return response()->json($quiz->load(['lesson.course', 'questions']), 201);
    }

    public function update(Request $request, Quiz $quiz)
    {
        $this->authorizeQuiz($request, $quiz);

        $validated = $this->validatedData($request, false);

        if (isset($validated['lesson_id']) && (int) $validated['lesson_id'] !== (int) $quiz->lesson_id) {
            $this->authorizeCourse($request, Lesson::findOrFail($validated['lesson_id'])->course);
        }

        $quiz->update($validated);

        return response()->json($quiz->fresh()->load(['lesson.course', 'questions']));
    }

    public function destroy(Request $request, Quiz $quiz)
    {
        $this->authorizeQuiz($request, $quiz);

        $quiz->delete();

        return response()->json([
            'message' => 'Quiz deleted successfully.',
        ]);
    }

    private function validatedData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lesson_id' => [$required, 'integer', 'exists:lessons,id'],
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'passing_score' => ['sometimes', 'integer', 'min:60', 'max:100'],
            'max_attempts' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_published' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeQuiz(Request $request, Quiz $quiz): void
    {
        $this->authorizeCourse($request, $quiz->lesson?->course);
    }

    private function authorizeCourse(Request $request, ?Course $course): void
    {
        if (! $course || ! $request->user()?->canManageCourse($course)) {
            abort(403, 'Only admins can manage quizzes.');
        }
    }
}
