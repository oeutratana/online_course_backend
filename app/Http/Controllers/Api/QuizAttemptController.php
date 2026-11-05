<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizAttemptController extends Controller
{
    /**
     * Submit a quiz attempt. Scoring and the pass/fail decision are performed
     * on the server; the request only carries the selected options.
     */
    public function store(Request $request, Quiz $quiz)
    {
        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
        ]);

        $user = $request->user();

        if (! $quiz->is_published) {
            abort(404);
        }

        $this->ensureEnrolled($user->id, $quiz);

        $questions = $quiz->questions()->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'answers' => ['This quiz has no questions yet.'],
            ]);
        }

        $attemptsUsed = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->count();

        if (! $quiz->hasAttemptsRemaining($attemptsUsed)) {
            throw ValidationException::withMessages([
                'answers' => ['You have used all of your attempts for this quiz.'],
            ]);
        }

        $answerMap = $this->normalizeAnswers($validated['answers'], $questions);

        $correctAnswers = 0;
        $earnedPoints = 0;
        $totalPoints = 0;
        $rows = [];

        foreach ($questions as $question) {
            $selected = $answerMap[$question->id];
            $isCorrect = $question->isCorrectAnswer($selected);
            $points = max(1, (int) $question->points);
            $totalPoints += $points;

            if ($isCorrect) {
                $correctAnswers++;
                $earnedPoints += $points;
            }

            $rows[] = [
                'question_id' => $question->id,
                'selected_option' => $selected,
                'is_correct' => $isCorrect,
            ];
        }

        $total = $questions->count();
        $score = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0;

        $attempt = DB::transaction(function () use ($quiz, $user, $attemptsUsed, $total, $correctAnswers, $score, $rows) {
            $attempt = QuizAttempt::create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'attempt_number' => $attemptsUsed + 1,
                'total_questions' => $total,
                'correct_answers' => $correctAnswers,
                'score' => $score,
                'passed' => $quiz->isPassing($score),
                'submitted_at' => now(),
            ]);

            $attempt->answers()->createMany($rows);

            return $attempt;
        });

        return response()->json(
            $attempt->load(['quiz.lesson.course', 'answers.question']),
            201
        );
    }

    public function index(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        $attempts = QuizAttempt::with(['quiz.lesson.course'])
            ->where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->orderBy('attempt_number')
            ->get();

        return response()->json($attempts);
    }

    public function show(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        $course = $attempt->quiz?->lesson?->course;

        $allowed = (int) $attempt->user_id === (int) $user->id
            || ($course !== null && $user->canManageCourse($course));

        if (! $allowed) {
            abort(403, 'You cannot view this quiz attempt.');
        }

        return response()->json($attempt->load(['quiz.lesson.course', 'answers.question']));
    }

    /**
     * Accept both {"question_id": "a"} and [{"question_id": 1, "selected_option": "a"}].
     *
     * Every question has to be answered exactly once; unknown question ids and
     * invalid option letters are rejected with a normal 422 validation error.
     */
    private function normalizeAnswers(array $answers, $questions): array
    {
        $knownQuestionIds = $questions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $map = [];
        $errors = [];

        if (array_is_list($answers)) {
            foreach ($answers as $index => $answer) {
                if (! is_array($answer) || ! isset($answer['question_id'])) {
                    $errors[] = "answers.{$index} must contain a question_id and selected_option.";

                    continue;
                }

                $map[(int) $answer['question_id']] = $answer['selected_option'] ?? null;
            }
        } else {
            foreach ($answers as $questionId => $selectedOption) {
                $map[(int) $questionId] = $selectedOption;
            }
        }

        $normalized = [];

        foreach ($knownQuestionIds as $questionId) {
            if (! array_key_exists($questionId, $map)) {
                $errors[] = "Question {$questionId} must be answered.";

                continue;
            }

            $option = Question::normalizeOption((string) $map[$questionId]);

            if ($option === null) {
                $errors[] = "Question {$questionId} has an invalid selected option.";

                continue;
            }

            $normalized[$questionId] = $option;
        }

        foreach (array_keys($map) as $questionId) {
            if (! in_array($questionId, $knownQuestionIds, true)) {
                $errors[] = "Question {$questionId} does not belong to this quiz.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['answers' => $errors]);
        }

        return $normalized;
    }

    private function ensureEnrolled(int $userId, Quiz $quiz): void
    {
        $course = $quiz->lesson?->course;

        $enrolled = $course !== null && Enrollment::where('user_id', $userId)
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->exists();

        if (! $enrolled) {
            abort(403, 'You need an active enrollment to attempt this quiz.');
        }
    }
}
