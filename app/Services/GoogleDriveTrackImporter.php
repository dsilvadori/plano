<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use Illuminate\Support\Str;

class GoogleDriveTrackImporter
{
    public function __construct(
        protected GoogleDriveClient $drive,
        protected PandaVideoClient $panda,
    ) {}

    public function importFolderSubfoldersAsTracks(?Course $course, CourseModule $module, string $folderUrlOrId, string $lessonStatus = 'draft', bool $createPandaFolders = true, bool $uploadPandaVideos = true, ?GoogleDriveImportRun $run = null): array
    {
        $folderId = $this->drive->folderIdFromUrl($folderUrlOrId);
        $folders = $this->sortNaturally($this->drive->listFolders($folderId));
        $this->updateRun($run, [
            'folder_id' => $folderId,
            'total_tracks' => count($folders),
            'latest_message' => 'Pastas do Drive carregadas.',
        ]);

        $createdTracks = 0;
        $updatedTracks = 0;
        $createdLessons = 0;
        $updatedLessons = 0;
        $pandaFolders = 0;
        $pandaVideosUploaded = 0;
        $pandaVideosSkipped = 0;
        $pandaVideosFailed = 0;

        if ($course) {
            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $module->sort_order],
            ]);
        }

        $modulePandaFolderId = $module->panda_folder_id;

        if ($createPandaFolders) {
            [$modulePandaFolderId, $moduleFolderCreated] = $this->resolvePandaFolderId($module->name, $modulePandaFolderId);
            $module->forceFill(['panda_folder_id' => $modulePandaFolderId])->save();
            $pandaFolders += $moduleFolderCreated ? 1 : 0;
        }

        foreach ($folders as $trackIndex => $folder) {
            $trackName = trim((string) ($folder['name'] ?? '')) ?: 'Trilha '.($trackIndex + 1);
            $trackSlug = Str::slug($trackName) ?: 'trilha-'.($trackIndex + 1);
            $track = $module->tracks()
                ->where('slug', $trackSlug)
                ->first()
                ?? new CourseModuleTrack([
                    'course_module_id' => $module->id,
                    'slug' => $trackSlug,
                ]);
            $trackWasCreated = ! $track->exists;
            $trackPandaFolderId = $track->panda_folder_id;

            if ($createPandaFolders) {
                [$trackPandaFolderId, $trackFolderCreated] = $this->resolvePandaFolderId($trackName, $trackPandaFolderId, $modulePandaFolderId);
                $pandaFolders += $trackFolderCreated ? 1 : 0;
            }

            $track->fill([
                'course_module_id' => $module->id,
                'name' => $trackName,
                'slug' => $track->exists ? $track->slug : $trackSlug,
                'thumbnail_url' => $folder['thumbnailLink'] ?? $track->thumbnail_url,
                'sort_order' => $trackIndex + 1,
                'status' => 'draft',
                'panda_folder_id' => $trackPandaFolderId,
                'google_doc_url' => $folder['webViewLink'] ?? $folderUrlOrId,
                'metadata' => [
                    'source' => 'google_drive',
                    'drive_folder_id' => $folder['id'] ?? null,
                    'parent_folder_id' => $folderId,
                    'panda_parent_folder_id' => $modulePandaFolderId,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
            $track->save();
            if ($course) {
                $track->courses()->syncWithoutDetaching([
                    $course->id => ['sort_order' => $trackIndex + 1],
                ]);
            }

            $trackWasCreated ? $createdTracks++ : $updatedTracks++;

            $files = $this->sortNaturally($this->drive->listFiles((string) $folder['id']));
            $this->updateRun($run, [
                'total_lessons' => (int) ($run?->total_lessons ?? 0) + count($files),
                'latest_message' => 'Trilha '.$trackName.' encontrada com '.count($files).' aulas.',
            ]);

            foreach ($files as $lessonIndex => $file) {
                $title = $this->lessonTitleFromFile((string) ($file['name'] ?? ''), $lessonIndex + 1);
                $slug = Str::slug($title) ?: 'aula-'.($lessonIndex + 1);
                $lesson = $track->lessons()
                    ->where('lessons.slug', $slug)
                    ->first()
                    ?? new Lesson([
                        'course_id' => $course?->id,
                        'course_module_id' => $module->id,
                        'course_module_track_id' => $track->id,
                        'slug' => $slug,
                    ]);
                $lessonWasCreated = ! $lesson->exists;
                $type = $this->lessonTypeForMimeType((string) ($file['mimeType'] ?? ''));
                $pandaVideo = null;
                $pandaUploadError = null;

                if ($uploadPandaVideos && $type === 'video') {
                    if (filled($lesson->panda_video_id)) {
                        $pandaVideo = $this->panda->processableVideo((string) $lesson->panda_video_id, $trackPandaFolderId);

                        if ($pandaVideo) {
                            $pandaVideosSkipped++;
                        } else {
                            $lesson->forceFill([
                                'panda_video_id' => null,
                                'panda_embed_url' => null,
                                'panda_player_url' => null,
                                'panda_status' => null,
                            ]);
                        }
                    }

                    if (! $pandaVideo) {
                        try {
                            $pandaVideo = $this->panda->findVideoByTitle($title, $trackPandaFolderId);

                            if ($pandaVideo) {
                                $pandaVideosSkipped++;
                            } else {
                                $pandaVideo = $this->uploadDriveVideoToPanda($file, $title, $trackPandaFolderId);
                                if (($pandaVideo['was_reused'] ?? false) === true) {
                                    $pandaVideosSkipped++;
                                } else {
                                    $pandaVideosUploaded++;
                                }
                                $this->pauseAfterPandaUploadIfConfigured();
                            }
                        } catch (\Throwable $exception) {
                            $pandaUploadError = $exception->getMessage();
                            $pandaVideosFailed++;
                        }
                    }
                }

                $lesson->fill([
                    'course_id' => $lesson->exists ? $lesson->course_id : $course?->id,
                    'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                    'course_module_track_id' => $lesson->exists ? ($lesson->course_module_track_id ?: $track->id) : $track->id,
                    'title' => $title,
                    'slug' => $lesson->exists ? $lesson->slug : $slug,
                    'description' => 'Aula criada a partir do Google Drive.',
                    'type' => $type,
                    'thumbnail_url' => $file['thumbnailLink'] ?? $lesson->thumbnail_url,
                    'duration_seconds' => $lesson->duration_seconds ?: 0,
                    'sort_order' => $lessonIndex + 1,
                    'status' => $lessonStatus,
                    'panda_video_id' => $pandaVideo['panda_video_id'] ?? $lesson->panda_video_id,
                    'panda_embed_url' => $pandaVideo['panda_embed_url'] ?? $lesson->panda_embed_url,
                    'panda_player_url' => $pandaVideo['panda_player_url'] ?? $lesson->panda_player_url,
                    'panda_status' => $pandaVideo['panda_status'] ?? $lesson->panda_status,
                    'google_doc_url' => $file['webViewLink'] ?? $lesson->google_doc_url,
                    'source_status' => $pandaVideo || filled($lesson->panda_video_id) || $type === 'pdf' ? 'media_ready' : 'awaiting_media',
                    'metadata' => [
                        'source' => 'google_drive',
                        'drive_file_id' => $file['id'] ?? null,
                        'drive_mime_type' => $file['mimeType'] ?? null,
                        'drive_modified_time' => $file['modifiedTime'] ?? null,
                        'drive_web_content_link' => $file['webContentLink'] ?? null,
                        'panda_folder_id' => $trackPandaFolderId,
                        'panda_upload' => $pandaVideo['payload'] ?? null,
                        'panda_upload_error' => $pandaUploadError,
                        'imported_at' => now()->toIso8601String(),
                    ],
                ]);
                $lesson->save();
                $lesson->modules()->syncWithoutDetaching([
                    $module->id => ['sort_order' => $lessonIndex + 1],
                ]);
                $lesson->tracks()->syncWithoutDetaching([
                    $track->id => ['sort_order' => $lessonIndex + 1],
                ]);

                $lessonWasCreated ? $createdLessons++ : $updatedLessons++;
                $this->updateRun($run, [
                    'processed_lessons' => (int) ($run?->processed_lessons ?? 0) + 1,
                    'panda_folders' => $pandaFolders,
                    'panda_videos_uploaded' => $pandaVideosUploaded,
                    'panda_videos_skipped' => $pandaVideosSkipped,
                    'panda_videos_failed' => $pandaVideosFailed,
                    'latest_message' => $pandaUploadError
                        ? 'Aula criada; upload Panda pendente: '.$title
                        : 'Aula processada: '.$title,
                ]);
            }

            $this->updateRun($run, [
                'processed_tracks' => $trackIndex + 1,
                'panda_folders' => $pandaFolders,
                'panda_videos_uploaded' => $pandaVideosUploaded,
                'panda_videos_skipped' => $pandaVideosSkipped,
                'panda_videos_failed' => $pandaVideosFailed,
                'latest_message' => 'Trilha processada: '.$trackName,
            ]);
        }

        return [
            'folder_id' => $folderId,
            'tracks' => count($folders),
            'created_tracks' => $createdTracks,
            'updated_tracks' => $updatedTracks,
            'panda_folders' => $pandaFolders,
            'panda_videos_uploaded' => $pandaVideosUploaded,
            'panda_videos_skipped' => $pandaVideosSkipped,
            'panda_videos_failed' => $pandaVideosFailed,
            'created_lessons' => $createdLessons,
            'updated_lessons' => $updatedLessons,
        ];
    }

    protected function sortNaturally(array $files): array
    {
        usort($files, fn (array $left, array $right): int => strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));

        return $files;
    }

    protected function updateRun(?GoogleDriveImportRun $run, array $attributes): void
    {
        if (! $run) {
            return;
        }

        $run->forceFill($attributes)->save();
        $run->refresh();
    }

    protected function resolvePandaFolderId(string $name, ?string $localFolderId, ?string $parentFolderId = null): array
    {
        if (filled($localFolderId) && $this->panda->activeFolder((string) $localFolderId)) {
            return [(string) $localFolderId, false];
        }

        $folder = $this->panda->findOrCreateFolder($name, $parentFolderId);

        return [$folder['panda_folder_id'], (bool) ($folder['was_created'] ?? true)];
    }

    protected function pauseAfterPandaUploadIfConfigured(): void
    {
        $seconds = (int) config('services.panda.video_upload_delay_seconds', 0);

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function lessonTitleFromFile(string $name, int $fallbackIndex): string
    {
        $title = trim(pathinfo($name, PATHINFO_FILENAME));

        return $title !== '' ? $title : 'Aula '.$fallbackIndex;
    }

    protected function lessonTypeForMimeType(string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => 'pdf',
            str_starts_with($mimeType, 'video/') => 'video',
            default => 'text',
        };
    }

    protected function uploadDriveVideoToPanda(array $file, string $title, ?string $pandaFolderId): array
    {
        $driveFileId = (string) ($file['id'] ?? '');

        if ($driveFileId === '') {
            throw new \RuntimeException("A aula {$title} não possui ID do arquivo no Google Drive.");
        }

        $path = $this->temporaryVideoPath((string) ($file['name'] ?? $title));

        try {
            $this->drive->downloadFileToPath($driveFileId, $path);

            return $this->uploadLocalVideoToPandaWithRetry($path, $title, $pandaFolderId);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    protected function uploadLocalVideoToPandaWithRetry(string $path, string $title, ?string $pandaFolderId): array
    {
        $attempts = max(1, (int) config('services.panda.video_upload_retry_attempts', 1));
        $delaySeconds = max(0, (int) config('services.panda.video_upload_retry_delay_seconds', 0));
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $existingVideo = $attempt > 1
                ? $this->panda->findVideoByTitle($title, $pandaFolderId)
                : null;

            if ($existingVideo) {
                return array_merge($existingVideo, ['was_reused' => true]);
            }

            try {
                return $this->panda->uploadVideo($path, $title, $pandaFolderId);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                if (! $this->isPandaUploadConcurrencyLimit($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                $this->sleepBeforeRetry($delaySeconds, $attempt);
            }
        }

        throw $lastException ?? new \RuntimeException('Upload Panda não concluído.');
    }

    protected function isPandaUploadConcurrencyLimit(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'upload concurrency limit')
            || str_contains($message, 'reached the upload concurrency')
            || str_contains($message, 'errcode":10')
            || str_contains($message, 'errcode": 10');
    }

    protected function sleepBeforeRetry(int $delaySeconds, int $attempt): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        sleep($delaySeconds * $attempt);
    }

    protected function temporaryVideoPath(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $baseName = Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'video';
        $suffix = $extension !== '' ? '.'.$extension : '';

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'drive-panda-'.Str::random(16).'-'.$baseName.$suffix;
    }
}
