<?php

namespace App\Models;

use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Question $question): void {
            $question->course_id = null;
            $question->course_module_id = null;
            $question->lesson_id = null;
        });

        static::saved(function (Question $question): void {
            $question->syncCorrectOptionsFromAnswerKey();
        });
    }

    protected $fillable = [
        'question_bank_id',
        'course_id',
        'course_module_id',
        'lesson_id',
        'number',
        'subject',
        'topic',
        'subtopic',
        'statement',
        'type',
        'answer_key',
        'commentary',
        'commentary_provider',
        'source_reference',
        'difficulty',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order')->orderBy('label');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuestionAttempt::class);
    }

    public function syncCorrectOptionsFromAnswerKey(): void
    {
        $answerKey = $this->normalizedAnswerKey();

        if ($answerKey === null) {
            $this->options()->update(['is_correct' => false]);

            return;
        }

        $this->options()->get()->each(function (QuestionOption $option) use ($answerKey): void {
            $isCorrect = $option->normalizedLabel() === $answerKey;

            if ((bool) $option->is_correct !== $isCorrect) {
                $option->forceFill(['is_correct' => $isCorrect])->saveQuietly();
            }
        });
    }

    public function normalizedAnswerKey(): ?string
    {
        $answerKey = str($this->answer_key ?? '')->lower()->ascii()->squish()->value();

        return $answerKey !== '' ? $answerKey : null;
    }

    public function imageUrls(): array
    {
        return collect(data_get($this->metadata, 'image_urls', []))
            ->filter()
            ->map(fn (string $path): string => $this->imageDisplayUrl($path))
            ->values()
            ->all();
    }

    public function imageDescription(): ?string
    {
        $description = trim((string) data_get($this->metadata, 'image_description', ''));

        return $description !== '' ? $description : null;
    }

    public function imagePosition(): string
    {
        $position = data_get($this->metadata, 'image_position', 'after_statement');

        return in_array($position, ['before_statement', 'after_statement'], true)
            ? $position
            : 'after_statement';
    }

    protected function imageDisplayUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
