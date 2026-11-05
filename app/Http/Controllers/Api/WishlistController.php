<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Wishlist;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    private const PER_PAGE = 15;

    private const MAX_PER_PAGE = 60;

    /**
     * The signed-in student's wishlist, newest first.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $wishlists = Wishlist::query()
            ->where('user_id', $request->user()->id)
            ->with(['course.instructor', 'course.category'])
            ->latest()
            ->latest('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Wishlist $wishlist) => $this->serializeWishlist($wishlist));

        return response()->json($wishlists);
    }

    /**
     * Add a course to the wishlist.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ]);

        $course = Course::findOrFail($validated['course_id']);

        if (! $course->is_published) {
            return response()->json([
                'message' => 'This course is not available.',
            ], 422);
        }

        $existing = Wishlist::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This course is already in your wishlist.',
                'data' => $this->serializeWishlist($existing->load(['course.instructor', 'course.category'])),
            ], 409);
        }

        try {
            $wishlist = Wishlist::create([
                // Always the authenticated user: a client supplied user_id is ignored.
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
            ]);
        } catch (QueryException) {
            // Two parallel requests: the unique index kept a single row.
            $wishlist = Wishlist::where('user_id', $request->user()->id)
                ->where('course_id', $course->id)
                ->firstOrFail();

            return response()->json([
                'message' => 'This course is already in your wishlist.',
                'data' => $this->serializeWishlist($wishlist->load(['course.instructor', 'course.category'])),
            ], 409);
        }

        return response()->json([
            'message' => 'Course added to wishlist.',
            'data' => $this->serializeWishlist($wishlist->load(['course.instructor', 'course.category'])),
        ], 201);
    }

    /**
     * Remove a course from the signed-in student's wishlist.
     */
    public function destroy(Request $request, Course $course)
    {
        $deleted = Wishlist::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'message' => 'This course is not in your wishlist.',
            ], 404);
        }

        return response()->json([
            'message' => 'Course removed from wishlist.',
        ]);
    }

    /**
     * Is this course on the signed-in student's wishlist?
     */
    public function check(Request $request, Course $course)
    {
        $inWishlist = Wishlist::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->exists();

        return response()->json([
            'course_id' => $course->id,
            'in_wishlist' => $inWishlist,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWishlist(Wishlist $wishlist): array
    {
        $course = $wishlist->course;

        return [
            'id' => $wishlist->id,
            'course_id' => $wishlist->course_id,
            'created_at' => $wishlist->created_at?->toIso8601String(),
            'course' => $course ? [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'description' => $course->description,
                'thumbnail' => $course->thumbnail,
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
            ] : null,
        ];
    }
}
