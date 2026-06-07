<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\StudyTime;
use Carbon\CarbonInterface;

class StudyPlan extends Model
{
    /** @use HasFactory<\Database\Factories\StudyPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'study_track_id',
        'name',
        'exam_date',
        'exam_date_confirmed',
        'start_date',
        'available_days',
        'available_minutes_by_day',
        'total_available_minutes',
        'total_required_minutes',
        'intensity',
        'status',
        'viability_status',
        'viability_message',
        'generated_at',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'exam_date_confirmed' => 'boolean',
        'start_date' => 'date',
        'available_days' => 'array',
        'available_minutes_by_day' => 'array',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function studyTrack(): BelongsTo
    {
        return $this->belongsTo(StudyTrack::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudyPlanItem::class)->orderBy('scheduled_date')->orderBy('sort_order');
    }

    public function getCompletedMinutesAttribute(): int
    {
        return (int) $this->items()->whereNotNull('completed_at')->sum('estimated_minutes');
    }

    public function getPlannedMinutesAttribute(): int
    {
        return (int) $this->items()->sum('estimated_minutes');
    }

    public function getPendingMinutesAttribute(): int
    {
        return (int) $this->items()->whereNull('completed_at')->sum('estimated_minutes');
    }

    public function getProgressPercentageAttribute(): int
    {
        if ($this->planned_minutes === 0) {
            return 0;
        }

        if ($this->pending_minutes <= 0) {
            return 100;
        }

        return min(99, (int) round(($this->completed_minutes / $this->planned_minutes) * 100));
    }

    public function getDaysUntilExamAttribute(): int
    {
        if (! $this->exam_date || ! $this->exam_date_confirmed) {
            return 0;
        }

        return now()->startOfDay()->diffInDays($this->exam_date->copy()->startOfDay(), false);
    }

    public function getDaysUntilExamLabelAttribute(): string
    {
        return $this->exam_date_confirmed
            ? (string) $this->days_until_exam
            : 'Sem previsão';
    }

    public function getExamDateLabelAttribute(): string
    {
        return $this->exam_date_confirmed
            ? ($this->exam_date?->format('d/m/Y') ?? 'Sem previsão')
            : 'Sem previsão';
    }

    public function getViabilityLabelAttribute(): string
    {
        return match ($this->viability_status) {
            'good' => 'Boa',
            'warning' => 'Atenção',
            'critical' => 'Crítica',
            default => ucfirst((string) $this->viability_status),
        };
    }

    public function getCompletedHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes($this->completed_minutes);
    }

    public function getRequiredHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes((int) $this->total_required_minutes);
    }

    public function getPlannedHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes($this->planned_minutes);
    }

    public function getPendingHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes($this->pending_minutes);
    }

    public function getWeeklyQuestionsHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes((int) $this->items()->where('type', 'questions')->sum('estimated_minutes'));
    }

    public function getWeeklyReviewHoursMinutesAttribute(): string
    {
        return StudyTime::formatMinutes((int) $this->items()->where('type', 'review')->sum('estimated_minutes'));
    }
}
