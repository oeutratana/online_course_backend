<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    /** Certificate eligibility requires this score even if a quiz is easier. */
    public const MINIMUM_PASSING_SCORE = 60;

    protected $fillable = [
        'lesson_id',
        'title',
        'description',
        'passing_score',
        'max_attempts',
        'is_published',
        'is_required',
    ];

    protected $casts = [
        'passing_score' => 'integer',
        'max_attempts' => 'integer',
        'is_published' => 'boolean',
        'is_required' => 'boolean',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order')->orderBy('id');
    }

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_published'), true);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_required'), true);
    }

    public function isPassing(float $score): bool
    {
        return $score >= (int) $this->passing_score;
    }

    /**
     * Unlimited attempts are represented by max_attempts = 0.
     */
    public function hasAttemptsRemaining(int $usedAttempts): bool
    {
        return (int) $this->max_attempts === 0 || $usedAttempts < (int) $this->max_attempts;
    }
}
