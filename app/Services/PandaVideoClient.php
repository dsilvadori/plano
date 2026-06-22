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

    protected function getWithAuthFallback(string $path, array $query = []): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de importar vídeos do Panda.');
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
            'Panda retornou 401 Unauthorized em todas as tentativas de autenticação. ' .
            'Confira se a chave é uma API Key válida e se a API está liberada na conta. Última resposta: ' .
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
            ?? Arr::get($video, 'panda_embed_url');
        $playerUrl = Arr::get($video, 'player_url')
            ?? Arr::get($video, 'url')
            ?? Arr::get($video, 'panda_player_url');

        if (blank($embedUrl) && filled(config('services.panda.embed_base_url')) && filled($pandaId)) {
            $embedUrl = rtrim((string) config('services.panda.embed_base_url'), '/') . '/' . $pandaId;
        }

        return [
            'panda_video_id' => (string) $pandaId,
            'title' => (string) (Arr::get($video, 'title') ?? Arr::get($video, 'name') ?? ''),
            'description' => Arr::get($video, 'description'),
            'duration_seconds' => (int) round((float) $duration),
            'thumbnail_url' => Arr::get($video, 'thumbnail_url') ?? Arr::get($video, 'thumbnail') ?? Arr::get($video, 'thumb'),
            'panda_status' => Arr::get($video, 'status'),
            'panda_embed_url' => $embedUrl,
            'panda_player_url' => $playerUrl,
            'folder_id' => Arr::get($video, 'folder_id') ?? Arr::get($video, 'folder.id') ?? $folderId,
            'folder_name' => Arr::get($video, 'folder_name') ?? Arr::get($video, 'folder.name'),
            'payload' => $video,
        ];
    }
}
