<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseModule extends Model
{
    /** @use HasFactory<\Database\Factories\CourseModuleFactory> */
    use HasFactory;

    protected $fillable = [
        'course_id',
        'name',
        'type',
        'lessons',
        'workload_minutes',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'lessons' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function studyTracks(): BelongsToMany
    {
        return $this->belongsToMany(StudyTrack::class, 'study_track_modules')
            ->withPivot(['weight', 'sort_order'])
            ->withTimestamps();
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(StudyPlanItem::class);
    }

    public function getPlanningLessonsAttribute(): array
    {
        $lessons = collect($this->lessons ?? [])
            ->map(function (array $lesson, int $index) {
                $minutes = (int) ($lesson['minutes'] ?? 0);

                return [
                    'name' => trim((string) ($lesson['name'] ?? '')) ?: ($this->name . ' - Aula ' . ($index + 1)),
                    'minutes' => max(0, $minutes),
                ];
            })
            ->filter(fn (array $lesson) => $lesson['minutes'] > 0)
            ->values()
            ->all();

        if ($lessons !== []) {
            return $lessons;
        }

        $remainingMinutes = max(0, (int) $this->workload_minutes);
        $fallbackLessons = [];
        $index = 1;

        while ($remainingMinutes > 0) {
            $lessonMinutes = min(60, $remainingMinutes);
            $fallbackLessons[] = [
                'name' => $this->name . ' - Etapa ' . $index,
                'minutes' => $lessonMinutes,
            ];
            $remainingMinutes -= $lessonMinutes;
            $index++;
        }

        return $fallbackLessons;
    }

    public function getLessonsCountAttribute(): int
    {
        return count($this->planning_lessons);
    }
}
