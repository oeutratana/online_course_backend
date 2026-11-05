<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question',
        'type',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'correct_option',
        'points',
        'explanation',
        'order',
    ];

    protected $casts = [
        'points' => 'integer',
        'order' => 'integer',
    ];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Answers are stored as either "a" or "option_a", depending on the client.
     * Normalising here keeps scoring and validation consistent.
     */
    public static function normalizeOption(?string $option): ?string
    {
        $option = strtolower(trim((string) $option));

        if (preg_match('/^option_([a-d])$/', $option, $matches) === 1) {
            return $matches[1];
        }

        return in_array($option, ['a', 'b', 'c', 'd'], true) ? $option : null;
    }

    public function correctOptionLetter(): string
    {
        return self::normalizeOption($this->correct_option) ?? 'a';
    }

    public function isCorrectAnswer(?string $option): bool
    {
        return self::normalizeOption($option) === $this->correctOptionLetter();
    }

    /**
     * Students must never receive the answer key before submitting.
     */
    public function toStudentArray(): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'question' => $this->question,
            'type' => $this->type ?: 'multiple_choice',
            'option_a' => $this->option_a,
            'option_b' => $this->option_b,
            'option_c' => $this->option_c,
            'option_d' => $this->option_d,
            'points' => (int) $this->points,
            'order' => (int) $this->order,
        ];
    }
}
