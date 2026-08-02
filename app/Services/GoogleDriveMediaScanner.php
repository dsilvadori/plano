<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveMediaScanner
{
    public function listMediaFiles(?string $folderId = null): array
    {
        $folderId ??= config('services.google_drive.media_folder_id');

        if (blank($folderId)) {
            throw new RuntimeException('Informe o ID da pasta do Google Drive.');
        }

        $token = $this->accessToken();
        $files = [];
        $pageToken = null;

        do {
            $response = Http::withToken($token)
                ->get('https://www.googleapis.com/drive/v3/files', array_filter([
                    'q' => sprintf("'%s' in parents and trashed = false", $folderId),
                    'fields' => 'nextPageToken, files(id, name, mimeType, webViewLink)',
                    'pageSize' => 1000,
                    'pageToken' => $pageToken,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ]));

            if (! $response->successful()) {
                throw new RuntimeException('Não foi possível listar arquivos do Drive: '.$response->body());
            }

            $payload = $response->json();
            $pageToken = $payload['nextPageToken'] ?? null;

            foreach ($payload['files'] ?? [] as $file) {
                if (! str_starts_with((string) ($file['mimeType'] ?? ''), 'video/')) {
                    continue;
                }

                $files[] = [
                    'id' => $file['id'] ?? null,
                    'name' => $file['name'] ?? '',
                    'mime_type' => $file['mimeType'] ?? null,
                    'web_url' => $file['webViewLink'] ?? null,
                    'source' => 'google_drive',
                ];
            }
        } while ($pageToken);

        return $files;
    }

    protected function accessToken(): string
    {
        $credentialsPath = config('services.google_drive.credentials_path');

        if (blank($credentialsPath)) {
            throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS_PATH não configurado.');
        }

        $absolutePath = base_path((string) $credentialsPath);

        if (! is_file($absolutePath)) {
            $absolutePath = storage_path(str_replace('storage/', '', (string) $credentialsPath));
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException("Credenciais do Google Drive não encontradas em {$credentialsPath}.");
        }

        $credentials = json_decode((string) file_get_contents($absolutePath), true);
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'] ?? null,
            'scope' => implode(' ', (array) config('services.google_drive.scopes', [])),
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsignedJwt = "{$header}.{$claims}";

        openssl_sign($unsignedJwt, $signature, (string) ($credentials['private_key'] ?? ''), OPENSSL_ALGO_SHA256);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsignedJwt.'.'.$this->base64UrlEncode($signature),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível autenticar no Google Drive: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
