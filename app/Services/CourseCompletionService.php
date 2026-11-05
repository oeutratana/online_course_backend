<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "is this course finished?" rules.
 *
 * The project stores no completion flag: an enrollment row only ever carries
 * `status = 'active'` (see PaymentController::enroll()), so lesson completion has
 * to be derived from `lesson_progress`.
 *
 * This service intentionally measures lesson progress only. CertificateService
 * layers the "all required quizzes passed" rule on top, so dashboard progress
 * and certificate eligibility stay distinct concerns.
 *
 * A course with zero lessons is reported as 0% and is NOT complete: awarding a
 * certificate for an empty course would be meaningless, and it keeps the
 * division safe.
 */
class CourseCompletionService
{
    /**
     * Progress of one user in one course.
     *
     * @return array{total_lessons:int, completed_lessons:int, progress:float, is_complete:bool}
     */
    public function progressFor(int $userId, int $courseId): array
    {
        $totalLessons = (int) Lesson::where('course_id', $courseId)->count();
        $completedLessons = $this->completedLessonCount($userId, $courseId);

        return [
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'progress' => $this->percentage($completedLessons, $totalLessons),
            'is_complete' => $totalLessons > 0 && $completedLessons >= $totalLessons,
        ];
    }

    /**
     * @return array{total_lessons:int, completed_lessons:int, progress:float, is_complete:bool}
     */
    public function progressForCourse(User $user, Course $course): array
    {
        return $this->progressFor($user->id, $course->id);
    }

    public function isComplete(int $userId, int $courseId): bool
    {
        return $this->progressFor($userId, $courseId)['is_complete'];
    }

    /**
     * Does the user hold an active enrollment for the course?
     */
    public function hasActiveEnrollment(int $userId, int $courseId): bool
    {
        return Enrollment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Lesson counts per course for the given courses (one query).
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, int>
     */
    public function lessonCountsForCourses(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return Lesson::whereIn('course_id', $courseIds)
            ->selectRaw('course_id, count(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Completed lesson counts per course for one student (one query).
     *
     * @param  array<int, int>  $courseIds
     * @return array<int, int>
     */
    public function completedLessonCountsForCourses(int $userId, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return LessonProgress::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->where('lesson_progress.user_id', $userId)
            ->where('lesson_progress.is_completed', true)
            ->whereIn('lessons.course_id', $courseIds)
            ->groupBy('lessons.course_id')
            ->selectRaw('lessons.course_id as course_id, count(*) as total')
            ->pluck('total', 'course_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Number of active enrollments whose course has been fully completed.
     *
     * "Fully completed" means: the course has at least one lesson and none of
     * the course lessons is left uncompleted for that student. Written as two
     * NOT EXISTS / EXISTS subqueries so it stays a single query and uses the
     * existing indexes instead of scanning progress rows in PHP.
     */
    public function countCompletedEnrollments(): int
    {
        return (int) $this->completedEnrollmentQuery()->count();
    }

    /**
     * Shared query for "completed enrollments" so the dashboard and any future
     * report cannot drift apart.
     */
    public function completedEnrollmentQuery()
    {
        return DB::table('enrollments as e')
            ->where('e.status', 'active')
            // The course must actually have content, otherwise there is nothing
            // that could have been completed.
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('lessons as l')
                    ->whereColumn('l.course_id', 'e.course_id');
            })
            // ... and none of its lessons may be left unfinished by the student.
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('lessons as l3')
                    ->whereColumn('l3.course_id', 'e.course_id')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('lesson_progress as lp')
                            ->whereColumn('lp.lesson_id', 'l3.id')
                            ->whereColumn('lp.user_id', 'e.user_id')
                            ->where('lp.is_completed', true);
                    });
            });
    }

    private function completedLessonCount(int $userId, int $courseId): int
    {
        return (int) LessonProgress::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->where('lesson_progress.user_id', $userId)
            ->where('lesson_progress.is_completed', true)
            ->where('lessons.course_id', $courseId)
            ->count();
    }

    private function percentage(int $completed, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(min($completed / $total, 1) * 100, 2);
    }
}
