<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\StudyTrack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseSpreadsheetImporter
{
    public function __construct(
        protected CourseSpreadsheetParser $parser,
    ) {}

    public function preview(string $path, ?Course $course = null): array
    {
        $payload = $this->parser->parse($path);
        $targetCourse = $course ?: Course::query()->where('slug', $payload['course_slug'])->first();
        $moduleStats = ['create' => 0, 'update' => 0];
        $lessonStats = ['create' => 0, 'update' => 0];

        foreach ($payload['modules'] as $moduleData) {
            $module = $targetCourse ? $this->resolveModuleForCourse($targetCourse, $moduleData['name']) : $this->resolveReusableModule($moduleData['name']);

            $module ? $moduleStats['update']++ : $moduleStats['create']++;

            foreach ($moduleData['lessons'] ?? [] as $index => $lessonData) {
                $slug = $this->lessonSlug((string) ($lessonData['name'] ?? ''), $index + 1);

                $lesson = $module ? $this->resolveReusableLesson($module, (string) ($lessonData['name'] ?? ''), $slug, $lessonData) : null;

                $lesson ? $lessonStats['update']++ : $lessonStats['create']++;
            }
        }

        return [
            'payload' => $payload,
            'course' => [
                'name' => $course?->name ?? $payload['course_name'],
                'action' => $targetCourse ? 'update' : 'create',
            ],
            'modules' => [
                'total' => count($payload['modules']),
                ...$moduleStats,
            ],
            'lessons' => [
                'total' => collect($payload['modules'])->sum(fn (array $module) => count($module['lessons'] ?? [])),
                ...$lessonStats,
            ],
            'total_minutes' => array_sum(array_column($payload['modules'], 'workload_minutes')),
            'errors' => [],
        ];
    }

    public function import(string $path): Course
    {
        $payload = $this->parser->parse($path);

        return DB::transaction(function () use ($payload) {
            $course = Course::updateOrCreate(
                ['slug' => $payload['course_slug']],
                [
                    'name' => $payload['course_name'],
                    'description' => 'Curso importado por planilha.',
                    'is_active' => true,
                ],
            );

            $this->importStructure($course, $payload, $payload['study_track_name']);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }

    public function importInto(Course $course, string $path): Course
    {
        $payload = $this->parser->parse($path);

        return DB::transaction(function () use ($course, $payload) {
            $studyTrackName = $this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - ' . $course->name;

            $this->importStructure($course, $payload, $studyTrackName);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }

    protected function importStructure(Course $course, array $payload, string $studyTrackName, bool $replaceTrackModules = true): void
    {
        $moduleIds = [];

        foreach ($payload['modules'] as $moduleData) {
            $module = $this->resolveModuleForCourse($course, $moduleData['name'])
                ?? $this->resolveReusableModule($moduleData['name'])
                ?? new CourseModule([
                    'course_id' => $course->id,
                    'name' => $moduleData['name'],
                ]);

            $module->fill([
                'course_id' => $module->exists ? $module->course_id : $course->id,
                'name' => $module->name ?: $moduleData['name'],
                'type' => $moduleData['type'],
                'lessons' => $moduleData['lessons'] ?? [],
                'workload_minutes' => $moduleData['workload_minutes'],
                'sort_order' => $moduleData['sort_order'],
                'is_active' => true,
            ]);
            $module->save();
            $module->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => $moduleData['sort_order']],
            ]);

            $this->importLessons($course, $module, $moduleData['lessons'] ?? []);

            $moduleIds[$module->id] = [
                'weight' => 1,
                'sort_order' => $moduleData['sort_order'],
            ];
        }

        $studyTrack = StudyTrack::updateOrCreate(
            [
                'course_id' => $course->id,
                'name' => $studyTrackName,
            ],
            [
                'description' => 'Trilha oficial gerada automaticamente a partir da planilha do curso.',
                'is_active' => true,
            ],
        );

        if ($replaceTrackModules) {
            $studyTrack->modules()->sync($moduleIds);

            return;
        }

        $studyTrack->modules()->syncWithoutDetaching($moduleIds);
    }

    protected function resolveOfficialStudyTrackName(Course $course): ?string
    {
        return $course->studyTracks()
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->value('name');
    }

    protected function importLessons(Course $course, CourseModule $module, array $lessons): void
    {
        $importedLessonIds = [];

        foreach (array_values($lessons) as $index => $lessonData) {
            $title = trim((string) ($lessonData['name'] ?? ''));

            if ($title === '') {
                continue;
            }

            $sortOrder = (int) ($lessonData['sort_order'] ?? ($index + 1));
            $slug = $this->lessonSlug($title, $sortOrder);
            $minutes = max(0, (int) ($lessonData['minutes'] ?? 0));

            $pandaVideoId = filled($lessonData['panda_video_id'] ?? null) ? (string) $lessonData['panda_video_id'] : null;
            $lesson = $this->resolveReusableLesson($module, $title, $slug, $lessonData)
                ?? ($pandaVideoId
                    ? Lesson::query()->firstOrNew(['panda_video_id' => $pandaVideoId])
                    : new Lesson([
                        'course_module_id' => $module->id,
                        'slug' => $slug,
                    ]));

            $lesson->fill([
                'course_id' => $lesson->exists ? $lesson->course_id : $course->id,
                'course_module_id' => $lesson->exists ? $lesson->course_module_id : $module->id,
                'title' => $title,
                'slug' => $lesson->exists ? $lesson->slug : $slug,
                'description' => 'Aula importada por planilha.',
                'type' => $this->normalizeLessonType((string) ($lessonData['type'] ?? 'video')),
                'thumbnail_url' => $lessonData['thumbnail_url'] ?? $lesson->thumbnail_url,
                'duration_seconds' => $minutes * 60,
                'sort_order' => $sortOrder,
                'status' => $this->normalizeLessonStatus((string) ($lessonData['status'] ?? 'published')),
                'panda_video_id' => $pandaVideoId ?: $lesson->panda_video_id,
                'panda_embed_url' => $lessonData['panda_embed_url'] ?? $lesson->panda_embed_url,
                'panda_player_url' => $lessonData['panda_player_url'] ?? $lesson->panda_player_url,
                'metadata' => [
                    'source' => 'spreadsheet',
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
            $lesson->save();
            $lesson->modules()->syncWithoutDetaching([
                $module->id => ['sort_order' => $sortOrder],
            ]);

            $importedLessonIds[] = $lesson->id;
        }

        if ($importedLessonIds !== []) {
            $module->onlineLessons()
                ->where('metadata->source', 'spreadsheet')
                ->whereNotIn('lessons.id', $importedLessonIds)
                ->update(['status' => 'archived']);
        }
    }

    protected function lessonSlug(string $title, int $sortOrder): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'aula-' . $sortOrder;
    }

    protected function normalizeLessonType(string $type): string
    {
        return in_array($type, ['video', 'pdf', 'mixed', 'text', 'quiz'], true) ? $type : 'video';
    }

    protected function normalizeLessonStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'published';
    }

    protected function resolveModuleForCourse(Course $course, string $moduleName): ?CourseModule
    {
        return $course->modules()
            ->where('course_modules.name', $moduleName)
            ->first()
            ?? CourseModule::query()
                ->where('course_id', $course->id)
                ->where('name', $moduleName)
                ->first();
    }

    protected function resolveReusableModule(string $moduleName): ?CourseModule
    {
        $normalizedName = $this->normalizeName($moduleName);

        return CourseModule::query()
            ->orderBy('id')
            ->get()
            ->first(fn (CourseModule $module) => $this->normalizeName($module->name) === $normalizedName);
    }

    protected function resolveReusableLesson(CourseModule $module, string $title, string $slug, array $lessonData): ?Lesson
    {
        $pandaVideoId = filled($lessonData['panda_video_id'] ?? null) ? (string) $lessonData['panda_video_id'] : null;

        if ($pandaVideoId) {
            $lesson = Lesson::query()->where('panda_video_id', $pandaVideoId)->first();

            if ($lesson) {
                return $lesson;
            }
        }

        $lesson = $module->onlineLessons()
            ->where('lessons.slug', $slug)
            ->first();

        if ($lesson) {
            return $lesson;
        }

        $normalizedTitle = $this->normalizeName($title);

        return Lesson::query()
            ->orderBy('id')
            ->get()
            ->first(fn (Lesson $lesson) => $this->normalizeName($lesson->title) === $normalizedTitle);
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
