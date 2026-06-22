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

            $lesson->modules()->syncWithoutDetaching([
                $lesson->course_module_id => ['sort_order' => (int) $lesson->sort_order],
            ]);
        });
    }

    protected $fillable = [
        'course_id',
        'course_module_id',
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

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(CourseModule::class, 'course_module_lessons')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
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
