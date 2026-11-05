<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Wishlist;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-course numbers for the admin console.
 *
 * Everything is aggregated in SQL: the endpoint is called on every analytics
 * screen open, so it must not fan out into one query per student or per lesson.
 *
 * "Completed" always means what CourseCompletionService says it means — an
 * active enrollment whose every lesson is marked completed. The completion rule
 * lives in that service so a number shown here can never disagree with the
 * student dashboard or with certificate eligibility.
 */
class CourseAnalyticsService
{
    private const TREND_DAYS = 30;

    public function __construct(private readonly CourseCompletionService $completion) {}

    /**
     * @return array<string, mixed>
     */
    public function forCourse(Course $course): array
    {
        return [
            'course' => $this->courseSummary($course),
            'enrollments' => $this->enrollmentStats($course),
            'progress' => $this->progressStats($course),
            'reviews' => $this->reviewStats($course),
            'revenue' => $this->revenueStats($course),
            'demand' => [
                'wishlists' => Wishlist::where('course_id', $course->id)->count(),
                'certificates' => Certificate::where('course_id', $course->id)->count(),
            ],
            'trend' => $this->trend($course),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseSummary(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug,
            'price' => (float) $course->price,
            'is_published' => $course->is_published,
            'category' => $course->category?->name,
            'instructor' => $course->instructor?->name,
            'created_at' => $course->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentStats(Course $course): array
    {
        $total = Enrollment::where('course_id', $course->id)->count();
        $active = Enrollment::where('course_id', $course->id)->where('status', 'active')->count();
        $completed = $this->completion->completedEnrollmentQuery()
            ->where('e.course_id', $course->id)
            ->count();

        $lastEnrolledAt = Enrollment::where('course_id', $course->id)->max('enrolled_at');

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'in_progress' => max(0, $active - $completed),
            'completion_rate' => $active > 0 ? round($completed / $active * 100, 2) : 0.0,
            'last_enrolled_at' => $lastEnrolledAt
                ? Carbon::parse($lastEnrolledAt)->toIso8601String()
                : null,
            'last_30_days' => Enrollment::where('course_id', $course->id)
                ->where('enrolled_at', '>=', now()->subDays(self::TREND_DAYS))
                ->count(),
        ];
    }

    /**
     * How far the enrolled students actually got, and where they stall.
     *
     * @return array<string, mixed>
     */
    private function progressStats(Course $course): array
    {
        $totalLessons = (int) Lesson::where('course_id', $course->id)->count();

        // Completed lessons per enrolled student in a single grouped query.
        $completedPerStudent = DB::table('enrollments as e')
            ->join('lessons as l', 'l.course_id', '=', 'e.course_id')
            ->leftJoin('lesson_progress as lp', function ($join) {
                $join->on('lp.lesson_id', '=', 'l.id')
                    ->on('lp.user_id', '=', 'e.user_id')
                    ->where('lp.is_completed', true);
            })
            ->where('e.course_id', $course->id)
            ->where('e.status', 'active')
            ->groupBy('e.user_id')
            ->select(DB::raw('count(lp.id) as completed'))
            ->pluck('completed')
            ->map(fn ($completed) => (int) $completed);

        $students = $completedPerStudent->count();
        $percentages = $totalLessons > 0
            ? $completedPerStudent->map(fn ($completed) => min($completed / $totalLessons, 1) * 100)
            : collect();

        return [
            'total_lessons' => $totalLessons,
            'tracked_students' => $students,
            'average_completion_percent' => $percentages->isEmpty()
                ? 0.0
                : round($percentages->avg(), 2),
            'fully_completed_students' => $totalLessons > 0
                ? $completedPerStudent->filter(fn ($completed) => $completed >= $totalLessons)->count()
                : 0,
            // The lesson the most enrolled students have not finished yet: the
            // place to look first when a course is not being completed.
            'biggest_drop_off' => $this->biggestDropOff($course),
        ];
    }

    /**
     * The lesson where the most enrolled students stop progressing.
     *
     * Counting raw incomplete rows would be useless: every student who has not
     * finished the course is incomplete on the final lesson, so that lesson would
     * always win. Instead each student contributes only their *first* unfinished
     * lesson, which is the step that is actually blocking them.
     *
     * @return array<string, mixed>|null
     */
    private function biggestDropOff(Course $course): ?array
    {
        // `order` is a reserved word, and MySQL only tolerates it unquoted after
        // a dot while SQLite rejects it outright, so it is wrapped by the
        // grammar instead of being written by hand.
        $orderColumn = DB::connection()->getQueryGrammar()->wrap('l.order');

        $firstUnfinishedOrder = DB::table('enrollments as e')
            ->join('lessons as l', 'l.course_id', '=', 'e.course_id')
            ->leftJoin('lesson_progress as lp', function ($join) {
                $join->on('lp.lesson_id', '=', 'l.id')
                    ->on('lp.user_id', '=', 'e.user_id')
                    ->where('lp.is_completed', true);
            })
            ->where('e.course_id', $course->id)
            ->where('e.status', 'active')
            ->whereNull('lp.id')
            ->groupBy('e.user_id')
            ->select('e.user_id')
            ->selectRaw("MIN({$orderColumn}) as first_order");

        $row = DB::table('lessons as l')
            ->joinSub($firstUnfinishedOrder, 'stop', function ($join) {
                $join->on('l.order', '=', 'stop.first_order');
            })
            ->where('l.course_id', $course->id)
            ->groupBy('l.id', 'l.title', 'l.order')
            ->select('l.id', 'l.title', 'l.order', DB::raw('count(*) as students'))
            ->orderByDesc('students')
            // A tie means two lessons block the same number of students, so the
            // earlier one wins: it holds back more of the course.
            ->orderBy('l.order')
            ->orderBy('l.id')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'lesson_id' => $row->id,
            'title' => $row->title,
            'order' => (int) $row->order,
            'students_incomplete' => (int) $row->students,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewStats(Course $course): array
    {
        return CourseReview::ratingSummary($course->id);
    }

    /**
     * Money. Only PayWay-confirmed rows (`status = paid`) count as revenue.
     *
     * @return array<string, mixed>
     */
    private function revenueStats(Course $course): array
    {
        $byStatus = Payment::where('course_id', $course->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $gross = (float) Payment::where('course_id', $course->id)
            ->where('status', 'paid')
            ->sum('amount');

        $successful = $byStatus['paid'] ?? 0;

        return [
            'currency' => $this->currency(),
            'gross_revenue' => round($gross, 2),
            'successful_payments' => $successful,
            'pending_payments' => $byStatus['pending'] ?? 0,
            'failed_payments' => $byStatus['failed'] ?? 0,
            'expired_payments' => $byStatus['expired'] ?? 0,
            'average_order_value' => $successful > 0 ? round($gross / $successful, 2) : 0.0,
            'failed_rate' => array_sum($byStatus) > 0
                ? round(($byStatus['failed'] ?? 0) / array_sum($byStatus) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Daily enrollments and revenue over the last 30 days.
     *
     * Days without activity are returned as zero so the chart has no gaps.
     *
     * @return array<string, mixed>
     */
    private function trend(Course $course): array
    {
        $from = now()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $enrollments = $this->dailyCounts(
            Enrollment::where('course_id', $course->id)->where('enrolled_at', '>=', $from),
            'enrolled_at'
        );

        $revenue = $this->dailySums(
            Payment::where('course_id', $course->id)
                ->where('status', 'paid')
                ->where('paid_at', '>=', $from),
            'paid_at'
        );

        $points = [];

        for ($day = 0; $day < self::TREND_DAYS; $day++) {
            $date = $from->copy()->addDays($day)->toDateString();

            $points[] = [
                'date' => $date,
                'enrollments' => $enrollments[$date] ?? 0,
                'revenue' => $revenue[$date] ?? 0.0,
            ];
        }

        return [
            'days' => self::TREND_DAYS,
            'from' => $from->toDateString(),
            'to' => $from->copy()->addDays(self::TREND_DAYS - 1)->toDateString(),
            'points' => $points,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dailyCounts($query, string $column): array
    {
        return $this->dailyAggregate($query, $column, 'count(*) as value')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function dailySums($query, string $column): array
    {
        return $this->dailyAggregate($query, $column, 'sum(amount) as value')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * @param  Builder  $query
     * @return Collection<string, float|int>
     */
    private function dailyAggregate($query, string $column, string $aggregate): Collection
    {
        // date() truncates to a calendar day on both MySQL and SQLite, so the
        // daily buckets do not depend on which database the console runs on.
        return $query
            ->selectRaw("date({$column}) as day, {$aggregate}")
            ->groupBy('day')
            ->pluck('value', 'day');
    }

    private function currency(): string
    {
        return strtoupper((string) (config('services.payway.currency', 'USD') ?: 'USD'));
    }
}
