<?php

namespace App\Models;

use Database\Factories\QuestionBankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    /** @use HasFactory<QuestionBankFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'source_type',
        'source_file_path',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('number')->orderBy('id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(QuestionImportBatch::class);
    }
}
