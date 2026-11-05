<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        $lessons = Lesson::with('course')
            ->withCount('quizzes')
            ->when($request->course_id, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('course_id')
            ->orderBy('order')
            ->paginate($request->integer('per_page', 10))
            ->withQueryString();

        return response()->json($lessons);
    }

    public function show(Request $request, Lesson $lesson)
    {
        $this->authorizeLesson($request, $lesson);

        return response()->json($lesson->load(['course', 'quizzes']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_preview' => ['sometimes', 'boolean'],
        ]);

        $course = Course::findOrFail($validated['course_id']);
        $this->authorizeCourse($request, $course);

        $lesson = Lesson::create($validated);

        return response()->json($lesson->load(['course', 'quizzes']), 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        $this->authorizeLesson($request, $lesson);

        $validated = $request->validate([
            'course_id' => ['sometimes', 'integer', 'exists:courses,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_preview' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['course_id']) && (int) $validated['course_id'] !== (int) $lesson->course_id) {
            $this->authorizeCourse($request, Course::findOrFail($validated['course_id']));
        }

        $lesson->update($validated);

        return response()->json($lesson->fresh()->load(['course', 'quizzes']));
    }

    public function destroy(Request $request, Lesson $lesson)
    {
        $this->authorizeLesson($request, $lesson);

        $lesson->delete();

        return response()->json([
            'message' => 'Lesson deleted successfully.',
        ]);
    }

    private function authorizeLesson(Request $request, Lesson $lesson): void
    {
        $this->authorizeCourse($request, $lesson->course);
    }

    private function authorizeCourse(Request $request, ?Course $course): void
    {
        $user = $request->user();

        if (! $course || ! $user?->canManageCourse($course)) {
            abort(403, 'Only admins can manage lessons.');
        }
    }
}
