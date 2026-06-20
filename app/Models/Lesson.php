<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    /** @use HasFactory<\Database\Factories\LessonFactory> */
    use HasFactory;

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

    public function getDurationMinutesAttribute(): int
    {
        return (int) ceil($this->duration_seconds / 60);
    }
}
