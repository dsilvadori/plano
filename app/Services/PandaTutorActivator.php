<?php

namespace App\Services;

use App\Jobs\SyncPandaTutorAvailability;
use App\Models\Lesson;
use RuntimeException;
use Throwable;

class PandaTutorActivator
{
    public function __construct(
        protected PandaVideoClient $panda,
    ) {}

    public function activate(Lesson $lesson): array
    {
        $result = [
            'available' => false,
            'requested' => false,
            'assistant_id' => null,
        ];

        try {
            $result = $this->syncAvailability($lesson);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($result['available']) {
            return $result;
        }

        $pandaVideoId = $this->pandaVideoId($lesson);

        if (blank($pandaVideoId)) {
            throw new RuntimeException('Esta aula não tem ID de vídeo no Panda para solicitar o Tutor IA.');
        }

        $tutorResponse = $this->panda->createTutor($pandaVideoId);
        $assistantId = data_get($tutorResponse, 'assistant.id')
            ?: data_get($tutorResponse, 'assistant_id')
            ?: data_get($tutorResponse, 'data.assistant.id')
            ?: data_get($tutorResponse, 'data.assistant_id');

        $metadata = array_replace_recursive($lesson->fresh()->metadata ?? [], [
            'panda_ai' => [
                'tutor_available' => false,
                'tutor_status' => 'requested',
                'tutor_requested_at' => now()->toIso8601String(),
                'tutor_checked_at' => now()->toIso8601String(),
                'tutor_message' => (string) config('services.panda.tutor_message', 'Converse com a tutora LilIA'),
                'tutor_last_request_language' => (string) config('services.panda.ai_from_lang', 'pt-BR'),
                'tutor_response' => $tutorResponse,
                'tutor_assistant_id' => filled($assistantId) ? (string) $assistantId : null,
            ],
        ]);

        $lesson->forceFill(['metadata' => $metadata])->save();

        SyncPandaTutorAvailability::dispatch($lesson->id)
            ->delay(now()->addSeconds($this->nextSyncDelaySeconds()));

        return [
            'available' => false,
            'requested' => true,
            'assistant_id' => data_get($metadata, 'panda_ai.tutor_assistant_id'),
            'tutor_response' => $tutorResponse,
        ];
    }

    public function syncAvailability(Lesson $lesson): array
    {
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $pullzoneName || ! $videoExternalId) {
            throw new RuntimeException('Esta aula não tem dados de player suficientes para localizar o Tutor IA no Panda.');
        }

        $config = $this->panda->playerConfig($pullzoneName, $videoExternalId) ?? [];
        $assistantId = data_get($config, 'assistant_id');
        $available = filled($assistantId);
        $knownAssistantId = data_get($lesson->metadata, 'panda_ai.tutor_assistant_id');

        if (filled($knownAssistantId) || filled($assistantId)) {
            $assistantResult = $this->syncAssistantAvailability(
                $lesson,
                $pullzoneName,
                $videoExternalId,
                (string) ($knownAssistantId ?: $assistantId),
            );

            if ($assistantResult['known']) {
                return $assistantResult;
            }
        }

        $metadata = array_replace_recursive($lesson->metadata ?? [], [
            'panda_ai' => [
                'tutor_available' => $available,
                'tutor_status' => $available ? 'active' : 'not_ready',
                'tutor_assistant_id' => $available ? (string) $assistantId : null,
                'tutor_checked_at' => now()->toIso8601String(),
                'tutor_pullzone_name' => $pullzoneName,
                'tutor_video_external_id' => $videoExternalId,
            ],
        ]);

        $lesson->forceFill(['metadata' => $metadata])->save();

        return [
            'available' => $available,
            'requested' => false,
            'assistant_id' => $available ? (string) $assistantId : null,
        ];
    }

