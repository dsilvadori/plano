<?php

namespace App\Services;

use App\Jobs\SyncPandaAiArtifacts;
use App\Models\AiArtifact;
use App\Models\Lesson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PandaAiResourceActivator
{
    protected const ARTIFACT_TYPES = ['summary', 'quiz', 'mindmap'];

    public function __construct(
        protected PandaVideoClient $panda,
    ) {}

    public function generate(Lesson $lesson): array
    {
        if ($this->missingArtifactTypes($lesson) === []) {
            $metadata = array_replace_recursive($lesson->metadata ?? [], [
                'panda_ai' => [
                    'last_request_status' => 'already_ready',
                    'last_payload_status' => 'ready',
                    'last_synced_at' => data_get($lesson->metadata, 'panda_ai.last_synced_at') ?: now()->toIso8601String(),
                ],
            ]);

            $lesson->forceFill(['metadata' => $metadata])->save();

            return [
                'created_artifacts' => 0,
                'requested' => false,
                'pending' => false,
                'replaced_existing' => false,
                'panda_video_id' => $this->pandaVideoId($lesson),
            ];
        }

        $createdArtifacts = $this->syncReadyArtifacts($lesson);
        $lesson->refresh();
        $missingArtifacts = $this->missingArtifactTypes($lesson);

        if ($missingArtifacts === []) {
            return [
                'created_artifacts' => $createdArtifacts,
                'requested' => false,
                'pending' => false,
                'replaced_existing' => false,
                'panda_video_id' => $this->pandaVideoId($lesson),
            ];
        }

        if ($this->hasPendingGeneration($lesson)) {
            $createdArtifacts = $this->syncReadyArtifacts($lesson);

            if ($createdArtifacts === 0) {
                SyncPandaAiArtifacts::dispatch($lesson->id)
                    ->delay(now()->addSeconds($this->nextSyncDelaySeconds()));
            }

            return [
                'created_artifacts' => $createdArtifacts,
                'requested' => false,
                'pending' => $createdArtifacts === 0,
                'replaced_existing' => false,
                'panda_video_id' => $this->pandaVideoId($lesson),
            ];
        }

        return $this->activate($lesson);
    }

    public function activate(Lesson $lesson, bool $forceRequest = true, bool $replaceExisting = false): array
    {
        $pandaVideoId = $this->pandaVideoId($lesson);

        if (blank($pandaVideoId)) {
            throw new RuntimeException('Esta aula não tem ID de vídeo no Panda.');
        }

        if ($replaceExisting) {
            $this->deleteExistingPandaArtifacts($lesson);
        }

        $metadata = $lesson->metadata ?? [];
        $createdArtifacts = $replaceExisting ? 0 : $this->syncReadyArtifacts($lesson);
        $lesson->refresh();
        $missingArtifacts = $this->missingArtifactTypes($lesson);
        $workflowResponse = null;

        if ($missingArtifacts !== [] && ($forceRequest || $createdArtifacts === 0)) {
            $workflowResponse = $this->panda->createAiPackage($pandaVideoId);
        }

        $metadata = array_replace_recursive($lesson->metadata ?? $metadata, [
            'panda_ai' => [
                'auto_sync_enabled' => true,
                'requested_at' => now()->toIso8601String(),
                'last_manual_request_at' => now()->toIso8601String(),
                'last_request_status' => $workflowResponse ? 'requested' : ($missingArtifacts === [] ? 'already_ready' : 'enabled'),
                'last_request_language' => (string) config('services.panda.ai_from_lang', 'pt-BR'),
                'last_auto_sync_attempt_at' => $workflowResponse ? now()->toIso8601String() : data_get($metadata, 'panda_ai.last_auto_sync_attempt_at'),
                'last_payload_status' => $workflowResponse ? 'regenerating' : data_get($metadata, 'panda_ai.last_payload_status'),
                'workflow_response' => $workflowResponse,
                'request_count' => ((int) data_get($metadata, 'panda_ai.request_count', 0)) + ($workflowResponse ? 1 : 0),
            ],
        ]);

        if ($pullzoneName = $this->pandaPullzoneName($lesson)) {
            data_set($metadata, 'panda_ai.pullzone_name', $pullzoneName);
        }

        if ($videoExternalId = $this->pandaVideoExternalId($lesson)) {
            data_set($metadata, 'panda_ai.video_external_id', $videoExternalId);
        }

        $lesson->forceFill(['metadata' => $metadata])->save();

        if ($workflowResponse) {
            SyncPandaAiArtifacts::dispatch($lesson->id)
                ->delay(now()->addMinutes((int) config('services.panda.ai_regeneration_poll_delay_minutes', 10)));
        }

        return [
            'created_artifacts' => $createdArtifacts,
            'requested' => (bool) $workflowResponse,
            'pending' => (bool) $workflowResponse,
            'replaced_existing' => $replaceExisting,
            'panda_video_id' => $pandaVideoId,
        ];
    }

    protected function hasPendingGeneration(Lesson $lesson): bool
    {
        $metadata = $lesson->metadata ?? [];

        return data_get($metadata, 'panda_ai.last_request_status') === 'requested'
            || data_get($metadata, 'panda_ai.last_payload_status') === 'regenerating';
    }

    protected function nextSyncDelaySeconds(): int
    {
        return collect(explode(',', (string) config('services.panda.ai_sync_backoff_seconds', '300,600,1200,2400')))
            ->map(fn (string $seconds): int => max(60, (int) trim($seconds)))
            ->filter()
            ->first() ?? 300;
    }

    protected function deleteExistingPandaArtifacts(Lesson $lesson): void
    {
        AiArtifact::query()
            ->where('source_type', Lesson::class)
            ->where('source_id', $lesson->id)
            ->where('provider', 'panda')
            ->whereIn('artifact_type', [...self::ARTIFACT_TYPES, 'panda_payload'])
            ->delete();

        Cache::forget("lesson:{$lesson->id}:ai-artifacts");
        Cache::forget("lesson:{$lesson->id}:ai-payload");
        $lesson->unsetRelation('aiArtifacts');
    }

    public function syncReadyArtifacts(Lesson $lesson): int
    {
        $pullzoneName = $this->pandaPullzoneName($lesson);
        $videoExternalId = $this->pandaVideoExternalId($lesson);

        if (! $pullzoneName || ! $videoExternalId) {
            return 0;
        }

        $payload = $this->panda->aiPackage($pullzoneName, $videoExternalId);

        if (! $payload) {
            $this->markPayloadNotReady($lesson);

            return 0;
        }

        $created = $this->syncArtifactsFromPayload($lesson, $payload);

        $metadata = array_replace_recursive($lesson->fresh()->metadata ?? [], [
            'panda_ai' => [
                'last_payload_status' => $created > 0 ? 'ready' : 'empty',
                'last_synced_at' => now()->toIso8601String(),
                'last_auto_sync_attempt_at' => now()->toIso8601String(),
            ],
        ]);

        $lesson->forceFill(['metadata' => $metadata])->save();

        return $created;
    }

    protected function markPayloadNotReady(Lesson $lesson): void
    {
        $metadata = array_replace_recursive($lesson->metadata ?? [], [
            'panda_ai' => [
                'last_payload_status' => 'not_ready',
                'last_auto_sync_attempt_at' => now()->toIso8601String(),
            ],
        ]);

        $lesson->forceFill(['metadata' => $metadata])->save();
    }

    protected function syncArtifactsFromPayload(Lesson $lesson, array $payload): int
    {
        $artifacts = [
            'summary' => $this->firstAiPayloadValue($payload, ['summary', 'abstract', 'ebook', 'eBook', 'data.summary', 'data.abstract']),
            'quiz' => $this->firstAiPayloadValue($payload, ['quiz', 'questions', 'data.quiz', 'data.questions']),
            'mindmap' => $this->firstAiPayloadValue($payload, ['mindmap', 'mind_map', 'mindMap', 'data.mindmap', 'data.mind_map', 'data.mindMap']),
        ];

        $created = 0;

        foreach ($artifacts as $type => $content) {
            if (blank($content)) {
                continue;
            }

            AiArtifact::query()->updateOrCreate([
                'source_type' => Lesson::class,
                'source_id' => $lesson->id,
                'artifact_type' => $type,
                'provider' => 'panda',
            ], [
                'status' => 'ready',
                'content' => is_array($content) ? $content : ['text' => (string) $content],
                'metadata' => [
                    'panda_video_id' => $this->pandaVideoId($lesson),
                    'video_external_id' => $this->pandaVideoExternalId($lesson),
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);

            $created++;
        }

        AiArtifact::query()->updateOrCreate([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'panda_payload',
            'provider' => 'panda',
        ], [
            'status' => 'ready',
            'content' => $payload,
            'metadata' => [
                'panda_video_id' => $this->pandaVideoId($lesson),
                'video_external_id' => $this->pandaVideoExternalId($lesson),
                'imported_at' => now()->toIso8601String(),
            ],
        ]);

        return $created;
    }

    protected function missingArtifactTypes(Lesson $lesson): array
    {
        $readyTypes = AiArtifact::query()
            ->where('source_type', Lesson::class)
            ->where('source_id', $lesson->id)
            ->where('provider', 'panda')
            ->where('status', 'ready')
            ->whereIn('artifact_type', self::ARTIFACT_TYPES)
            ->pluck('artifact_type')
            ->all();

        return array_values(array_diff(self::ARTIFACT_TYPES, $readyTypes));
    }

    protected function firstAiPayloadValue(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
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
        $externalId = data_get($metadata, 'panda_ai.video_external_id')
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
