<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleDriveImportRun extends Model
{
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'course_id',
        'course_module_id',
        'folder_url',
        'folder_id',
        'status',
        'total_tracks',
        'processed_tracks',
        'total_lessons',
        'processed_lessons',
        'panda_folders',
        'panda_videos_uploaded',
        'panda_videos_skipped',
        'panda_videos_failed',
        'summary',
        'latest_message',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->status === 'finished') {
            return 100;
        }

        if ($this->total_lessons > 0) {
            return (int) min(100, floor(($this->processed_lessons / $this->total_lessons) * 100));
        }

        if ($this->total_tracks > 0) {
            return (int) min(100, floor(($this->processed_tracks / $this->total_tracks) * 100));
        }

        return 0;
    }

    public function getProgressLabelAttribute(): string
    {
        if ($this->total_lessons > 0) {
            return "{$this->progress_percent}% ({$this->processed_lessons}/{$this->total_lessons} aulas)";
        }

        if ($this->total_tracks > 0) {
            return "{$this->progress_percent}% ({$this->processed_tracks}/{$this->total_tracks} trilhas)";
        }

        return "{$this->progress_percent}%";
    }

    public function cancel(?string $message = null): bool
    {
        return $this->forceFill([
            'status' => self::STATUS_CANCELED,
            'latest_message' => $message ?: 'Importação interrompida manualmente.',
            'error_message' => null,
            'finished_at' => now(),
        ])->save();
    }

    public function isCanceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['finished', 'failed', self::STATUS_CANCELED], true);
    }
}
