<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveClient
{
    public const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    public function listFolders(string $folderId): array
    {
        return array_values(array_filter(
            $this->listChildren($folderId),
            fn (array $file): bool => ($file['mimeType'] ?? null) === self::FOLDER_MIME_TYPE,
        ));
    }

    public function listFiles(string $folderId): array
    {
        return array_values(array_filter(
            $this->listChildren($folderId),
            fn (array $file): bool => ($file['mimeType'] ?? null) !== self::FOLDER_MIME_TYPE,
        ));
    }

    public function listChildren(string $folderId): array
    {
        $files = [];
        $pageToken = null;

        do {
            $query = [
                'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $folderId)),
                'fields' => 'nextPageToken,files(id,name,mimeType,webViewLink,webContentLink,thumbnailLink,modifiedTime,size)',
                'pageSize' => 1000,
                'orderBy' => 'folder,name_natural',
            ];

            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request()
                ->get(rtrim((string) config('services.google_drive.api_base_url'), '/').'/files', $query);

            if ($response->failed()) {
                throw new RuntimeException('Google Drive API error: '.$response->body());
            }

            $payload = $response->json();
            $files = array_merge($files, Arr::get($payload, 'files', []));
            $pageToken = Arr::get($payload, 'nextPageToken');
        } while ($pageToken);

        return $files;
    }

    public function downloadFileToPath(string $fileId, string $path): void
    {
        $response = $this->request()
            ->timeout((int) config('services.google_drive.download_timeout', 7200))
            ->retry(
                (int) config('services.google_drive.download_retry_attempts', 3),
                max(0, (int) config('services.google_drive.download_retry_delay_seconds', 5)) * 1000,
            )
            ->sink($path)
            ->get(rtrim((string) config('services.google_drive.api_base_url'), '/').'/files/'.$fileId, [
                'alt' => 'media',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Google Drive download error: '.$response->body());
        }
    }

    public function folderIdFromUrl(string $urlOrId): string
    {
        $value = trim($urlOrId);

        if ($value === '') {
            throw new RuntimeException('Informe a URL ou ID da pasta do Google Drive.');
        }

        if (preg_match('~/folders/([^/?#]+)~', $value, $matches)) {
            return $matches[1];
        }

        if (preg_match('~^[A-Za-z0-9_-]+$~', $value)) {
            return $value;
        }

        throw new RuntimeException('Não foi possível identificar o ID da pasta do Google Drive.');
    }

    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('services.google_drive.request_timeout', 120))
            ->retry(
                (int) config('services.google_drive.request_retry_attempts', 3),
                max(0, (int) config('services.google_drive.request_retry_delay_seconds', 2)) * 1000,
            )
            ->withToken($this->accessToken());
    }

    protected function accessToken(): string
    {
        return Cache::remember('google-drive:service-account-token', 3300, function (): string {
            $credentials = $this->credentials();
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
            $now = time();
            $assertion = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR)).'.'.$this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => (string) config('services.google_drive.scopes'),
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $signature = '';
            $signed = openssl_sign($assertion, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

            if (! $signed) {
                throw new RuntimeException('Não foi possível assinar a autenticação do Google Drive.');
            }

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion.'.'.$this->base64UrlEncode($signature),
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Google OAuth error: '.$response->body());
            }

            $token = (string) $response->json('access_token');

            if ($token === '') {
                throw new RuntimeException('Google OAuth não retornou access_token.');
            }

            return $token;
        });
    }

    protected function credentials(): array
    {
        if (! config('services.google_drive.enabled')) {
            throw new RuntimeException('A integração Google Drive está desativada.');
        }

        $path = (string) config('services.google_drive.credentials_path');
        $fullPath = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($fullPath)) {
            throw new RuntimeException("Credenciais do Google Drive não encontradas em {$path}.");
        }

        $credentials = json_decode((string) file_get_contents($fullPath), true);

        if (! is_array($credentials) || ($credentials['type'] ?? null) !== 'service_account') {
            throw new RuntimeException('As credenciais do Google Drive precisam ser do tipo service_account.');
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (empty($credentials[$field])) {
                throw new RuntimeException("Credenciais do Google Drive sem {$field}.");
            }
        }

        return $credentials;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
