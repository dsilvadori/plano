<?php

namespace App\Models;

use Database\Factories\CourseModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourseModule extends Model
{
    /** @use HasFactory<CourseModuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (CourseModule $module): void {
            $module->onlineLessons()->detach();
            $module->courses()->detach();
            $module->studyTracks()->detach();
            $module->questionBanks()->detach();

            $module->tracks()->get()->each->delete();

            Lesson::query()
                ->where('course_module_id', $module->id)
                ->update([
                    'course_module_id' => null,
                    'course_module_track_id' => null,
                ]);
        });

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

    public function setLessonsAttribute(?array $lessons): void
    {
        $existingLessonsByName = collect($this->lessons ?? [])
            ->mapWithKeys(fn (array $lesson): array => [$this->lessonMediaKey((string) ($lesson['name'] ?? '')) => $lesson]);

        $this->attributes['lessons'] = json_encode(
            collect($lessons ?? [])
                ->map(function (array $lesson) use ($existingLessonsByName): array {
                    if (array_key_exists('media_status', $lesson)) {
                        return $lesson;
                    }

                    $existingLesson = $existingLessonsByName->get($this->lessonMediaKey((string) ($lesson['name'] ?? '')));

                    if (! $existingLesson) {
                        return $lesson;
                    }

                    return array_merge($lesson, collect($existingLesson)
                        ->only([
                            'has_media',
                            'media_status',
                            'media_source',
                            'media_file_id',
                            'media_name',
                            'media_mime_type',
                            'media_url',
                            'media_match_confidence',
                            'media_matched_at',
                            'is_published',
                            'published_at',
                        ])
                        ->all());
                })
                ->values()
                ->all()
        );
    }

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

    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'question_bank_course_module')
            ->withTimestamps();
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
                    'name' => trim((string) ($lesson['name'] ?? '')) ?: ($this->name.' - Aula '.($index + 1)),
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
                    'name' => trim((string) $lesson->title) ?: ($this->name.' - Aula '.($index + 1)),
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
                'name' => $this->name.' - Etapa '.$index,
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

    public function getImportedMediaLessonsCountAttribute(): int
    {
        if ($this->hasOnlineLessonsForMedia()) {
            return $this->onlineLessonsForMedia()
                ->filter(fn (Lesson $lesson): bool => $this->lessonHasMedia($lesson))
                ->count();
        }

        return $this->jsonLessonsForMedia()
            ->filter(fn (array $lesson): bool => ($lesson['has_media'] ?? false) === true || ($lesson['media_status'] ?? null) === 'imported')
            ->count();
    }

    public function getMissingMediaLessonsCountAttribute(): int
    {
        if ($this->hasOnlineLessonsForMedia()) {
            return $this->onlineLessonsForMedia()
                ->filter(fn (Lesson $lesson): bool => ! $this->lessonHasMedia($lesson))
                ->count();
        }

        return $this->jsonLessonsForMedia()
            ->filter(fn (array $lesson): bool => ! (($lesson['has_media'] ?? false) === true || ($lesson['media_status'] ?? null) === 'imported'))
            ->count();
    }

    public function getPublishedLessonsCountAttribute(): int
    {
        if ($this->hasOnlineLessonsForMedia()) {
            return $this->onlineLessonsForMedia()
                ->where('status', 'published')
                ->count();
        }

        return $this->jsonLessonsForMedia()
            ->where('is_published', true)
            ->count();
    }

    public function getMediaCoverageLabelAttribute(): string
    {
        $total = $this->hasOnlineLessonsForMedia()
            ? $this->onlineLessonsForMedia()->count()
            : count($this->lessons ?? []);

        if ($total === 0) {
            return 'Sem aulas';
        }

        return "{$this->imported_media_lessons_count}/{$total}";
    }

    public function getMissingMediaLessonsLabelAttribute(): string
    {
        if ($this->hasOnlineLessonsForMedia()) {
            $missingLessons = $this->onlineLessonsForMedia()
                ->filter(fn (Lesson $lesson): bool => ! $this->lessonHasMedia($lesson))
                ->pluck('title')
                ->filter()
                ->values();
        } else {
            $missingLessons = $this->jsonLessonsForMedia()
                ->filter(fn (array $lesson): bool => ! (($lesson['has_media'] ?? false) === true || ($lesson['media_status'] ?? null) === 'imported'))
                ->pluck('name')
                ->filter()
                ->values();
        }

        if ($missingLessons->isEmpty()) {
            return $this->mediaLessonsTotal() === 0
                ? 'Sem aulas'
                : 'Todas com mídia';
        }

        $visible = $missingLessons->take(3)->implode(', ');
        $remaining = $missingLessons->count() - 3;

        return $remaining > 0
            ? "{$visible} + {$remaining} aula(s)"
            : $visible;
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

    protected function hasOnlineLessonsForMedia(): bool
    {
        return $this->relationLoaded('onlineLessons')
            ? $this->onlineLessons->isNotEmpty()
            : $this->onlineLessons()->exists();
    }

    protected function mediaLessonsTotal(): int
    {
        return $this->hasOnlineLessonsForMedia()
            ? $this->onlineLessonsForMedia()->count()
            : count($this->lessons ?? []);
    }

    protected function onlineLessonsForMedia(): Collection
    {
        return $this->relationLoaded('onlineLessons')
            ? $this->onlineLessons
            : $this->onlineLessons()->get();
    }

    protected function jsonLessonsForMedia(): Collection
    {
        return collect($this->lessons ?? []);
    }

    protected function lessonHasMedia(Lesson $lesson): bool
    {
        return filled($lesson->panda_video_id)
            || filled($lesson->panda_embed_url)
            || filled($lesson->panda_player_url)
            || in_array((string) $lesson->source_status, [
                'media_ready',
                'published',
                'panda_processing',
                'upload_queued',
                'uploading',
            ], true);
    }

    protected function lessonMediaKey(string $name): string
    {
        return Str::of($name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish()
            ->value();
    }
}
