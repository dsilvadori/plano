<?php

namespace App\Models;

use Database\Factories\QuestionAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionAttempt extends Model
{
    /** @use HasFactory<QuestionAttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'question_id',
        'question_option_id',
        'answer_label',
        'is_correct',
        'answered_at',
        'metadata',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'answered_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'question_option_id');
    }
}
