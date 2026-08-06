<?php

namespace App\Services;

use App\Jobs\SyncPandaVideoStatus;
use App\Jobs\UploadLessonToPanda;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use App\Support\LessonTitleNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleDriveTrackImporter
{
    protected array $importWarnings = [];

    public function __construct(
        protected GoogleDriveClient $drive,
        protected PandaVideoClient $panda,
        protected ?LessonCourseLinker $lessonCourseLinker = null,
    ) {}

    public function importFolderSubfoldersAsTracks(?Course $course, CourseModule $module, string $folderUrlOrId, string $lessonStatus = 'draft', bool $createPandaFolders = true, bool $uploadPandaVideos = true, ?GoogleDriveImportRun $run = null): array
    {
        $this->importWarnings = [];

        $folderId = $this->drive->folderIdFromUrl($folderUrlOrId);
        $folders = $this->sortNaturally($this->guardedDriveList(
            fn (): array => $this->drive->listFolders($folderId),
            'subpastas da pasta raiz',
        ));
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
        $planningLessons = [];

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

            $files = $this->sortNaturally($this->guardedDriveList(
                fn (): array => $this->drive->listFiles((string) $folder['id']),
                'trilha '.$trackName,
            ));
            $this->updateRun($run, [
                'total_lessons' => (int) ($run?->total_lessons ?? 0) + count($files),
                'latest_message' => 'Trilha '.$trackName.' encontrada com '.count($files).' aulas.',
            ]);

            foreach ($files as $lessonIndex => $file) {
                $title = $this->lessonTitleFromFile((string) ($file['name'] ?? ''), $lessonIndex + 1);
                $slug = Str::slug($title) ?: 'aula-'.($lessonIndex + 1);
                $lesson = $this->resolveReusableDriveLesson($course, $module, $track, $title, $slug, $file)
                    ?? new Lesson([
                        'course_id' => null,
                        'course_module_id' => null,
                        'course_module_track_id' => null,
                        'slug' => $slug,
                    ]);
                $lessonWasCreated = ! $lesson->exists;
                $type = $this->lessonTypeForMimeType((string) ($file['mimeType'] ?? ''));
                $pandaVideo = null;
                $pandaUploadError = null;

                if ($uploadPandaVideos && $type === 'video') {
                    if (filled($lesson->panda_video_id)) {
                        $pandaVideo = $this->panda->reusableVideo((string) $lesson->panda_video_id, $trackPandaFolderId);

                        if ($pandaVideo) {
                            $pandaVideosSkipped++;
                            if (! $this->pandaVideoIsReady($pandaVideo)) {
                                $pandaVideosFailed++;
                            }
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
                                if (! $this->pandaVideoIsReady($pandaVideo)) {
                                    $pandaVideosFailed++;
                                }
                            } else {
                                $pandaVideo = $this->uploadDriveVideoToPanda($file, $title, $trackPandaFolderId);
                                if (($pandaVideo['was_reused'] ?? false) === true) {
                                    $pandaVideosSkipped++;
                                } else {
                                    $pandaVideosUploaded++;
                                }
                                if (! $this->pandaVideoIsReady($pandaVideo)) {
                                    $pandaVideosFailed++;
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
                    'course_id' => null,
                    'course_module_id' => null,
                    'course_module_track_id' => null,
                    'title' => $title,
                    'slug' => $lesson->exists ? $lesson->slug : $slug,
                    'description' => 'Aula adicionada à plataforma.',
                    'type' => $type,
                    'thumbnail_url' => $file['thumbnailLink'] ?? $lesson->thumbnail_url,
                    'duration_seconds' => $this->durationSecondsFromPandaVideo($pandaVideo, $lesson),
                    'sort_order' => $lessonIndex + 1,
                    'status' => $lessonStatus,
                    'panda_video_id' => $pandaVideo['panda_video_id'] ?? $lesson->panda_video_id,
                    'panda_embed_url' => $pandaVideo['panda_embed_url'] ?? $lesson->panda_embed_url,
                    'panda_player_url' => $pandaVideo['panda_player_url'] ?? $lesson->panda_player_url,
                    'panda_status' => $pandaVideo['panda_status'] ?? $lesson->panda_status,
                    'google_doc_url' => $file['webViewLink'] ?? $lesson->google_doc_url,
                    'source_status' => $this->sourceStatusForImportedLesson($type, $pandaVideo, $lesson, false),
                    'metadata' => [
                        'source' => 'google_drive',
                        'drive_file_id' => $file['id'] ?? null,
                        'drive_parent_folder_id' => $folderId,
                        'drive_source_folder_id' => $folder['id'] ?? null,
                        'drive_source_folder_path' => $trackName,
                        'drive_mime_type' => $file['mimeType'] ?? null,
                        'drive_modified_time' => $file['modifiedTime'] ?? null,
                        'drive_web_content_link' => $file['webContentLink'] ?? null,
                        'panda_folder_id' => $trackPandaFolderId,
                        'library_folder_name' => $trackName,
                        'library_folder_path' => collect([$module->name, $trackName])->filter()->join(' / '),
                        'import_context_module_id' => $module->id,
                        'import_context_track_id' => $track->id,
                        'panda_upload' => $pandaVideo['payload'] ?? null,
                        'panda_upload_error' => $pandaUploadError,
                        'panda_processing_error' => $pandaVideo['panda_processing_message'] ?? null,
                        'imported_at' => now()->toIso8601String(),
                    ],
                ]);
                $lesson->save();
                if ($pandaVideo && ! $this->pandaVideoIsReady($pandaVideo)) {
                    $this->dispatchPandaStatusSync($lesson, $run);
                }
                $planningLessons[] = $this->planningLessonFromImportedLesson($lesson, $title);
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

        if ($course) {
            $this->syncModulePlanningLessons($module, $planningLessons);
        }

        $this->lessonCourseLinker()->sync($course);

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
            'warnings' => $this->importWarnings,
        ];
    }

    public function importFolderFilesAsLessons(?Course $course, ?CourseModule $module, ?CourseModuleTrack $track, string $folderUrlOrId, string $lessonStatus = 'draft', ?string $pandaFolderName = null, bool $createPandaFolder = true, bool $uploadPandaVideos = true, ?GoogleDriveImportRun $run = null): array
    {
        $this->importWarnings = [];

        if ($track && ! $module) {
            $module = $track->module()->first();
        }

        if ($course && $module) {
            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $module->sort_order],
            ]);
        }

        if ($course && $track) {
            $track->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $track->sort_order],
            ]);
        }

        $folderId = $this->drive->folderIdFromUrl($folderUrlOrId);
        $files = $this->listFilesRecursively($folderId);

        $this->updateRun($run, [
            'folder_id' => $folderId,
            'total_tracks' => 0,
            'total_lessons' => count($files),
            'latest_message' => count($files) > 0
                ? 'Arquivos do Drive carregados.'
                : 'Nenhum arquivo encontrado na pasta do Drive.',
        ]);

        $createdLessons = 0;
        $updatedLessons = 0;
        $pandaFolders = 0;
        $pandaVideosUploaded = 0;
        $pandaVideosSkipped = 0;
        $pandaVideosFailed = 0;

        $modulePandaFolderId = $module?->panda_folder_id;
        $pandaFolderId = $track?->panda_folder_id ?: $modulePandaFolderId;

        if ($createPandaFolder && $module) {
            [$modulePandaFolderId, $moduleFolderCreated] = $this->resolvePandaFolderId($module->name, $modulePandaFolderId);
            $module->forceFill(['panda_folder_id' => $modulePandaFolderId])->save();
            $pandaFolders += $moduleFolderCreated ? 1 : 0;
            $pandaFolderId = $modulePandaFolderId;
        }

        if ($createPandaFolder && $track) {
            [$trackPandaFolderId, $trackFolderCreated] = $this->resolvePandaFolderId($track->name, $track->panda_folder_id, $modulePandaFolderId);
            $track->forceFill(['panda_folder_id' => $trackPandaFolderId])->save();
            $pandaFolders += $trackFolderCreated ? 1 : 0;
            $pandaFolderId = $trackPandaFolderId;
        }

        if ($createPandaFolder && ! $module && ! $track && filled($pandaFolderName)) {
            [$pandaFolderId, $standaloneFolderCreated] = $this->resolvePandaFolderId((string) $pandaFolderName, null);
            $pandaFolders += $standaloneFolderCreated ? 1 : 0;
        }

        foreach ($files as $lessonIndex => $file) {
            $title = $this->lessonTitleFromFile((string) ($file['name'] ?? ''), $lessonIndex + 1);
            $slug = Str::slug($title) ?: 'aula-'.($lessonIndex + 1);
            [$lessonPandaFolderId, $lessonPandaFolderError, $lessonPandaFolderCreated] = $this->resolvePandaFolderIdForStandaloneDriveFile(
                $file,
                $pandaFolderId,
                $module,
                $track,
                $uploadPandaVideos && $this->lessonTypeForMimeType((string) ($file['mimeType'] ?? '')) === 'video',
                $createPandaFolder,
            );
            $pandaFolders += $lessonPandaFolderCreated ? 1 : 0;
            $lesson = $this->lessonQueryForScope($course, $module, $track)
                ->where('slug', $slug)
                ->first()
            ?? $this->resolveReusableDriveLesson($course, $module, $track, $title, $slug, $file)
            ?? new Lesson([
                'course_id' => null,
                'course_module_id' => null,
                'course_module_track_id' => null,
                'slug' => $slug,
            ]);
            $lessonWasCreated = ! $lesson->exists;
            $type = $this->lessonTypeForMimeType((string) ($file['mimeType'] ?? ''));
            $pandaVideo = null;
            $pandaUploadError = $lessonPandaFolderError;
            $pandaUploadQueued = false;

            if ($uploadPandaVideos && $type === 'video' && blank($pandaUploadError)) {
                if (filled($lesson->panda_video_id)) {
                    $pandaVideo = $this->panda->reusableVideo((string) $lesson->panda_video_id, $lessonPandaFolderId);

                    if ($pandaVideo && $this->pandaVideoMatchesExpectedFolder($pandaVideo, $lessonPandaFolderId)) {
                        $pandaVideosSkipped++;
                    } else {
                        $pandaVideo = null;
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
                        $pandaVideo = $this->panda->findVideoByTitle($title, $lessonPandaFolderId);

                        if ($pandaVideo) {
                            $pandaVideosSkipped++;
                            if (! $this->pandaVideoIsReady($pandaVideo)) {
                                $pandaVideosFailed++;
                            }
                        } elseif ($this->shouldQueuePandaUploads()) {
                            $pandaUploadQueued = true;
                            $pandaVideosFailed++;
                        } else {
                            $pandaVideo = $this->uploadDriveVideoToPanda($file, $title, $lessonPandaFolderId);
                            if (($pandaVideo['was_reused'] ?? false) === true) {
                                $pandaVideosSkipped++;
                            } else {
                                $pandaVideosUploaded++;
                            }
                            if (! $this->pandaVideoIsReady($pandaVideo)) {
                                $pandaVideosFailed++;
                            }
                            $this->pauseAfterPandaUploadIfConfigured();
                        }
                    } catch (\Throwable $exception) {
                        $pandaUploadError = $exception->getMessage();
                        $pandaVideosFailed++;
                    }
                }
            } elseif ($uploadPandaVideos && $type === 'video' && filled($pandaUploadError)) {
                $pandaVideosFailed++;
            }

            $lesson->fill([
                'course_id' => null,
                'course_module_id' => null,
                'course_module_track_id' => null,
                'title' => $title,
                'slug' => $lesson->exists ? $lesson->slug : $slug,
                'description' => 'Aula adicionada à plataforma.',
                'type' => $type,
                'thumbnail_url' => $file['thumbnailLink'] ?? $lesson->thumbnail_url,
                'duration_seconds' => $this->durationSecondsFromPandaVideo($pandaVideo, $lesson),
                'sort_order' => $lessonIndex + 1,
                'status' => $lessonStatus,
                'panda_video_id' => $pandaVideo['panda_video_id'] ?? $lesson->panda_video_id,
                'panda_embed_url' => $pandaVideo['panda_embed_url'] ?? $lesson->panda_embed_url,
                'panda_player_url' => $pandaVideo['panda_player_url'] ?? $lesson->panda_player_url,
                'panda_status' => $pandaVideo['panda_status'] ?? $lesson->panda_status,
                'google_doc_url' => $file['webViewLink'] ?? $lesson->google_doc_url,
                'source_status' => $this->sourceStatusForImportedLesson($type, $pandaVideo, $lesson, $pandaUploadQueued),
                'metadata' => [
                    'source' => 'google_drive',
                    'drive_file_id' => $file['id'] ?? null,
                    'drive_parent_folder_id' => $folderId,
                    'drive_source_folder_id' => $file['_source_folder_id'] ?? null,
                    'drive_source_folder_path' => $file['_source_folder_path'] ?? null,
                    'drive_mime_type' => $file['mimeType'] ?? null,
                    'drive_modified_time' => $file['modifiedTime'] ?? null,
                    'drive_web_content_link' => $file['webContentLink'] ?? null,
                    'panda_folder_id' => $lessonPandaFolderId,
                    'library_folder_name' => $this->sourceFolderNameForPath((string) ($file['_source_folder_path'] ?? '')) ?: ($track?->name ?? $module?->name ?? $pandaFolderName),
                    'library_folder_path' => collect([$module?->name, $track?->name, $file['_source_folder_path'] ?? null])->filter()->join(' / '),
                    'import_context_module_id' => $module?->id,
                    'import_context_track_id' => $track?->id,
                    'panda_upload' => $pandaVideo['payload'] ?? null,
                    'panda_upload_error' => $pandaUploadError,
                    'panda_processing_error' => $pandaVideo['panda_processing_message'] ?? null,
                    'panda_upload_queued_at' => $pandaUploadQueued ? now()->toIso8601String() : null,
                    'panda_upload_run_id' => $pandaUploadQueued ? $run?->id : null,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
            $lesson->save();

            if ($pandaUploadQueued) {
                $this->dispatchPandaUpload($lesson, $run);
            } elseif ($pandaVideo && ! $this->pandaVideoIsReady($pandaVideo)) {
                $this->dispatchPandaStatusSync($lesson, $run);
            }

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

        $this->lessonCourseLinker()->sync($course);

        return [
            'folder_id' => $folderId,
            'tracks' => 0,
            'created_tracks' => 0,
            'updated_tracks' => 0,
            'total_lessons' => count($files),
            'warnings' => $this->importWarnings,
            'panda_folders' => $pandaFolders,
            'panda_videos_uploaded' => $pandaVideosUploaded,
            'panda_videos_skipped' => $pandaVideosSkipped,
            'panda_videos_failed' => $pandaVideosFailed,
            'created_lessons' => $createdLessons,
            'updated_lessons' => $updatedLessons,
        ];
    }

    protected function listFilesRecursively(string $folderId, string $folderPath = ''): array
    {
        $files = collect($this->sortNaturally($this->guardedDriveList(
            fn (): array => $this->drive->listFiles($folderId),
            $folderPath === '' ? 'pasta raiz' : $folderPath,
        )))
            ->map(function (array $file) use ($folderId, $folderPath): array {
                return [
                    ...$file,
                    '_source_folder_id' => $folderId,
                    '_source_folder_path' => $folderPath,
                ];
            })
            ->all();

        foreach ($this->sortNaturally($this->guardedDriveList(
            fn (): array => $this->drive->listFolders($folderId),
            $folderPath === '' ? 'subpastas da pasta raiz' : 'subpastas de '.$folderPath,
        )) as $folder) {
            $childFolderId = (string) ($folder['id'] ?? '');

            if ($childFolderId === '') {
                continue;
            }

            $childFolderName = trim((string) ($folder['name'] ?? ''));
            $childFolderPath = trim($folderPath.'/'.$childFolderName, '/');

            $files = array_merge($files, $this->listFilesRecursively($childFolderId, $childFolderPath));
        }

        return $this->sortNaturally($files);
    }

    protected function guardedDriveList(callable $callback, string $context): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->importWarnings[] = 'Não foi possível ler '.$context.': '.$exception->getMessage();

            return [];
        }
    }

    protected function resolvePandaFolderIdForStandaloneDriveFile(array $file, ?string $defaultPandaFolderId, ?CourseModule $module, ?CourseModuleTrack $track, bool $requiresPandaFolder, bool $createMissingPandaFolder = true): array
    {
        if ($module || $track || filled($defaultPandaFolderId)) {
            return [$defaultPandaFolderId, null, false];
        }

        if (! $requiresPandaFolder) {
            return [null, null, false];
        }

        $sourceFolderName = $this->sourceFolderNameForFile($file);

        if ($sourceFolderName === '') {
            return [null, 'Arquivo do Drive sem subpasta de origem; upload na raiz do Panda foi bloqueado.', false];
        }

        $folder = $this->findPandaFolderForDrivePath((string) ($file['_source_folder_path'] ?? ''));

        if ($folder && filled($folder['panda_folder_id'] ?? null)) {
            return [(string) $folder['panda_folder_id'], null, false];
        }

        if (! $createMissingPandaFolder) {
            return [null, "Pasta Panda não encontrada para a subpasta do Drive: {$sourceFolderName}. Upload na raiz bloqueado.", false];
        }

        $folder = $this->panda->createFolder($sourceFolderName);

        if (blank($folder['panda_folder_id'] ?? null)) {
            return [null, "O Panda criou a pasta {$sourceFolderName}, mas não retornou o ID da pasta.", false];
        }

        return [(string) $folder['panda_folder_id'], null, true];
    }

    protected function findPandaFolderForDrivePath(string $path): ?array
    {
        foreach ($this->pandaFolderNameCandidatesForDrivePath($path) as $candidate) {
            $folder = $this->panda->findFolderByName($candidate);

            if ($folder && filled($folder['panda_folder_id'] ?? null)) {
                return $folder;
            }
        }

        return null;
    }

    protected function pandaFolderNameCandidatesForDrivePath(string $path): array
    {
        $parts = array_values(array_filter(
            explode('/', trim($path, '/')),
            fn (string $part): bool => trim($part) !== '',
        ));

        $candidates = [];

        for ($index = count($parts) - 1; $index >= 0; $index--) {
            $part = trim($parts[$index]);

            if ($part === '') {
                continue;
            }

            $candidates[] = $part;
            $withoutNumericPrefix = trim((string) preg_replace('/^\d+\s*[-_.]\s*/', '', $part));

            if ($withoutNumericPrefix !== '' && $withoutNumericPrefix !== $part) {
                $candidates[] = $withoutNumericPrefix;
            }
        }

        return collect($candidates)->unique(fn (string $name): string => $this->normalizeImportName($name))->values()->all();
    }

    protected function sourceFolderNameForFile(array $file): string
    {
        return $this->sourceFolderNameForPath((string) ($file['_source_folder_path'] ?? ''));
    }

    protected function sourceFolderNameForPath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $parts = array_values(array_filter(explode('/', $path), fn (string $part): bool => trim($part) !== ''));

        return trim((string) end($parts));
    }

    protected function planningLessonFromImportedLesson(Lesson $lesson, string $title): array
    {
        return [
            'name' => $title,
            'minutes' => max(1, (int) $lesson->duration_minutes),
        ];
    }

    protected function syncModulePlanningLessons(CourseModule $module, array $planningLessons): void
    {
        if ($planningLessons === []) {
            return;
        }

        $module->forceFill([
            'lessons' => array_values($planningLessons),
            'workload_minutes' => array_sum(array_column($planningLessons, 'minutes')),
        ])->save();
    }

    protected function lessonQueryForScope(?Course $course, ?CourseModule $module, ?CourseModuleTrack $track): Builder
    {
        return Lesson::query();
    }

    protected function resolveReusableDriveLesson(?Course $course, ?CourseModule $module, ?CourseModuleTrack $track, string $title, string $slug, array $file): ?Lesson
    {
        $driveFileId = (string) ($file['id'] ?? '');

        if ($driveFileId !== '') {
            $lesson = Lesson::query()
                ->orderBy('id')
                ->get()
                ->first(function (Lesson $lesson) use ($driveFileId): bool {
                    $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

                    return ($metadata['source'] ?? null) === 'google_drive'
                        && ($metadata['drive_file_id'] ?? null) === $driveFileId;
                });

            if ($lesson) {
                return $lesson;
            }
        }

        $normalizedTitle = LessonTitleNormalizer::matchKey($title);

        if ($normalizedTitle === '') {
            return null;
        }

        return Lesson::query()
            ->orderByRaw("case when (panda_video_id is null and panda_embed_url is null and panda_player_url is null and coalesce(source_status, '') in ('', 'awaiting_media', 'upload_queued', 'upload_failed')) then 0 else 1 end")
            ->orderBy('id')
            ->get()
            ->first(function (Lesson $lesson) use ($normalizedTitle, $slug): bool {
                return $lesson->slug === $slug
                    || LessonTitleNormalizer::matchKey($lesson->title) === $normalizedTitle
                    || LessonTitleNormalizer::matchKey(pathinfo($lesson->title, PATHINFO_FILENAME)) === $normalizedTitle;
            });
    }

    public function reprocessPendingLessonsForRun(GoogleDriveImportRun $sourceRun, ?GoogleDriveImportRun $run = null): array
    {
        $folderId = $sourceRun->folder_id ?: $this->drive->folderIdFromUrl($sourceRun->folder_url);
        $lessons = $this->pendingLessonsForFolder($folderId);

        $this->updateRun($run, [
            'folder_id' => $folderId,
            'total_tracks' => 0,
            'total_lessons' => $lessons->count(),
            'latest_message' => $lessons->isEmpty()
                ? 'Nenhuma aula pendente encontrada para reprocessar.'
                : 'Aulas pendentes carregadas para reprocessamento.',
        ]);

        $pandaVideosUploaded = 0;
        $pandaVideosSkipped = 0;
        $pandaVideosFailed = 0;
        $pandaFolders = 0;

        foreach ($lessons->values() as $index => $lesson) {
            $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
            [$pandaFolderId, $pandaFolderError, $pandaFolderCreated] = $this->resolvePandaFolderIdForPendingLesson($lesson);
            $pandaFolders += $pandaFolderCreated ? 1 : 0;
            $pandaVideo = null;
            $pandaUploadError = $pandaFolderError;
            $pandaUploadQueued = false;

            if (blank($pandaUploadError) && ! $this->lessonPandaFolderMatches($lesson, $pandaFolderId)) {
                $pandaVideo = null;
            }

            if (blank($pandaUploadError) && filled($lesson->panda_video_id)) {
                $pandaVideo = $this->panda->reusableVideo((string) $lesson->panda_video_id, $pandaFolderId);

                if ($pandaVideo && $this->pandaVideoMatchesExpectedFolder($pandaVideo, $pandaFolderId)) {
                    $pandaVideosSkipped++;
                } else {
                    $pandaVideo = null;
                    $lesson->forceFill([
                        'panda_video_id' => null,
                        'panda_embed_url' => null,
                        'panda_player_url' => null,
                        'panda_status' => null,
                    ]);
                }
            }

            if (! $pandaVideo && blank($pandaUploadError)) {
                try {
                    $pandaVideo = $this->panda->findVideoByTitle($lesson->title, $pandaFolderId);

                    if ($pandaVideo) {
                        $pandaVideosSkipped++;
                        if (! $this->pandaVideoIsReady($pandaVideo)) {
                            $pandaVideosFailed++;
                        }
                    } elseif ($this->shouldQueuePandaUploads()) {
                        $pandaUploadQueued = true;
                        $pandaVideosFailed++;
                    } else {
                        $pandaVideo = $this->uploadDriveVideoToPanda($this->driveFileForLesson($lesson), $lesson->title, $pandaFolderId);

                        if (($pandaVideo['was_reused'] ?? false) === true) {
                            $pandaVideosSkipped++;
                        } else {
                            $pandaVideosUploaded++;
                        }
                        if (! $this->pandaVideoIsReady($pandaVideo)) {
                            $pandaVideosFailed++;
                        }

                        $this->pauseAfterPandaUploadIfConfigured();
                    }
                } catch (\Throwable $exception) {
                    $pandaUploadError = $exception->getMessage();
                    $pandaVideosFailed++;
                }
            } elseif (filled($pandaUploadError)) {
                $pandaVideosFailed++;
            }

            $lesson->forceFill([
                'panda_video_id' => $pandaVideo['panda_video_id'] ?? $lesson->panda_video_id,
                'panda_embed_url' => $pandaVideo['panda_embed_url'] ?? $lesson->panda_embed_url,
                'panda_player_url' => $pandaVideo['panda_player_url'] ?? $lesson->panda_player_url,
                'panda_status' => $pandaVideo['panda_status'] ?? $lesson->panda_status,
                'duration_seconds' => $this->durationSecondsFromPandaVideo($pandaVideo, $lesson),
                'source_status' => $this->sourceStatusForImportedLesson('video', $pandaVideo, $lesson, $pandaUploadQueued),
                'metadata' => [
                    ...$metadata,
                    'panda_upload' => $pandaVideo['payload'] ?? ($metadata['panda_upload'] ?? null),
                    'panda_upload_error' => $pandaUploadError,
                    'panda_processing_error' => $pandaVideo['panda_processing_message'] ?? null,
                    'panda_upload_queued_at' => $pandaUploadQueued ? now()->toIso8601String() : ($metadata['panda_upload_queued_at'] ?? null),
                    'panda_upload_run_id' => $pandaUploadQueued ? $run?->id : ($metadata['panda_upload_run_id'] ?? null),
                    'reprocessed_at' => now()->toIso8601String(),
                    'reprocessed_from_run_id' => $sourceRun->id,
                ],
            ])->save();

            if ($pandaUploadQueued) {
                $this->dispatchPandaUpload($lesson, $run);
            } elseif ($pandaVideo && ! $this->pandaVideoIsReady($pandaVideo)) {
                $this->dispatchPandaStatusSync($lesson, $run);
            }

            $this->updateRun($run, [
                'processed_lessons' => $index + 1,
                'panda_videos_uploaded' => $pandaVideosUploaded,
                'panda_videos_skipped' => $pandaVideosSkipped,
                'panda_videos_failed' => $pandaVideosFailed,
                'latest_message' => $pandaUploadError
                    ? 'Aula ainda pendente no Panda: '.$lesson->title
                    : 'Aula reprocessada: '.$lesson->title,
            ]);
        }

        return [
            'folder_id' => $folderId,
            'tracks' => 0,
            'created_tracks' => 0,
            'updated_tracks' => 0,
            'total_lessons' => $lessons->count(),
            'panda_folders' => $pandaFolders,
            'panda_videos_uploaded' => $pandaVideosUploaded,
            'panda_videos_skipped' => $pandaVideosSkipped,
            'panda_videos_failed' => $pandaVideosFailed,
            'created_lessons' => 0,
            'updated_lessons' => $lessons->count(),
            'reprocessed_from_run_id' => $sourceRun->id,
        ];
    }

    protected function pendingLessonsForFolder(string $folderId): Collection
    {
        return Lesson::query()
            ->whereIn('source_status', ['awaiting_media', 'upload_queued', 'uploading', 'upload_failed', 'panda_processing'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(function (Lesson $lesson) use ($folderId): bool {
                $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

                return ($metadata['source'] ?? null) === 'google_drive'
                    && ($metadata['drive_parent_folder_id'] ?? null) === $folderId
                    && filled($metadata['drive_file_id'] ?? null)
                    && str_starts_with((string) ($metadata['drive_mime_type'] ?? ''), 'video/');
            })
            ->values();
    }

    protected function driveFileForLesson(Lesson $lesson): array
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $driveFileId = (string) ($metadata['drive_file_id'] ?? '');

        if ($driveFileId === '') {
            throw new \RuntimeException("A aula {$lesson->title} não possui ID do arquivo no Google Drive para reprocessamento.");
        }

        return [
            'id' => $driveFileId,
            'name' => $lesson->title.'.mp4',
            'mimeType' => $metadata['drive_mime_type'] ?? 'video/mp4',
            'webViewLink' => $lesson->google_doc_url,
            'webContentLink' => $metadata['drive_web_content_link'] ?? null,
            'modifiedTime' => $metadata['drive_modified_time'] ?? null,
        ];
    }

    protected function resolvePandaFolderIdForPendingLesson(Lesson $lesson): array
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        if (filled($metadata['panda_folder_id'] ?? null)) {
            return [(string) $metadata['panda_folder_id'], null, false];
        }

        $sourceFolderName = $this->sourceFolderNameForPath((string) ($metadata['drive_source_folder_path'] ?? ''));

        if ($sourceFolderName === '') {
            return [null, 'Aula sem pasta Panda e sem subpasta de origem; upload na raiz do Panda foi bloqueado.', false];
        }

        $folder = $this->findPandaFolderForDrivePath((string) ($metadata['drive_source_folder_path'] ?? ''));

        if ($folder && filled($folder['panda_folder_id'] ?? null)) {
            return [(string) $folder['panda_folder_id'], null, false];
        }

        $folder = $this->panda->createFolder($sourceFolderName);

        if (blank($folder['panda_folder_id'] ?? null)) {
            return [null, "O Panda criou a pasta {$sourceFolderName}, mas não retornou o ID da pasta.", false];
        }

        return [(string) $folder['panda_folder_id'], null, true];
    }

    protected function lessonPandaFolderMatches(Lesson $lesson, ?string $expectedPandaFolderId): bool
    {
        if (blank($expectedPandaFolderId)) {
            return true;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $currentPandaFolderId = filled($metadata['panda_folder_id'] ?? null) ? (string) $metadata['panda_folder_id'] : null;

        return $currentPandaFolderId === (string) $expectedPandaFolderId;
    }

    protected function pandaVideoMatchesExpectedFolder(array $video, ?string $expectedPandaFolderId): bool
    {
        if (blank($expectedPandaFolderId)) {
            return true;
        }

        $actualFolderId = (string) ($video['folder_id'] ?? '');

        return $actualFolderId === '' || $actualFolderId === (string) $expectedPandaFolderId;
    }

    protected function pandaVideoIsReady(array $video): bool
    {
        $status = strtoupper((string) ($video['panda_status'] ?? ''));

        if (in_array($status, ['CONVERTED', 'READY', 'AVAILABLE', 'ACTIVE', 'PUBLISHED'], true)) {
            return true;
        }

        return filled($video['panda_video_id'])
            && (filled($video['panda_embed_url'] ?? null) || filled($video['panda_player_url'] ?? null))
            && (int) ($video['duration_seconds'] ?? 0) > 0
            && ! in_array($status, ['ERROR', 'FAILED', 'DELETED'], true);
    }

    protected function durationSecondsFromPandaVideo(?array $pandaVideo, Lesson $lesson): int
    {
        $duration = $pandaVideo['duration_seconds'] ?? null;

        if (is_numeric($duration) && (int) $duration > 0) {
            return (int) $duration;
        }

        return (int) ($lesson->duration_seconds ?: 0);
    }

    public function uploadQueuedLessonToPanda(Lesson $lesson, ?GoogleDriveImportRun $run = null): array
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $pandaFolderId = filled($metadata['panda_folder_id'] ?? null)
            ? (string) $metadata['panda_folder_id']
            : $this->resolvePandaFolderIdForPendingLesson($lesson)[0];

        if (blank($pandaFolderId)) {
            throw new \RuntimeException('Aula sem pasta Panda resolvida; upload na raiz do Panda foi bloqueado.');
        }

        $lesson->forceFill([
            'source_status' => 'uploading',
            'metadata' => [
                ...$metadata,
                'panda_upload_started_at' => now()->toIso8601String(),
                'panda_upload_error' => null,
            ],
        ])->save();

        $pandaVideo = null;
        $wasReused = false;

        if (filled($lesson->panda_video_id)) {
            $pandaVideo = $this->panda->reusableVideo((string) $lesson->panda_video_id, $pandaFolderId);
            $wasReused = (bool) $pandaVideo;
        }

        if (! $pandaVideo) {
            $pandaVideo = $this->panda->findVideoByTitle($lesson->title, $pandaFolderId);
            $wasReused = (bool) $pandaVideo;
        }

        if (! $pandaVideo) {
            $pandaVideo = $this->uploadDriveVideoToPanda($this->driveFileForLesson($lesson), $lesson->title, $pandaFolderId);
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $lesson->forceFill([
            'panda_video_id' => $pandaVideo['panda_video_id'] ?? $lesson->panda_video_id,
            'panda_embed_url' => $pandaVideo['panda_embed_url'] ?? $lesson->panda_embed_url,
            'panda_player_url' => $pandaVideo['panda_player_url'] ?? $lesson->panda_player_url,
            'panda_status' => $pandaVideo['panda_status'] ?? $lesson->panda_status,
            'duration_seconds' => $this->durationSecondsFromPandaVideo($pandaVideo, $lesson),
            'source_status' => $this->pandaVideoIsReady($pandaVideo) ? 'media_ready' : 'panda_processing',
            'metadata' => [
                ...$metadata,
                'panda_folder_id' => $pandaFolderId,
                'panda_upload' => $pandaVideo['payload'] ?? ($metadata['panda_upload'] ?? null),
                'panda_upload_error' => null,
                'panda_processing_error' => $pandaVideo['panda_processing_message'] ?? null,
                'panda_upload_completed_at' => now()->toIso8601String(),
                'panda_status_sync_queued_at' => $this->pandaVideoIsReady($pandaVideo) ? null : now()->toIso8601String(),
            ],
        ])->save();

        if ($run) {
            $updates = [
                'latest_message' => ($wasReused ? 'Vídeo Panda reaproveitado: ' : 'Upload Panda concluído: ').$lesson->title,
                'error_message' => null,
                'updated_at' => now(),
            ];

            if ($wasReused) {
                $updates['panda_videos_skipped'] = DB::raw('panda_videos_skipped + 1');
            } else {
                $updates['panda_videos_uploaded'] = DB::raw('panda_videos_uploaded + 1');
            }

            if ($this->pandaVideoIsReady($pandaVideo) && $run->panda_videos_failed > 0) {
                $updates['panda_videos_failed'] = DB::raw('panda_videos_failed - 1');
            }

            GoogleDriveImportRun::query()->whereKey($run->id)->update($updates);
            $run->refresh();
        }

        return [
            ...$pandaVideo,
            'was_reused' => $wasReused,
        ];
    }

    protected function normalizeImportName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
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

    protected function shouldQueuePandaUploads(): bool
    {
        return (bool) config('services.panda.queue_drive_uploads', false);
    }

    protected function dispatchPandaUpload(Lesson $lesson, ?GoogleDriveImportRun $run): void
    {
        UploadLessonToPanda::dispatch($lesson->id, $run?->id);
    }

    protected function dispatchPandaStatusSync(Lesson $lesson, ?GoogleDriveImportRun $run): void
    {
        SyncPandaVideoStatus::dispatch($lesson->id, $run?->id)
            ->delay(now()->addSeconds(max(0, (int) config('services.panda.video_status_sync_delay_seconds', 300))))
            ->afterResponse();
    }

    protected function sourceStatusForImportedLesson(string $type, ?array $pandaVideo, Lesson $lesson, bool $pandaUploadQueued): string
    {
        if ($pandaUploadQueued) {
            return 'upload_queued';
        }

        if ($type === 'pdf') {
            return 'media_ready';
        }

        if ($pandaVideo) {
            return $this->pandaVideoIsReady($pandaVideo) ? 'media_ready' : 'panda_processing';
        }

        if (filled($lesson->panda_video_id) && $lesson->source_status === 'media_ready') {
            return 'media_ready';
        }

        return 'awaiting_media';
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

        return $title !== ''
            ? LessonTitleNormalizer::normalizePreservingNumber($title, $fallbackIndex)
            : LessonTitleNormalizer::normalize('Aula '.$fallbackIndex, $fallbackIndex);
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

    protected function lessonCourseLinker(): LessonCourseLinker
    {
        return $this->lessonCourseLinker ??= app(LessonCourseLinker::class);
    }
}
