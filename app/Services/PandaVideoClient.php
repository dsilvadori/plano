<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PandaVideoClient
{
    public function folders(?string $parentFolderId = null): Collection
    {
        $parentQueryParam = trim((string) config('services.panda.folder_parent_query_param', ''));
        $query = filled($parentFolderId) && $parentQueryParam !== '' ? [
            $parentQueryParam => $parentFolderId,
        ] : [];

        try {
            $response = $this->getAllWithAuthFallback($this->path('folders_path'), $query, 'folders_page_size');
        } catch (\Throwable $exception) {
            if ($query === [] || ! str_contains($exception->getMessage(), $parentQueryParam)) {
                throw $exception;
            }

            $response = $this->getAllWithAuthFallback($this->path('folders_path'), [], 'folders_page_size');
        }

        return $response
            ->map(fn (array $folder) => $this->normalizeFolder($folder))
            ->filter(fn (array $folder) => filled($folder['panda_folder_id']) && filled($folder['name']))
            ->values();
    }

    public function findOrCreateFolder(string $name, ?string $parentFolderId = null): array
    {
        $existing = $this->findFolderByName($name, $parentFolderId);

        if ($existing) {
            return array_merge($existing, ['was_created' => false]);
        }

        return array_merge($this->createFolder($name, $parentFolderId), ['was_created' => true]);
    }

    public function findFolderByName(string $name, ?string $parentFolderId = null): ?array
    {
        $normalizedName = $this->normalizeName($name);

        if ($normalizedName === '') {
            return null;
        }

        $folder = $this->folders($parentFolderId)
            ->first(fn (array $folder) => $this->normalizeName((string) $folder['name']) === $normalizedName);

        if ($folder || blank($parentFolderId)) {
            return $folder;
        }

        return $this->folders()
            ->first(fn (array $folder) => $this->normalizeName((string) $folder['name']) === $normalizedName);
    }

    public function activeFolder(string $folderId): ?array
    {
        try {
            $folder = $this->normalizeFolder($this->getWithAuthFallback($this->path('folders_path').'/'.rawurlencode($folderId)));
        } catch (\Throwable) {
            return null;
        }

        if (blank($folder['panda_folder_id'])) {
            return null;
        }

        if (($folder['status'] ?? true) !== true) {
            return null;
        }

        return $folder;
    }

    public function createFolder(string $name, ?string $parentFolderId = null): array
    {
        $payload = [
            (string) config('services.panda.folder_name_field', 'name') => $name,
        ];
        $parentPayloadKey = trim((string) config('services.panda.folder_parent_payload_key', ''));

        if (filled($parentFolderId) && $parentPayloadKey !== '') {
            $payload[$parentPayloadKey] = $parentFolderId;
        }

        try {
            return $this->normalizeFolder($this->postWithAuthFallback($this->path('folders_path'), $payload));
        } catch (\Throwable $exception) {
            if (! filled($parentFolderId) || $parentPayloadKey === '') {
                throw $exception;
            }

            if (! str_contains($exception->getMessage(), $parentPayloadKey)) {
                throw $exception;
            }
        }

        unset($payload[$parentPayloadKey]);

        return $this->normalizeFolder($this->postWithAuthFallback($this->path('folders_path'), $payload));
    }

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

    public function findVideoByTitle(string $title, ?string $folderId = null): ?array
    {
        $normalizedTitle = $this->normalizeName($title);

        if ($normalizedTitle === '') {
            return null;
        }

        return $this->videos($folderId)
            ->first(fn (array $video) => $this->normalizedTitleMatches((string) $video['title'], $normalizedTitle)
                && $this->isReusablePandaVideo($video));
    }

    public function processableVideo(string $videoId, ?string $folderId = null): ?array
    {
        try {
            $video = $this->video($videoId, $folderId);
        } catch (\Throwable) {
            return null;
        }

        return $video && $this->isProcessablePandaVideo($video) ? $video : null;
    }

    public function reusableVideo(string $videoId, ?string $folderId = null): ?array
    {
        try {
            $video = $this->video($videoId, $folderId);
        } catch (\Throwable) {
            return null;
        }

        return $video && $this->isReusablePandaVideo($video) ? $video : null;
    }

    public function uploadVideo(string $path, string $title, ?string $folderId = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Arquivo de vídeo não encontrado para upload: {$path}");
        }

        $mode = (string) config('services.panda.video_upload_mode', 'create_then_put');

        if ($mode === 'tus') {
            return $this->uploadVideoUsingTus($path, $title, $folderId);
        }

        if ($mode === 'create_then_put') {
            return $this->uploadVideoByCreatingDraftAndPuttingBinary($path, $title, $folderId);
        }

        $payload = [
            (string) config('services.panda.video_title_field', 'title') => $title,
        ];

        $folderField = trim((string) config('services.panda.video_folder_field', 'folder_id'));

        if (filled($folderId) && $folderField !== '') {
            $payload[$folderField] = $folderId;
        }

        $response = $this->multipartPostWithAuthFallback(
            $this->path('video_upload_path'),
            $payload,
            (string) config('services.panda.video_file_field', 'file'),
            $path,
        );

        $video = $this->normalizeVideo($this->extractSingleItem($response), $folderId);

        if (blank($video['panda_video_id'])) {
            throw new RuntimeException('O Panda aceitou o upload, mas não retornou o ID do vídeo.');
        }

        return $video;
    }

    protected function uploadVideoUsingTus(string $path, string $title, ?string $folderId = null): array
    {
        $videoId = (string) Str::uuid();
        $uploadUrl = $this->createTusUpload($path, $title, $videoId, $folderId);
        $this->sendTusPatch($uploadUrl, $path);

        $video = $this->waitForUploadedVideo($videoId, $folderId);
        $this->ensureVideoIsInExpectedFolder($video, $folderId);

        return $video;
    }

    protected function createTusUpload(string $path, string $title, string $videoId, ?string $folderId): string
    {
        $lastResponse = null;

        foreach ($this->uploaderAuthorizationValues() as $authorization) {
            $metadata = [
                'video_id' => $videoId,
                'user_id' => (string) config('services.panda.uploader_user_id', ''),
                'upload_type' => 'direct',
                'authorization' => $authorization,
                'filename' => $this->uploadFilename($path, $title),
                'filetype' => mime_content_type($path) ?: 'video/mp4',
                'name' => $title,
            ];

            if (filled($folderId)) {
                $metadata['folder_id'] = (string) $folderId;
            }

            $response = Http::timeout((int) config('services.panda.video_upload_timeout', 600))
                ->withHeaders([
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Length' => (string) filesize($path),
                    'Upload-Metadata' => $this->tusMetadataHeader($metadata),
                ])
                ->post($this->uploaderUrl());

            if ($response->successful()) {
                $lastResponse = $response;
                break;
            }

            $lastResponse = $response;

            if (! in_array($response->status(), [401, 403], true)) {
                $response->throw();
            }
        }

        $lastResponse?->throw();

        $location = $lastResponse?->header('Location');

        if (blank($location)) {
            throw new RuntimeException('O Panda aceitou a criação do upload TUS, mas não retornou o header Location.');
        }

        return $this->absoluteUploaderLocation((string) $location);
    }

    protected function sendTusPatch(string $uploadUrl, string $path): void
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir o arquivo de vídeo: {$path}");
        }

        try {
            $response = Http::timeout((int) config('services.panda.video_upload_timeout', 600))
                ->withHeaders([
                    'Tus-Resumable' => '1.0.0',
                    'Upload-Offset' => '0',
                    'Content-Type' => 'application/offset+octet-stream',
                ])
                ->withBody($handle, 'application/offset+octet-stream')
                ->send('PATCH', $uploadUrl);
        } finally {
            fclose($handle);
        }

        $response->throw();
    }

    protected function tusMetadataHeader(array $metadata): string
    {
        return collect($metadata)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, string $key) => $key.' '.base64_encode((string) $value))
            ->implode(',');
    }

    protected function uploaderAuthorizationValues(): array
    {
        $apiKey = (string) config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de enviar vídeos ao Panda.');
        }

        $scheme = trim((string) config('services.panda.uploader_auth_scheme', ''));
        $configuredValue = $scheme !== '' ? $scheme.' '.$apiKey : $apiKey;

        return collect([$configuredValue, 'Bearer '.$apiKey, $apiKey])
            ->unique()
            ->values()
            ->all();
    }

    protected function uploaderUrl(): string
    {
        return rtrim((string) config('services.panda.uploader_base_url'), '/')
            .'/'.ltrim((string) config('services.panda.uploader_path', '/files/'), '/');
    }

    protected function absoluteUploaderLocation(string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        return rtrim((string) config('services.panda.uploader_base_url'), '/').'/'.ltrim($location, '/');
    }

    protected function uploadFilename(string $path, string $title): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'mp4';
        $filename = Str::slug(pathinfo($title, PATHINFO_FILENAME));

        return ($filename !== '' ? $filename : pathinfo($path, PATHINFO_FILENAME)).'.'.$extension;
    }

    protected function waitForUploadedVideo(string $videoId, ?string $folderId): array
    {
        $attempts = max(1, (int) config('services.panda.uploader_video_lookup_attempts', 6));
        $delaySeconds = max(0, (int) config('services.panda.uploader_video_lookup_delay_seconds', 2));
        $lastVideo = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $lastVideo = $this->video($videoId, $folderId);

                if ($lastVideo && $this->isReusablePandaVideo($lastVideo)) {
                    return $lastVideo;
                }
            } catch (\Throwable) {
                //
            }

            if ($attempt < $attempts && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }

        if ($lastVideo && $this->isReusablePandaVideo($lastVideo)) {
            return $lastVideo;
        }

        if ($lastVideo) {
            throw new RuntimeException(
                'O upload TUS foi concluído, mas o Panda ainda retornou o vídeo como '.
                strtoupper((string) ($lastVideo['panda_status'] ?? 'status desconhecido')).'.'
            );
        }

        throw new RuntimeException('O upload TUS foi concluído, mas o vídeo ainda não apareceu na API do Panda.');
    }

    protected function uploadVideoByCreatingDraftAndPuttingBinary(string $path, string $title, ?string $folderId = null): array
    {
        $payload = [
            (string) config('services.panda.video_title_field', 'title') => $title,
        ];
        $folderField = trim((string) config('services.panda.video_folder_field', 'folder_id'));

        if (filled($folderId) && $folderField !== '') {
            $payload[$folderField] = $folderId;
        }

        $created = $this->postWithAuthFallback($this->path('video_create_path'), $payload);
        $video = $this->normalizeVideo($this->extractSingleItem($created), $folderId);
        $videoId = $video['panda_video_id'];

        if (blank($videoId)) {
            throw new RuntimeException('O Panda criou o rascunho do vídeo, mas não retornou o ID.');
        }

        $uploadPath = str_replace('{id}', rawurlencode($videoId), $this->path('video_binary_upload_path'));
        try {
            $uploaded = $this->putBinaryWithAuthFallback($uploadPath, $path);
        } catch (\Throwable $exception) {
            if ($this->shouldReconcileCreatedVideoAfterBinaryFailure($exception)) {
                $syncedVideo = $this->findCreatedVideoAfterBinaryFailure($videoId, $folderId);

                if ($syncedVideo && $this->isProcessablePandaVideo($syncedVideo)) {
                    return $syncedVideo;
                }
            }

            $this->deleteVideoQuietly($videoId);

            throw $exception;
        }

        if ($uploaded !== []) {
            $uploadedVideo = $this->normalizeVideo($this->extractSingleItem($uploaded), $folderId);

            if (filled($uploadedVideo['panda_video_id'])) {
                $this->ensureVideoIsInExpectedFolder($uploadedVideo, $folderId);

                return $uploadedVideo;
            }
        }

        $syncedVideo = $this->video($videoId, $folderId) ?? $video;
        $this->ensureVideoIsInExpectedFolder($syncedVideo, $folderId);

        return $syncedVideo;
    }

    protected function shouldReconcileCreatedVideoAfterBinaryFailure(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '413')
            || str_contains($message, '10485760')
            || str_contains($message, 'endpoint binário configurado');
    }

    protected function findCreatedVideoAfterBinaryFailure(string $videoId, ?string $folderId): ?array
    {
        try {
            return $this->video($videoId, $folderId);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isProcessablePandaVideo(array $video): bool
    {
        $status = strtoupper((string) ($video['panda_status'] ?? ''));

        return filled($video['panda_video_id'])
            && ! in_array($status, ['DRAFT', 'DELETING', 'DELETED', 'ERROR', 'FAILED'], true);
    }

    protected function isReusablePandaVideo(array $video): bool
    {
        $status = strtoupper((string) ($video['panda_status'] ?? ''));

        return filled($video['panda_video_id'])
            && ! in_array($status, ['DELETING', 'DELETED', 'ERROR', 'FAILED'], true);
    }

    public function video(string $videoId, ?string $folderId = null): ?array
    {
        $response = $this->getWithAuthFallback($this->path('videos_path').'/'.rawurlencode($videoId));
        $video = $this->normalizeVideo($this->extractSingleItem($response), $folderId);

        return filled($video['panda_video_id']) ? $video : null;
    }

    protected function ensureVideoIsInExpectedFolder(array $video, ?string $folderId): void
    {
        if (blank($folderId)) {
            return;
        }

        if ((string) ($video['folder_id'] ?? '') === (string) $folderId) {
            return;
        }

        $videoId = (string) ($video['panda_video_id'] ?? '');

        if (filled($videoId)) {
            $this->deleteVideoQuietly($videoId);
        }

        throw new RuntimeException(
            'O Panda criou o vídeo, mas não vinculou à pasta esperada. '.
            'A API está ignorando o folder_id no endpoint atual; confirme com o Panda o endpoint/campo para upload direto em pasta.'
        );
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
            ->get('/'.trim($pullzoneName, '/').'/'.$videoExternalId.'-ai.json');

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
            ->get('/'.trim($pullzoneName, '/').'/'.$videoExternalId.'.json');

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
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de autenticação. '.
            'Confira se a chave é uma API Key válida e se a API está liberada na conta. Última resposta: '.
            trim((string) $lastBody).' (status '.$lastStatus.')'
        );
    }

    protected function getAllWithAuthFallback(string $path, array $query = [], string $pageSizeConfig = 'page_size'): Collection
    {
        $response = $this->getWithAuthFallback($path, $query);
        $items = $this->extractItems($response);
        $nextQuery = $this->nextPageQuery($response, $query, $items->count(), $pageSizeConfig);
        $visitedQueries = [];

        while ($nextQuery !== null) {
            $fingerprint = http_build_query($nextQuery);

            if (isset($visitedQueries[$fingerprint])) {
                break;
            }

            $visitedQueries[$fingerprint] = true;

            $response = $this->getWithAuthFallback($path, $nextQuery);
            $pageItems = $this->extractItems($response);
            $items = $items->merge($pageItems);
            $nextQuery = $this->nextPageQuery($response, $nextQuery, $pageItems->count(), $pageSizeConfig);
        }

        return $items->values();
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
                : $path.'?'.http_build_query($attempt['query']);
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
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de autenticação da IA. '.
            'Confira a chave da API e permissões da conta. Última resposta: '.
            trim((string) $lastBody).' (status '.$lastStatus.')'
        );
    }

    protected function multipartPostWithAuthFallback(string $path, array $payload, string $fileField, string $filePath): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de enviar vídeos ao Panda.');
        }

        $attempts = $this->authAttempts((string) $apiKey);
        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $attempt) {
            $requestPath = $attempt['query'] === []
                ? $path
                : $path.'?'.http_build_query($attempt['query']);
            $handle = fopen($filePath, 'r');

            if ($handle === false) {
                throw new RuntimeException("Não foi possível abrir o arquivo de vídeo: {$filePath}");
            }

            try {
                $response = $this->http($attempt['headers'])
                    ->timeout((int) config('services.panda.video_upload_timeout', 600))
                    ->attach($fileField, $handle, basename($filePath))
                    ->post($requestPath, $payload);
            } finally {
                fclose($handle);
            }

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
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de upload. '.
            'Confira a chave da API e permissões da conta. Última resposta: '.
            trim((string) $lastBody).' (status '.$lastStatus.')'
        );
    }

    protected function putBinaryWithAuthFallback(string $path, string $filePath): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de enviar vídeos ao Panda.');
        }

        $attempts = $this->authAttempts((string) $apiKey);
        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $attempt) {
            $requestPath = $attempt['query'] === []
                ? $path
                : $path.'?'.http_build_query($attempt['query']);
            $handle = fopen($filePath, 'r');

            if ($handle === false) {
                throw new RuntimeException("Não foi possível abrir o arquivo de vídeo: {$filePath}");
            }

            try {
                $response = $this->http($attempt['headers'])
                    ->timeout((int) config('services.panda.video_upload_timeout', 600))
                    ->withBody($handle, 'application/octet-stream')
                    ->put($requestPath);
            } finally {
                fclose($handle);
            }

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $lastStatus = $response->status();
            $lastBody = $response->body();

            if ($response->status() !== 401) {
                if ($response->status() === 413 || str_contains($response->body(), '10485760')) {
                    throw new RuntimeException(
                        'O endpoint binário configurado no Panda ainda recusou arquivo acima de 10MB. '.
                        'Confirme PANDA_VIDEO_BINARY_UPLOAD_PATH com o suporte do Panda para upload grande.'
                    );
                }

                $response->throw();
            }
        }

        throw new RuntimeException(
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de upload binário. '.
            'Confira a chave da API e permissões da conta. Última resposta: '.
            trim((string) $lastBody).' (status '.$lastStatus.')'
        );
    }

    protected function deleteVideoQuietly(string $videoId): void
    {
        try {
            $this->deleteWithAuthFallback($this->path('videos_path').'/'.rawurlencode($videoId));
        } catch (\Throwable) {
            //
        }
    }

    protected function deleteWithAuthFallback(string $path): array
    {
        $apiKey = config('services.panda.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure PANDA_API_KEY antes de remover vídeos do Panda.');
        }

        $attempts = $this->authAttempts((string) $apiKey);
        $lastStatus = null;
        $lastBody = null;

        foreach ($attempts as $attempt) {
            $requestPath = $attempt['query'] === []
                ? $path
                : $path.'?'.http_build_query($attempt['query']);
            $response = $this->http($attempt['headers'])->delete($requestPath);

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
            'O provedor de vídeo retornou 401 Unauthorized em todas as tentativas de remoção. '.
            'Última resposta: '.trim((string) $lastBody).' (status '.$lastStatus.')'
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
        $configuredValue = $configuredScheme !== '' ? $configuredScheme.' '.$apiKey : $apiKey;

        return collect([
            ['headers' => [$configuredHeader => $configuredValue], 'query' => []],
            ['headers' => ['Authorization' => 'Bearer '.$apiKey], 'query' => []],
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
        return '/'.ltrim((string) config("services.panda.{$configKey}"), '/');
    }

    protected function extractItems(array $response): Collection
    {
        foreach (['data', 'items', 'videos', 'folders', 'results'] as $key) {
            $items = Arr::get($response, $key);

            if (is_array($items)) {
                return collect($items);
            }
        }

        return collect(array_is_list($response) ? $response : []);
    }

    protected function nextPageQuery(array $response, array $query, int $pageItemCount, string $pageSizeConfig): ?array
    {
        $nextCursor = Arr::get($response, 'next_cursor')
            ?? Arr::get($response, 'pagination.next_cursor')
            ?? Arr::get($response, 'meta.next_cursor');

        if (filled($nextCursor)) {
            return array_merge($query, ['cursor' => $nextCursor]);
        }

        $nextPage = Arr::get($response, 'next_page')
            ?? Arr::get($response, 'pagination.next_page')
            ?? Arr::get($response, 'meta.next_page')
            ?? Arr::get($response, 'links.next');

        if (filled($nextPage) && is_numeric($nextPage)) {
            return array_merge($query, ['page' => (int) $nextPage]);
        }

        $currentPage = Arr::get($response, 'current_page')
            ?? Arr::get($response, 'page')
            ?? Arr::get($response, 'pagination.current_page')
            ?? Arr::get($response, 'meta.current_page');
        $lastPage = Arr::get($response, 'last_page')
            ?? Arr::get($response, 'total_pages')
            ?? Arr::get($response, 'pagination.last_page')
            ?? Arr::get($response, 'pagination.total_pages')
            ?? Arr::get($response, 'meta.last_page')
            ?? Arr::get($response, 'meta.total_pages');

        if (is_numeric($currentPage) && is_numeric($lastPage) && (int) $currentPage < (int) $lastPage) {
            return array_merge($query, ['page' => (int) $currentPage + 1]);
        }

        $total = Arr::get($response, 'total')
            ?? Arr::get($response, 'pagination.total')
            ?? Arr::get($response, 'meta.total');
        $perPage = Arr::get($response, 'per_page')
            ?? Arr::get($response, 'page_size')
            ?? Arr::get($response, 'limit')
            ?? Arr::get($response, 'pagination.per_page')
            ?? Arr::get($response, 'pagination.page_size')
            ?? Arr::get($response, 'pagination.limit')
            ?? Arr::get($response, 'meta.per_page')
            ?? Arr::get($response, 'meta.page_size')
            ?? Arr::get($response, 'meta.limit')
            ?? config("services.panda.{$pageSizeConfig}");

        if (! is_numeric($total) || $pageItemCount <= 0) {
            return null;
        }

        $page = is_numeric($currentPage) ? (int) $currentPage : (int) ($query['page'] ?? 1);
        $pageSize = is_numeric($perPage) ? max(1, (int) $perPage) : $pageItemCount;

        if ($page * $pageSize >= (int) $total) {
            return null;
        }

        return array_merge($query, [
            'page' => $page + 1,
            'per_page' => $pageSize,
        ]);
    }

    protected function extractSingleItem(array $response): array
    {
        foreach (['data', 'video', 'item', 'result'] as $key) {
            $item = Arr::get($response, $key);

            if (is_array($item) && ! array_is_list($item)) {
                return $item;
            }
        }

        return $response;
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
            $embedUrl = rtrim((string) config('services.panda.embed_base_url'), '/').'/'.$pandaId;
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

    protected function normalizeFolder(array $folder): array
    {
        $pandaId = Arr::get($folder, 'id')
            ?? Arr::get($folder, '_id')
            ?? Arr::get($folder, 'folder_id')
            ?? Arr::get($folder, 'video_folder_id')
            ?? Arr::get($folder, 'panda_folder_id');

        return [
            'panda_folder_id' => filled($pandaId) ? (string) $pandaId : '',
            'name' => (string) (Arr::get($folder, 'name') ?? Arr::get($folder, 'title') ?? Arr::get($folder, 'folder_name') ?? ''),
            'parent_id' => Arr::get($folder, 'parent_id')
                ?? Arr::get($folder, 'parent_folder_id')
                ?? Arr::get($folder, 'folder_id_parent')
                ?? Arr::get($folder, 'parent.id'),
            'status' => Arr::get($folder, 'status'),
            'payload' => $folder,
        ];
    }

    protected function normalizeName(string $value): string
    {
        return str((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    protected function normalizedTitleMatches(string $candidate, string $normalizedTitle): bool
    {
        if ($this->normalizeName($candidate) === $normalizedTitle) {
            return true;
        }

        $extensionless = pathinfo($candidate, PATHINFO_FILENAME);

        return $extensionless !== ''
            && $this->normalizeName($extensionless) === $normalizedTitle;
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
