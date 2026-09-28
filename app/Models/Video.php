<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    protected $fillable = [
        'lesson_folder_id',
        'title',
        'slug',
        'description',
        'provider',
        'provider_video_id',
        'provider_status',
        'embed_url',
        'player_url',
        'thumbnail_url',
        'duration_seconds',
        'source_status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(LessonFolder::class, 'lesson_folder_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function aiArtifacts(): HasMany
    {
        return $this->hasMany(AiArtifact::class, 'source_id')
            ->where('source_type', self::class);
    }

}
