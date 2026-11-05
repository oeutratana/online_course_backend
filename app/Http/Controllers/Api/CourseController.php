<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseController extends Controller
{
    private const DEFAULT_PER_PAGE = 12;

    private const MAX_PER_PAGE = 60;

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);

        $query = Course::query()
            ->published()
            ->with(['instructor', 'category'])
            ->withCount(['reviews'])
            ->withAvg('reviews', 'rating')
            ->search($filters['search'])
            ->forCategory($filters['category_id'])
            ->forInstructor($filters['instructor_id'])
            ->priceBetween($filters['min_price'], $filters['max_price'])
            ->ratingAtLeast($filters['rating']);

        if ($filters['price_type'] === 'free') {
            $query->where('price', 0);
        }

        if ($filters['price_type'] === 'paid') {
            $query->where('price', '>', 0);
        }

        $this->applySorting($query, $filters);

        $user = $request->user() ?: auth('sanctum')->user();

        $courses = $query
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (Course $course) => $this->serializeCourse($course, $user));

        return response()->json($courses);
    }

    public function myIndex(Request $request)
    {
        $user = $request->user();
        $filters = $this->validatedFilters($request);

        // Only return courses where the user has an active enrollment (paid)
        $enrolledCourseIds = Enrollment::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('course_id');

        $query = Course::query()
            ->with(['instructor', 'category'])
            ->withCount(['reviews'])
            ->withAvg('reviews', 'rating')
            ->whereIn('id', $enrolledCourseIds)
            ->search($filters['search'])
            ->forCategory($filters['category_id'])
            ->priceBetween($filters['min_price'], $filters['max_price'])
            ->ratingAtLeast($filters['rating']);

        if ($filters['price_type'] === 'free') {
            $query->where('price', 0);
        }

        if ($filters['price_type'] === 'paid') {
            $query->where('price', '>', 0);
        }

        $this->applySorting($query, $filters);

        $courses = $query
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (Course $course) => $this->serializeCourse($course, $user));

        return response()->json($courses);
    }

    public function show(Request $request, Course $course)
    {
        $user = $request->user() ?: auth('sanctum')->user();

        // Draft courses are private: only an admin may preview them before
        // they are published.
        if (! $course->is_published && ! $this->canViewDraft($user, $course)) {
            abort(404);
        }

        $manager = $this->canManageCourse($user, $course);

        $course->load([
            'instructor',
            'category',
            'lessons.quizzes.questions',
        ]);

        if ($manager) {
            $course->load(['enrollments.user', 'payments.user']);
        }

        $course->loadCount(['reviews', 'certificates']);
        $course->loadAvg('reviews', 'rating');

        if (! $manager) {
            $this->hideQuizAnswerKeys($course);
        }

        $courseData = $this->serializeCourse($course, $user);

        if (! $courseData['has_access']) {
            $courseData['lessons'] = collect($courseData['lessons'] ?? [])
                ->map(function (array $lesson) {
                    if (! ($lesson['is_preview'] ?? false)) {
                        $lesson['video_url'] = null;
                    }

                    return $lesson;
                })
                ->values()
                ->all();
        }

        return response()->json($courseData);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedCourseData($request);

        $course = Course::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'slug' => $this->uniqueSlug($validated['title']),
            'is_published' => $request->boolean('is_published'),
        ]);

        return response()->json($course->load(['instructor', 'category']), 201);
    }

    public function update(Request $request, Course $course)
    {
        $this->authorizeCourseChange($request, $course);

        $validated = $this->validatedCourseData($request);

        $attributes = [
            ...$validated,
            'slug' => $this->uniqueSlug($validated['title'], $course),
            'is_published' => $request->boolean('is_published'),
        ];

        $course->update($attributes);

        return response()->json($course->fresh()->load(['instructor', 'category']));
    }

    public function destroy(Request $request, Course $course)
    {
        $this->authorizeCourseChange($request, $course);

        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }

    /**
     * Validate and normalise every supported query parameter.
     *
     * @return array{search:?string, category_id:?int, instructor_id:?int, min_price:?float,
     *               max_price:?float, rating:?float, sort_by:string, sort_order:string,
     *               per_page:int, price_type:?string}
     */
    private function validatedFilters(Request $request): array
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
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in(Course::SORTABLE_COLUMNS)],
            'sort_order' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            // Legacy sort aliases: newest, price_low, price_high.
            'sort' => ['sometimes', 'nullable', Rule::in(['newest', 'price_low', 'price_high'])],
            'price' => ['sometimes', 'nullable', 'string', Rule::in(['free', 'paid'])],
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

        [$sortBy, $sortOrder] = $this->resolveSorting($validated);

        return [
            'search' => isset($validated['search']) ? trim($validated['search']) : null,
            'category_id' => $validated['category_id'] ?? null,
            'instructor_id' => $validated['instructor_id'] ?? $validated['user_id'] ?? null,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'rating' => isset($validated['rating']) ? (float) $validated['rating'] : null,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder,
            'per_page' => $validated['per_page'] ?? self::DEFAULT_PER_PAGE,
            'price_type' => $validated['price'] ?? null,
        ];
    }

    /**
     * Translate sort_by/sort_order (and the legacy sort aliases) into a column.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSorting(array $validated): array
    {
        if (! empty($validated['sort_by'])) {
            $sortBy = $validated['sort_by'];
            $defaultOrder = $sortBy === 'title' ? 'asc' : 'desc';

            return [$sortBy, $validated['sort_order'] ?? $defaultOrder];
        }

        return match ($validated['sort'] ?? null) {
            'price_low' => ['price', 'asc'],
            'price_high' => ['price', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

    /**
     * Apply the validated sort. Whitelisting and the `rating` special case live
     * on the model so this listing and the admin listing cannot drift apart.
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $query->sortedBy($filters['sort_by'], $filters['sort_order']);
    }

    private function validatedCourseData(Request $request): array
    {
        $rules = [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'video_url' => ['nullable', 'string', 'max:255'],
            'video_file' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo', 'max:102400'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_published' => ['sometimes', 'boolean'],
        ];

        $validated = $request->validate($rules);

        unset($validated['image_file']);
        unset($validated['video_file']);

        if ($request->hasFile('image_file')) {
            $path = $request->file('image_file')->store('course-images', 'public');
            $validated['thumbnail'] = Storage::url($path);
        }

        if ($request->hasFile('video_file')) {
            $path = $request->file('video_file')->store('course-videos', 'public');
            $validated['video_url'] = Storage::url($path);
        }

        return $validated;
    }

    private function authorizeCourseChange(Request $request, Course $course): void
    {
        if ($this->canManageCourse($request->user(), $course)) {
            return;
        }

        abort(403, 'Only admins can change courses.');
    }

    /**
     * Only admins manage course content.
     */
    private function canManageCourse(?User $user, Course $course): bool
    {
        return $user?->canManageCourse($course) ?? false;
    }

    private function canViewDraft(?User $user, Course $course): bool
    {
        return $user?->isAdmin() ?? false;
    }

    /**
     * Public course payloads embed lessons -> quizzes -> questions, so the
     * answer key has to be removed from every nested question.
     */
    private function hideQuizAnswerKeys(Course $course): void
    {
        foreach ($course->lessons as $lesson) {
            foreach ($lesson->quizzes as $quiz) {
                foreach ($quiz->questions as $question) {
                    $question->makeHidden('correct_option');
                }
            }
        }
    }

    private function uniqueSlug(string $title, ?Course $course = null): string
    {
        $baseSlug = Str::slug($title) ?: 'course';
        $slug = $baseSlug;
        $counter = 2;

        while (Course::where('slug', $slug)
            ->when($course, fn ($query) => $query->whereKeyNot($course->id))
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function serializeCourse(Course $course, ?User $user): array
    {
        $data = $course->toArray();
        $hasAccess = $this->hasCourseAccess($course, $user);
        $isEnrolled = $this->isEnrolled($course, $user);

        $data['has_access'] = $hasAccess;
        $data['is_enrolled'] = $isEnrolled;
        $data['requires_payment'] = (float) $course->price > 0 && ! $hasAccess;

        if (! $hasAccess) {
            $data['video_url'] = null;
        }

        return $data;
    }

    private function hasCourseAccess(Course $course, ?User $user): bool
    {
        if ((float) $course->price <= 0) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin' || $course->user_id === $user->id) {
            return true;
        }

        return $this->isEnrolled($course, $user);
    }

    private function isEnrolled(Course $course, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($course->relationLoaded('enrollments')) {
            return $course->enrollments->contains(fn ($enrollment) => (
                $enrollment->user_id === $user->id && $enrollment->status === 'active'
            ));
        }

        return $course->enrollments()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
