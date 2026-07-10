<?php

namespace App\Models;

use Database\Factories\QuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    /** @use HasFactory<QuestionOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'question_id',
        'label',
        'text',
        'is_correct',
        'sort_order',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuestionOption $option): void {
            $answerKey = $option->question?->normalizedAnswerKey();

            if ($answerKey === null) {
                $option->is_correct = false;

                return;
            }

            $option->is_correct = $option->normalizedLabel() === $answerKey;
        });
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function normalizedLabel(): string
    {
        return str($this->label ?? '')->lower()->ascii()->squish()->value();
    }
}
