<?php

namespace App\Models;

use Database\Factories\QuestionImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionImportBatch extends Model
{
    /** @use HasFactory<QuestionImportBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'question_bank_id',
        'created_by',
        'source_type',
        'file_path',
        'status',
        'questions_found',
        'questions_imported',
        'error_message',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'summary' => 'array',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
