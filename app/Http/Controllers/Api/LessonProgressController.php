<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonProgress;
use Illuminate\Http\Request;

class LessonProgressController extends Controller
{
    public function index(Request $request)
    {
        $progress = LessonProgress::with(['user', 'lesson.course'])
            ->when($request->user_id, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->lesson_id, fn ($query, $lessonId) => $query->where('lesson_id', $lessonId))
            ->when($request->has('completed'), fn ($query) => $query->where('is_completed', $request->boolean('completed')))
            ->latest()
            ->get();

        return response()->json($progress);
    }

    public function show(LessonProgress $lessonProgress)
    {
        return response()->json($lessonProgress->load(['user', 'lesson.course']));
    }
}
