<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class CourseReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'rating',
        'review',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Limit a review query to a single course.
     */
    public function scopeForCourse(Builder $query, int $courseId): Builder
    {
        return $query->where($this->qualifyColumn('course_id'), $courseId);
    }

    /**
     * Limit a review query to a single student.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $userId);
    }

    /**
     * Average rating, total review count and 1..5 distribution.
     *
     * Computed with a single grouped aggregate query (optionally scoped to one
     * course) so the numbers always come from real rows and never from a
     * denormalised column that could drift.
     *
     * @return array{average_rating:float, total_reviews:int, distribution:array<string, int>}
     */
    public static function ratingSummary(?int $courseId = null): array
    {
        $rows = static::query()
            ->when($courseId, fn (Builder $query) => $query->forCourse($courseId))
            ->groupBy('rating')
            ->select('rating', DB::raw('count(*) as total'))
            ->get();

        $distribution = [];
        $totalReviews = 0;
        $weighted = 0;

        foreach (range(1, 5) as $star) {
            $distribution[(string) $star] = 0;
        }

        foreach ($rows as $row) {
            $count = (int) $row->total;
            $distribution[(string) $row->rating] = $count;
            $totalReviews += $count;
            $weighted += $count * (int) $row->rating;
        }

        return [
            'average_rating' => $totalReviews > 0 ? round($weighted / $totalReviews, 2) : 0.0,
            'total_reviews' => $totalReviews,
            'distribution' => $distribution,
        ];
    }
}
