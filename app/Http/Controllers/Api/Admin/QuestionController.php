<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $questions = Question::with('quiz.lesson.course')
            ->when($request->quiz_id, fn ($query, $quizId) => $query->where('quiz_id', $quizId))
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json($questions);
    }

    public function store(Request $request, Quiz $quiz)
    {
        $this->authorizeQuiz($request, $quiz);

        $validated = $this->validatedData($request, true);
        $validated['quiz_id'] = $quiz->id;
        $validated['correct_option'] = 'option_'.Question::normalizeOption($validated['correct_option']);

        if (! array_key_exists('order', $validated)) {
            $validated['order'] = ((int) Question::where('quiz_id', $quiz->id)->max('order')) + 1;
        }

        $question = Question::create($validated);

        return response()->json($question->load('quiz.lesson.course'), 201);
    }

    public function reorder(Request $request, Quiz $quiz)
    {
        $this->authorizeQuiz($request, $quiz);

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'distinct', 'exists:questions,id'],
        ]);

        $existingIds = $quiz->questions()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $requestedIds = collect($validated['question_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($existingIds !== $requestedIds) {
            throw ValidationException::withMessages([
                'question_ids' => ['The question list must contain every question in this quiz exactly once.'],
            ]);
        }

        DB::transaction(function () use ($validated, $quiz) {
            foreach (array_values($validated['question_ids']) as $index => $questionId) {
                Question::where('quiz_id', $quiz->id)
                    ->whereKey($questionId)
                    ->update(['order' => $index]);
            }
        });

        return response()->json(
            $quiz->fresh()->load(['lesson.course', 'questions'])
        );
    }

    public function update(Request $request, Question $question)
    {
        $this->authorizeQuestion($request, $question);

        $validated = $this->validatedData($request, false);

        if (isset($validated['correct_option'])) {
            $validated['correct_option'] = 'option_'.Question::normalizeOption($validated['correct_option']);
        }

        $question->update($validated);

        return response()->json($question->fresh()->load('quiz.lesson.course'));
    }

    public function destroy(Request $request, Question $question)
    {
        $this->authorizeQuestion($request, $question);

        $question->delete();

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
    }

    private function validatedData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $optionValues = ['a', 'b', 'c', 'd', 'option_a', 'option_b', 'option_c', 'option_d'];

        return $request->validate([
            'question' => [$required, 'string'],
            'type' => ['sometimes', 'string', Rule::in(['multiple_choice'])],
            'option_a' => [$required, 'string', 'max:255'],
            'option_b' => [$required, 'string', 'max:255'],
            'option_c' => [$required, 'string', 'max:255'],
            'option_d' => [$required, 'string', 'max:255'],
            'correct_option' => [$required, 'string', Rule::in($optionValues)],
            'points' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'explanation' => ['nullable', 'string'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    private function authorizeQuestion(Request $request, Question $question): void
    {
        $this->authorizeQuiz($request, $question->quiz);
    }

    private function authorizeQuiz(Request $request, ?Quiz $quiz): void
    {
        $course = $quiz?->lesson?->course;

        if (! $course || ! $request->user()?->canManageCourse($course)) {
            abort(403, 'Only admins can manage questions.');
        }
    }
}
