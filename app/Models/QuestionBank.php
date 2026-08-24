<?php

namespace App\Models;

use Database\Factories\QuestionBankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    /** @use HasFactory<QuestionBankFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'exam_board',
        'exam_year',
        'exam_name',
        'source_type',
        'source_file_path',
        'status',
        'metadata',
    ];

    protected $casts = [
        'exam_year' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuestionBank $bank): void {
            $bank->course_id = null;
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('number')->orderBy('id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'question_bank_course_module')
            ->withTimestamps()
            ->orderBy('course_modules.name');
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(CourseModuleTrack::class, 'question_bank_course_module_track')
            ->withTimestamps()
            ->orderBy('course_module_tracks.name');
    }

    public function lessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'question_bank_lesson')
            ->withTimestamps()
            ->orderBy('lessons.title');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(QuestionImportBatch::class);
    }
}
