<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseSpreadsheetImportRun extends Model
{
    protected $fillable = [
        'course_id',
        'course_name',
        'file_name',
        'stored_path',
        'status',
        'total_modules',
        'processed_modules',
        'total_tracks',
        'total_lessons',
        'total_minutes',
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

    public function getProgressPercentAttribute(): int
    {
        if ($this->status === 'finished') {
            return 100;
        }

        if ($this->total_modules > 0) {
            return (int) min(99, floor(($this->processed_modules / $this->total_modules) * 100));
        }

        return 0;
    }

    public function getProgressLabelAttribute(): string
    {
        if ($this->status === 'finished') {
            return '100% concluído';
        }

        if ($this->status === 'failed') {
            return 'Falhou';
        }

        if ($this->total_modules > 0) {
            return "{$this->progress_percent}% ({$this->processed_modules}/{$this->total_modules} módulos)";
        }

        return match ($this->status) {
            'running' => 'Processando',
            default => 'Na fila',
        };
    }

    public function getDurationLabelAttribute(): string
    {
        $start = $this->started_at;
        $end = $this->finished_at;

        if (! $start) {
            return '-';
        }

        $seconds = (int) max(0, floor($start->diffInSeconds($end ?: now())));

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 ? "{$minutes}min {$remainingSeconds}s" : "{$minutes}min";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}min" : "{$hours}h";
    }
}
