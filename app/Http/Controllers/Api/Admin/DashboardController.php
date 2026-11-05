<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use App\Services\CourseCompletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    private const RECENT_LIMIT = 10;

    private const OVERVIEW_RANGES = ['7d', '30d', '3m', '1y'];

    public function __construct(private readonly CourseCompletionService $completion) {}

    /**
     * Platform statistics for admins (auth:sanctum + admin middleware).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'nullable', 'string', Rule::in(self::OVERVIEW_RANGES)],
        ]);

        $range = $validated['range'] ?? '30d';

        $users = $this->userStats();
        $courses = $this->courseStats();
        $categories = $this->categoryStats();
        $lessons = $this->lessonStats();
        $enrollments = $this->enrollmentStats();
        $payments = $this->paymentStats();
        $quizzes = $this->quizStats();
        $questions = $this->questionStats();

        return response()->json([
            'range' => $range,
            // The headline card row. The console reads only this block, so
            // the counters never require downloading whole tables.
            'summary' => $this->summary(
                $users, $courses, $categories, $lessons, $enrollments, $payments, $quizzes, $questions
            ),
            'users' => $users,
            'courses' => $courses,
            'categories' => $categories,
            'lessons' => $lessons,
            'enrollments' => $enrollments,
            'payments' => $payments,
            'quizzes' => $quizzes,
            'questions' => $questions,
            'reviews' => $this->reviewStats(),
            'certificates' => [
                'total_certificates' => Certificate::query()->count(),
            ],
            // Chart data. The overview is bucketed in PHP from timestamps so
            // the endpoint behaves identically on MySQL and SQLite.
            'enrollment_overview' => $this->enrollmentOverview($range),
            'courses_by_category' => $this->coursesByCategory(),
            // A derived activity feed built from the timestamps that already
            // exist on real rows. There is no separate event-log table.
            'recent_activity' => $this->recentActivity(),
            'recent_users' => $this->recentUsers(),
            'recent_courses' => $this->recentCourses(),
            'recent_enrollments' => $this->recentEnrollments(),
            'recent_payments' => $this->recentPayments(),
        ]);
    }

    /**
     * The eight counters shown above the admin console, derived from the same
     * blocks as the detail below them so the two can never disagree.
     *
     * @return array<string, mixed>
     */
    private function summary(
        array $users,
        array $courses,
        array $categories,
        array $lessons,
        array $enrollments,
        array $payments,
        array $quizzes,
        array $questions
    ): array {
        $growth = $this->courseGrowth();

        return [
            'total_users' => $users['total_users'],
            'total_students' => $users['total_students'],
            'total_admins' => $users['total_admins'],
            'total_instructors' => $users['total_instructors'],
            'total_courses' => $courses['total_courses'],
            'published_courses' => $courses['published_courses'],
            'draft_courses' => $courses['draft_courses'],
            'total_categories' => $categories['total_categories'],
            'total_enrollments' => $enrollments['total_enrollments'],
            'active_enrollments' => $enrollments['active_enrollments'],
            'completed_enrollments' => $enrollments['completed_enrollments'],
            'total_payments' => $payments['total_payments'],
            'total_lessons' => $lessons['total_lessons'],
            'total_quizzes' => $quizzes['total_quizzes'],
            'total_questions' => $questions['total_questions'],
            'successful_payments' => $payments['successful_payments'],
            'pending_payments' => $payments['pending_payments'],
            'failed_payments' => $payments['failed_payments'],
            // Payments are reported as a count *and* a value: how many
            // attempts were made, and how much money was actually collected.
            'total_revenue' => $payments['successful_revenue'],
            'currency' => $payments['currency'],
            // Month-over-month course growth. The percentage is only
            // meaningful once the previous month has data; it is reported as
            // null in that case instead of inventing a comparison.
            'courses_this_month' => $growth['current_month'],
            'course_growth' => $growth,
        ];
    }

    /**
     * Month-over-month course creation, used by the Total Courses card.
     *
     * @return array{current_month:int, previous_month:int, percentage:float|null}
     */
    private function courseGrowth(): array
    {
        $currentStart = now()->startOfMonth();
        $previousStart = now()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = now()->subMonthNoOverflow()->endOfMonth();

        $current = Course::query()->where('created_at', '>=', $currentStart)->count();
        $previous = Course::query()
            ->whereBetween('created_at', [$previousStart, $previousEnd])
            ->count();

        return [
            'current_month' => $current,
            'previous_month' => $previous,
            'percentage' => $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : null,
        ];
    }

    /**
     * Enrollment counts grouped into buckets for the selected range.
     *
     * Only the two timestamp columns are selected and the bucketing happens in
     * PHP: this keeps the query portable between MySQL and SQLite without
     * driver-specific date functions.
     *
     * @return array<string, mixed>
     */
    private function enrollmentOverview(string $range): array
    {
        [$start, $bucket] = $this->overviewWindow($range);

        $buckets = $this->overviewBuckets($start, $bucket);
        $positions = [];

        foreach ($buckets as $index => $item) {
            $positions[$item['key']] = $index;
        }

        Enrollment::query()
            ->whereRaw('COALESCE(enrolled_at, created_at) >= ?', [$start->toDateTimeString()])
            ->select(['id', 'enrolled_at', 'created_at'])
            ->chunkById(1000, function (Collection $enrollments) use (&$buckets, $positions, $bucket) {
                foreach ($enrollments as $enrollment) {
                    $timestamp = $enrollment->enrolled_at ?? $enrollment->created_at;

                    if (! $timestamp) {
                        continue;
                    }

                    $key = $this->overviewKey($timestamp, $bucket);

                    if (isset($positions[$key])) {
                        $buckets[$positions[$key]]['count']++;
                    }
                }
            });

        return [
            'range' => $range,
            'bucket' => $bucket,
            'start' => $start->toIso8601String(),
            'total' => array_sum(array_column($buckets, 'count')),
            'data' => array_values($buckets),
        ];
    }

    /**
     * @return array{0:Carbon,1:string}
     */
    private function overviewWindow(string $range): array
    {
        return match ($range) {
            '7d' => [now()->subDays(6)->startOfDay(), 'day'],
            '30d' => [now()->subDays(29)->startOfDay(), 'day'],
            '3m' => [now()->subWeeks(12)->startOfWeek(), 'week'],
            default => [now()->subMonths(11)->startOfMonth(), 'month'],
        };
    }

    /**
     * @return array<int, array{key:string, label:string, count:int}>
     */
    private function overviewBuckets(Carbon $start, string $bucket): array
    {
        $cursor = $start->copy();
        $end = now();
        $buckets = [];

        while ($cursor->lte($end)) {
            $key = match ($bucket) {
                'week' => $cursor->copy()->startOfWeek()->toDateString(),
                'month' => $cursor->format('Y-m'),
                default => $cursor->toDateString(),
            };

            $buckets[] = [
                'key' => $key,
                'label' => $bucket === 'month'
                    ? $cursor->format('M')
                    : $cursor->format('M j'),
                'count' => 0,
            ];

            $cursor = match ($bucket) {
                'week' => $cursor->copy()->addWeek(),
                'month' => $cursor->copy()->addMonthNoOverflow(),
                default => $cursor->copy()->addDay(),
            };

            // Safety valve so a clock change can never create an endless loop.
            if (count($buckets) > 400) {
                break;
            }
        }

        return $buckets;
    }

    private function overviewKey(Carbon $timestamp, string $bucket): string
    {
        return match ($bucket) {
            'week' => $timestamp->copy()->startOfWeek()->toDateString(),
            'month' => $timestamp->format('Y-m'),
            default => $timestamp->toDateString(),
        };
    }

    /**
     * Course distribution by category, including courses with no category.
     *
     * @return array<string, mixed>
     */
    private function coursesByCategory(): array
    {
        $categories = Category::query()
            ->withCount('courses')
            ->orderByDesc('courses_count')
            ->orderBy('name')
            ->get();

        $uncategorized = Course::query()->whereNull('category_id')->count();
        $total = (int) $categories->sum('courses_count') + $uncategorized;

        $items = $categories->map(fn (Category $category) => [
            'id' => $category->id,
            'name' => $category->name,
            'count' => (int) $category->courses_count,
            'percentage' => $total > 0
                ? round(((int) $category->courses_count / $total) * 100, 1)
                : 0.0,
        ]);

        if ($uncategorized > 0) {
            $items->push([
                'id' => null,
                'name' => 'Uncategorized',
                'count' => $uncategorized,
                'percentage' => $total > 0 ? round(($uncategorized / $total) * 100, 1) : 0.0,
            ]);
        }

        return [
            'total_courses' => $total,
            'data' => $items->values(),
        ];
    }

    /**
     * A real-data activity feed assembled from the timestamps already stored
     * on enrollments, payments, users and courses. There is intentionally no
     * fake/seed activity: if none of those rows exist the feed is empty.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentActivity()
    {
        $events = collect();
        $currency = strtoupper((string) (config('services.payway.currency', 'USD') ?: 'USD'));

        Enrollment::query()
            ->with([
                'user' => fn ($query) => $query->select('id', 'name'),
                'course:id,title',
            ])
            ->latest('enrolled_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(function (Enrollment $enrollment) use ($events) {
                $timestamp = $enrollment->enrolled_at ?? $enrollment->created_at;

                if ($timestamp) {
                    $events->push([
                        'id' => 'enrollment-'.$enrollment->id,
                        'type' => 'enrollment',
                        'title' => 'New enrollment',
                        'description' => sprintf(
                            '%s enrolled in %s',
                            $enrollment->user?->name ?? 'A student',
                            $enrollment->course?->title ?? 'a course'
                        ),
                        'timestamp' => $timestamp->toIso8601String(),
                    ]);
                }
            });

        Payment::query()
            ->where('status', Payment::STATUS_PAID)
            ->with([
                'user' => fn ($query) => $query->select('id', 'name'),
                'course:id,title',
            ])
            ->latest('paid_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(function (Payment $payment) use ($events, $currency) {
                $timestamp = $payment->paid_at ?? $payment->created_at;

                if ($timestamp) {
                    $events->push([
                        'id' => 'payment-'.$payment->id,
                        'type' => 'payment',
                        'title' => 'Payment received',
                        'description' => sprintf(
                            '%s paid %s%s for %s',
                            $payment->user?->name ?? 'A student',
                            $currency === 'KHR' ? '៛' : '$',
                            number_format((float) $payment->amount, $currency === 'KHR' ? 0 : 2),
                            $payment->course?->title ?? 'a course'
                        ),
                        'timestamp' => $timestamp->toIso8601String(),
                    ]);
                }
            });

        User::query()
            ->select(['id', 'name', 'role', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(function (User $user) use ($events) {
                if ($user->created_at) {
                    $events->push([
                        'id' => 'user-'.$user->id,
                        'type' => 'user',
                        'title' => 'New user registered',
                        'description' => sprintf(
                            '%s joined as %s',
                            $user->name,
                            $user->role ?: 'student'
                        ),
                        'timestamp' => $user->created_at->toIso8601String(),
                    ]);
                }
            });

        Course::query()
            ->with(['instructor:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(function (Course $course) use ($events) {
                if ($course->created_at) {
                    $events->push([
                        'id' => 'course-created-'.$course->id,
                        'type' => 'course_created',
                        'title' => 'Course created',
                        'description' => sprintf(
                            '%s created %s',
                            $course->instructor?->name ?? 'An instructor',
                            $course->title
                        ),
                        'timestamp' => $course->created_at->toIso8601String(),
                    ]);
                }
            });

        Course::query()
            ->whereColumn('updated_at', '>', 'created_at')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(function (Course $course) use ($events) {
                if (! $course->updated_at) {
                    return;
                }

                // A row touched within a minute of creation is the create
                // event itself, not a separate edit.
                if ($course->created_at && $course->updated_at->diffInSeconds($course->created_at) < 60) {
                    return;
                }

                $events->push([
                    'id' => 'course-updated-'.$course->id,
                    'type' => 'course_updated',
                    'title' => 'Course updated',
                    'description' => sprintf('%s was updated', $course->title),
                    'timestamp' => $course->updated_at->toIso8601String(),
                ]);
            });

        return $events
            ->sortByDesc(fn (array $event) => $event['timestamp'])
            ->take(self::RECENT_LIMIT)
            ->values();
    }

    /**
     * Newest accounts, safe columns only.
     *
     * The columns are listed explicitly instead of relying on the model's
     * $hidden array, so the password hash and remember_token can never leak
     * into the dashboard even if that array is edited later.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentUsers()
    {
        return User::query()
            ->select(['id', 'name', 'email', 'role', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->values();
    }

    /**
     * Newest courses, drafts included, with their instructor and category.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentCourses()
    {
        return Course::query()
            ->with(['instructor:id,name', 'category:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'thumbnail' => $course->thumbnail,
                'price' => (float) $course->price,
                'is_published' => $course->is_published,
                'instructor' => $course->instructor ? [
                    'id' => $course->instructor->id,
                    'name' => $course->instructor->name,
                ] : null,
                'category' => $course->category ? [
                    'id' => $course->category->id,
                    'name' => $course->category->name,
                ] : null,
                'created_at' => $course->created_at?->toIso8601String(),
                'updated_at' => $course->updated_at?->toIso8601String(),
            ])
            ->values();
    }

    /**
     * @return array<string, int>
     */
    private function userStats(): array
    {
        $byRole = User::query()
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->map(fn ($total) => (int) $total)
            ->all();

        // A course always has an author, so distinct course authors are the
        // only accounts that can manage content.
        $totalInstructors = Course::query()->distinct()->count('user_id');

        return [
            'total_users' => array_sum($byRole),
            'total_students' => $byRole['student'] ?? 0,
            'total_admins' => $byRole['admin'] ?? 0,
            'total_instructors' => $totalInstructors,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function courseStats(): array
    {
        return [
            'total_courses' => Course::query()->count(),
            'published_courses' => Course::query()->where('is_published', true)->count(),
            'draft_courses' => Course::query()->where('is_published', false)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function categoryStats(): array
    {
        return [
            'total_categories' => Category::query()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function lessonStats(): array
    {
        return [
            'total_lessons' => Lesson::query()->count(),
            // Lessons that no student can reach yet, because their course is
            // still a draft. Useful when deciding what to publish first.
            'draft_lessons' => Lesson::query()
                ->whereIn('course_id', Course::query()->select('id')->where('is_published', false))
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function enrollmentStats(): array
    {
        return [
            'total_enrollments' => Enrollment::query()->count(),
            'active_enrollments' => Enrollment::query()->where('status', 'active')->count(),
            'completed_enrollments' => $this->completion->countCompletedEnrollments(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function quizStats(): array
    {
        return [
            'total_quizzes' => Quiz::query()->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function questionStats(): array
    {
        return [
            'total_questions' => Question::query()->count(),
        ];
    }

    /**
     * Revenue only counts payments PayWay confirmed (`status = paid`).
     * pending / expired / failed rows are never added to the total.
     *
     * @return array<string, mixed>
     */
    private function paymentStats(): array
    {
        $byStatus = Payment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $successfulRevenue = (float) Payment::query()
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'total_payments' => array_sum($byStatus),
            'successful_payments' => $byStatus['paid'] ?? 0,
            'pending_payments' => $byStatus['pending'] ?? 0,
            'failed_payments' => $byStatus['failed'] ?? 0,
            'expired_payments' => $byStatus['expired'] ?? 0,
            'successful_revenue' => round($successfulRevenue, 2),
            'currency' => strtoupper((string) (config('services.payway.currency', 'USD') ?: 'USD')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewStats(): array
    {
        return CourseReview::ratingSummary();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentEnrollments()
    {
        return Enrollment::query()
            ->with([
                'user' => fn ($query) => $query->select('id', 'name', 'email'),
                'course:id,title',
            ])
            ->latest('enrolled_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'student' => $enrollment->user ? [
                    'id' => $enrollment->user->id,
                    'name' => $enrollment->user->name,
                    'email' => $enrollment->user->email,
                ] : null,
                'course' => $enrollment->course ? [
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->title,
                ] : null,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recentPayments()
    {
        return Payment::query()
            ->with([
                'user' => fn ($query) => $query->select('id', 'name'),
                'course:id,title',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'currency' => strtoupper((string) (config('services.payway.currency', 'USD') ?: 'USD')),
                'status' => $payment->status,
                'method' => $payment->method,
                'transaction_id' => $payment->transaction_id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
                'student' => $payment->user ? [
                    'id' => $payment->user->id,
                    'name' => $payment->user->name,
                ] : null,
                'course' => $payment->course ? [
                    'id' => $payment->course->id,
                    'title' => $payment->course->title,
                ] : null,
            ])
            ->values();
    }
}
