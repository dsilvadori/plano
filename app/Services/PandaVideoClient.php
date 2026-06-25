<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PandaVideoClient
{
    public function videos(?string $folderId = null): Collection
    {
        $folderQueryParam = (string) config('services.panda.folder_query_param', 'folder_id');
        $response = $this->getWithAuthFallback($this->path('videos_path'), filled($folderId) ? [
            $folderQueryParam => $folderId,
        ] : []);

        return $this->extractItems($response)
            ->map(fn (array $video) => $this->normalizeVideo($video, $folderId))
            ->filter(fn (array $video) => filled($video['panda_video_id']) && filled($video['title']))
            ->values();
    }

    public function createAiPackage(string $videoId, string $fromLang = 'auto', string $type = 'ALL_TEXT_ITEMS'): array
    {
        return $this->postWithAuthFallback($this->path('ai_workflow_path'), [
            'video_id' => $videoId,
            'from_lang' => $fromLang,
            'type' => $type,
        ]);
    }

    public function aiPackage(string $pullzoneName, string $videoExternalId): ?array
    {
        $response = Http::baseUrl(rtrim((string) config('services.panda.ai_config_base_url'), '/'))
            ->acceptJson()
            ->get('/' . trim($pullzoneName, '/') . '/' . $videoExternalId . '-ai.json');

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json() ?? [];
    }

    public function playerConfig(string $pullzoneName, string $videoExternalId): ?array
    {
        $response = Http::baseUrl(rtrim((string) config('services.panda.ai_config_base_url'), '/'))
            ->acceptJson()
            ->timeout(5)
            ->get('/' . trim($pullzoneName, '/') . '/' . $videoExternalId . '.json');

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        return $response->json() ?? [];
    }

    protected function getWithAuthFallback(string $path, array $query = []): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de importar vídeos.');
        }

        $attempts = $this->authAttempts((string) $apiKey);
        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $attempt) {
            $response = $this->http($attempt['headers'])->get($path, array_merge($query, $attempt['query']));

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $lastStatus = $response->status();
            $lastBody = $response->body();

            if ($response->status() !== 401) {
                $response->throw();
            }
        }

        throw new RuntimeException(
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de autenticação. ' .
            'Confira se a chave é uma API Key válida e se a API está liberada na conta. Última resposta: ' .
            trim((string) $lastBody) . ' (status ' . $lastStatus . ')'
        );
    }

    protected function postWithAuthFallback(string $path, array $payload = []): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de usar a IA da aula.');
        }

        $attempts = $this->authAttempts((string) $apiKey);
        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $attempt) {
            $requestPath = $attempt['query'] === []
                ? $path
                : $path . '?' . http_build_query($attempt['query']);
            $response = $this->http($attempt['headers'])->post($requestPath, $payload);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $lastStatus = $response->status();
            $lastBody = $response->body();

            if ($response->status() !== 401) {
                $response->throw();
            }
        }

        throw new RuntimeException(
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de autenticação da IA. ' .
            'Confira a chave da API e permissões da conta. Última resposta: ' .
            trim((string) $lastBody) . ' (status ' . $lastStatus . ')'
        );
    }

    protected function http(array $headers = []): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.panda.base_url'), '/'))
            ->acceptJson()
            ->withHeaders($headers);
    }

    protected function authAttempts(string $apiKey): array
    {
        $configuredHeader = (string) config('services.panda.auth_header', 'Authorization');
        $configuredScheme = trim((string) config('services.panda.auth_scheme', 'Bearer'));
        $configuredValue = $configuredScheme !== '' ? $configuredScheme . ' ' . $apiKey : $apiKey;

        return collect([
            ['headers' => [$configuredHeader => $configuredValue], 'query' => []],
            ['headers' => ['Authorization' => 'Bearer ' . $apiKey], 'query' => []],
            ['headers' => ['Authorization' => $apiKey], 'query' => []],
            ['headers' => ['X-API-Key' => $apiKey], 'query' => []],
            ['headers' => ['x-api-key' => $apiKey], 'query' => []],
            ['headers' => ['Api-Key' => $apiKey], 'query' => []],
            ['headers' => ['api-key' => $apiKey], 'query' => []],
            ['headers' => [], 'query' => ['api_key' => $apiKey]],
            ['headers' => [], 'query' => ['apikey' => $apiKey]],
        ])
            ->unique(fn (array $attempt) => json_encode($attempt))
            ->values()
            ->all();
    }

    protected function path(string $configKey): string
    {
        return '/' . ltrim((string) config("services.panda.{$configKey}"), '/');
    }

    protected function extractItems(array $response): Collection
    {
        foreach (['data', 'items', 'videos', 'results'] as $key) {
            $items = Arr::get($response, $key);

            if (is_array($items)) {
                return collect($items);
            }
        }

        return collect(array_is_list($response) ? $response : []);
    }

    protected function normalizeVideo(array $video, ?string $folderId): array
    {
        $pandaId = Arr::get($video, 'id')
            ?? Arr::get($video, 'video_id')
            ?? Arr::get($video, 'panda_video_id');
        $duration = Arr::get($video, 'duration_seconds')
            ?? Arr::get($video, 'duration')
            ?? Arr::get($video, 'length')
            ?? 0;
        $embedUrl = Arr::get($video, 'embed_url')
            ?? Arr::get($video, 'player_embed_url')
            ?? Arr::get($video, 'panda_embed_url')
            ?? Arr::get($video, 'video_player');
        $playerUrl = Arr::get($video, 'player_url')
            ?? Arr::get($video, 'url')
            ?? Arr::get($video, 'panda_player_url')
            ?? Arr::get($video, 'video_player')
            ?? Arr::get($video, 'video_hls');

        if (blank($embedUrl) && filled(config('services.panda.embed_base_url')) && filled($pandaId)) {
            $embedUrl = rtrim((string) config('services.panda.embed_base_url'), '/') . '/' . $pandaId;
        }

        return [
            'panda_video_id' => (string) $pandaId,
            'title' => (string) (Arr::get($video, 'title') ?? Arr::get($video, 'name') ?? ''),
            'description' => Arr::get($video, 'description'),
            'duration_seconds' => (int) round((float) $duration),
            'thumbnail_url' => Arr::get($video, 'thumbnail_url') ?? Arr::get($video, 'thumbnail') ?? Arr::get($video, 'preview') ?? Arr::get($video, 'thumb'),
            'panda_status' => Arr::get($video, 'status'),
            'panda_embed_url' => $embedUrl,
            'panda_player_url' => $playerUrl,
            'ai_artifacts' => $this->extractAiArtifacts($video),
            'folder_id' => Arr::get($video, 'folder_id') ?? Arr::get($video, 'folder.id') ?? $folderId,
            'folder_name' => Arr::get($video, 'folder_name') ?? Arr::get($video, 'folder.name'),
            'payload' => $video,
        ];
    }

    protected function extractAiArtifacts(array $video): array
    {
        $paths = [
            'summary' => ['summary', 'ai_summary', 'ai.summary', 'intelligence.summary'],
            'transcript' => ['transcript', 'transcription', 'captions', 'subtitles', 'ai.transcript', 'ai.transcription'],
            'chapters' => ['chapters', 'ai_chapters', 'ai.chapters'],
            'quiz' => ['questions', 'quiz', 'ai_questions', 'ai.questions', 'ai.quiz'],
            'mindmap' => ['mindmap', 'mind_map', 'ai.mindmap', 'ai.mind_map'],
            'raw_ai' => ['ai', 'intelligence'],
        ];

        return collect($paths)
            ->mapWithKeys(function (array $candidatePaths, string $type) use ($video) {
                foreach ($candidatePaths as $path) {
                    $value = Arr::get($video, $path);

                    if (filled($value)) {
                        return [$type => $value];
                    }
                }

                return [];
            })
            ->all();
    }
}
