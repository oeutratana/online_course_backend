<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quiz_id',
        'attempt_number',
        'total_questions',
        'correct_answers',
        'score',
        'passed',
        'submitted_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'total_questions' => 'integer',
        'correct_answers' => 'integer',
        'score' => 'decimal:2',
        'passed' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function answers()
    {
        return $this->hasMany(QuizAttemptAnswer::class);
    }
}