    protected function syncAssistantAvailability(Lesson $lesson, string $pullzoneName, string $videoExternalId, ?string $assistantId = null): array
    {
        $metadata = $lesson->metadata ?? [];
        $assistantId = $assistantId
            ?: data_get($metadata, 'panda_ai.tutor_assistant_id')
            ?: data_get($metadata, 'panda_ai.tutor_response.assistant.id')
            ?: data_get($metadata, 'panda_ai.tutor_response.data.assistant.id');

        if (blank($assistantId)) {
            return [
                'available' => false,
                'requested' => false,
                'assistant_id' => null,
                'known' => false,
            ];
        }

        $assistantId = (string) $assistantId;
        $pandaVideoId = $this->pandaVideoId($lesson);
        $assistant = $this->panda->tutorAssistant($assistantId);

        if (filled($pandaVideoId)) {
            $assistantStatus = (string) data_get($assistant, 'status', '');

            if ($assistantStatus !== 'ready') {
                $this->panda->updateTutorStatus($assistantId, 'ready');
                $assistant['status'] = 'ready';
            }

            $this->panda->updateTutorChatVisibility($assistantId, (string) $pandaVideoId, true);
        }

        $status = (string) data_get($assistant, 'status', '');
        $assistantVideo = $this->assistantVideo($assistant, $pandaVideoId, $videoExternalId);
        $videoReady = $this->assistantVideoIsReady($assistantVideo);
        $available = $status === 'ready' && $videoReady;
        $nextStatus = $available ? 'active' : ($this->assistantVideoStatus($assistantVideo) ?: $status ?: 'requested');

        $metadata = array_replace_recursive($metadata, [
            'panda_ai' => [
                'tutor_available' => $available,
                'tutor_status' => $nextStatus,
                'tutor_assistant_id' => $assistantId,
                'tutor_checked_at' => now()->toIso8601String(),
                'tutor_pullzone_name' => $pullzoneName,
                'tutor_video_external_id' => $videoExternalId,
                'tutor_assistant_response' => $assistant,
            ],
        ]);

        $lesson->forceFill(['metadata' => $metadata])->save();

        return [
            'available' => $available,
            'requested' => ! $available,
            'assistant_id' => $assistantId,
            'known' => true,
        ];
    }

    protected function assistantVideo(array $assistant, ?string $pandaVideoId, string $videoExternalId): ?array
    {
        $videos = data_get($assistant, 'videos', []);

        if (! is_array($videos)) {
            return null;
        }

        foreach ($videos as $video) {
            if (! is_array($video)) {
                continue;
            }

            if (
                (filled($pandaVideoId) && (string) data_get($video, 'id') === (string) $pandaVideoId)
                || (string) data_get($video, 'video_external_id') === $videoExternalId
            ) {
                return $video;
            }
        }

        return null;
    }

    protected function assistantVideoIsReady(?array $video): bool
    {
        if (! $video) {
            return false;
        }

        $processStatus = strtolower((string) data_get($video, 'process_status', ''));

        if (in_array($processStatus, ['processing', 'queued', 'failed'], true)) {
            return false;
        }

        return true;
    }

    protected function assistantVideoStatus(?array $video): ?string
    {
        $status = $video ? (string) data_get($video, 'process_status', '') : '';

        return filled($status) ? strtolower($status) : null;
    }

    protected function nextSyncDelaySeconds(): int
    {
        return collect(explode(',', (string) config('services.panda.tutor_sync_backoff_seconds', '300,600,1200,2400')))
            ->map(fn (string $seconds): int => max(60, (int) trim($seconds)))
            ->filter()
            ->first() ?? 300;
    }

    protected function pandaVideoId(Lesson $lesson): ?string
    {
        $videoId = $lesson->panda_video_id ?: data_get($lesson->metadata, 'payload.id');

        return filled($videoId) ? (string) $videoId : null;
    }

    protected function pandaVideoExternalId(Lesson $lesson): ?string
    {
        $metadata = $lesson->metadata ?? [];
        $payload = (array) data_get($metadata, 'payload', []);
        $externalId = data_get($metadata, 'panda_ai.tutor_video_external_id')
            ?? data_get($metadata, 'panda_ai.video_external_id')
            ?? data_get($payload, 'video_external_id')
            ?? data_get($payload, 'external_id');

        if (filled($externalId)) {
            return (string) $externalId;
        }

        foreach ([$lesson->panda_embed_url, $lesson->panda_player_url, data_get($payload, 'video_player')] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $query = parse_url($url, PHP_URL_QUERY);
            parse_str((string) $query, $params);

            if (filled($params['v'] ?? null)) {
                return (string) $params['v'];
            }
        }

        return null;
    }

    protected function pandaPullzoneName(Lesson $lesson): ?string
    {
        $metadata = $lesson->metadata ?? [];
        $payload = (array) data_get($metadata, 'payload', []);

        foreach ([
            data_get($metadata, 'panda_ai.tutor_pullzone_name'),
            data_get($metadata, 'panda_ai.pullzone_name'),
            data_get($payload, 'pullzone_name'),
            data_get($payload, 'pullzone'),
        ] as $pullzoneName) {
            if (filled($pullzoneName)) {
                return (string) $pullzoneName;
            }
        }

        foreach ([
            data_get($payload, 'video_player'),
            data_get($payload, 'video_hls'),
            data_get($payload, 'thumbnail'),
            data_get($payload, 'preview'),
            $lesson->panda_embed_url,
            $lesson->panda_player_url,
            $lesson->thumbnail_url,
        ] as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            if (preg_match('/(?:^|[.\/-])(vz-[a-z0-9-]+)/i', $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
