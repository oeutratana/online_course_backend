<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Certificate eligibility, issuance and rendering.
 *
 * Eligibility is always recomputed from the database — the client never sends
 * a completion flag. Issuance runs inside a transaction and relies on the
 * unique (user_id, course_id) index, so two simultaneous requests can never
 * produce two certificates.
 */
class CertificateService
{
    /**
     * Characters used in certificate numbers: no 0/O and no 1/I, so a number
     * read off a printed certificate cannot be mistyped.
     */
    private const NUMBER_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly CourseCompletionService $completion) {}

    /**
     * Why a student may or may not claim a certificate for a course.
     *
     * @return array{eligible:bool, reason:?string, progress:array<string, mixed>}
     */
    public function eligibility(User $user, Course $course): array
    {
        $progress = $this->completion->progressForCourse($user, $course);

        if (! $this->completion->hasActiveEnrollment($user->id, $course->id)) {
            return [
                'eligible' => false,
                'reason' => 'You need an active enrollment for this course.',
                'progress' => $progress,
            ];
        }

        if ($progress['total_lessons'] === 0) {
            return [
                'eligible' => false,
                'reason' => 'This course has no lessons yet, so it cannot be completed.',
                'progress' => $progress,
            ];
        }

        if (! $progress['is_complete']) {
            return [
                'eligible' => false,
                'reason' => 'Complete every lesson in the course before claiming a certificate.',
                'progress' => $progress,
            ];
        }

        $quizzes = $this->quizRequirement($user, $course);

        if (! $quizzes['satisfied']) {
            return [
                'eligible' => false,
                'reason' => 'Pass every required quiz with at least 60% before claiming a certificate.',
                'progress' => [...$progress, 'quizzes' => $quizzes],
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'progress' => [...$progress, 'quizzes' => $quizzes],
        ];
    }

    /**
     * Every published, required quiz in the course must have a passing attempt.
     * A course without quizzes passes this rule automatically.
     *
     * @return array{total_required:int, passed:int, satisfied:bool, missing:array<int, int>}
     */
    public function quizRequirement(User $user, Course $course): array
    {
        $quizIds = Quiz::query()
            ->published()
            ->required()
            ->whereHas('lesson', fn ($query) => $query->where('course_id', $course->id))
            ->pluck('id');

        if ($quizIds->isEmpty()) {
            return [
                'total_required' => 0,
                'passed' => 0,
                'satisfied' => true,
                'missing' => [],
            ];
        }

        $passedQuizIds = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('quiz_id', $quizIds)
            ->where('passed', true)
            ->where('score', '>=', Quiz::MINIMUM_PASSING_SCORE)
            ->pluck('quiz_id')
            ->unique()
            ->all();

        $missing = $quizIds->filter(
            fn ($quizId) => ! in_array($quizId, $passedQuizIds, true)
        )->values()->all();

        return [
            'total_required' => $quizIds->count(),
            'passed' => count($passedQuizIds),
            'satisfied' => $missing === [],
            'missing' => $missing,
        ];
    }

    public function isEligible(User $user, Course $course): bool
    {
        return $this->eligibility($user, $course)['eligible'];
    }

    /**
     * Issue a certificate, or return the existing one when it already exists.
     *
     * @throws RuntimeException when the student is not eligible
     */
    public function issue(User $user, Course $course): Certificate
    {
        $eligibility = $this->eligibility($user, $course);

        if (! $eligibility['eligible']) {
            throw new RuntimeException($eligibility['reason'] ?? 'Not eligible for a certificate.');
        }

        $existing = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $course) {
                // Re-check under the transaction: a concurrent request may have
                // inserted the row between the read above and this insert.
                $locked = Certificate::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked) {
                    return $locked;
                }

                return Certificate::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'certificate_number' => $this->generateNumber(),
                    'issued_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            // Lost the race against a parallel request: the unique index saved
            // us, so return the certificate that won instead of a 500.
            $winner = Certificate::where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->first();

            if ($winner) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * Issue certificates for every completed course that still has none.
     *
     * Used by the student dashboard so finishing a course is enough to receive
     * the certificate without an extra round trip.
     *
     * @return Collection<int, Certificate>
     */
    public function issueForCompletedCourses(User $user)
    {
        $courseIds = $user->enrollments()
            ->where('status', 'active')
            ->pluck('course_id')
            ->all();

        if ($courseIds === []) {
            return collect();
        }

        $lessonCounts = $this->completion->lessonCountsForCourses($courseIds);
        $completedCounts = $this->completion->completedLessonCountsForCourses($user->id, $courseIds);

        $completedCourseIds = collect($courseIds)
            ->filter(function (int $courseId) use ($user, $lessonCounts, $completedCounts) {
                $total = $lessonCounts[$courseId] ?? 0;

                if ($total <= 0 || ($completedCounts[$courseId] ?? 0) < $total) {
                    return false;
                }

                $course = Course::find($courseId);

                return $course !== null && $this->quizRequirement($user, $course)['satisfied'];
            })
            ->values();

        if ($completedCourseIds->isEmpty()) {
            return collect();
        }

        $alreadyIssued = Certificate::where('user_id', $user->id)
            ->whereIn('course_id', $completedCourseIds)
            ->pluck('course_id')
            ->all();

        $issued = collect();

        foreach ($completedCourseIds as $courseId) {
            if (in_array($courseId, $alreadyIssued, true)) {
                continue;
            }

            try {
                $issued->push($this->issue($user, Course::findOrFail($courseId)));
            } catch (QueryException) {
                // Another request issued it first — nothing to do.
            }
        }

        return $issued;
    }

    /**
     * Human readable, collision resistant certificate number.
     *
     * Ambiguous characters (0/O, 1/I) are left out because students read these
     * numbers off a printed PDF. The segments come from random_int() so the
     * number cannot be guessed or predicted from an issued one.
     */
    public function generateNumber(): string
    {
        do {
            $number = sprintf(
                'CERT-%s-%s-%s',
                now()->format('Y'),
                $this->randomSegment(),
                $this->randomSegment(),
            );
        } while (Certificate::where('certificate_number', $number)->exists());

        return $number;
    }

    /**
     * Four characters from the unambiguous alphabet.
     */
    private function randomSegment(int $length = 4): string
    {
        $alphabet = self::NUMBER_ALPHABET;
        $maxIndex = strlen($alphabet) - 1;
        $segment = '';

        for ($index = 0; $index < $length; $index++) {
            $segment .= $alphabet[random_int(0, $maxIndex)];
        }

        return $segment;
    }

    /**
     * Render the certificate PDF.
     */
    public function renderPdf(Certificate $certificate)
    {
        $certificate->loadMissing(['user', 'course.category', 'course.instructor']);

        return Pdf::loadView('certificates.certificate', [
            'certificate' => $certificate,
            'studentName' => $certificate->user->name,
            'courseTitle' => $certificate->course->title,
            'instructorName' => $certificate->course->instructor?->name,
            'categoryName' => $certificate->course->category?->name,
            'issuedOn' => ($certificate->issued_at ?? $certificate->created_at)->format('F j, Y'),
            'verificationUrl' => route('api.certificates.verify', $certificate->certificate_number),
        ])->setPaper('a4', 'landscape');
    }

    public function downloadName(Certificate $certificate): string
    {
        return 'certificate-'.$certificate->certificate_number.'.pdf';
    }
}
