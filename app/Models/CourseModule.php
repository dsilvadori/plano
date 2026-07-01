<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class CourseModule extends Model
{
    /** @use HasFactory<\Database\Factories\CourseModuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (CourseModule $module): void {
            if (! $module->course_id) {
                return;
            }

            $module->courses()->syncWithoutDetaching([
                $module->course_id => ['sort_order' => (int) $module->sort_order],
            ]);
        });
    }

    protected $fillable = [
        'course_id',
        'name',
        'description',
        'type',
        'lessons',
        'workload_minutes',
        'sort_order',
        'panda_folder_id',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'lessons' => 'array',
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_module_course')
            ->withPivot('sort_order')
            ->withTimestamps();
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

    public function onlineLessons(): BelongsToMany
    {
        return $this->belongsToMany(Lesson::class, 'course_module_lessons')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.title');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(CourseModuleTrack::class)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function getPlanningLessonsAttribute(): array
    {
        $lessons = $this->sortLessonsNaturally(collect($this->lessons ?? [])
            ->map(function (array $lesson, int $index) {
                $minutes = (int) ($lesson['minutes'] ?? 0);

                return [
                    'name' => trim((string) ($lesson['name'] ?? '')) ?: ($this->name . ' - Aula ' . ($index + 1)),
                    'minutes' => max(0, $minutes),
                    '_index' => $index,
                ];
            })
            ->filter(fn (array $lesson) => $lesson['minutes'] > 0)
            ->values())
            ->map(fn (array $lesson) => [
                'name' => $lesson['name'],
                'minutes' => $lesson['minutes'],
            ])
            ->all();

        if ($lessons !== []) {
            return $lessons;
        }

        $onlineLessons = $this->sortLessonsNaturally(($this->relationLoaded('onlineLessons') ? $this->onlineLessons : $this->onlineLessons()->get())
            ->map(function (Lesson $lesson, int $index) {
                return [
                    'name' => trim((string) $lesson->title) ?: ($this->name . ' - Aula ' . ($index + 1)),
                    'minutes' => max(1, (int) $lesson->duration_minutes),
                    '_index' => $index,
                ];
            })
            ->filter(fn (array $lesson) => $lesson['minutes'] > 0)
            ->values())
            ->map(fn (array $lesson) => [
                'name' => $lesson['name'],
                'minutes' => $lesson['minutes'],
            ])
            ->all();

        if ($onlineLessons !== []) {
            return $onlineLessons;
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

    protected function sortLessonsNaturally(Collection $lessons): Collection
    {
        return $lessons
            ->sortBy(function (array $lesson): string {
                preg_match('/^\D*(\d+)/', (string) ($lesson['name'] ?? ''), $matches);

                return implode('|', [
                    isset($matches[1]) ? '0' : '1',
                    str_pad((string) ((int) ($matches[1] ?? 0)), 8, '0', STR_PAD_LEFT),
                    str_pad((string) ($lesson['_index'] ?? 0), 8, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();
    }
}
