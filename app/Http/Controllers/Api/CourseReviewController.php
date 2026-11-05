<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class CourseReviewController extends Controller
{
    private const PER_PAGE = 10;

    private const MAX_PER_PAGE = 50;

    /**
     * Public list of the reviews of a published course.
     */
    public function index(Request $request, Course $course)
    {
        $this->ensureCourseIsVisible($course, $request->user());

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $reviews = CourseReview::query()
            ->forCourse($course->id)
            ->with(['user' => fn ($query) => $query->select('id', 'name')])
            ->when(
                $validated['rating'] ?? null,
                fn ($query, $rating) => $query->where('rating', $rating)
            )
            ->latest()
            ->latest('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CourseReview $review) => $this->serializeReview($review));

        return response()->json($reviews);
    }

    /**
     * Admin listing of every review, newest first.
     *
     * Mounted under the existing `admin` middleware. The public endpoint stays
     * scoped to one course at a time; this one is for moderation.
     */
    public function adminIndex(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $reviews = CourseReview::query()
            ->with([
                'user' => fn ($query) => $query->select('id', 'name'),
                'course:id,title,slug',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function ($query) use ($like) {
                    $query->where('review', 'like', $like)
                        ->orWhereHas('user', function ($user) use ($like) {
                            $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('course', fn ($course) => $course->where('title', 'like', $like));
                });
            })
            ->when($validated['rating'] ?? null, fn ($query, $rating) => $query->where('rating', $rating))
            ->latest()
            ->latest('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CourseReview $review) => $this->serializeAdminReview($review));

        return response()->json($reviews);
    }

    /**
     * Average rating, total reviews and the 1..5 distribution of a course.
     */
    public function rating(Request $request, Course $course)
    {
        $this->ensureCourseIsVisible($course, $request->user());

        return response()->json($this->summary($course));
    }

    /**
     * Store a review. Only students with an active enrollment may review.
     */
    public function store(Request $request, Course $course)
    {
        $this->ensureCourseIsVisible($course, $request->user());

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if (! $this->canReview($user, $course)) {
            abort(403, 'Only enrolled students can review this course.');
        }

        if (CourseReview::where('user_id', $user->id)->where('course_id', $course->id)->exists()) {
            return response()->json([
                'message' => 'You have already reviewed this course.',
            ], 409);
        }

        try {
            $review = CourseReview::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'rating' => $validated['rating'],
                'review' => $validated['review'] ?? null,
            ]);
        } catch (QueryException) {
            // Concurrent duplicate insert: the unique index rejected the second row.
            return response()->json([
                'message' => 'You have already reviewed this course.',
            ], 409);
        }

        return response()->json([
            'message' => 'Review submitted.',
            'data' => $this->serializeReview($review->load(['user' => fn ($query) => $query->select('id', 'name')])),
        ], 201);
    }

    /**
     * Update an existing review (author or admin only).
     */
    public function update(Request $request, Course $course, CourseReview $review)
    {
        $this->abortWhenCourseMismatch($course, $review);
        $this->authorize('update', $review);

        $validated = $request->validate([
            'rating' => ['sometimes', 'required', 'integer', 'min:1', 'max:5'],
            'review' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $review->update($validated);

        return response()->json([
            'message' => 'Review updated.',
            'data' => $this->serializeReview($review->fresh()->load(['user' => fn ($query) => $query->select('id', 'name')])),
        ]);
    }

    /**
     * Delete a review (author or admin only).
     */
    public function destroy(Request $request, Course $course, CourseReview $review)
    {
        $this->abortWhenCourseMismatch($course, $review);
        $this->authorize('delete', $review);

        $review->delete();

        return response()->json([
            'message' => 'Review deleted.',
        ]);
    }

    /**
     * Rating summary computed from real rows.
     *
     * @return array<string, mixed>
     */
    public function summary(Course $course): array
    {
        return [
            'course_id' => $course->id,
            ...CourseReview::ratingSummary($course->id),
        ];
    }

    /**
     * Draft courses are only visible to their author and to admins.
     */
    private function ensureCourseIsVisible(Course $course, ?User $user): void
    {
        if ($course->is_published) {
            return;
        }

        if ($user && ($user->role === 'admin' || $course->user_id === $user->id)) {
            return;
        }

        abort(404);
    }

    /**
     * Eligibility follows the existing enrollment rules: an active enrollment,
     * with the same admin bypass the rest of the project uses.
     */
    private function canReview(User $user, Course $course): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * `{review}` is bound globally, so make sure it belongs to this course.
     */
    private function abortWhenCourseMismatch(Course $course, CourseReview $review): void
    {
        if ($review->course_id !== $course->id) {
            abort(404);
        }
    }

    /**
     * Only public-safe columns are exposed: never the reviewer's email or id
     * fields beyond the public identifier.
     *
     * @return array<string, mixed>
     */
    private function serializeReview(CourseReview $review): array
    {
        return [
            'id' => $review->id,
            'course_id' => $review->course_id,
            'rating' => $review->rating,
            'review' => $review->review,
            'user' => $review->user ? [
                'id' => $review->user->id,
                'name' => $review->user->name,
            ] : null,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Admin rows also carry the course so the moderation table can show which
     * course each review belongs to.
     *
     * @return array<string, mixed>
     */
    private function serializeAdminReview(CourseReview $review): array
    {
        return [
            ...$this->serializeReview($review),
            'course' => $review->course ? [
                'id' => $review->course->id,
                'title' => $review->course->title,
                'slug' => $review->course->slug,
            ] : null,
        ];
    }
}
