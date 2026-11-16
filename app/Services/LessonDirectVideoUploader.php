<?php

namespace App\Services;

use App\Jobs\UploadDirectVideoToPanda;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Recebe um vídeo enviado pelo admin, guarda temporariamente no storage privado
 * e agenda o envio ao Panda. Quando o Panda termina de processar, a aula e a
 * Biblioteca de Vídeos passam a refletir o vídeo (via SyncPandaVideoStatus).
 */
class LessonDirectVideoUploader
{
    public function __construct(
        protected PandaVideoClient $panda,
    ) {}

    public static function disk(): string
    {
        return (string) config('services.panda.direct_upload_disk', 'local');
    }

    public static function directory(): string
    {
        return trim((string) config('services.panda.direct_upload_directory', 'panda-uploads'), '/');
    }

    public static function maxSizeKilobytes(): int
    {
        return max(1, (int) config('services.panda.direct_upload_max_mb', 2048)) * 1024;
    }

    public static function lessonHasPandaMedia(Lesson $lesson): bool
    {
        return filled($lesson->panda_video_id)
            || filled($lesson->panda_embed_url)
            || filled($lesson->panda_player_url);
    }

    /**
     * Pasta Panda sugerida para a aula: a pasta já usada pela aula, ou a da trilha/módulo.
     */
    public function defaultFolderReferenceFor(?Lesson $lesson): ?string
    {
        if (! $lesson) {
            return null;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        foreach (['panda_folder_id', 'folder_id'] as $key) {
            if (filled($metadata[$key] ?? null)) {
                return (string) $metadata[$key];
            }
        }

        $trackFolder = $lesson->tracks()
            ->whereNotNull('course_module_tracks.panda_folder_id')
            ->where('course_module_tracks.panda_folder_id', '!=', '')
            ->value('course_module_tracks.panda_folder_id');

        if (filled($trackFolder)) {
            return (string) $trackFolder;
        }

        $moduleFolder = $lesson->modules()
            ->whereNotNull('course_modules.panda_folder_id')
            ->where('course_modules.panda_folder_id', '!=', '')
            ->value('course_modules.panda_folder_id');

        return filled($moduleFolder) ? (string) $moduleFolder : null;
    }

    /**
     * Resolve a pasta de destino. O upload na raiz do Panda é bloqueado, como no fluxo do Drive.
     */
    public function resolveFolderId(?string $folderReference, bool $createWhenMissing = false): string
    {
        $folderReference = trim((string) $folderReference);

        if ($folderReference === '') {
            throw new RuntimeException('Informe a pasta do Panda. O envio para a raiz do Panda é bloqueado.');
        }

        try {
            return $this->panda->resolveFolderReference($folderReference);
        } catch (Throwable $exception) {
            if (! $createWhenMissing || filter_var($folderReference, FILTER_VALIDATE_URL)) {
                throw $exception;
            }

            $folder = $this->panda->findOrCreateFolder($folderReference);

            if (blank($folder['panda_folder_id'] ?? null)) {
                throw new RuntimeException("O Panda não retornou o ID da pasta {$folderReference}.");
            }

            return (string) $folder['panda_folder_id'];
        }
    }

    /**
     * @param  string  $storedPath  Caminho relativo ao disco de upload direto.
     */
    public function queue(
        Lesson $lesson,
        string $storedPath,
        ?string $originalName,
        ?string $folderReference,
        ?string $title = null,
        bool $createFolderWhenMissing = false,
    ): Lesson {
        $disk = Storage::disk(self::disk());

        if (! $disk->exists($storedPath)) {
            throw new RuntimeException('O arquivo de vídeo não foi recebido. Envie o arquivo novamente.');
        }

        try {
            $folderId = $this->resolveFolderId($folderReference, $createFolderWhenMissing);
        } catch (Throwable $exception) {
            $disk->delete($storedPath);

            throw $exception;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $previousUpload = is_array($metadata['panda_direct_upload'] ?? null) ? $metadata['panda_direct_upload'] : [];

        // Um envio novo substitui um anterior que ainda não foi processado.
        if (filled($previousUpload['path'] ?? null)
            && $previousUpload['path'] !== $storedPath
            && in_array($previousUpload['status'] ?? null, ['queued', 'uploading'], true)) {
            Storage::disk((string) ($previousUpload['disk'] ?? self::disk()))->delete((string) $previousUpload['path']);
        }

        $uploadTitle = trim((string) $title) !== '' ? trim((string) $title) : (string) $lesson->title;

        $lesson->forceFill([
            'type' => 'video',
            // Aula que já tem vídeo continua tocando o atual até o Panda aceitar o novo arquivo.
            'source_status' => self::lessonHasPandaMedia($lesson) ? $lesson->source_status : 'upload_queued',
            'metadata' => array_replace($metadata, [
                'panda_folder_id' => $folderId,
                'panda_upload_error' => null,
                'panda_processing_error' => null,
                'panda_direct_upload' => [
                    'status' => 'queued',
                    'disk' => self::disk(),
                    'path' => $storedPath,
                    'original_name' => $originalName ?: basename($storedPath),
                    'size_bytes' => (int) $disk->size($storedPath),
                    'mime_type' => $disk->mimeType($storedPath) ?: null,
                    'title' => $uploadTitle,
                    'folder_id' => $folderId,
                    'folder_reference' => $folderReference,
                    'previous_panda_video_id' => $lesson->panda_video_id,
                    'queued_at' => now()->toIso8601String(),
                    'queued_by' => auth()->id(),
                ],
            ]),
        ])->save();

        UploadDirectVideoToPanda::dispatch((int) $lesson->id, self::disk(), $storedPath);

        return $lesson->refresh();
    }
}
