<?php

namespace App\Services;

use App\Models\Lesson;
use RuntimeException;

class LessonPandaVideoImporter
{
    public function __construct(
        protected PandaVideoClient $client,
    ) {}

    public function importFromReference(Lesson $lesson, string $reference): Lesson
    {
        $videoId = $this->client->resolveVideoReference($reference);
        $video = $this->client->video($videoId);

        if (! $video) {
            throw new RuntimeException('Não encontrei esse vídeo no Panda.');
        }

        if ($this->client->videoIsFailed($video)) {
            throw new RuntimeException('O vídeo está com falha no Panda e não pode ser vinculado à aula.');
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $durationSeconds = (int) ($video['duration_seconds'] ?? 0);

        $lesson->forceFill([
            'description' => filled($lesson->description)
                ? $lesson->description
                : ((string) ($video['description'] ?? '') ?: 'Aula em vídeo.'),
            'type' => 'video',
            'thumbnail_url' => filled($video['thumbnail_url'] ?? null)
                ? $video['thumbnail_url']
                : $lesson->thumbnail_url,
            'duration_seconds' => $durationSeconds > 0
                ? $durationSeconds
                : (int) $lesson->duration_seconds,
            'status' => 'published',
            'panda_video_id' => $video['panda_video_id'],
            'panda_status' => $video['panda_status'],
            'panda_embed_url' => $video['panda_embed_url'],
            'panda_player_url' => $video['panda_player_url'],
            'source_status' => $this->client->videoIsReady($video) ? 'media_ready' : 'panda_processing',
            'metadata' => array_merge($metadata, [
                'source' => 'panda',
                'panda_direct_import_reference' => $reference,
                'panda_direct_imported_at' => now()->toIso8601String(),
                'folder_id' => $video['folder_id'] ?? ($metadata['folder_id'] ?? null),
                'payload' => $video['payload'],
            ]),
        ])->save();

        return $lesson->refresh();
    }
}
