<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\AiArtifact;
use App\Models\Lesson;
use App\Models\PandaImportRun;
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
                    ?: 'Panda - Pasta ' . $folderId;

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

                foreach ($videos->values() as $index => $video) {
                    $lesson = Lesson::query()->firstOrNew([
                        'panda_video_id' => $video['panda_video_id'],
                    ]);
                    $wasRecentlyCreated = ! $lesson->exists;

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $course->id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                        'title' => $video['title'],
                        'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($video['title'], $index + 1),
                        'description' => $video['description'] ?: 'Aula importada do Panda Video.',
                        'type' => 'video',
                        'thumbnail_url' => $video['thumbnail_url'],
                        'duration_seconds' => $video['duration_seconds'],
                        'sort_order' => $index + 1,
                        'status' => $this->normalizeLessonStatus($lessonStatus),
                        'panda_status' => $video['panda_status'],
                        'panda_embed_url' => $video['panda_embed_url'],
                        'panda_player_url' => $video['panda_player_url'],
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
                    $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);
                    $planningLessons[] = $this->planningLessonFromVideo($video, $index);

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

    public function importIntoModule(CourseModule $module, string $folderId, string $lessonStatus = 'draft', ?string $moduleType = null): PandaImportRun
    {
        $course = $module->course;
        $run = PandaImportRun::create([
            'course_id' => $course->id,
            'panda_folder_id' => $folderId,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $videos = $this->sortVideosNaturally($this->client->videos($folderId));

            DB::transaction(function () use ($module, $folderId, $lessonStatus, $moduleType, $run, $videos): void {
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

                foreach ($videos->values() as $index => $video) {
                    $lesson = Lesson::query()->firstOrNew([
                        'panda_video_id' => $video['panda_video_id'],
                    ]);
                    $wasRecentlyCreated = ! $lesson->exists;

                    $lesson->fill([
                        'course_id' => $lesson->exists ? $lesson->course_id : $module->course_id,
                        'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                        'title' => $video['title'],
                        'slug' => $lesson->exists ? $lesson->slug : $this->lessonSlug($video['title'], $index + 1),
                        'description' => $video['description'] ?: 'Aula importada do Panda Video.',
                        'type' => 'video',
                        'thumbnail_url' => $video['thumbnail_url'],
                        'duration_seconds' => $video['duration_seconds'],
                        'sort_order' => $index + 1,
                        'status' => $this->normalizeLessonStatus($lessonStatus),
                        'panda_status' => $video['panda_status'],
                        'panda_embed_url' => $video['panda_embed_url'],
                        'panda_player_url' => $video['panda_player_url'],
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
                    $this->syncPandaAiArtifacts($lesson, $video['ai_artifacts'] ?? [], $video['payload']);
                    $planningLessons[] = $this->planningLessonFromVideo($video, $index);

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

    public function importReplacingModuleByName(Course $fallbackCourse, string $moduleName, string $folderId, string $lessonStatus = 'draft', string $moduleType = 'specific'): PandaImportRun
    {
        $module = $this->findModuleByName($moduleName);

        if (! $module) {
            $module = CourseModule::create([
                'course_id' => $fallbackCourse->id,
                'name' => $moduleName,
                'type' => $this->normalizeModuleType($moduleType),
                'workload_minutes' => 0,
                'sort_order' => (int) ($fallbackCourse->modules()->max('course_modules.sort_order') + 1),
                'is_active' => true,
            ]);
        }

        $module->courses()->syncWithoutDetaching([
            $fallbackCourse->id => ['sort_order' => (int) $module->sort_order],
        ]);

        $module->forceFill([
            'type' => $this->normalizeModuleType($moduleType),
        ])->save();

        return $this->importIntoModule($module, $folderId, $lessonStatus, $moduleType);
    }

    protected function lessonSlug(string $title, int $sortOrder): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'aula-panda-' . $sortOrder;
    }

    protected function normalizeLessonStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    protected function normalizeModuleType(string $type): string
    {
        return in_array($type, ['basic', 'specific', 'complementary', 'review', 'questions', 'other'], true) ? $type : 'specific';
    }

    protected function planningLessonFromVideo(array $video, int $index): array
    {
        $minutes = (int) ceil(((int) ($video['duration_seconds'] ?? 0)) / 60);

        return [
            'name' => trim((string) ($video['title'] ?? '')) ?: 'Aula Panda ' . ($index + 1),
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
