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

        $externalVideoId = $this->pandaExternalVideoId();
        $pullzone = $this->pandaPullzoneName();

        if (blank($externalVideoId) || blank($pullzone)) {
            return null;
        }

        return 'https://player-'.$pullzone.'.tv.pandavideo.com.br/embed/?v='.$externalVideoId;
    }

    protected function pandaExternalVideoId(): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        foreach ([
            data_get($metadata, 'video_external_id'),
            data_get($metadata, 'external_id'),
            data_get($metadata, 'externalId'),
            data_get($metadata, 'payload.video_external_id'),
            data_get($metadata, 'payload.external_id'),
            data_get($metadata, 'payload.externalId'),
        ] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        foreach ($this->pandaPlayerCandidates() as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $query = parse_url($candidate, PHP_URL_QUERY);
            parse_str((string) $query, $params);

            if (filled($params['v'] ?? null)) {
                return (string) $params['v'];
            }

            if (preg_match('~/vz-[a-z0-9-]+/([^/?#]+)~i', $candidate, $matches) === 1) {
                return (string) $matches[1];
            }
        }

        return null;
    }

    protected function pandaPullzoneName(): ?string
    {
        foreach ($this->pandaPlayerCandidates() as $candidate) {
            if (preg_match('/\b(vz-[a-z0-9-]+)/i', (string) $candidate, $matches) === 1) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    protected function pandaPlayerCandidates(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return [
            $this->attributes['thumbnail_url'] ?? null,
            $this->attributes['embed_url'] ?? null,
            $this->attributes['player_url'] ?? null,
            data_get($metadata, 'pullzone'),
            data_get($metadata, 'pullzone_name'),
            data_get($metadata, 'video_player'),
            data_get($metadata, 'embed_url'),
            data_get($metadata, 'player_url'),
            data_get($metadata, 'thumbnail_url'),
            data_get($metadata, 'thumbnail'),
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
