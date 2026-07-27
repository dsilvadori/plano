<?php

namespace App\Services;

use App\Models\AiArtifact;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\PandaImportRun;
use App\Support\LessonTitleNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PandaCourseImporter
{
    public function __construct(
        protected PandaVideoClient $client,
    ) {}

    public function importFolder(Course $course, string $folderId, ?string $moduleName = null, string $lessonStatus = 'draft', string $moduleType = 'specific'): PandaImportRun
    {
        $run = PandaImportRun::create([
            'course_id' => $course->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            DB::transaction(function () use ($course, $folderId, $moduleName, $lessonStatus, $moduleType, $run, $videos): void {
                $resolvedModuleName = $moduleName
                    ?: (string) ($videos->first()['folder_name'] ?? null)
                    ?: 'Pasta de vídeos '.$folderId;

                $module = CourseModule::updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'panda_folder_id' => $folderId,
                    ],
                    [
                        'name' => $resolvedModuleName,
                        'type' => $this->normalizeModuleType($moduleType),
                        'workload_minutes' => (int) ceil($videos->sum('duration_seconds') / 60),
                        'sort_order' => (int) ($course->modules()->max('course_modules.sort_order') + 1),
                        'is_active' => true,
                        'metadata' => [
                            'source' => 'panda',
                            'last_imported_at' => now()->toIso8601String(),
                        ],
                    ],
                );

                $created = 0;
                $updated = 0;
                $planningLessons = [];
                $track = $this->ensureTrackForModule($module, $course, $resolvedModuleName, $folderId);

                foreach ($videos->values() as $index => $video) {
                    $normalizedTitle = LessonTitleNormalizer::normalize($video['title'], $index + 1);
                    $lesson = $this->resolveLessonForPandaVideo($video, $normalizedTitle, $course, $module, null);
                    $wasRecentlyCreated = ! $lesson->exists;

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $course->id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                        'course_module_track_id' => $lesson->exists ? ($lesson->course_module_track_id ?: $track->id) : $track->id,
                        'title' => $normalizedTitle,
                        'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($normalizedTitle, $index + 1),
                        'description' => $video['description'] ?: 'Aula em vídeo.',
                        'type' => 'video',
                        'thumbnail_url' => $video['thumbnail_url'],
                        'duration_seconds' => $video['duration_seconds'],
                        'sort_order' => $index + 1,
                        'status' => $this->normalizeLessonStatus($lessonStatus),
                        'panda_video_id' => $video['panda_video_id'],
                        'panda_status' => $video['panda_status'],
                        'panda_embed_url' => $video['panda_embed_url'],
                        'panda_player_url' => $video['panda_player_url'],
                        'source_status' => 'media_ready',
                        'metadata' => [
                            'source' => 'panda',
                            'folder_id' => $folderId,
                            'payload' => $video['payload'],
                            'last_imported_at' => now()->toIso8601String(),
                        ],
                    ]);
                    $lesson->save();
                    $lesson->modules()->syncWithoutDetaching([
                        $module->id => ['sort_order' => $index + 1],
                    ]);
                    $lesson->tracks()->syncWithoutDetaching([
                        $track->id => ['sort_order' => $index + 1],
                    ]);
                    $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);
                    $planningLessons[] = $this->planningLessonFromVideo($video, $index, $normalizedTitle);

                    $run->items()->create([
                        'external_type' => 'video',
                        'external_id' => $video['panda_video_id'],
                        'local_type' => 'lesson',
                        'local_id' => $lesson->id,
                        'status' => $wasRecentlyCreated ? 'created' : 'updated',
                        'payload' => $video['payload'],
                    ]);

                    $wasRecentlyCreated ? $created++ : $updated++;
                }

                $this->syncModulePlanningLessons($module, $planningLessons);

                $run->forceFill([
                    'status' => 'finished',
                    'summary' => [
                        'module_id' => $module->id,
                        'videos' => $videos->count(),
                        'created' => $created,
                        'updated' => $updated,
                    ],
                    'finished_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->fresh(['items']);
    }

    public function importIntoModule(CourseModule $module, string $folderId, string $lessonStatus = 'draft', ?string $moduleType = null, ?Course $course = null): PandaImportRun
    {
        $run = PandaImportRun::create([
            'course_id' => $course?->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            DB::transaction(function () use ($module, $course, $folderId, $lessonStatus, $moduleType, $run, $videos): void {
                $module->forceFill([
                    'panda_folder_id' => $folderId,
                    'type' => $moduleType ? $this->normalizeModuleType($moduleType) : $module->type,
                    'workload_minutes' => (int) ceil($videos->sum('duration_seconds') / 60),
                    'metadata' => [
                        'source' => 'panda',
                        'last_imported_at' => now()->toIso8601String(),
                    ],
                ])->save();

                $created = 0;
                $updated = 0;
                $planningLessons = [];
                $track = $this->ensureTrackForModule($module, $course, $module->name, $folderId);

                foreach ($videos->values() as $index => $video) {
                    $normalizedTitle = LessonTitleNormalizer::normalize($video['title'], $index + 1);
                    $lesson = $this->resolveLessonForPandaVideo($video, $normalizedTitle, $course, $module, null);
                    $wasRecentlyCreated = ! $lesson->exists;

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $course?->id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                        'course_module_track_id' => $lesson->exists ? ($lesson->course_module_track_id ?: $track->id) : $track->id,
                        'title' => $normalizedTitle,
                        'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($normalizedTitle, $index + 1),
                        'description' => $video['description'] ?: 'Aula em vídeo.',
                        'type' => 'video',
                        'thumbnail_url' => $video['thumbnail_url'],
                        'duration_seconds' => $video['duration_seconds'],
                        'sort_order' => $index + 1,
                        'status' => $this->normalizeLessonStatus($lessonStatus),
                        'panda_video_id' => $video['panda_video_id'],
                        'panda_status' => $video['panda_status'],
                        'panda_embed_url' => $video['panda_embed_url'],
                        'panda_player_url' => $video['panda_player_url'],
                        'source_status' => 'media_ready',
                        'metadata' => [
                            'source' => 'panda',
                            'folder_id' => $folderId,
                            'payload' => $video['payload'],
                            'last_imported_at' => now()->toIso8601String(),
                        ],
                    ]);
                    $lesson->save();
                    $lesson->modules()->syncWithoutDetaching([
                        $module->id => ['sort_order' => $index + 1],
                    ]);
                    $lesson->tracks()->syncWithoutDetaching([
                        $track->id => ['sort_order' => $index + 1],
                    ]);
                    $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);
                    $planningLessons[] = $this->planningLessonFromVideo($video, $index, $normalizedTitle);

                    $run->items()->create([
                        'external_type' => 'video',
                        'external_id' => $video['panda_video_id'],
                        'local_type' => 'lesson',
                        'local_id' => $lesson->id,
                        'status' => $wasRecentlyCreated ? 'created' : 'updated',
                        'payload' => $video['payload'],
                    ]);

                    $wasRecentlyCreated ? $created++ : $updated++;
                }

                $this->syncModulePlanningLessons($module, $planningLessons);

                $run->forceFill([
                    'status' => 'finished',
                    'summary' => [
                        'module_id' => $module->id,
                        'videos' => $videos->count(),
                        'created' => $created,
                        'updated' => $updated,
                    ],
                    'finished_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->fresh(['items']);
    }

    public function importLessons(?Course $course, ?CourseModule $module, ?CourseModuleTrack $track, string $folderId, string $lessonStatus = 'draft'): PandaImportRun
    {
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

        $run = PandaImportRun::create([
            'course_id' => $course?->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            DB::transaction(function () use ($course, $module, $track, $folderId, $lessonStatus, $run, $videos): void {
                $created = 0;
                $updated = 0;
                $planningLessons = [];

                foreach ($videos->values() as $index => $video) {
                    $sortOrder = $index + 1;
                    $normalizedTitle = LessonTitleNormalizer::normalize($video['title'], $sortOrder);
                    $lesson = $this->resolveLessonForPandaVideo($video, $normalizedTitle, $course, $module, $track);
                    $wasRecentlyCreated = ! $lesson->exists;

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $course?->id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module?->id,
                        'course_module_track_id' => $lesson->exists ? ($lesson->course_module_track_id ?: $track?->id) : $track?->id,
                        'title' => $normalizedTitle,
                        'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($normalizedTitle, $sortOrder),
                        'description' => $video['description'] ?: 'Aula em vídeo.',
                        'type' => 'video',
                        'thumbnail_url' => $video['thumbnail_url'],
                        'duration_seconds' => $video['duration_seconds'],
                        'sort_order' => $sortOrder,
                        'status' => $this->normalizeLessonStatus($lessonStatus),
                        'panda_video_id' => $video['panda_video_id'],
                        'panda_status' => $video['panda_status'],
                        'panda_embed_url' => $video['panda_embed_url'],
                        'panda_player_url' => $video['panda_player_url'],
                        'source_status' => 'media_ready',
                        'metadata' => [
                            'source' => 'panda',
                            'folder_id' => $folderId,
                            'payload' => $video['payload'],
                            'last_imported_at' => now()->toIso8601String(),
                        ],
                    ]);
                    $lesson->save();

                    if ($module) {
                        $lesson->modules()->syncWithoutDetaching([
                            $module->id => ['sort_order' => $sortOrder],
                        ]);
                        $planningLessons[] = $this->planningLessonFromVideo($video, $index, $normalizedTitle);
                    }

                    if ($track) {
                        $lesson->tracks()->syncWithoutDetaching([
                            $track->id => ['sort_order' => $sortOrder],
                        ]);
                    }

                    $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);

                    $run->items()->create([
                        'external_type' => 'video',
                        'external_id' => $video['panda_video_id'],
                        'local_type' => 'lesson',
                        'local_id' => $lesson->id,
                        'status' => $wasRecentlyCreated ? 'created' : 'updated',
                        'payload' => $video['payload'],
                    ]);

                    $wasRecentlyCreated ? $created++ : $updated++;
                }

                if ($module) {
                    $module->forceFill(['panda_folder_id' => $folderId])->save();
                    $this->syncModulePlanningLessons($module, $planningLessons);
                }

                if ($track) {
                    $track->forceFill(['panda_folder_id' => $folderId])->save();
                }

                $run->forceFill([
                    'status' => 'finished',
                    'summary' => [
                        'course_id' => $course?->id,
                        'module_id' => $module?->id,
                        'track_id' => $track?->id,
                        'videos' => $videos->count(),
                        'created' => $created,
                        'updated' => $updated,
                    ],
                    'finished_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->fresh(['items']);
    }

    public function importReplacingModuleByName(?Course $fallbackCourse, string $moduleName, string $folderId, string $lessonStatus = 'draft', string $moduleType = 'specific'): PandaImportRun
    {
        $module = $this->findModuleByName($moduleName);

        if (! $module) {
            $module = CourseModule::create([
                'course_id' => $fallbackCourse?->id,
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
            $lesson = Lesson::query()->where('panda_video_id', $videoId)->first();

            if ($lesson) {
                return $lesson;
            }
        }

        $titleKey = LessonTitleNormalizer::matchKey($normalizedTitle);

        if ($titleKey !== '') {
            $lesson = Lesson::query()
                ->orderBy('id')
                ->get()
                ->first(function (Lesson $lesson) use ($titleKey, $course, $module, $track): bool {
                    return $this->lessonCanReceiveImportedVideo($lesson)
                        && $this->lessonMatchesImportScope($lesson, $course, $module, $track)
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

    protected function lessonCanReceiveImportedVideo(Lesson $lesson): bool
    {
        if (filled($lesson->panda_video_id) || filled($lesson->panda_embed_url) || filled($lesson->panda_player_url)) {
            return false;
        }

        return in_array((string) $lesson->source_status, ['', 'awaiting_media', 'upload_queued', 'upload_failed'], true);
    }

    protected function lessonMatchesImportScope(Lesson $lesson, ?Course $course, ?CourseModule $module, ?CourseModuleTrack $track): bool
    {
        if ($track && (int) $lesson->course_module_track_id !== (int) $track->id && ! $lesson->tracks()->whereKey($track->id)->exists()) {
            return false;
        }

        if ($module && (int) $lesson->course_module_id !== (int) $module->id && ! $lesson->modules()->whereKey($module->id)->exists()) {
            return false;
        }

        if (! $module && ! $track && $course && (int) $lesson->course_id !== (int) $course->id) {
            return false;
        }

        return true;
    }

    protected function normalizeLessonStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
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
