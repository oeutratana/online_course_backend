<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $courses = Course::with(['instructor', 'category'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->category_id, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->price === 'free', fn ($query) => $query->where('price', 0))
            ->when($request->price === 'paid', fn ($query) => $query->where('price', '>', 0))
            ->when($request->boolean('published'), fn ($query) => $query->where('is_published', true))
            ->when(
                $request->sort === 'price_low',
                fn ($query) => $query->orderBy('price'),
                fn ($query) => $query->when(
                    $request->sort === 'newest',
                    fn ($query) => $query->latest(),
                    fn ($query) => $query->latest()
                )
            )
            ->get();

        $user = $request->user() ?: auth('sanctum')->user();

        return response()->json($this->serializeCourses($courses, $user));
    }

    public function myIndex(Request $request)
    {
        $courses = Course::with(['instructor', 'category'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->category_id, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($request->search, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->price === 'free', fn ($query) => $query->where('price', 0))
            ->when($request->price === 'paid', fn ($query) => $query->where('price', '>', 0))
            ->when($request->boolean('published'), fn ($query) => $query->where('is_published', true))
            ->when(
                $request->sort === 'price_low',
                fn ($query) => $query->orderBy('price'),
                fn ($query) => $query->when(
                    $request->sort === 'newest',
                    fn ($query) => $query->latest(),
                    fn ($query) => $query->latest()
                )
            )
            ->get();

        return response()->json($this->serializeCourses($courses, $request->user()));
    }

    public function show(Request $request, Course $course)
    {
        $course->load([
            'instructor',
            'category',
            'lessons.quizzes.questions',
            'enrollments.user',
            'payments.user',
        ]);

        $user = $request->user() ?: auth('sanctum')->user();
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

        $course->update([
            ...$validated,
            'slug' => $this->uniqueSlug($validated['title'], $course),
            'is_published' => $request->boolean('is_published'),
        ]);

        return response()->json($course->load(['instructor', 'category']));
    }

    public function destroy(Request $request, Course $course)
    {
        $this->authorizeCourseChange($request, $course);

        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }

    private function validatedCourseData(Request $request): array
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'video_url' => ['nullable', 'string', 'max:255'],
            'video_file' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo', 'max:102400'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

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
        if ($request->user()->role === 'admin' || $course->user_id === $request->user()->id) {
            return;
        }

        abort(403, 'You can only change your own courses.');
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

    private function serializeCourses($courses, ?User $user)
    {
        return $courses
            ->map(fn (Course $course) => $this->serializeCourse($course, $user))
            ->values();
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
