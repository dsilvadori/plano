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

    public function getPlayerUrlAttribute(): ?string
    {
        $playerUrl = $this->attributes['player_url'] ?? null;
        $embedUrl = $this->attributes['embed_url'] ?? null;

        if (filled($playerUrl)) {
            return $playerUrl;
        }

        if (filled($embedUrl)) {
            return $embedUrl;
        }

        $providerVideoId = $this->attributes['provider_video_id'] ?? null;
        $pullzone = $this->pandaPullzoneName();

        if (blank($providerVideoId) || blank($pullzone)) {
            return null;
        }

        return 'https://player-'.$pullzone.'.tv.pandavideo.com.br/embed/?v='.$providerVideoId;
    }

    protected function pandaPullzoneName(): ?string
    {
        foreach ($this->pandaPullzoneCandidates() as $candidate) {
            if (preg_match('/\b(vz-[a-z0-9-]+)/i', (string) $candidate, $matches) === 1) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    protected function pandaPullzoneCandidates(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            $this->attributes['thumbnail_url'] ?? null,
            data_get($metadata, 'pullzone'),
            data_get($metadata, 'pullzone_name'),
            data_get($metadata, 'payload.pullzone'),
            data_get($metadata, 'payload.pullzone_name'),
            data_get($metadata, 'payload.pullzoneName'),
            data_get($metadata, 'payload.video_player'),
            data_get($metadata, 'payload.embed_url'),
            data_get($metadata, 'payload.player_url'),
            data_get($metadata, 'payload.thumbnail_url'),
            data_get($metadata, 'payload.thumbnail'),
        ];
    }
}
