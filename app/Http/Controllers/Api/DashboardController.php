<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use App\Services\CertificateService;
use App\Services\CourseCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    private const RECENT_ACTIVITY_LIMIT = 10;

    public function __construct(
        private readonly CourseCompletionService $completion,
        private readonly CertificateService $certificates,
    ) {}

    /**
     * Learning dashboard for the authenticated user.
     *
     * Everything is derived from the current user's own enrollments and lesson
     * progress; no other student's data is ever read. Finished courses get
     * their certificate issued on the fly so a student never has to make an
     * extra request for it.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $enrollments = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['course.instructor:id,name', 'course.category:id,name'])
            ->latest('enrolled_at')
            ->latest('id')
            ->get();

        $courseIds = $enrollments->pluck('course_id')->all();
        $lessonCounts = $this->completion->lessonCountsForCourses($courseIds);
        $completedCounts = $this->completion->completedLessonCountsForCourses($user->id, $courseIds);

        // Claim any certificate the student has earned but not requested yet.
        $this->certificates->issueForCompletedCourses($user);

        $courses = $enrollments->map(function (Enrollment $enrollment) use ($lessonCounts, $completedCounts) {
            $courseId = $enrollment->course_id;
            $totalLessons = $lessonCounts[$courseId] ?? 0;
            $completedLessons = $completedCounts[$courseId] ?? 0;
            $progress = $totalLessons > 0
                ? round(min($completedLessons / $totalLessons, 1) * 100, 2)
                : 0.0;

            return [
                'course_id' => $courseId,
                'title' => $enrollment->course?->title,
                'slug' => $enrollment->course?->slug,
                'thumbnail' => $enrollment->course?->thumbnail,
                'instructor' => $enrollment->course?->instructor?->name,
                'category' => $enrollment->course?->category?->name,
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'progress' => $progress,
                'is_completed' => $totalLessons > 0 && $completedLessons >= $totalLessons,
            ];
        })->values();

        $totalLessons = $courses->sum('total_lessons');
        $completedLessons = $courses->sum('completed_lessons');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'summary' => [
                'total_enrolled_courses' => $courses->count(),
                'completed_courses' => $courses->where('is_completed', true)->count(),
                'in_progress_courses' => $courses->where('is_completed', false)->count(),
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'learning_progress' => $totalLessons > 0
                    ? round(min($completedLessons / $totalLessons, 1) * 100, 2)
                    : 0.0,
                'total_certificates' => $user->certificates()->count(),
                'wishlist_count' => $user->wishlists()->count(),
                'reviews_written' => $user->courseReviews()->count(),
            ],
            'courses' => $courses,
            'recent_activity' => $this->recentActivity($user->id),
            'certificates' => $user->certificates()
                ->with(['course:id,title'])
                ->latest('issued_at')
                ->limit(self::RECENT_ACTIVITY_LIMIT)
                ->get()
                ->map(fn ($certificate) => [
                    'id' => $certificate->id,
                    'certificate_number' => $certificate->certificate_number,
                    'course_title' => $certificate->course?->title,
                    'issued_at' => $certificate->issued_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentActivity(int $userId)
    {
        return LessonProgress::query()
            ->where('user_id', $userId)
            ->where('is_completed', true)
            ->with(['lesson:id,title,course_id', 'lesson.course:id,title'])
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get()
            ->map(fn (LessonProgress $progress) => [
                'lesson_id' => $progress->lesson_id,
                'lesson_title' => $progress->lesson?->title,
                'course_id' => $progress->lesson?->course_id,
                'course_title' => $progress->lesson?->course?->title,
                'completed_at' => $progress->completed_at?->toIso8601String(),
            ])
            ->values();
    }
}
