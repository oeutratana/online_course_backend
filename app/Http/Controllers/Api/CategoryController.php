<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::withCount('courses')->latest()->get());
    }

    public function show(Category $category)
    {
        return response()->json($category->load('courses.instructor'));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedCategoryData($request);

        $category = Category::create([
            ...$validated,
            'slug' => $this->uniqueSlug($validated['slug'] ?? $validated['name']),
        ]);

        return response()->json($category->loadCount('courses'), 201);
    }

    public function update(Request $request, Category $category)
    {
        $validated = $this->validatedCategoryData($request, $category);

        $category->update([
            ...$validated,
            'slug' => $this->uniqueSlug($validated['slug'] ?? $validated['name'], $category),
        ]);

        return response()->json($category->loadCount('courses'));
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }

    private function validatedCategoryData(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($category),
            ],
            'description' => ['nullable', 'string'],
        ]);
    }

    private function uniqueSlug(string $value, ?Category $category = null): string
    {
        $baseSlug = Str::slug($value) ?: 'category';
        $slug = $baseSlug;
        $counter = 2;

        while (Category::where('slug', $slug)
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
