<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    /** @use HasFactory<\Database\Factories\LessonFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(function (Lesson $lesson): void {
            if (! $lesson->course_module_id) {
                return;
            }

            if (! $lesson->course_module_track_id) {
                $track = CourseModuleTrack::query()->firstOrCreate(
                    [
                        'course_module_id' => $lesson->course_module_id,
                        'slug' => 'aulas',
                    ],
                    [
                        'name' => 'Aulas',
                        'sort_order' => 1,
                        'status' => 'published',
                        'metadata' => ['source' => 'legacy_module'],
                    ],
                );

                $lesson->forceFill(['course_module_track_id' => $track->id])->saveQuietly();

                $courseIds = $lesson->module?->courses()->pluck('courses.id')->all() ?? [];

                if ($lesson->course_id) {
                    $courseIds[] = $lesson->course_id;
                }

                foreach (array_unique(array_filter($courseIds)) as $courseId) {
                    $track->courses()->syncWithoutDetaching([
                        $courseId => ['sort_order' => (int) $track->sort_order],
                    ]);
                }
            }

            $lesson->modules()->syncWithoutDetaching([
                $lesson->course_module_id => ['sort_order' => (int) $lesson->sort_order],
            ]);

            if ($lesson->course_module_track_id) {
                $lesson->tracks()->syncWithoutDetaching([
                    $lesson->course_module_track_id => ['sort_order' => (int) $lesson->sort_order],
                ]);
            }
        });
    }

    protected $fillable = [
        'course_id',
        'course_module_id',
        'course_module_track_id',
        'title',
        'slug',
        'description',
        'type',
        'thumbnail_url',
        'duration_seconds',
        'sort_order',
        'status',
        'panda_video_id',
        'panda_embed_url',
        'panda_player_url',
        'panda_status',
        'google_doc_url',
        'source_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'course_module_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(CourseModuleTrack::class, 'course_module_track_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'course_module_lessons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(CourseModuleTrack::class, 'course_module_track_lessons')
            ->withPivot(['sort_order', 'status_override'])
            ->withTimestamps();
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function aiArtifacts(): HasMany
    {
        return $this->hasMany(AiArtifact::class, 'source_id')
            ->where('source_type', self::class);
    }

    public function studyPlanItems(): BelongsToMany
    {
        return $this->belongsToMany(StudyPlanItem::class, 'study_plan_item_lessons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) ceil($this->duration_seconds / 60);
    }

    public function getPlayerUrlAttribute(): ?string
    {
        return $this->panda_embed_url ?: $this->panda_player_url;
    }
}
