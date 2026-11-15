<?php

namespace App\Services;

use App\Jobs\SyncPandaVideoStatus;
use App\Models\AiArtifact;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\PandaImportRun;
use App\Models\Video;
use App\Support\LessonTitleNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PandaCourseImporter
{
    public function __construct(
        protected PandaVideoClient $client,
        protected LessonCourseLinker $lessonCourseLinker,
    ) {}

    public function importVideos(?CourseModule $module, ?CourseModuleTrack $track, string $folderId, ?PandaImportRun $run = null): PandaImportRun
    {
        $folderReference = $folderId;
        $folderId = $this->client->resolveFolderReference($folderId);

        if ($track && ! $module) {
            $module = $track->module()->first();
        }

        $run ??= PandaImportRun::create([
            'course_id' => null,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'course_id' => null,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => $run->started_at ?: now(),
        ])->save();

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));
            $stats = $this->emptyImportStats();
            $libraryFolderPath = collect([$module?->name, $track?->name])->filter()->join(' / ');
            $lessonFolder = LessonFolder::findOrCreatePath($libraryFolderPath, ['source' => 'panda']);

            foreach ($videos->values() as $index => $pandaVideo) {
                $sortOrder = $index + 1;
                $normalizedTitle = LessonTitleNormalizer::normalize($pandaVideo['title'], $sortOrder);
                $providerVideoId = filled($pandaVideo['panda_video_id']) ? (string) $pandaVideo['panda_video_id'] : null;

                if ($this->client->videoIsFailed($pandaVideo)) {
                    $this->recordSkippedVideo($run, $pandaVideo, 'video', $normalizedTitle);
                    $stats['skipped']++;

                    continue;
                }

                try {
                    [$video, $wasRecentlyCreated] = DB::transaction(function () use ($pandaVideo, $normalizedTitle, $sortOrder, $providerVideoId, $lessonFolder, $module, $track, $folderId, $folderReference, $libraryFolderPath): array {
                        $video = $this->resolveVideoForPandaVideo($pandaVideo, $normalizedTitle);
                        $wasRecentlyCreated = ! $video->exists;
                        $metadata = is_array($video->metadata) ? $video->metadata : [];
                        $durationSeconds = (int) ($pandaVideo['duration_seconds'] ?? 0);

                        $video->fill([
                            'lesson_folder_id' => $lessonFolder?->id,
                            'title' => $normalizedTitle,
                            'slug' => $video->exists ? $video->slug : $this->lessonSlug($normalizedTitle, $sortOrder),
                            'description' => $pandaVideo['description'] ?: ($video->description ?: 'Vídeo importado do Panda.'),
                            'provider' => 'panda',
                            'provider_video_id' => $providerVideoId,
                            'provider_status' => $pandaVideo['panda_status'],
                            'embed_url' => $pandaVideo['panda_embed_url'],
                            'player_url' => $pandaVideo['panda_player_url'],
                            'thumbnail_url' => $pandaVideo['thumbnail_url'] ?: $video->thumbnail_url,
                            'duration_seconds' => $durationSeconds > 0 ? $durationSeconds : (int) $video->duration_seconds,
                            'source_status' => $this->sourceStatusForPandaVideo($pandaVideo),
                            'metadata' => array_replace_recursive($metadata, [
                                'source' => 'panda',
                                'folder_id' => $folderId,
                                'folder_reference' => $folderReference,
                                'library_folder_name' => $track?->name ?? $module?->name,
                                'library_folder_path' => $libraryFolderPath,
                                'import_context_module_id' => $module?->id,
                                'import_context_track_id' => $track?->id,
                                'payload' => $pandaVideo['payload'],
                                'last_imported_at' => now()->toIso8601String(),
                            ]),
                        ]);
                        $video->save();

                        $this->syncPandaVideoAiArtifacts($video, $pandaVideo['ai_artifacts'] ?? [], $pandaVideo['payload']);

                        return [$video, $wasRecentlyCreated];
                    });
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordFailedVideo($run, $pandaVideo, 'video', $normalizedTitle, $exception);
                    $stats['failed']++;
                    $stats['errors'][] = ['title' => $normalizedTitle, 'message' => $exception->getMessage()];

                    continue;
                }

                $run->items()->create([
                    'external_type' => 'video',
                    'external_id' => $providerVideoId ?: $normalizedTitle,
                    'local_type' => 'video',
                    'local_id' => $video->id,
                    'status' => $wasRecentlyCreated ? 'created' : 'updated',
                    'payload' => $pandaVideo['payload'],
                ]);

                $wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;

                if ($video->source_status === 'panda_processing') {
                    $stats['processing']++;
                }
            }

            if ($module) {
                $module->forceFill(['panda_folder_id' => $folderId])->save();
            }

            if ($track) {
                $track->forceFill(['panda_folder_id' => $folderId])->save();
            }

            $this->finishRun($run, $videos, $stats, [
                'module_id' => $module?->id,
                'track_id' => $track?->id,
            ]);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }

        return $run->fresh(['items']);
    }

    public function importFolder(Course $course, string $folderId, ?string $moduleName = null, string $lessonStatus = 'draft', string $moduleType = 'specific'): PandaImportRun
    {
        $folderReference = $folderId;
        $folderId = $this->client->resolveFolderReference($folderId);

        $run = PandaImportRun::create([
            'course_id' => $course->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            [$module, $track] = DB::transaction(function () use ($course, $folderId, $folderReference, $moduleName, $moduleType, $videos): array {
                $resolvedModuleName = $moduleName
                    ?: (string) ($videos->first()['folder_name'] ?? null)
                    ?: 'Pasta de vídeos '.$folderId;

                $module = CourseModule::updateOrCreate(
                    [
                        'panda_folder_id' => $folderId,
                    ],
                    [
                        'course_id' => null,
                        'name' => $resolvedModuleName,
                        'type' => $this->normalizeModuleType($moduleType),
                        'workload_minutes' => (int) ceil($videos->sum('duration_seconds') / 60),
                        'sort_order' => (int) ($course->modules()->max('course_modules.sort_order') + 1),
                        'is_active' => true,
                        'metadata' => [
                            'source' => 'panda',
                            'folder_reference' => $folderReference,
                            'last_imported_at' => now()->toIso8601String(),
                        ],
                    ],
                );

                $track = $this->ensureTrackForModule($module, $course, $resolvedModuleName, $folderId);
                $module->courses()->syncWithoutDetaching([
                    $course->id => ['sort_order' => (int) $module->sort_order],
                ]);

                return [$module, $track];
            });

            $result = $this->importPandaVideosAsLessons($videos, $run, $course, $module, null, $track, $folderId, null, $lessonStatus);

            $this->syncModulePlanningLessons($module, $result['planning_lessons']);
            $this->finishRun($run, $videos, $result['stats'], ['module_id' => $module->id]);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }

        $this->lessonCourseLinker->sync($course);
        $this->queueStatusSyncForProcessingLessons($result['processing_lesson_ids']);

        return $run->fresh(['items']);
    }

    public function importIntoModule(CourseModule $module, string $folderId, string $lessonStatus = 'draft', ?string $moduleType = null, ?Course $course = null): PandaImportRun
    {
        $folderReference = $folderId;
        $folderId = $this->client->resolveFolderReference($folderId);

        $run = PandaImportRun::create([
            'course_id' => $course?->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            $track = DB::transaction(function () use ($module, $course, $folderId, $folderReference, $moduleType, $videos): CourseModuleTrack {
                $module->forceFill([
                    'panda_folder_id' => $folderId,
                    'type' => $moduleType ? $this->normalizeModuleType($moduleType) : $module->type,
                    'workload_minutes' => (int) ceil($videos->sum('duration_seconds') / 60),
                    'metadata' => [
                        'source' => 'panda',
                        'folder_reference' => $folderReference,
                        'last_imported_at' => now()->toIso8601String(),
                    ],
                ])->save();

                return $this->ensureTrackForModule($module, $course, $module->name, $folderId);
            });

            $result = $this->importPandaVideosAsLessons($videos, $run, $course, $module, null, $track, $folderId, null, $lessonStatus);

            $this->syncModulePlanningLessons($module, $result['planning_lessons']);
            $this->finishRun($run, $videos, $result['stats'], ['module_id' => $module->id]);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }

        $this->lessonCourseLinker->sync($course ?? $module->courses()->first());
        $this->queueStatusSyncForProcessingLessons($result['processing_lesson_ids']);

        return $run->fresh(['items']);
    }

    public function importLessons(?Course $course, ?CourseModule $module, ?CourseModuleTrack $track, string $folderId, string $lessonStatus = 'draft', ?PandaImportRun $run = null): PandaImportRun
    {
        $folderReference = $folderId;
        $folderId = $this->client->resolveFolderReference($folderId);

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

        $run ??= PandaImportRun::create([
            'course_id' => $course?->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'course_id' => $course?->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => $run->started_at ?: now(),
        ])->save();

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            $result = $this->importPandaVideosAsLessons($videos, $run, $course, $module, $track, $track, $folderId, $folderReference, $lessonStatus);

            if ($module) {
                $module->forceFill(['panda_folder_id' => $folderId])->save();
                $this->syncModulePlanningLessons($module, $result['planning_lessons']);
            }

            if ($track) {
                $track->forceFill(['panda_folder_id' => $folderId])->save();
            }

            $this->finishRun($run, $videos, $result['stats'], [
                'course_id' => $course?->id,
                'module_id' => $module?->id,
                'track_id' => $track?->id,
            ]);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }

        $this->lessonCourseLinker->sync($course);
        $this->queueStatusSyncForProcessingLessons($result['processing_lesson_ids']);

        return $run->fresh(['items']);
    }

    /**
     * Texto curto com o resultado de uma importação, usado nas notificações do admin.
     */
    public static function summaryMessage(PandaImportRun $run): string
    {
        $summary = is_array($run->summary) ? $run->summary : [];

        $parts = [
            'Vídeos: '.($summary['videos'] ?? 0),
            'Criados: '.($summary['created'] ?? 0),
            'Atualizados: '.($summary['updated'] ?? 0),
        ];

        if (($summary['processing'] ?? 0) > 0) {
            $parts[] = 'Ainda processando no Panda: '.$summary['processing'];
        }

        if (($summary['skipped'] ?? 0) > 0) {
            $parts[] = 'Ignorados (falha no Panda): '.$summary['skipped'];
        }

        if (($summary['failed'] ?? 0) > 0) {
            $parts[] = 'Com erro: '.$summary['failed'];
        }

        $message = implode('. ', $parts).'.';

        $errors = collect($summary['errors'] ?? [])->take(3);

        if ($errors->isNotEmpty()) {
            $message .= ' '.$errors->map(fn (array $error): string => ($error['title'] ?? 'Vídeo').': '.($error['message'] ?? ''))->join(' | ');
        }

        return $message;
    }

    /**
     * Importa cada vídeo como aula de forma isolada: um vídeo com problema
     * não desfaz nem bloqueia a importação dos demais.
     *
     * @return array{stats: array<string, mixed>, planning_lessons: array<int, array>, processing_lesson_ids: array<int, int>}
     */
    protected function importPandaVideosAsLessons(
        Collection $videos,
        PandaImportRun $run,
        ?Course $course,
        ?CourseModule $module,
        ?CourseModuleTrack $matchTrack,
        ?CourseModuleTrack $libraryTrack,
        string $folderId,
        ?string $folderReference,
        string $lessonStatus,
    ): array {
        $stats = $this->emptyImportStats();
        $planningLessons = [];
        $processingLessonIds = [];
        $libraryFolderPath = collect([$module?->name, $libraryTrack?->name])->filter()->join(' / ');
        $lessonFolder = LessonFolder::findOrCreatePath($libraryFolderPath, ['source' => 'panda']);

        foreach ($videos->values() as $index => $video) {
            $sortOrder = $index + 1;
            $normalizedTitle = LessonTitleNormalizer::normalize($video['title'], $sortOrder);

            if ($this->client->videoIsFailed($video)) {
                $this->recordSkippedVideo($run, $video, 'lesson', $normalizedTitle);
                $stats['skipped']++;

                continue;
            }

            try {
                [$lesson, $wasRecentlyCreated] = DB::transaction(fn (): array => $this->upsertLessonFromPandaVideo(
                    $video,
                    $sortOrder,
                    $normalizedTitle,
                    $course,
                    $module,
                    $matchTrack,
                    $libraryTrack,
                    $lessonFolder,
                    $libraryFolderPath,
                    $folderId,
                    $folderReference,
                    $lessonStatus,
                ));
            } catch (Throwable $exception) {
                report($exception);
                $this->recordFailedVideo($run, $video, 'lesson', $normalizedTitle, $exception);
                $stats['failed']++;
                $stats['errors'][] = ['title' => $normalizedTitle, 'message' => $exception->getMessage()];

                continue;
            }

            if ($module) {
                $planningLessons[] = $this->planningLessonFromVideo($video, $index, $normalizedTitle);
            }

            $run->items()->create([
                'external_type' => 'video',
                'external_id' => $video['panda_video_id'],
                'local_type' => 'lesson',
                'local_id' => $lesson->id,
                'status' => $wasRecentlyCreated ? 'created' : 'updated',
                'payload' => $video['payload'],
            ]);

            $wasRecentlyCreated ? $stats['created']++ : $stats['updated']++;

            if ($lesson->source_status === 'panda_processing') {
                $stats['processing']++;
                $processingLessonIds[] = (int) $lesson->id;
            }
        }

        return [
            'stats' => $stats,
            'planning_lessons' => $planningLessons,
            'processing_lesson_ids' => $processingLessonIds,
        ];
    }

    /**
     * @return array{0: Lesson, 1: bool}
     */
    protected function upsertLessonFromPandaVideo(
        array $video,
        int $sortOrder,
        string $normalizedTitle,
        ?Course $course,
        ?CourseModule $module,
        ?CourseModuleTrack $matchTrack,
        ?CourseModuleTrack $libraryTrack,
        ?LessonFolder $lessonFolder,
        string $libraryFolderPath,
        string $folderId,
        ?string $folderReference,
        string $lessonStatus,
    ): array {
        $lesson = $this->resolveLessonForPandaVideo($video, $normalizedTitle, $course, $module, $matchTrack);
        $wasRecentlyCreated = ! $lesson->exists;
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $durationSeconds = (int) ($video['duration_seconds'] ?? 0);

        $lesson->fill([
            'course_id' => null,
            'lesson_folder_id' => $lessonFolder?->id,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => $normalizedTitle,
            'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($normalizedTitle, $sortOrder),
            // Reimportar não deve apagar descrição, capa ou duração que o Panda não devolveu.
            'description' => filled($video['description'] ?? null)
                ? $video['description']
                : (filled($lesson->description) ? $lesson->description : 'Aula em vídeo.'),
            'type' => 'video',
            'thumbnail_url' => filled($video['thumbnail_url'] ?? null) ? $video['thumbnail_url'] : $lesson->thumbnail_url,
            'duration_seconds' => $durationSeconds > 0 ? $durationSeconds : (int) $lesson->duration_seconds,
            'sort_order' => $sortOrder,
            'status' => $this->statusForReadyPandaLesson($lessonStatus),
            'panda_video_id' => $video['panda_video_id'],
            'panda_status' => $video['panda_status'],
            'panda_embed_url' => $video['panda_embed_url'],
            'panda_player_url' => $video['panda_player_url'],
            'source_status' => $this->sourceStatusForPandaVideo($video),
            // Mescla com o que já existia para não perder dados de outras origens (planilha, Drive, IA).
            'metadata' => array_replace($metadata, array_filter([
                'source' => 'panda',
                'folder_id' => $folderId,
                'panda_folder_id' => $folderId,
                'folder_reference' => $folderReference,
                'library_folder_name' => $libraryTrack?->name ?? $module?->name,
                'library_folder_path' => $libraryFolderPath,
                'import_context_module_id' => $module?->id,
                'import_context_track_id' => $libraryTrack?->id,
                'payload' => $video['payload'],
                'last_imported_at' => now()->toIso8601String(),
            ], fn ($value): bool => $value !== null)),
        ]);
        $lesson->save();

        $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);

        return [$lesson, $wasRecentlyCreated];
    }

    /**
     * Vídeo pronto (ou sem status informado, como em contas legadas) vira mídia pronta;
     * vídeo com status explícito de processamento fica aguardando o Panda.
     */
    protected function sourceStatusForPandaVideo(array $video): string
    {
        if ($this->client->videoIsReady($video)) {
            return 'media_ready';
        }

        return blank($video['panda_status'] ?? null) ? 'media_ready' : 'panda_processing';
    }

    protected function queueStatusSyncForProcessingLessons(array $lessonIds): void
    {
        $delaySeconds = max(0, (int) config('services.panda.video_status_sync_delay_seconds', 300));

        foreach (array_unique($lessonIds) as $lessonId) {
            $dispatch = SyncPandaVideoStatus::dispatch((int) $lessonId)
                ->delay(now()->addSeconds($delaySeconds));

            if (config('queue.default') === 'sync') {
                $dispatch->afterResponse();
            }
        }
    }

    protected function emptyImportStats(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'processing' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];
    }

    protected function recordSkippedVideo(PandaImportRun $run, array $video, string $localType, string $title): void
    {
        $run->items()->create([
            'external_type' => 'video',
            'external_id' => (string) ($video['panda_video_id'] ?: $title),
            'local_type' => $localType,
            'local_id' => null,
            'status' => 'skipped',
            'payload' => $video['payload'] ?? [],
            'error_message' => 'O vídeo está com falha no Panda (status '.($video['panda_status'] ?? 'desconhecido').') e não foi importado.',
        ]);
    }

    protected function recordFailedVideo(PandaImportRun $run, array $video, string $localType, string $title, Throwable $exception): void
    {
        $run->items()->create([
            'external_type' => 'video',
            'external_id' => (string) ($video['panda_video_id'] ?: $title),
            'local_type' => $localType,
            'local_id' => null,
            'status' => 'failed',
            'payload' => $video['payload'] ?? [],
            'error_message' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }

    protected function finishRun(PandaImportRun $run, Collection $videos, array $stats, array $context): void
    {
        $errors = array_slice($stats['errors'] ?? [], 0, 20);
        unset($stats['errors']);

        $run->forceFill([
            'status' => 'finished',
            'summary' => array_merge($context, [
                'videos' => $videos->count(),
            ], $stats, $errors !== [] ? ['errors' => $errors] : []),
            'error_message' => $errors !== []
                ? collect($errors)->map(fn (array $error): string => $error['title'].': '.$error['message'])->join("\n")
                : null,
            'finished_at' => now(),
        ])->save();
    }

    protected function failRun(PandaImportRun $run, Throwable $exception): void
    {
        $run->forceFill([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();
    }

    public function importReplacingModuleByName(?Course $fallbackCourse, string $moduleName, string $folderId, string $lessonStatus = 'draft', string $moduleType = 'specific'): PandaImportRun
    {
        $folderId = $this->client->resolveFolderReference($folderId);
        $module = $this->findModuleByName($moduleName);

        if (! $module) {
            $module = CourseModule::create([
                'course_id' => null,
                'name' => $moduleName,
                'type' => $this->normalizeModuleType($moduleType),
                'workload_minutes' => 0,
                'sort_order' => $fallbackCourse
                    ? (int) ($fallbackCourse->modules()->max('course_modules.sort_order') + 1)
                    : (int) (CourseModule::query()->max('sort_order') + 1),
                'is_active' => true,
            ]);
        }

        if ($fallbackCourse) {
            $module->courses()->syncWithoutDetaching([
                $fallbackCourse->id => ['sort_order' => (int) $module->sort_order],
            ]);
        }

        $module->forceFill([
            'type' => $this->normalizeModuleType($moduleType),
        ])->save();

        return $this->importIntoModule($module, $folderId, $lessonStatus, $moduleType, $fallbackCourse);
    }

    protected function lessonSlug(string $title, int $sortOrder): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'aula-panda-'.$sortOrder;
    }

    protected function resolveLessonForPandaVideo(array $video, string $normalizedTitle, ?Course $course, ?CourseModule $module, ?CourseModuleTrack $track): Lesson
    {
        $videoId = (string) ($video['panda_video_id'] ?? '');

        if ($videoId !== '') {
            $lesson = Lesson::query()
                ->where('panda_video_id', $videoId)
                ->orWhereHas('video', fn ($query) => $query->where('provider_video_id', $videoId))
                ->orderByRaw("case when status = 'published' then 0 else 1 end")
                ->orderBy('id')
                ->first();

            if ($lesson) {
                return $lesson;
            }
        }

        $titleKey = LessonTitleNormalizer::matchKey($normalizedTitle);

        if ($titleKey !== '') {
            $lesson = $this->candidateLessonsForImportedVideo($titleKey)
                ->first(function (Lesson $lesson) use ($titleKey): bool {
                    return $this->lessonCanReceiveImportedVideo($lesson)
                        && LessonTitleNormalizer::matchKey($lesson->title) === $titleKey;
                });

            if ($lesson) {
                return $lesson;
            }
        }

        return new Lesson([
            'panda_video_id' => $videoId !== '' ? $videoId : null,
        ]);
    }

    protected function resolveVideoForPandaVideo(array $pandaVideo, string $normalizedTitle): Video
    {
        $videoId = (string) ($pandaVideo['panda_video_id'] ?? '');

        if ($videoId !== '') {
            $video = Video::query()
                ->where('provider', 'panda')
                ->where('provider_video_id', $videoId)
                ->first();

            if ($video) {
                return $video;
            }
        }

        $titleKey = LessonTitleNormalizer::matchKey($normalizedTitle);

        if ($titleKey !== '') {
            $tokens = collect(explode(' ', $titleKey))
                ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
                ->unique()
                ->take(4)
                ->values();

            if ($tokens->isNotEmpty()) {
                $video = Video::query()
                    ->where('provider', 'panda')
                    ->where(function ($query) use ($tokens): void {
                        foreach ($tokens as $token) {
                            $query->orWhere('title', 'like', '%'.$token.'%');
                        }
                    })
                    ->orderBy('id')
                    ->limit(100)
                    ->get()
                    ->first(fn (Video $video): bool => LessonTitleNormalizer::matchKey($video->title) === $titleKey);

                if ($video) {
                    return $video;
                }
            }
        }

        return new Video([
            'provider' => 'panda',
            'provider_video_id' => $videoId !== '' ? $videoId : null,
        ]);
    }

    protected function candidateLessonsForImportedVideo(string $titleKey): Collection
    {
        $tokens = collect(explode(' ', $titleKey))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 4)
            ->unique()
            ->take(4)
            ->values();

        if ($tokens->isEmpty()) {
            return collect();
        }

        return Lesson::query()
            ->where(function ($query): void {
                $query->whereNull('panda_video_id')
                    ->whereNull('panda_embed_url')
                    ->whereNull('panda_player_url')
                    ->where(function ($query): void {
                        $query->whereNull('video_id')
                            ->orWhereDoesntHave('video')
                            ->orWhereHas('video', fn ($query) => $query
                                ->whereNull('provider_video_id')
                                ->whereNull('embed_url')
                                ->whereNull('player_url'));
                    })
                    ->where(function ($query): void {
                        $query->whereNull('source_status')
                            ->orWhereIn('source_status', ['', 'awaiting_media', 'upload_queued', 'upload_failed', 'structure_only']);
                    });
            })
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->orWhere('title', 'like', '%'.$token.'%');
                }
            })
            ->orderBy('id')
            ->limit(100)
            ->get();
    }

    protected function lessonCanReceiveImportedVideo(Lesson $lesson): bool
    {
        if (filled($lesson->panda_video_id) || filled($lesson->panda_embed_url) || filled($lesson->panda_player_url)) {
            return false;
        }

        return in_array((string) $lesson->source_status, ['', 'awaiting_media', 'upload_queued', 'upload_failed'], true);
    }

    protected function normalizeLessonStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    protected function statusForReadyPandaLesson(string $status): string
    {
        $normalizedStatus = $this->normalizeLessonStatus($status);

        return $normalizedStatus === 'archived' ? 'archived' : 'published';
    }

    protected function normalizeModuleType(string $type): string
    {
        return in_array($type, ['basic', 'specific', 'complementary', 'review', 'questions', 'other'], true) ? $type : 'specific';
    }

    protected function planningLessonFromVideo(array $video, int $index, ?string $normalizedTitle = null): array
    {
        $minutes = (int) ceil(((int) ($video['duration_seconds'] ?? 0)) / 60);

        return [
            'name' => $normalizedTitle ?: (trim((string) ($video['title'] ?? '')) ?: 'Aula importada '.($index + 1)),
            'minutes' => max(1, $minutes),
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

    protected function ensureTrackForModule(CourseModule $module, ?Course $course, string $name, string $folderId): CourseModuleTrack
    {
        $trackName = trim($name) ?: 'Aulas';
        $slug = Str::slug($trackName) ?: 'aulas';

        $track = CourseModuleTrack::query()->updateOrCreate(
            [
                'course_module_id' => $module->id,
                'slug' => $slug,
            ],
            [
                'name' => $trackName,
                'sort_order' => 1,
                'status' => 'published',
                'panda_folder_id' => $folderId,
                'metadata' => [
                    'source' => 'panda',
                    'last_imported_at' => now()->toIso8601String(),
                ],
            ],
        );

        if ($course) {
            $track->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => (int) $track->sort_order],
            ]);
        }

        return $track;
    }

    protected function sortVideosNaturally(Collection $videos): Collection
    {
        return $videos
            ->values()
            ->sortBy(function (array $video, int $index): string {
                preg_match('/^\D*(\d+)/', (string) ($video['title'] ?? ''), $matches);

                return implode('|', [
                    isset($matches[1]) ? '0' : '1',
                    str_pad((string) ((int) ($matches[1] ?? 0)), 8, '0', STR_PAD_LEFT),
                    str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                ]);
            })
            ->values();
    }

    protected function syncPandaAiArtifacts(Lesson $lesson, array $artifacts, array $payload): void
    {
        foreach ($artifacts as $type => $content) {
            AiArtifact::query()->updateOrCreate([
                'source_type' => Lesson::class,
                'source_id' => $lesson->id,
                'artifact_type' => $type,
                'provider' => 'panda',
            ], [
                'status' => 'ready',
                'content' => is_array($content) ? $content : ['text' => (string) $content],
                'metadata' => [
                    'panda_video_id' => $lesson->panda_video_id,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
        }

        if ($artifacts === []) {
            return;
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
                'panda_video_id' => $lesson->panda_video_id,
                'imported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function syncPandaVideoAiArtifacts(Video $video, array $artifacts, array $payload): void
    {
        foreach ($artifacts as $type => $content) {
            AiArtifact::query()->updateOrCreate([
                'source_type' => Video::class,
                'source_id' => $video->id,
                'artifact_type' => $type,
                'provider' => 'panda',
            ], [
                'status' => 'ready',
                'content' => is_array($content) ? $content : ['text' => (string) $content],
                'metadata' => [
                    'panda_video_id' => $video->provider_video_id,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
        }

        if ($artifacts === []) {
            return;
        }

        AiArtifact::query()->updateOrCreate([
            'source_type' => Video::class,
            'source_id' => $video->id,
            'artifact_type' => 'panda_payload',
            'provider' => 'panda',
        ], [
            'status' => 'ready',
            'content' => $payload,
            'metadata' => [
                'panda_video_id' => $video->provider_video_id,
                'imported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function findModuleByName(string $moduleName): ?CourseModule
    {
        $normalizedName = $this->normalizeName($moduleName);

        return CourseModule::query()
            ->orderBy('id')
            ->get()
            ->first(fn (CourseModule $module) => $this->normalizeName($module->name) === $normalizedName);
    }

    protected function normalizeName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }
}
