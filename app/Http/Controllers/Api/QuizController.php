<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function index(Request $request)
    {
        $quizzes = Quiz::with('lesson.course')
            ->when($request->lesson_id, fn ($query, $lessonId) => $query->where('lesson_id', $lessonId))
            ->latest()
            ->get();

        return response()->json($quizzes);
    }

    public function show(Quiz $quiz)
    {
        return response()->json($quiz->load(['lesson.course', 'questions']));
    }
}
