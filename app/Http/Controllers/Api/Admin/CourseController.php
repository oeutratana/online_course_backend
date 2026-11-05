<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\CourseAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * Every course, drafts included, for the management console.
     *
     * The public listing only ever exposes published courses, so an admin needs
     * this endpoint to reach the drafts they still have to finish. It reuses the
     * Course scopes, which keeps the filter and sort rules identical to the
     * public listing.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'instructor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            // Legacy alias kept for existing clients.
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:5'],
            'price' => ['sometimes', 'nullable', Rule::in(['free', 'paid'])],
            'is_published' => ['sometimes', 'boolean'],
            'sort_by' => ['sometimes', 'nullable', Rule::in(Course::SORTABLE_COLUMNS)],
            'sort_order' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $minPrice = isset($validated['min_price']) ? (float) $validated['min_price'] : null;
        $maxPrice = isset($validated['max_price']) ? (float) $validated['max_price'] : null;

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw ValidationException::withMessages([
                'max_price' => ['The max_price must be greater than or equal to the min_price.'],
            ]);
        }

        $query = Course::query()
            ->with(['instructor', 'category'])
            ->withCount(['reviews', 'enrollments', 'lessons', 'wishlists', 'certificates'])
            ->withAvg('reviews', 'rating')
            ->search(isset($validated['search']) ? trim($validated['search']) : null)
            ->forCategory($validated['category_id'] ?? null)
            ->forInstructor($validated['instructor_id'] ?? $validated['user_id'] ?? null)
            ->priceBetween($minPrice, $maxPrice)
            ->ratingAtLeast(isset($validated['rating']) ? (float) $validated['rating'] : null);

        if (isset($validated['is_published'])) {
            $query->where('is_published', (bool) $validated['is_published']);
        }

        if (($validated['price'] ?? null) === 'free') {
            $query->where('price', 0);
        }

        if (($validated['price'] ?? null) === 'paid') {
            $query->where('price', '>', 0);
        }

        $query->sortedBy(
            $validated['sort_by'] ?? 'created_at',
            $validated['sort_order'] ?? 'desc'
        );

        $courses = $query
            ->paginate($validated['per_page'] ?? self::DEFAULT_PER_PAGE)
            ->withQueryString()
            ->through(fn (Course $course) => $this->serializeCourse($course));

        return response()->json($courses);
    }

    /**
     * Per-course numbers for the analytics screen: enrollment funnel, how far
     * students actually got, ratings, revenue and a 30 day trend.
     *
     * Draft courses are included on purpose — a draft with early testers is
     * exactly the course whose completion rate you want to check before
     * publishing it.
     */
    public function analytics(Course $course, CourseAnalyticsService $analytics)
    {
        $course->loadMissing(['category', 'instructor']);

        return response()->json($analytics->forCourse($course));
    }

    /**
     * Management view of a course: publication state plus the counters the
     * console shows next to each row.
     *
     * @return array<string, mixed>
     */
    private function serializeCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'user_id' => $course->user_id,
            'category_id' => $course->category_id,
            'title' => $course->title,
            'slug' => $course->slug,
            'description' => $course->description,
            'thumbnail' => $course->thumbnail,
            'video_url' => $course->video_url,
            'price' => (float) $course->price,
            'is_published' => $course->is_published,
            'category' => $course->category ? [
                'id' => $course->category->id,
                'name' => $course->category->name,
                'slug' => $course->category->slug,
            ] : null,
            'instructor' => $course->instructor ? [
                'id' => $course->instructor->id,
                'name' => $course->instructor->name,
            ] : null,
            'reviews_count' => (int) $course->reviews_count,
            'reviews_avg_rating' => $course->reviews_avg_rating !== null
                ? round((float) $course->reviews_avg_rating, 2)
                : null,
            'enrollments_count' => (int) $course->enrollments_count,
            'lessons_count' => (int) $course->lessons_count,
            'wishlists_count' => (int) $course->wishlists_count,
            'certificates_count' => (int) $course->certificates_count,
            'created_at' => $course->created_at?->toIso8601String(),
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];
    }
}
