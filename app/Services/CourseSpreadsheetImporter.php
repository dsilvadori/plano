<?php

namespace App\Services;

use App\Http\Controllers\CourseCatalogController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\CourseSpreadsheetImportRun;
use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\StudyTrack;
use App\Models\Teacher;
use App\Models\Video;
use App\Support\LessonTitleNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CourseSpreadsheetImporter
{
    protected ?Collection $reusableLessonCandidates = null;

    protected ?Collection $reusableVideoCandidates = null;

    protected array $lessonIndex = [];

    protected array $videoIndex = [];

    protected array $lessonIdsByVideo = [];

    protected array $trackLessonIds = [];

    protected ?int $targetCourseId = null;

    protected ?array $courseLessonIds = null;

    protected array $folderIds = [];

    protected array $matchScoreCache = [];

    protected array $matchKeyCache = [];

    protected array $leadingNumberCache = [];

    protected array $removedStructureModuleIds = [];

    public function __construct(
        protected CourseSpreadsheetParser $parser,
    ) {}

    public function preview(string $path, ?Course $course = null): array
    {
        $payload = $this->parser->parse($path);
        $this->ensurePayloadHasImportableStructure($payload);
        $this->resetImportCaches();
        $targetCourse = $course ?: Course::query()->where('slug', $payload['course_slug'])->first();
        $this->targetCourseId = $targetCourse?->id;
        $moduleStats = ['create' => 0, 'update' => 0];
        $lessonStats = ['create' => 0, 'update' => 0, 'with_media' => 0, 'without_media' => 0];

        foreach ($payload['modules'] as $moduleData) {
            $moduleName = (string) $moduleData['name'];
            $existingModule = $targetCourse ? $this->findCourseModule($targetCourse, $moduleName) : null;
            $module = $existingModule ?? new CourseModule(['name' => $moduleName]);

            $existingModule ? $moduleStats['update']++ : $moduleStats['create']++;

            foreach ($this->tracksFromModuleData($moduleData) as $trackData) {
                $trackName = trim((string) ($trackData['name'] ?? '')) ?: 'Aulas';
                $track = ($existingModule ? $this->resolveTrackForModule($existingModule, $trackName, $this->trackSlug($trackName, (int) ($trackData['sort_order'] ?? 1))) : null)
                    ?? new CourseModuleTrack(['name' => $trackName]);
                $usedLessonIds = [];

                foreach (array_values($trackData['lessons'] ?? []) as $index => $lessonData) {
                    $title = trim((string) ($lessonData['name'] ?? ''));

                    if ($title === '') {
                        continue;
                    }

                    $slug = $this->lessonSlug($title, (int) ($lessonData['sort_order'] ?? ($index + 1)));
                    $lesson = $this->resolveReusableLesson($module, $title, $slug, $lessonData, $track, $usedLessonIds);

                    $lesson?->exists ? $lessonStats['update']++ : $lessonStats['create']++;
                    $lesson && $this->lessonHasReadyMedia($lesson) ? $lessonStats['with_media']++ : $lessonStats['without_media']++;

                    if ($lesson?->exists) {
                        $usedLessonIds[] = $lesson->id;
                    }
                }
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
                'total' => collect($payload['modules'])->sum(fn (array $module) => count($this->lessonsFromModuleData($module))),
                ...$lessonStats,
            ],
            'total_minutes' => array_sum(array_column($payload['modules'], 'workload_minutes')),
            'errors' => [],
        ];
    }

    public function import(string $path): Course
    {
        $payload = $this->parser->parse($path);
        $this->ensurePayloadHasImportableStructure($payload);
        $this->resetImportCaches();

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
            CourseCatalogController::forgetCourseCatalogCache((int) $course->id);

            return $course->fresh(['modules.tracks.lessons', 'studyTracks.modules']);
        });
    }

    public function importWithProgress(string $path, ?callable $progress = null): Course
    {
        $payload = $this->parser->parse($path);
        $this->ensurePayloadHasImportableStructure($payload);
        $this->resetImportCaches();

        $course = DB::transaction(function () use ($payload) {
            return Course::updateOrCreate(
                ['slug' => $payload['course_slug']],
                [
                    'name' => $payload['course_name'],
                    'description' => 'Curso importado por planilha.',
                    'is_active' => true,
                ],
            );
        });

        return $this->importStructureWithProgress($course, $payload, $payload['study_track_name'], true, $progress);
    }

    public function importInto(Course $course, string $path): Course
    {
        $payload = $this->parser->parse($path);
        $this->ensurePayloadHasImportableStructure($payload);
        $this->resetImportCaches();

        return DB::transaction(function () use ($course, $payload) {
            $studyTrackName = $this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - '.$course->name;

            $this->importStructure($course, $payload, $studyTrackName);
            CourseCatalogController::forgetCourseCatalogCache((int) $course->id);

            return $course->fresh(['modules.tracks.lessons', 'studyTracks.modules']);
        });
    }

    public function importIntoWithProgress(Course $course, string $path, ?callable $progress = null): Course
    {
        $payload = $this->parser->parse($path);
        $this->ensurePayloadHasImportableStructure($payload);
        $this->resetImportCaches();

        $studyTrackName = $this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - '.$course->name;

        return $this->importStructureWithProgress($course, $payload, $studyTrackName, true, $progress);
    }

    public function processImportRun(CourseSpreadsheetImportRun $run, int $moduleLimit = 1): CourseSpreadsheetImportRun
    {
        $run = $run->fresh();

        if (! $run || $run->status === 'finished') {
            return $run;
        }

        if ($run->status === 'failed') {
            return $this->recoverCompletedRunIfPossible($run);
        }

        if (blank($run->stored_path) || ! Storage::disk('local')->exists($run->stored_path)) {
            $recoveredRun = $this->recoverCompletedRunIfPossible($run);

            if ($recoveredRun->status === 'finished') {
                return $recoveredRun;
            }

            $run->forceFill([
                'status' => 'failed',
                'latest_message' => 'A planilha temporária não existe mais.',
                'error_message' => 'Reenvie a planilha para criar uma nova importação.',
                'finished_at' => now(),
            ])->save();

            return $run->fresh();
        }

        $this->resetImportCaches();

        $state = $run->summary['state'] ?? null;

        if (! is_array($state)) {
            $state = $this->startImportRun($run);
            $run->refresh();
        } else {
            $this->removedStructureModuleIds = array_values($state['removed_structure_module_ids'] ?? []);
        }

        $payload = $state['payload'] ?? null;

        if (! is_array($payload)) {
            throw new RuntimeException('O estado da importação está inválido. Reenvie a planilha.');
        }

        $course = Course::query()->findOrFail($run->course_id);
        $modules = array_values($payload['modules'] ?? []);
        $totalModules = count($modules);
        $processedModules = min((int) $run->processed_modules, $totalModules);
        $moduleIds = $state['module_ids'] ?? [];
        $trackIds = $state['track_ids'] ?? [];
        $limit = max(1, $moduleLimit);

        for ($index = $processedModules; $index < $totalModules && $index < $processedModules + $limit; $index++) {
            $moduleData = $modules[$index];

            DB::transaction(function () use ($course, $moduleData, &$moduleIds, &$trackIds): void {
                $this->importModuleData($course, $moduleData, $moduleIds, $trackIds);
            });

            $state['module_ids'] = $moduleIds;
            $state['track_ids'] = $trackIds;
            $run->forceFill([
                'status' => 'running',
                'processed_modules' => $index + 1,
                'latest_message' => sprintf('Módulo %d/%d importado: %s.', $index + 1, $totalModules, (string) ($moduleData['name'] ?? '')),
                'summary' => ['state' => $state],
            ])->save();
        }

        if ((int) $run->processed_modules < $totalModules) {
            return $run->fresh();
        }

        $studyTrackName = (string) ($state['study_track_name'] ?? $payload['study_track_name'] ?? 'Trilha Oficial - '.$course->name);
        $replaceTrackModules = (bool) ($state['replace_track_modules'] ?? true);

        DB::transaction(function () use ($course, $studyTrackName, $moduleIds, $trackIds, $replaceTrackModules): void {
            $this->syncCourseStructureToSpreadsheet($course, array_keys($moduleIds), array_keys($trackIds));
            $this->syncOfficialStudyTrack($course, $studyTrackName, $moduleIds, $replaceTrackModules);
            Course::ensureStartHereModuleIsAttached($course);
            CourseCatalogController::forgetCourseCatalogCache((int) $course->id);
        });

        $run->forceFill([
            'latest_message' => 'Curso atualizado. Revinculando aulas com a biblioteca.',
        ])->save();

        $linkStats = app(LessonCourseLinker::class)->sync($course);
        $modules = $course->modules()->with('tracks.lessons')->get();
        $moduleCount = $modules->count();
        $trackCount = $modules->sum(fn (CourseModule $module): int => $module->tracks->count());
        $lessonCount = $course->linkedLessonsQuery()->count('lessons.id');

        $run->forceFill([
            'status' => 'finished',
            'course_name' => $run->course_name ?: $course->name,
            'processed_modules' => $totalModules,
            'latest_message' => 'Curso atualizado e aulas revinculadas. Planos não foram atualizados automaticamente; alunos precisam clicar em Reprocessar aulas no plano.',
            'error_message' => null,
            'finished_at' => now(),
            'summary' => [
                'course_id' => $course->id,
                'course_name' => $course->name,
                'modules' => $moduleCount,
                'tracks' => $trackCount,
                'lessons' => $lessonCount,
                'link_stats' => $linkStats,
            ],
        ])->save();

        Storage::disk('local')->delete($run->stored_path);

        return $run->fresh();
    }

    /**
     * Roda um passo da importação dentro do polling da tela. Uma falha fica registrada na própria
     * importação: se subisse como exceção, a página daria 500 a cada 5 segundos sem dizer o motivo.
     */
    public function processImportRunExclusively(CourseSpreadsheetImportRun $run): CourseSpreadsheetImportRun
    {
        $lock = Cache::lock('course-spreadsheet-import-run-'.$run->id, 300);

        if (! $lock->get()) {
            return $run;
        }

        try {
            return $this->processImportRun($run);
        } catch (Throwable $exception) {
            report($exception);

            $run->forceFill([
                'status' => 'failed',
                'latest_message' => 'A importação falhou.',
                'error_message' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ])->save();

            return $run->fresh();
        } finally {
            $lock->release();
        }
    }

    protected function recoverCompletedRunIfPossible(CourseSpreadsheetImportRun $run): CourseSpreadsheetImportRun
    {
        if (! $run->course_id) {
            return $run;
        }

        $course = Course::query()->find($run->course_id);

        if (! $course) {
            return $run;
        }

        $modules = $course->modules()->with('tracks.lessons')->get();
        $moduleCount = $modules->reject(fn (CourseModule $module): bool => $module->shouldBeExcludedFromStudyPlan())->count();
        $trackCount = $modules->sum(fn (CourseModule $module): int => $module->tracks->count());
        $lessonCount = $course->linkedLessonsQuery()->count('lessons.id');

        $hasExpectedStructure = ((int) $run->total_tracks <= 0 || $trackCount >= (int) $run->total_tracks)
            && ((int) $run->total_lessons <= 0 || $lessonCount >= (int) $run->total_lessons)
            && ((int) $run->total_modules <= 0 || $moduleCount >= (int) $run->total_modules);

        if (! $hasExpectedStructure) {
            return $run;
        }

        $linkStats = app(LessonCourseLinker::class)->sync($course);

        $run->forceFill([
            'status' => 'finished',
            'processed_modules' => max((int) $run->processed_modules, (int) $run->total_modules),
            'latest_message' => 'Curso atualizado e aulas revinculadas. Planos não foram atualizados automaticamente; alunos precisam clicar em Reprocessar aulas no plano.',
            'error_message' => null,
            'finished_at' => $run->finished_at ?: now(),
            'summary' => [
                'course_id' => $course->id,
                'course_name' => $course->name,
                'modules' => $moduleCount,
                'tracks' => $trackCount,
                'lessons' => $lessonCount,
                'link_stats' => $linkStats,
                'recovered_from_completed_structure' => true,
            ],
        ])->save();

        return $run->fresh();
    }

    protected function startImportRun(CourseSpreadsheetImportRun $run): array
    {
        $payload = $this->parser->parse(Storage::disk('local')->path($run->stored_path));
        $this->ensurePayloadHasImportableStructure($payload);

        $course = $run->course_id
            ? Course::query()->findOrFail($run->course_id)
            : DB::transaction(fn () => Course::updateOrCreate(
                ['slug' => $payload['course_slug']],
                [
                    'name' => $payload['course_name'],
                    'description' => 'Curso importado por planilha.',
                    'is_active' => true,
                ],
            ));

        $studyTrackName = $run->course_id
            ? ($this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - '.$course->name)
            : $payload['study_track_name'];

        $state = [
            'payload' => $payload,
            'study_track_name' => $studyTrackName,
            'replace_track_modules' => true,
            'module_ids' => [],
            'track_ids' => [],
            'removed_structure_module_ids' => $this->removedStructureModuleIds,
        ];

        $run->forceFill([
            'course_id' => $course->id,
            'course_name' => $run->course_name ?: $course->name,
            'status' => 'running',
            'latest_message' => 'Importação em andamento.',
            'error_message' => null,
            'started_at' => $run->started_at ?: now(),
            'summary' => ['state' => $state],
        ])->save();

        return $state;
    }

    protected function ensurePayloadHasImportableStructure(array $payload): void
    {
        $modules = $payload['modules'] ?? [];
        $trackCount = collect($modules)->sum(fn (array $module): int => count($module['tracks'] ?? []));
        $lessonCount = collect($modules)->sum(fn (array $module): int => count($this->lessonsFromModuleData($module)));

        if ($modules === [] || ($trackCount === 0 && $lessonCount === 0)) {
            throw new RuntimeException('A planilha não possui módulos, trilhas ou aulas importáveis. Confira se as trilhas estão identificadas e se as aulas estão na coluna A com carga horária/minutos na coluna B.');
        }
    }

    protected function importStructure(Course $course, array $payload, string $studyTrackName, bool $replaceTrackModules = true): void
    {
        $moduleIds = [];
        $trackIds = [];

        foreach ($payload['modules'] as $moduleData) {
            $this->importModuleData($course, $moduleData, $moduleIds, $trackIds);
        }

        $this->syncCourseStructureToSpreadsheet($course, array_keys($moduleIds), array_keys($trackIds));
        $this->syncOfficialStudyTrack($course, $studyTrackName, $moduleIds, $replaceTrackModules);
        Course::ensureStartHereModuleIsAttached($course);
    }

    protected function importStructureWithProgress(Course $course, array $payload, string $studyTrackName, bool $replaceTrackModules = true, ?callable $progress = null): Course
    {
        $moduleIds = [];
        $trackIds = [];
        $modules = array_values($payload['modules'] ?? []);
        $totalModules = count($modules);

        foreach ($modules as $index => $moduleData) {
            DB::transaction(function () use ($course, $moduleData, &$moduleIds, &$trackIds): void {
                $this->importModuleData($course, $moduleData, $moduleIds, $trackIds);
            });

            $progress?->__invoke([
                'course_id' => $course->id,
                'processed_modules' => $index + 1,
                'total_modules' => $totalModules,
                'message' => sprintf('Módulo %d/%d importado: %s.', $index + 1, $totalModules, (string) ($moduleData['name'] ?? '')),
            ]);
        }

        DB::transaction(function () use ($course, $studyTrackName, $moduleIds, $trackIds, $replaceTrackModules): void {
            $this->syncCourseStructureToSpreadsheet($course, array_keys($moduleIds), array_keys($trackIds));
            $this->syncOfficialStudyTrack($course, $studyTrackName, $moduleIds, $replaceTrackModules);
            Course::ensureStartHereModuleIsAttached($course);
            CourseCatalogController::forgetCourseCatalogCache((int) $course->id);
        });

        return $course->fresh(['modules.tracks.lessons', 'studyTracks.modules']);
    }

    protected function importModuleData(Course $course, array $moduleData, array &$moduleIds, array &$trackIds = []): void
    {
        if ($this->shouldAttachCompoundTrackModules($moduleData)) {
            $moduleData = $this->attachCompoundTrackModules($course, $moduleData, $moduleIds, $trackIds);
        }

        if (empty($moduleData['tracks']) && empty($moduleData['lessons'])) {
            return;
        }

        $module = $this->resolveModuleForImport($course, $moduleData);
        $moduleTeacherName = filled($moduleData['teacher_name'] ?? null)
            ? trim((string) $moduleData['teacher_name'])
            : null;
        $moduleTeacher = $moduleTeacherName ? $this->resolveTeacher($moduleTeacherName) : null;

        $module->fill([
            'course_id' => null,
            'teacher_id' => $moduleTeacher?->id ?: $module->teacher_id,
            'name' => $module->name ?: $moduleData['name'],
            'type' => $moduleData['type'],
            'teacher_name' => $moduleTeacherName ?: $module->teacher_name,
            'lessons' => $moduleData['lessons'] ?? [],
            'workload_minutes' => $moduleData['workload_minutes'],
            'sort_order' => $moduleData['sort_order'],
            'is_active' => true,
        ]);
        $module->save();
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => $moduleData['sort_order']],
        ]);

        foreach ($this->importTracks($course, $module, $moduleData) as $trackId) {
            $trackIds[$trackId] = true;
        }

        $moduleIds[$module->id] = [
            'weight' => 1,
            'sort_order' => $moduleData['sort_order'],
        ];
    }

    protected function syncOfficialStudyTrack(Course $course, string $studyTrackName, array $moduleIds, bool $replaceTrackModules): void
    {
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
        } else {
            $studyTrack->modules()->syncWithoutDetaching($moduleIds);
        }
    }

    protected function syncCourseStructureToSpreadsheet(Course $course, array $importedModuleIds, array $importedTrackIds): void
    {
        $importedModuleIds = array_map('intval', $importedModuleIds);
        $importedTrackIds = array_map('intval', $importedTrackIds);
        $modules = $this->modulesForCourseStructureReplacement($course);

        foreach ($modules as $module) {
            $moduleWasImported = in_array((int) $module->id, $importedModuleIds, true);

            if (! $moduleWasImported) {
                $module->courses()->detach($course->id);

                if ((int) $module->course_id === (int) $course->id) {
                    $module->forceFill(['course_id' => null])->save();
                }
            }

            $module->tracks()->get()->each(function (CourseModuleTrack $track) use ($course, $importedTrackIds, $moduleWasImported): void {
                if ($moduleWasImported && in_array((int) $track->id, $importedTrackIds, true)) {
                    return;
                }

                $track->courses()->detach($course->id);
                DB::table('course_module_track_lesson_course')
                    ->where('course_id', $course->id)
                    ->where('course_module_track_id', $track->id)
                    ->delete();
            });
        }
    }

    protected function shouldAttachCompoundTrackModules(array $moduleData): bool
    {
        return false;
    }

    protected function replaceExistingOfficialStructure(Course $course, string $_studyTrackName): void
    {
        $modules = $this->modulesForCourseStructureReplacement($course);

        if ($modules->isEmpty()) {
            return;
        }

        $course->studyTracks()
            ->get()
            ->each(fn (StudyTrack $track) => $track->modules()->detach($modules->pluck('id')->all()));

        foreach ($modules as $module) {
            $this->removedStructureModuleIds[] = (int) $module->id;
            $module->courses()->detach($course->id);
            $module->tracks()->get()->each(function (CourseModuleTrack $track) use ($course): void {
                $track->courses()->detach($course->id);
                DB::table('course_module_track_lesson_course')
                    ->where('course_id', $course->id)
                    ->where('course_module_track_id', $track->id)
                    ->delete();
            });

            $belongsOnlyToThisCourse = blank($module->course_id) || (int) $module->course_id === (int) $course->id;
            $isSharedThroughPivot = (int) $module->courses_count > 1;

            if ($belongsOnlyToThisCourse && ! $isSharedThroughPivot) {
                $module->delete();

                continue;
            }

            if ((int) $module->course_id === (int) $course->id) {
                $module->forceFill(['course_id' => null])->save();
            }
        }

        $this->removedStructureModuleIds = array_values(array_unique($this->removedStructureModuleIds));
    }

    protected function modulesForCourseStructureReplacement(Course $course): Collection
    {
        return CourseModule::query()
            ->withCount('courses')
            ->where(function ($query) use ($course): void {
                $query
                    ->where('course_id', $course->id)
                    ->orWhereHas('courses', fn ($query) => $query->whereKey($course->id));
            })
            ->get()
            ->reject(fn (CourseModule $module): bool => $module->shouldBeExcludedFromStudyPlan())
            ->values();
    }

    protected function attachCompoundTrackModules(Course $course, array $moduleData, array &$moduleIds, array &$trackIds = []): array
    {
        $tracks = $moduleData['tracks'] ?? [];

        if ($tracks === []) {
            return $moduleData;
        }

        $remainingTracks = [];

        foreach (array_values($tracks) as $index => $trackData) {
            $trackName = trim((string) ($trackData['name'] ?? ''));
            $compoundModule = $trackName !== ''
                ? $this->resolveCompoundTrackModule($course, (string) $moduleData['name'], $trackName)
                : null;

            if (! $compoundModule) {
                $remainingTracks[] = $trackData;

                continue;
            }

            $sortOrder = (int) ($trackData['sort_order'] ?? ($index + 1));
            $lessons = $this->lessonsForCompoundTrackModule($compoundModule, $trackData);
            $metadata = is_array($compoundModule->metadata) ? $compoundModule->metadata : [];

            $compoundModule->fill([
                'course_id' => null,
                'type' => $moduleData['type'],
                'lessons' => $lessons,
                'workload_minutes' => (int) ($trackData['workload_minutes'] ?? collect($lessons)->sum('minutes')),
                'sort_order' => $moduleData['sort_order'] * 100 + $sortOrder,
                'is_active' => true,
                'metadata' => array_merge($metadata, [
                    'matched_from_spreadsheet_module' => $moduleData['name'],
                    'matched_from_spreadsheet_track' => $trackName,
                    'matched_as_compound_track_module' => true,
                    'imported_at' => now()->toIso8601String(),
                ]),
            ]);
            $compoundModule->save();
            $compoundModule->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => $compoundModule->sort_order],
            ]);

            foreach ($this->importTracks($course, $compoundModule, [
                ...$moduleData,
                'name' => $compoundModule->name,
                'workload_minutes' => $compoundModule->workload_minutes,
                'lessons' => $lessons,
                'tracks' => [[
                    ...$trackData,
                    'lessons' => $lessons,
                ]],
            ]) as $trackId) {
                $trackIds[$trackId] = true;
            }

            $moduleIds[$compoundModule->id] = [
                'weight' => 1,
                'sort_order' => $compoundModule->sort_order,
            ];
        }

        return [
            ...$moduleData,
            'tracks' => $remainingTracks,
            'lessons' => $remainingTracks === [] ? [] : ($moduleData['lessons'] ?? []),
            'workload_minutes' => collect($remainingTracks)->sum(fn (array $track): int => (int) ($track['workload_minutes'] ?? 0)),
        ];
    }

    protected function resolveOfficialStudyTrackName(Course $course): ?string
    {
        return $course->studyTracks()
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->value('name');
    }

    protected function importTracks(Course $course, CourseModule $module, array $moduleData): array
    {
        $tracks = array_values($moduleData['tracks'] ?? [[
            'name' => $moduleData['track_name'] ?? 'Aulas',
            'sort_order' => 1,
            'workload_minutes' => $moduleData['workload_minutes'] ?? 0,
            'lessons' => $moduleData['lessons'] ?? [],
        ]]);
        $trackSlugCounts = collect($tracks)
            ->map(function (array $trackData, int $index): string {
                $trackName = trim((string) ($trackData['name'] ?? '')) ?: 'Aulas';
                $sortOrder = (int) ($trackData['sort_order'] ?? ($index + 1));

                return $this->trackSlug($trackName, $sortOrder);
            })
            ->countBy();
        $usedTrackSlugs = [];
        $trackIds = [];

        foreach ($tracks as $index => $trackData) {
            $trackName = trim((string) ($trackData['name'] ?? '')) ?: 'Aulas';
            $sortOrder = (int) ($trackData['sort_order'] ?? ($index + 1));
            $baseSlug = $this->trackSlug($trackName, $sortOrder);
            $hasDuplicateTrackSlug = (int) ($trackSlugCounts[$baseSlug] ?? 0) > 1;
            $slug = $this->uniqueTrackSlug($baseSlug, $sortOrder, $usedTrackSlugs, $hasDuplicateTrackSlug);
            $usedTrackSlugs[$slug] = true;
            $track = $this->resolveTrackForModule($module, $trackName, $slug, ! $hasDuplicateTrackSlug)
                ?? new CourseModuleTrack([
                    'course_module_id' => $module->id,
                    'slug' => $slug,
                ]);
            $teacherName = filled($trackData['teacher_name'] ?? null)
                ? trim((string) $trackData['teacher_name'])
                : null;
            $teacher = $teacherName ? $this->resolveTeacher($teacherName) : null;

            $track->fill([
                'course_module_id' => $module->id,
                'name' => $trackName,
                'slug' => $track->exists ? $track->slug : $slug,
                'teacher_id' => $teacher?->id ?: $track->teacher_id,
                'teacher_name' => $teacherName ?: $track->teacher_name,
                'thumbnail_url' => $trackData['thumbnail_url'] ?? $track->thumbnail_url,
                'sort_order' => $sortOrder,
                'status' => $trackData['status'] ?? 'published',
                'panda_folder_id' => $trackData['panda_folder_id'] ?? $track->panda_folder_id,
                'google_doc_url' => $trackData['google_doc_url'] ?? $track->google_doc_url,
                'metadata' => [
                    'source' => 'spreadsheet',
                    'workload_minutes' => $trackData['workload_minutes'] ?? 0,
                    'imported_at' => now()->toIso8601String(),
                ],
            ]);
            $track->save();
            $track->courses()->syncWithoutDetaching([
                $course->id => ['sort_order' => $sortOrder],
            ]);

            $this->importLessons($course, $module, $track, $trackData['lessons'] ?? []);
            $trackIds[] = (int) $track->id;
        }

        return $trackIds;
    }

    protected function resolveTeacher(string $name): Teacher
    {
        $normalizedName = $this->normalizeTeacherName($name);

        $teacher = Teacher::query()
            ->orderBy('id')
            ->get()
            ->first(fn (Teacher $teacher): bool => $this->normalizeTeacherName($teacher->name) === $normalizedName);

        if ($teacher) {
            return $teacher;
        }

        return Teacher::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function normalizeTeacherName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/\b(professor|professora|prof|profa|doutor|doutora|dr|dra)\b\.?/u', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }

    protected function importLessons(Course $course, CourseModule $module, CourseModuleTrack $track, array $lessons): void
    {
        $usedLessonIds = [];
        $this->targetCourseId = (int) $course->id;

        foreach (array_values($lessons) as $index => $lessonData) {
            $title = trim((string) ($lessonData['name'] ?? ''));

            if ($title === '') {
                continue;
            }

            $sortOrder = (int) ($lessonData['sort_order'] ?? ($index + 1));
            $slug = $this->lessonSlug($title, $sortOrder);
            $minutes = max(0, (int) ($lessonData['minutes'] ?? 0));

            $pandaVideoId = filled($lessonData['panda_video_id'] ?? null) ? (string) $lessonData['panda_video_id'] : null;
            $lesson = $this->resolveReusableLesson($module, $title, $slug, $lessonData, $track, $usedLessonIds)
                ?? ($pandaVideoId
                    ? $this->firstLessonOrNewForPandaVideo($pandaVideoId)
                    : new Lesson([
                        'course_module_id' => null,
                        'course_module_track_id' => null,
                        'slug' => $slug,
                    ]));
            $lessonExists = $lesson->exists;
            $usedByOtherCourse = $lessonExists && $this->lessonIsUsedByOtherCourse($lesson, $course);
            $matchedStandaloneLibraryLesson = $lessonExists
                && blank($lesson->course_id)
                && blank($lesson->course_module_id)
                && blank($lesson->course_module_track_id);
            $hasReadyMedia = $pandaVideoId
                || filled($lessonData['panda_embed_url'] ?? null)
                || filled($lessonData['panda_player_url'] ?? null)
                || filled($lesson->panda_video_id)
                || filled($lesson->panda_embed_url)
                || filled($lesson->panda_player_url)
                || filled($lesson->video?->provider_video_id)
                || filled($lesson->video?->embed_url)
                || filled($lesson->video?->player_url);
            $previousMetadata = is_array($lesson->metadata) ? $lesson->metadata : [];
            // A aula com vídeo ou usada em outro curso pertence à biblioteca: reaproveitar não pode
            // mudar o que os outros cursos exibem nem a pasta que identifica o contexto dela.
            $keepsLibraryData = $lessonExists && ($usedByOtherCourse || $this->lessonHasReadyMedia($lesson));
            $libraryFolderPath = collect([$module->name, $track->name])->filter()->join(' / ');
            $lessonFolderId = $keepsLibraryData
                ? $lesson->lesson_folder_id
                : $this->libraryFolderId($libraryFolderPath);
            $importMetadata = $keepsLibraryData
                ? []
                : [
                    'source' => 'spreadsheet',
                    'library_folder_name' => $track->name,
                    'library_folder_path' => $libraryFolderPath,
                ];

            $lesson->fill([
                'course_id' => null,
                'lesson_folder_id' => $lessonFolderId,
                'course_module_id' => $this->resolveLessonModuleId($lesson, $module),
                'course_module_track_id' => $this->resolveLessonTrackId($lesson, $track),
                'title' => $usedByOtherCourse ? $lesson->title : $title,
                'slug' => $lessonExists ? $lesson->slug : $slug,
                'description' => $lessonExists && $lesson->description !== 'Aula importada por planilha.'
                    ? $lesson->description
                    : null,
                'type' => $this->normalizeLessonType((string) ($lessonData['type'] ?? 'video')),
                'thumbnail_url' => $lessonData['thumbnail_url'] ?? $lesson->thumbnail_url,
                'duration_seconds' => $keepsLibraryData && (int) $lesson->duration_seconds > 0 ? $lesson->duration_seconds : $minutes * 60,
                'sort_order' => $usedByOtherCourse ? $lesson->sort_order : $sortOrder,
                'status' => $this->spreadsheetLessonStatus($hasReadyMedia),
                'panda_video_id' => $pandaVideoId ?: $lesson->panda_video_id,
                'panda_embed_url' => $lessonData['panda_embed_url'] ?? $lesson->panda_embed_url,
                'panda_player_url' => $lessonData['panda_player_url'] ?? $lesson->panda_player_url,
                'google_doc_url' => $lessonData['google_doc_url'] ?? $lesson->google_doc_url,
                'source_status' => $this->spreadsheetLessonSourceStatus($lesson, $hasReadyMedia),
                'metadata' => array_merge($previousMetadata, $importMetadata, [
                    'matched_existing_lesson' => $lessonExists,
                    'matched_standalone_library_lesson' => $matchedStandaloneLibraryLesson,
                    'matched_by_name' => $lessonExists && $this->lessonNamesMatch($lesson->title, $title),
                    'matched_by_duration' => $lessonExists && $this->lessonDurationMatchesImport($lesson, $lessonData),
                    'imported_at' => now()->toIso8601String(),
                ]),
            ]);
            $lesson->save();
            $lesson->modules()->syncWithoutDetaching([
                $module->id => ['sort_order' => $sortOrder],
            ]);
            $lesson->tracks()->syncWithoutDetaching([
                $track->id => ['sort_order' => $sortOrder],
            ]);
            DB::table('course_module_track_lesson_course')->updateOrInsert([
                'course_id' => $course->id,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
            ], [
                'sort_order' => $sortOrder,
                'status' => $this->normalizeLessonStatus((string) ($lessonData['status'] ?? 'published')),
                'metadata' => json_encode([
                    'source' => 'spreadsheet',
                    'imported_at' => now()->toIso8601String(),
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            $conflictingLessonIds = $track->lessons()
                ->wherePivot('sort_order', $sortOrder)
                ->whereKeyNot($lesson->id)
                ->pluck('lessons.id')
                ->all();

            if ($conflictingLessonIds !== []) {
                DB::table('course_module_track_lesson_course')
                    ->where('course_id', $course->id)
                    ->where('course_module_track_id', $track->id)
                    ->whereIn('lesson_id', $conflictingLessonIds)
                    ->delete();
            }

            $usedLessonIds[] = $lesson->id;
            $this->rememberReusableLessonCandidate($lesson->fresh());
        }

        DB::table('course_module_track_lesson_course')
            ->where('course_id', $course->id)
            ->where('course_module_track_id', $track->id)
            ->when($usedLessonIds !== [], fn ($query) => $query->whereNotIn('lesson_id', $usedLessonIds))
            ->delete();

        $this->detachLessonsOutsideAnyCourseScope($module, $track, $usedLessonIds);
    }

    /**
     * Tira da trilha as aulas que nenhum curso usa mais nela, como o placeholder trocado por uma
     * aula com vídeo. Sem isso, a aula antiga continuaria aparecendo na trilha.
     */
    protected function detachLessonsOutsideAnyCourseScope(CourseModule $module, CourseModuleTrack $track, array $usedLessonIds): void
    {
        $orphanLessonIds = DB::table('course_module_track_lessons')
            ->where('course_module_track_id', $track->id)
            ->when($usedLessonIds !== [], fn ($query) => $query->whereNotIn('lesson_id', $usedLessonIds))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('course_module_track_lesson_course')
                ->whereColumn('course_module_track_lesson_course.lesson_id', 'course_module_track_lessons.lesson_id')
                ->where('course_module_track_lesson_course.course_module_track_id', $track->id))
            ->pluck('lesson_id');

        if ($orphanLessonIds->isEmpty()) {
            return;
        }

        $track->lessons()->detach($orphanLessonIds->all());

        $lessonIdsInOtherTracks = DB::table('course_module_track_lessons')
            ->whereIn('course_module_track_id', $module->tracks()->pluck('id'))
            ->whereIn('lesson_id', $orphanLessonIds)
            ->pluck('lesson_id');

        $module->onlineLessons()->detach($orphanLessonIds->diff($lessonIdsInOtherTracks)->all());
    }

    protected function lessonIsUsedByOtherCourse(Lesson $lesson, Course $course): bool
    {
        return DB::table('course_module_track_lesson_course')
            ->where('lesson_id', $lesson->id)
            ->where('course_id', '!=', $course->id)
            ->exists();
    }

    protected function libraryFolderId(string $path): ?int
    {
        if (array_key_exists($path, $this->folderIds)) {
            return $this->folderIds[$path];
        }

        return $this->folderIds[$path] = LessonFolder::findOrCreatePath($path, ['source' => 'spreadsheet'])?->id;
    }

    protected function spreadsheetLessonStatus(bool $hasReadyMedia): string
    {
        return 'published';
    }

    protected function spreadsheetLessonSourceStatus(Lesson $lesson, bool $hasReadyMedia): string
    {
        // Envio e processamento em andamento não podem ser apagados por uma reimportação.
        if ($lesson->exists && in_array($lesson->source_status, ['uploading', 'upload_queued', 'upload_failed', 'panda_processing'], true)) {
            return $lesson->source_status;
        }

        return $hasReadyMedia ? 'media_ready' : 'awaiting_media';
    }

    protected function resolveLessonModuleId(Lesson $lesson, CourseModule $module): int|string|null
    {
        return $lesson->course_module_id;
    }

    protected function resolveLessonTrackId(Lesson $lesson, CourseModuleTrack $track): int|string|null
    {
        return $lesson->course_module_track_id;
    }

    protected function lessonSlug(string $title, int $sortOrder): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'aula-'.$sortOrder;
    }

    protected function trackSlug(string $title, int $sortOrder): string
    {
        $slug = Str::slug($title);

        return $slug !== '' ? $slug : 'trilha-'.$sortOrder;
    }

    protected function normalizeLessonType(string $type): string
    {
        return in_array($type, ['video', 'pdf', 'mixed', 'text', 'quiz'], true) ? $type : 'video';
    }

    protected function normalizeLessonStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'published';
    }

    protected function resolveModuleForImport(Course $course, array $moduleData): CourseModule
    {
        $moduleName = (string) $moduleData['name'];

        return $this->findCourseModule($course, $moduleName)
            ?? new CourseModule([
                'course_id' => null,
                'name' => $moduleName,
            ]);
    }

    protected function findCourseModule(Course $course, string $moduleName): ?CourseModule
    {
        return $course->modules()
            ->where('course_modules.name', $moduleName)
            ->first()
            ?? CourseModule::query()
                ->where('course_id', $course->id)
                ->where('name', $moduleName)
                ->first();
    }

    protected function cloneReusableModuleForImport(CourseModule $sourceModule, array $moduleData): CourseModule
    {
        return new CourseModule([
            'course_id' => null,
            'teacher_id' => $sourceModule->teacher_id,
            'name' => $moduleData['name'] ?: $sourceModule->name,
            'description' => $sourceModule->description,
            'teacher_name' => $sourceModule->teacher_name,
            'type' => $moduleData['type'] ?? $sourceModule->type,
            'panda_folder_id' => $sourceModule->panda_folder_id,
            'metadata' => array_merge(is_array($sourceModule->metadata) ? $sourceModule->metadata : [], [
                'cloned_from_module_id' => $sourceModule->id,
                'cloned_from_module_name' => $sourceModule->name,
                'cloned_reason' => 'spreadsheet_structure_differs',
                'cloned_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    protected function moduleStructureMatchesImport(CourseModule $module, array $moduleData): bool
    {
        $existingTrackSignatures = $this->moduleTrackSignatures($module);
        $incomingTrackSignatures = $this->moduleDataTrackSignatures($moduleData);

        if ($existingTrackSignatures !== [] && $incomingTrackSignatures !== []) {
            return $existingTrackSignatures === $incomingTrackSignatures;
        }

        return $this->lessonSignatures($module->planning_lessons) === $this->lessonSignatures($this->lessonsFromModuleData($moduleData));
    }

    protected function moduleTrackSignatures(CourseModule $module): array
    {
        return $module->tracks()
            ->where('status', 'published')
            ->with('lessons')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CourseModuleTrack $track): array => [
                'name' => $this->normalizeName($track->name),
                'lessons' => $this->lessonSignatures($track->lessons
                    ->sortBy(fn (Lesson $lesson): array => [(int) $lesson->pivot?->sort_order, (int) $lesson->sort_order, $lesson->title])
                    ->map(fn (Lesson $lesson): array => [
                        'name' => $lesson->title,
                        'minutes' => (int) $lesson->duration_minutes,
                    ])
                    ->values()
                    ->all()),
            ])
            ->values()
            ->all();
    }

    protected function moduleDataTrackSignatures(array $moduleData): array
    {
        $tracks = collect($moduleData['tracks'] ?? []);

        if ($tracks->count() === 1 && $this->normalizeName((string) ($tracks->first()['name'] ?? '')) === $this->normalizeName((string) ($moduleData['name'] ?? ''))) {
            return [];
        }

        return $tracks
            ->sortBy(fn (array $track): array => [(int) ($track['sort_order'] ?? 0), $this->normalizeName((string) ($track['name'] ?? ''))])
            ->map(fn (array $track): array => [
                'name' => $this->normalizeName((string) ($track['name'] ?? 'Aulas')),
                'lessons' => $this->lessonSignatures($track['lessons'] ?? []),
            ])
            ->values()
            ->all();
    }

    protected function lessonSignatures(array $lessons): array
    {
        return collect($lessons)
            ->map(fn (array $lesson): array => [
                'name' => $this->normalizeName((string) ($lesson['name'] ?? '')),
                'minutes' => (int) ($lesson['minutes'] ?? 0),
            ])
            ->filter(fn (array $lesson): bool => $lesson['name'] !== '')
            ->values()
            ->all();
    }

    protected function resolveCompoundTrackModule(Course $course, string $moduleName, string $trackName): ?CourseModule
    {
        $normalizedName = $this->normalizeName($moduleName.' '.$trackName);

        return $course->modules()
            ->get()
            ->first(fn (CourseModule $module): bool => $this->normalizeName($module->name) === $normalizedName)
            ?? CourseModule::query()
                ->when($this->removedStructureModuleIds !== [], fn ($query) => $query->whereNotIn('id', $this->removedStructureModuleIds))
                ->orderBy('id')
                ->get()
                ->first(fn (CourseModule $module): bool => $this->normalizeName($module->name) === $normalizedName);
    }

    protected function lessonsForCompoundTrackModule(CourseModule $module, array $trackData): array
    {
        $moduleLessons = collect($module->lessons ?? [])
            ->filter(fn (array $lesson): bool => trim((string) ($lesson['name'] ?? '')) !== '')
            ->values()
            ->all();

        return $moduleLessons !== [] ? $moduleLessons : ($trackData['lessons'] ?? []);
    }

    protected function uniqueTrackSlug(string $baseSlug, int $sortOrder, array $usedTrackSlugs, bool $forceSortOrderSuffix = false): string
    {
        $slug = $forceSortOrderSuffix ? $baseSlug.'-'.$sortOrder : $baseSlug;
        $attempt = 2;

        while (isset($usedTrackSlugs[$slug])) {
            $slug = $baseSlug.'-'.$sortOrder.'-'.$attempt;
            $attempt++;
        }

        return $slug;
    }

    protected function resolveTrackForModule(CourseModule $module, string $trackName, string $slug, bool $allowNameFallback = true): ?CourseModuleTrack
    {
        $track = $module->tracks()
            ->where('slug', $slug)
            ->first();

        if ($track) {
            return $track;
        }

        if (! $allowNameFallback) {
            return null;
        }

        $normalizedName = $this->normalizeName($trackName);

        return $module->tracks()
            ->get()
            ->first(fn (CourseModuleTrack $track) => $this->normalizeName($track->name) === $normalizedName);
    }

    protected function resolveReusableLesson(CourseModule $module, string $title, string $slug, array $lessonData, ?CourseModuleTrack $track = null, array $excludeLessonIds = []): ?Lesson
    {
        $pandaVideoId = filled($lessonData['panda_video_id'] ?? null) ? (string) $lessonData['panda_video_id'] : null;

        if ($pandaVideoId) {
            $lesson = Lesson::query()
                ->where(function ($query) use ($pandaVideoId): void {
                    $query->where('panda_video_id', $pandaVideoId)
                        ->orWhereHas('video', fn ($query) => $query->where('provider_video_id', $pandaVideoId));
                })
                ->when($excludeLessonIds !== [], fn ($query) => $query->whereNotIn('id', $excludeLessonIds))
                ->first();

            if ($lesson) {
                return $lesson;
            }
        }

        $fallbackLesson = null;

        if ($track) {
            $lesson = $track->lessons()
                ->where('lessons.slug', $slug)
                ->when($excludeLessonIds !== [], fn ($query) => $query->whereNotIn('lessons.id', $excludeLessonIds))
                ->first();

            if ($lesson && $this->lessonHasReadyMedia($lesson)) {
                return $lesson;
            }

            $fallbackLesson = $lesson;
        }

        $lesson = $module->onlineLessons()
            ->where('lessons.slug', $slug)
            ->when($excludeLessonIds !== [], fn ($query) => $query->whereNotIn('lessons.id', $excludeLessonIds))
            ->first();

        if ($lesson && $this->lessonHasReadyMedia($lesson) && $this->lessonMatchesImportContext($lesson, $module, $track)) {
            return $lesson;
        }

        if ($lesson && $this->lessonMatchesImportContext($lesson, $module, $track)) {
            $fallbackLesson ??= $lesson;
        }

        $lessonIdsInUse = $this->lessonIdsInUse($track);
        $matchedLesson = $this->reusableLessonCandidatesFor($title, $module, $track)
            ->when($excludeLessonIds !== [], fn (Collection $lessons) => $lessons->whereNotIn('id', $excludeLessonIds))
            ->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'score' => $this->lessonMatchScore($lesson, $title, $module, $track, $lessonData),
                'media_priority' => $this->lessonMediaPriority($lesson),
                'in_use' => isset($lessonIdsInUse[$lesson->id]),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sort(function (array $left, array $right): int {
                return [$right['media_priority'], $right['in_use'], $right['score'], -$right['lesson']->id]
                    <=> [$left['media_priority'], $left['in_use'], $left['score'], -$left['lesson']->id];
            });

        $exactMatchedCandidate = $this->isGenericLessonTitle($title)
            ? $matchedLesson->first(fn (array $candidate): bool => $this->matchKey($candidate['lesson']->title, 'lesson-title-key-'.$candidate['lesson']->id) === $this->matchKey($title))
            : null;
        $exactMatchedLesson = $exactMatchedCandidate['lesson'] ?? null;

        $matchedLesson = $exactMatchedLesson ?? ($matchedLesson->first()['lesson'] ?? null);

        return $matchedLesson
            ?? $this->resolveReusableVideoLesson($title, $module, $track, $lessonData, $excludeLessonIds)
            ?? $fallbackLesson;
    }

    protected function resolveReusableVideoLesson(string $title, CourseModule $module, ?CourseModuleTrack $track = null, array $lessonData = [], array $excludeLessonIds = []): ?Lesson
    {
        $candidate = $this->reusableVideoCandidatesFor($title, $module, $track)
            ->map(function (Video $video) use ($title, $module, $track, $lessonData, $excludeLessonIds): array {
                $lesson = $this->firstLessonForVideo($video);

                if ($lesson && in_array((int) $lesson->id, array_map('intval', $excludeLessonIds), true)) {
                    return ['video' => $video, 'lesson' => null, 'score' => 0.0];
                }

                return [
                    'video' => $video,
                    'lesson' => $lesson,
                    'score' => $this->videoMatchScore($video, $title, $module, $track, $lessonData),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sortByDesc('score')
            ->first();

        if (! $candidate) {
            return null;
        }

        if ($candidate['lesson'] instanceof Lesson) {
            return $candidate['lesson'];
        }

        /** @var Video $video */
        $video = $candidate['video'];

        return new Lesson([
            'lesson_folder_id' => $video->lesson_folder_id,
            'video_id' => $video->id,
            'title' => $video->title,
            'slug' => $video->slug ?: (Str::slug($video->title) ?: 'video-'.$video->id),
            'description' => $video->description,
            'thumbnail_url' => $video->thumbnail_url,
            'duration_seconds' => (int) $video->duration_seconds,
            'panda_video_id' => $video->provider_video_id,
            'panda_embed_url' => $video->embed_url,
            'panda_player_url' => $video->player_url,
            'source_status' => $video->source_status ?: 'media_ready',
        ]);
    }

    protected function firstLessonOrNewForPandaVideo(string $pandaVideoId): Lesson
    {
        $lesson = Lesson::query()
            ->where('panda_video_id', $pandaVideoId)
            ->orWhereHas('video', fn ($query) => $query->where('provider_video_id', $pandaVideoId))
            ->first();

        if ($lesson) {
            return $lesson;
        }

        $video = Video::query()->firstOrNew([
            'provider_video_id' => $pandaVideoId,
        ], [
            'provider' => 'panda',
            'source_status' => 'media_ready',
        ]);

        if (! $video->exists) {
            $video->fill([
                'title' => $pandaVideoId,
                'slug' => Str::slug($pandaVideoId) ?: $pandaVideoId,
                'provider_status' => null,
            ])->save();
        }

        return new Lesson([
            'video_id' => $video->id,
            'panda_video_id' => $pandaVideoId,
            'source_status' => $video->source_status,
        ]);
    }

    protected function tracksFromModuleData(array $moduleData): array
    {
        return array_values($moduleData['tracks'] ?? [[
            'name' => $moduleData['track_name'] ?? 'Aulas',
            'sort_order' => 1,
            'lessons' => $moduleData['lessons'] ?? [],
        ]]);
    }

    protected function lessonsFromModuleData(array $moduleData): array
    {
        if (! empty($moduleData['tracks'])) {
            return collect($moduleData['tracks'])
                ->flatMap(fn (array $track) => $track['lessons'] ?? [])
                ->values()
                ->all();
        }

        return $moduleData['lessons'] ?? [];
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

    protected function normalizeLessonName(string $value): string
    {
        return LessonTitleNormalizer::matchKey($value);
    }

    protected function lessonNamesMatch(string $left, string $right): bool
    {
        return $this->matchScoreForKeys($this->matchKey($left), $this->matchKey($right)) >= 72;
    }

    protected function lessonMatchScore(Lesson $lesson, string $title, CourseModule $module, ?CourseModuleTrack $track = null, array $lessonData = []): float
    {
        $titleKey = $this->matchKey($title);
        [$lessonKey, $score, $matchedTitle] = $this->bestLessonTitleMatch($lesson, $titleKey);
        $titleScore = $score;

        if ($score <= 0) {
            return 0;
        }

        $lessonNumber = $this->leadingNumber($lesson->title, 'lesson-title-'.$lesson->id);
        $titleNumber = $this->leadingNumber($title);
        // A parte vem do título que casou: a aula pode ter nome solto e um vídeo de outra parte.
        $lessonPartNumber = $this->partNumber($matchedTitle);
        $titlePartNumber = $this->partNumber($title);

        $sameContextualNumber = $lessonNumber && $titleNumber && $lessonNumber === $titleNumber;

        if ($lessonNumber && $titleNumber && ! $sameContextualNumber && $lessonKey === $titleKey && $this->isGenericLessonTitle($title)) {
            return 0;
        }

        if ($lessonNumber && $titleNumber) {
            $score += $lessonNumber === $titleNumber ? 8 : 0;
        }

        if ($lessonPartNumber && $titlePartNumber && $lessonPartNumber !== $titlePartNumber) {
            return 0;
        }

        if ($lessonPartNumber && $titlePartNumber && $lessonPartNumber === $titlePartNumber) {
            $score += 12;
        }

        if (! $this->isGenericLessonTitle($title) && ! $this->titleCoresAreCompatible($lessonKey, $titleKey, $lessonPartNumber === $titlePartNumber)) {
            return 0;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $path = $this->matchKey((string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? $lesson->folder?->path ?? ''), 'lesson-path-'.$lesson->id);
        $trackKey = $track ? $this->contextKey($track->name, 'track', $track->id) : '';
        $moduleKey = $this->contextKey($module->name, 'module', $module->id);
        $pathMatchesTrack = $trackKey !== '' && $this->pathMatchesContext($path, $trackKey);
        $pathMatchesModule = $moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey);
        $lessonProduct = $this->contentProduct($lessonKey) ?? $this->contentProduct($path);
        $trackProduct = $this->contentProduct($trackKey);

        // Aula que o curso já usa não precisa provar contexto: a pasta dela pode vir de outra importação.
        $alreadyInUse = isset($this->lessonIdsInUse($track)[$lesson->id]);

        if (! $alreadyInUse && ! $this->lessonMatchesImportContext($lesson, $module, $track, $path)) {
            return 0;
        }

        if ($lessonProduct && $trackProduct && $lessonProduct !== $trackProduct) {
            return 0;
        }

        if ($this->romanSuffix($lessonKey) !== $this->romanSuffix($titleKey)) {
            return 0;
        }

        if (! $alreadyInUse && $this->isGenericLessonTitle($title) && $lessonKey !== $titleKey && ! $pathMatchesTrack) {
            return 0;
        }

        if ($this->isGenericLessonTitle($title) && $pathMatchesTrack && $sameContextualNumber) {
            $score = max($score, 96);
        }

        if ($pathMatchesTrack) {
            $score += 12;
        }

        if ($pathMatchesModule) {
            $score += 4;
        }

        if (! $alreadyInUse && $this->isGenericLessonTitle($title) && $path !== '' && $trackKey !== '' && ! $this->pathMatchesContext($path, $trackKey)) {
            return 0;
        }

        $durationScore = $this->lessonDurationMatchPercent($lesson, $lessonData);

        $score += $this->durationAdjustment($durationScore, $titleScore);

        return round($score, 4);
    }

    /**
     * Duração é um desempate. Quando o título já casou bem, ela não elimina a aula sozinha:
     * a carga da planilha costuma ser estimada e diverge do vídeo real.
     */
    protected function durationAdjustment(?float $durationScore, float $titleScore): float
    {
        return match (true) {
            $durationScore === null => 0.0,
            $durationScore >= 90 => 10.0,
            $durationScore >= 75 => 4.0,
            $durationScore < 50 && $titleScore < 85 => -30.0,
            default => -12.0,
        };
    }

    protected function lessonDurationMatchPercent(Lesson $lesson, array $lessonData): ?float
    {
        $expectedMinutes = max(0, (int) ($lessonData['minutes'] ?? 0));
        $actualSeconds = max(0, (int) $lesson->duration_seconds);

        if ($expectedMinutes <= 0 || $actualSeconds <= 0) {
            return null;
        }

        $expectedSeconds = $expectedMinutes * 60;
        $shorter = min($expectedSeconds, $actualSeconds);
        $longer = max($expectedSeconds, $actualSeconds);

        return round(($shorter / $longer) * 100, 4);
    }

    protected function lessonDurationMatchesImport(Lesson $lesson, array $lessonData): bool
    {
        $score = $this->lessonDurationMatchPercent($lesson, $lessonData);

        return $score !== null && $score >= 75;
    }

    protected function lessonHasReadyMedia(Lesson $lesson): bool
    {
        return $this->lessonMediaPriority($lesson) >= 100;
    }

    protected function lessonMediaPriority(Lesson $lesson): int
    {
        $lesson->loadMissing('video');

        if (
            filled($lesson->panda_video_id)
            || filled($lesson->panda_embed_url)
            || filled($lesson->panda_player_url)
            || filled($lesson->video?->provider_video_id)
            || filled($lesson->video?->embed_url)
            || filled($lesson->video?->player_url)
        ) {
            return 120;
        }

        if ($lesson->source_status === 'media_ready' || $lesson->video?->source_status === 'media_ready') {
            return 100;
        }

        if (in_array($lesson->source_status, ['uploading', 'upload_queued'], true)) {
            return 20;
        }

        return 0;
    }

    protected function isGenericLessonTitle(string $title): bool
    {
        if (preg_match('/\b(?:L\.?\s*I\.?\s*A|L\.?\s*A\.?\s*I|L\.?\s*G\.?\s*P\.?\s*D|E\.?\s*C\.?\s*A)\b/iu', $title) === 1) {
            return true;
        }

        return count(array_filter(explode(' ', $this->matchKey($title)))) <= 2;
    }

    /**
     * Carrega a biblioteca inteira uma vez por importação. A busca antes parava nos 250 primeiros
     * resultados de um LIKE, e aulas com palavras comuns podiam ficar de fora.
     */
    protected function reusableLessonCandidates(): Collection
    {
        if ($this->reusableLessonCandidates instanceof Collection) {
            return $this->reusableLessonCandidates;
        }

        $this->reusableLessonCandidates = Lesson::query()
            ->select([
                'id',
                'course_id',
                'lesson_folder_id',
                'video_id',
                'course_module_id',
                'course_module_track_id',
                'title',
                'slug',
                'description',
                'type',
                'thumbnail_url',
                'duration_seconds',
                'sort_order',
                'status',
                'panda_video_id',
                'panda_embed_url',
                'panda_player_url',
                'google_doc_url',
                'source_status',
                'metadata',
            ])
            ->with(['video', 'folder'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($this->reusableLessonCandidates as $lesson) {
            $this->indexLesson($lesson);
        }

        return $this->reusableLessonCandidates;
    }

    protected function reusableVideoCandidates(): Collection
    {
        if ($this->reusableVideoCandidates instanceof Collection) {
            return $this->reusableVideoCandidates;
        }

        $this->reusableVideoCandidates = Video::query()
            ->with('folder')
            ->where(function ($query): void {
                $query->whereNotNull('provider_video_id')
                    ->orWhereNotNull('embed_url')
                    ->orWhereNotNull('player_url')
                    ->orWhere('source_status', 'media_ready');
            })
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($this->reusableVideoCandidates as $video) {
            $metadata = is_array($video->metadata) ? $video->metadata : [];

            $this->addToIndex($this->videoIndex, $video->id, 'title', [$video->title, $video->provider_video_id]);
            $this->addToIndex($this->videoIndex, $video->id, 'context', [$metadata['library_folder_path'] ?? null, $metadata['drive_source_folder_path'] ?? null, $video->folder?->path]);
        }

        return $this->reusableVideoCandidates;
    }

    protected function indexLesson(Lesson $lesson): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $this->addToIndex($this->lessonIndex, $lesson->id, 'title', [$lesson->title, $lesson->video?->title, $lesson->video?->provider_video_id]);
        $this->addToIndex($this->lessonIndex, $lesson->id, 'context', [$lesson->title, $metadata['library_folder_path'] ?? null, $metadata['drive_source_folder_path'] ?? null, $lesson->folder?->path]);

        if (filled($lesson->video_id)) {
            $current = $this->lessonIdsByVideo[$lesson->video_id] ?? null;
            $this->lessonIdsByVideo[$lesson->video_id] = $current === null ? (int) $lesson->id : min($current, (int) $lesson->id);
        }
    }

    protected function addToIndex(array &$index, int|string $id, string $kind, array $values): void
    {
        foreach ($values as $value) {
            if (blank($value)) {
                continue;
            }

            foreach ($this->indexStems((string) $value) as $stem) {
                $index[$kind][$stem][$id] = true;
            }
        }
    }

    /**
     * Radical de cada palavra, para "função" e "funções" caírem na mesma entrada do índice.
     */
    protected function indexStems(string $value): array
    {
        return collect($this->comparisonTokens($this->matchKey($value)))
            ->map(fn (string $token): string => mb_substr($token, 0, 5))
            ->unique()
            ->all();
    }

    protected function indexedIds(array $index, string $kind, array $tokens): array
    {
        $ids = [];

        foreach ($tokens as $token) {
            $ids += $index[$kind][mb_substr($token, 0, 5)] ?? [];
        }

        return array_keys($ids);
    }

    protected function reusableLessonCandidatesFor(string $title, CourseModule $module, ?CourseModuleTrack $track = null): Collection
    {
        $lessons = $this->reusableLessonCandidates();

        return $lessons->only($this->libraryCandidateIds($this->lessonIndex, $title, $module, $track));
    }

    protected function reusableVideoCandidatesFor(string $title, CourseModule $module, ?CourseModuleTrack $track = null): Collection
    {
        $videos = $this->reusableVideoCandidates();

        return $videos->only($this->libraryCandidateIds($this->videoIndex, $title, $module, $track));
    }

    protected function libraryCandidateIds(array $index, string $title, CourseModule $module, ?CourseModuleTrack $track): array
    {
        $titleTokens = $this->candidateTitleSearchTokens($title);

        if ($titleTokens !== []) {
            return $this->indexedIds($index, 'title', $titleTokens);
        }

        return $this->indexedIds($index, 'context', $this->candidateContextSearchTokens($module, $track));
    }

    protected function firstLessonForVideo(Video $video): ?Lesson
    {
        $this->reusableLessonCandidates();

        $lessonId = $this->lessonIdsByVideo[$video->id] ?? null;

        return $lessonId !== null ? $this->reusableLessonCandidates->get($lessonId) : null;
    }

    /**
     * Aulas que o curso já usa ganham das duplicatas do mesmo vídeo: o progresso dos alunos fica
     * preso à aula, e trocá-la numa reimportação zeraria esse histórico.
     */
    protected function lessonIdsInUse(?CourseModuleTrack $track): array
    {
        $this->courseLessonIds ??= $this->targetCourseId
            ? DB::table('course_module_track_lesson_course')
                ->where('course_id', $this->targetCourseId)
                ->pluck('lesson_id')
                ->flip()
                ->all()
            : [];

        if (! $track?->exists) {
            return $this->courseLessonIds;
        }

        $this->trackLessonIds[$track->id] ??= $track->lessons()->pluck('lessons.id')->flip()->all();

        return $this->courseLessonIds + $this->trackLessonIds[$track->id];
    }

    protected function videoMatchScore(Video $video, string $title, CourseModule $module, ?CourseModuleTrack $track = null, array $lessonData = []): float
    {
        $titleKey = $this->matchKey($title);
        [$videoKey, $score] = $this->bestVideoTitleMatch($video, $titleKey);
        $titleScore = $score;

        if ($score <= 0) {
            return 0.0;
        }

        $videoNumber = $this->leadingNumber((string) $video->title);
        $titleNumber = $this->leadingNumber($title);

        if ($videoNumber && $titleNumber && $videoNumber !== $titleNumber && $videoKey === $titleKey && $this->isGenericLessonTitle($title)) {
            return 0.0;
        }

        $videoPartNumber = $this->partNumber($video->title);
        $titlePartNumber = $this->partNumber($title);

        if ($videoPartNumber && $titlePartNumber && $videoPartNumber !== $titlePartNumber) {
            return 0.0;
        }

        if ($videoPartNumber && $titlePartNumber && $videoPartNumber === $titlePartNumber) {
            $score += 12;
        }

        if (! $this->isGenericLessonTitle($title) && ! $this->titleCoresAreCompatible($videoKey, $titleKey, $videoPartNumber === $titlePartNumber)) {
            return 0.0;
        }

        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $rawPath = (string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? $video->folder?->path ?? '');
        $path = $this->matchKey($rawPath, 'video-path-'.$video->id);
        $trackKey = $track ? $this->contextKey($track->name, 'track', $track->id) : '';
        $moduleKey = $this->contextKey($module->name, 'module', $module->id);
        $pathMatchesTrack = $trackKey !== '' && $this->pathMatchesContext($path, $trackKey);
        $pathMatchesModule = $moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey);
        $videoProduct = $this->contentProduct($path) ?? $this->contentProduct($videoKey);
        $trackProduct = $this->contentProduct($trackKey);

        if (! $this->mediaPathMatchesImportContext($rawPath, $path, $moduleKey, $trackKey)) {
            return 0.0;
        }

        if ($videoProduct && $trackProduct && $videoProduct !== $trackProduct) {
            return 0.0;
        }

        if ($this->romanSuffix($videoKey) !== $this->romanSuffix($titleKey)) {
            return 0.0;
        }

        if ($this->isGenericLessonTitle($title) && $path !== '' && $trackKey !== '' && ! $pathMatchesTrack) {
            return 0.0;
        }

        if ($pathMatchesTrack) {
            $score += 12;
        }

        if ($pathMatchesModule) {
            $score += 4;
        }

        $durationScore = $this->videoDurationMatchPercent($video, $lessonData);

        $score += $this->durationAdjustment($durationScore, $titleScore);

        return round($score, 4);
    }

    protected function bestVideoTitleMatch(Video $video, string $titleKey): array
    {
        return collect([
            $video->title,
            $video->provider_video_id,
        ])
            ->filter(fn ($value): bool => filled($value))
            ->map(function (string $value) use ($titleKey): array {
                $videoKey = $this->matchKey($value);
                $titleScore = $this->matchScoreForKeys($videoKey, $titleKey);
                $directionalTitleScore = $this->directionalTokenMatchPercent($titleKey, $videoKey);

                return [
                    $videoKey,
                    max($titleScore, $directionalTitleScore >= 80 ? min(98, $directionalTitleScore + 8) : $titleScore),
                ];
            })
            ->sortByDesc(fn (array $match): float => $match[1])
            ->first() ?? ['', 0.0];
    }

    protected function videoDurationMatchPercent(Video $video, array $lessonData): ?float
    {
        $expectedMinutes = max(0, (int) ($lessonData['minutes'] ?? 0));
        $actualSeconds = max(0, (int) $video->duration_seconds);

        if ($expectedMinutes <= 0 || $actualSeconds <= 0) {
            return null;
        }

        $expectedSeconds = $expectedMinutes * 60;
        $shorter = min($expectedSeconds, $actualSeconds);
        $longer = max($expectedSeconds, $actualSeconds);

        return round(($shorter / $longer) * 100, 4);
    }

    protected function candidateTitleSearchTokens(string $title): array
    {
        return $this->filterCandidateSearchTokens([
            ...$this->comparisonTokens($this->matchKey($title)),
            ...$this->knownAcronymSearchTokens($title),
        ]);
    }

    protected function candidateContextSearchTokens(CourseModule $module, ?CourseModuleTrack $track = null): array
    {
        $tokens = $track ? $this->comparisonTokens($this->contextKey($track->name, 'track', $track->id)) : [];
        $tokens = array_merge($tokens, $this->comparisonTokens($this->contextKey($module->name, 'module', $module->id)));
        $tokens = array_merge($tokens, $track ? $this->knownAcronymSearchTokens($track->name) : []);
        $tokens = array_merge($tokens, $this->knownAcronymSearchTokens($module->name));

        return $this->filterCandidateSearchTokens($tokens);
    }

    protected function filterCandidateSearchTokens(array $tokens): array
    {
        $knownAcronyms = ['lia', 'lai', 'lgpd', 'eca'];

        return collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->reject(fn (string $token): bool => in_array($token, ['aula', 'video', 'modulo', 'trilha'], true))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3 || in_array($token, $knownAcronyms, true))
            ->unique()
            ->values()
            ->all();
    }

    protected function knownAcronymSearchTokens(string $value): array
    {
        $key = $this->normalizeName($value);
        $tokens = [];

        if (str_contains($key, 'lei de improbidade administrativa') || preg_match('/\blia\b/u', $key) === 1) {
            $tokens[] = 'lia';
        }

        if (str_contains($key, 'lei de acesso a informacao') || preg_match('/\blai\b/u', $key) === 1) {
            $tokens[] = 'lai';
        }

        if (str_contains($key, 'lei geral de protecao de dados') || preg_match('/\blgpd\b/u', $key) === 1) {
            $tokens[] = 'lgpd';
        }

        if (str_contains($key, 'estatuto da crianca e do adolescente') || str_contains($key, 'estatuto crianca adolescente') || preg_match('/\beca\b/u', $key) === 1) {
            $tokens[] = 'eca';
        }

        return $tokens;
    }

    protected function rememberReusableLessonCandidate(?Lesson $lesson): void
    {
        if (! $lesson) {
            return;
        }

        $this->reusableLessonCandidates()->put($lesson->id, $lesson->load(['video', 'folder']));
        $this->indexLesson($lesson);
    }

    protected function resetImportCaches(): void
    {
        $this->reusableLessonCandidates = null;
        $this->reusableVideoCandidates = null;
        $this->lessonIndex = [];
        $this->videoIndex = [];
        $this->lessonIdsByVideo = [];
        $this->trackLessonIds = [];
        $this->targetCourseId = null;
        $this->courseLessonIds = null;
        $this->folderIds = [];
        $this->matchScoreCache = [];
        $this->matchKeyCache = [];
        $this->leadingNumberCache = [];
        $this->removedStructureModuleIds = [];
    }

    protected function contextKey(string $value, string $type, mixed $id): string
    {
        return $this->matchKey($value, filled($id) ? $type.'-'.$id : null);
    }

    protected function matchKey(string $value, ?string $cacheKey = null): string
    {
        $cacheKey ??= 'value:'.$value;

        return $this->matchKeyCache[$cacheKey] ??= LessonTitleNormalizer::matchKey($value);
    }

    protected function leadingNumber(string $value, ?string $cacheKey = null): ?int
    {
        $cacheKey ??= 'value:'.$value;

        return $this->leadingNumberCache[$cacheKey] ??= LessonTitleNormalizer::leadingNumber($value);
    }

    protected function matchScoreForKeys(string $leftKey, string $rightKey): float
    {
        if ($leftKey === '' || $rightKey === '') {
            return 0.0;
        }

        if ($leftKey === $rightKey) {
            return 100.0;
        }

        return $this->matchScoreCache[$leftKey."\0".$rightKey] ??= $this->calculateMatchScore($leftKey, $rightKey);
    }

    protected function calculateMatchScore(string $leftKey, string $rightKey): float
    {
        similar_text($leftKey, $rightKey, $percent);

        return max($this->tokenOverlapPercent($leftKey, $rightKey), $percent);
    }

    protected function bestLessonTitleMatch(Lesson $lesson, string $titleKey): array
    {
        $lesson->loadMissing('video');

        return collect([
            $lesson->title,
            $lesson->video?->title,
            $lesson->video?->provider_video_id,
        ])
            ->filter(fn ($value): bool => filled($value))
            ->map(function (string $value) use ($titleKey): array {
                $lessonKey = $this->matchKey($value);
                $titleScore = $this->matchScoreForKeys($lessonKey, $titleKey);
                $directionalTitleScore = $this->directionalTokenMatchPercent($titleKey, $lessonKey);

                return [
                    $lessonKey,
                    max($titleScore, $directionalTitleScore >= 80 ? min(98, $directionalTitleScore + 8) : $titleScore),
                    $value,
                ];
            })
            ->sortByDesc(fn (array $match): float => $match[1])
            ->first() ?? ['', 0.0, ''];
    }

    protected function tokenOverlapPercent(string $leftKey, string $rightKey): float
    {
        $leftTokens = $this->comparisonTokens($leftKey);
        $rightTokens = $this->comparisonTokens($rightKey);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = array_intersect($leftTokens, $rightTokens);
        $union = array_unique([...$leftTokens, ...$rightTokens]);

        return count($union) > 0 ? (count($intersection) / count($union)) * 100 : 0.0;
    }

    protected function titleCoresAreCompatible(string $candidateKey, string $titleKey, bool $samePart = true): bool
    {
        $leftKey = $candidateKey;
        $rightKey = $titleKey;

        if ($leftKey === '' || $rightKey === '') {
            return false;
        }

        if ($leftKey === $rightKey) {
            return true;
        }

        $leftCore = $this->titleCoreKey($leftKey);
        $rightCore = $this->titleCoreKey($rightKey);

        if ($leftCore === $rightCore) {
            return true;
        }

        if ($this->tokenOverlapPercent($leftCore, $rightCore) >= 75) {
            return true;
        }

        // O título da biblioteca costuma vir com prefixos do Panda: basta conter todo o título da planilha.
        return $samePart
            && count($this->comparisonTokens($rightCore)) >= 3
            && $this->directionalTokenMatchPercent($rightCore, $leftCore) === 100.0;
    }

    protected function titleCoreKey(string $key): string
    {
        return Str::of($key)
            ->replaceMatches('/\b(?:parte|bloco)\s+0*\d{1,3}\b/u', '')
            ->replaceMatches('/\b0*\d{1,3}\b/u', '')
            ->squish()
            ->value();
    }

    protected function directionalTokenMatchPercent(string $needleKey, string $haystackKey): float
    {
        $needleTokens = $this->comparisonTokens($needleKey);
        $haystackTokens = $this->comparisonTokens($haystackKey);

        if ($needleTokens === [] || $haystackTokens === []) {
            return 0.0;
        }

        $intersection = array_intersect($needleTokens, $haystackTokens);

        return (count($intersection) / count($needleTokens)) * 100;
    }

    protected function comparisonTokens(string $key): array
    {
        $ignored = ['a', 'as', 'ao', 'aos', 'aula', 'bloco', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'parte', 'para', 'por', 'um', 'uma'];

        return collect(explode(' ', $key))
            ->filter(fn (string $token): bool => $token !== '' && ! in_array($token, $ignored, true))
            ->unique()
            ->values()
            ->all();
    }

    protected function romanSuffix(string $key): ?string
    {
        $tokens = array_values(array_filter(explode(' ', $key)));
        $last = end($tokens);

        return in_array($last, ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'], true)
            ? $last
            : null;
    }

    protected function partNumber(string $key): ?int
    {
        $key = Str::of($key)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        if (preg_match('/\b(?:parte|bloco)\s+0*(\d{1,3})\b/u', $key, $matches) !== 1) {
            return null;
        }

        return max(1, (int) $matches[1]);
    }

    protected function pathMatchesContext(string $path, string $context): bool
    {
        if ($path === '' || $context === '') {
            return false;
        }

        $pathVariants = $this->contextVariants($path);
        $contextVariants = $this->contextVariants($context);

        foreach ($pathVariants as $pathVariant) {
            foreach ($contextVariants as $contextVariant) {
                if ($pathVariant !== '' && $contextVariant !== '' && (str_contains($pathVariant, $contextVariant) || str_contains($contextVariant, $pathVariant))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function contextVariants(string $value): array
    {
        $variants = [$value];

        $variants[] = Str::of($value)
            ->replace('ppt', 'power point')
            ->replace('powerpoint', 'power point')
            ->replace('windowns', 'windows')
            ->squish()
            ->value();

        $variants[] = Str::of($value)
            ->replace('power point', 'ppt')
            ->replace('powerpoint', 'ppt')
            ->replace('windowns', 'windows')
            ->squish()
            ->value();

        if (str_contains($value, 'eca')) {
            $variants[] = 'estatuto da crianca e do adolescente';
        }

        if (str_contains($value, 'estatuto') && str_contains($value, 'crianca') && str_contains($value, 'adolescente')) {
            $variants[] = 'eca';
        }

        if (str_contains($value, 'lia')) {
            $variants[] = 'lei de improbidade administrativa';
        }

        if (str_contains($value, 'improbidade administrativa')) {
            $variants[] = 'lia';
        }

        if (str_contains($value, 'lai')) {
            $variants[] = 'lei de acesso a informacao';
        }

        if (str_contains($value, 'acesso') && str_contains($value, 'informacao')) {
            $variants[] = 'lai';
        }

        if (str_contains($value, 'lgpd')) {
            $variants[] = 'lei geral de protecao de dados';
        }

        if (str_contains($value, 'geral') && str_contains($value, 'protecao') && str_contains($value, 'dados')) {
            $variants[] = 'lgpd';
        }

        return array_values(array_unique($variants));
    }

    protected function lessonMatchesImportContext(Lesson $lesson, CourseModule $module, ?CourseModuleTrack $track = null, ?string $path = null): bool
    {
        if (! $track) {
            return true;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $rawPath = (string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? $lesson->folder?->path ?? '');
        $path ??= $this->matchKey($rawPath, 'lesson-path-'.$lesson->id);

        if ($path === '') {
            return true;
        }

        $trackKey = $this->contextKey($track->name, 'track', $track->id);
        $moduleKey = $this->contextKey($module->name, 'module', $module->id);

        return $this->mediaPathMatchesImportContext($rawPath, $path, $moduleKey, $trackKey);
    }

    protected function mediaPathMatchesImportContext(string $rawPath, string $path, string $moduleKey, string $trackKey): bool
    {
        if ($path === '') {
            return true;
        }

        if ($trackKey !== '' && $this->pathMatchesContext($path, $trackKey)) {
            return true;
        }

        // Pasta só do módulo não identifica trilha. Com subpasta, a aula é de outra trilha do módulo.
        if ($moduleKey === '' || preg_match('#[/\\\\]#', $rawPath) === 1) {
            return $trackKey === '' && $moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey);
        }

        return $this->pathMatchesContext($path, $moduleKey);
    }

    protected function contentProduct(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (str_contains($value, 'excel')) {
            return 'excel';
        }

        if (str_contains($value, 'word')) {
            return 'word';
        }

        if (str_contains($value, 'power point') || str_contains($value, 'powerpoint') || str_contains($value, 'powerpont') || str_contains($value, 'ppt')) {
            return 'powerpoint';
        }

        if (str_contains($value, 'windows') || str_contains($value, 'windowns')) {
            return 'windows';
        }

        if (str_contains($value, 'internet') || str_contains($value, 'firefox') || str_contains($value, 'edge')) {
            return 'internet';
        }

        if (preg_match('/\blei\s+federal\s+(?:no\s+)?(\d{1,3})\s+(\d{3})\b/u', $value, $matches) === 1) {
            return 'lei-federal-'.$matches[1].$matches[2];
        }

        if (str_contains($value, 'poder executivo')) {
            return 'poder-executivo';
        }

        if (str_contains($value, 'poder legislativo') || str_contains($value, 'legislativo')) {
            return 'poder-legislativo';
        }

        if (str_contains($value, 'poder judiciario') || str_contains($value, 'judiciario')) {
            return 'poder-judiciario';
        }

        if (str_contains($value, 'poder de policia')) {
            return 'poder-de-policia';
        }

        if (str_contains($value, 'direito administrativo') || str_contains($value, 'administracao publica') || str_contains($value, 'autarquia')) {
            return 'direito-administrativo';
        }

        $hasEca = str_contains($value, 'estatuto') && str_contains($value, 'crianca') && str_contains($value, 'adolescente');
        $hasLdb = str_contains($value, 'ldb') || str_contains($value, 'diretrizes bases');

        if ($hasEca && $hasLdb) {
            return null;
        }

        if (str_contains($value, 'geral') && str_contains($value, 'protecao') && str_contains($value, 'dados')) {
            return 'lgpd';
        }

        if (str_contains($value, 'acesso') && str_contains($value, 'informacao')) {
            return 'lai';
        }

        if (str_contains($value, 'improbidade administrativa')) {
            return 'lia';
        }

        if (preg_match('/\bl\s*i\s*a\b/u', $value) === 1) {
            return 'lia';
        }

        if (preg_match('/\bl\s*a\s*i\b/u', $value) === 1) {
            return 'lai';
        }

        if (preg_match('/\bl\s*g\s*p\s*d\b/u', $value) === 1) {
            return 'lgpd';
        }

        if (preg_match('/\be\s*c\s*a\b/u', $value) === 1) {
            return 'eca';
        }

        if ($hasLdb) {
            return 'ldb';
        }

        if ($hasEca) {
            return 'eca';
        }

        return null;
    }
}
