<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function index(Request $request)
    {
        $lessons = Lesson::with('course')
            ->when($request->course_id, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->orderBy('course_id')
            ->orderBy('order')
            ->get();

        $user = $request->user() ?: auth('sanctum')->user();

        return response()->json($lessons->map(fn (Lesson $lesson) => $this->serializeLesson($lesson, $user))->values());
    }

    public function show(Request $request, Lesson $lesson)
    {
        $lesson->load([
            'course',
            'progress.user',
            'quizzes.questions',
        ]);

        $user = $request->user() ?: auth('sanctum')->user();

        return response()->json($this->serializeLesson($lesson, $user));
    }

    private function serializeLesson(Lesson $lesson, $user): array
    {
        $data = $lesson->toArray();

        if ($lesson->is_preview || $this->hasCourseAccess($lesson, $user)) {
            return $data;
        }

        $data['video_url'] = null;

        return $data;
    }

    private function hasCourseAccess(Lesson $lesson, $user): bool
    {
        $course = $lesson->course;

        if (! $course || (float) $course->price <= 0) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin' || $course->user_id === $user->id) {
            return true;
        }

        return $course->enrollments()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
