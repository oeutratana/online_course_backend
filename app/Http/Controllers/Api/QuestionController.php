<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $questions = Question::with('quiz.lesson')
            ->when($request->quiz_id, fn ($query, $quizId) => $query->where('quiz_id', $quizId))
            ->latest()
            ->get();

        return response()->json($questions);
    }

    public function show(Question $question)
    {
        return response()->json($question->load('quiz.lesson'));
    }
}
