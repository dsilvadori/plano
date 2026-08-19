<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyTrack;
use App\Models\Teacher;
use App\Support\LessonTitleNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CourseSpreadsheetImporter
{
    protected ?Collection $reusableLessonCandidates = null;

    protected array $matchKeyCache = [];

    protected array $leadingNumberCache = [];

    protected array $removedStructureModuleIds = [];

    public function __construct(
        protected CourseSpreadsheetParser $parser,
        protected ActiveStudyPlanRefresher $activeStudyPlanRefresher,
    ) {}

    public function preview(string $path, ?Course $course = null): array
    {
        $payload = $this->parser->parse($path);
        $this->resetImportCaches();
        $targetCourse = $course ?: Course::query()->where('slug', $payload['course_slug'])->first();
        $moduleStats = ['create' => 0, 'update' => 0];
        $lessonStats = ['create' => 0, 'update' => 0];

        foreach ($payload['modules'] as $moduleData) {
            $module = $targetCourse ? $this->resolveModuleForCourse($targetCourse, $moduleData['name']) : $this->resolveReusableModule($moduleData['name']);

            $module ? $moduleStats['update']++ : $moduleStats['create']++;

            foreach ($this->lessonsFromModuleData($moduleData) as $index => $lessonData) {
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
            $this->activeStudyPlanRefresher->refreshCourseFromNextWeek($course);

            return $course->fresh(['modules.tracks.lessons', 'studyTracks.modules']);
        });
    }

    public function importInto(Course $course, string $path): Course
    {
        $payload = $this->parser->parse($path);
        $this->resetImportCaches();

        return DB::transaction(function () use ($course, $payload) {
            $studyTrackName = $this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - '.$course->name;

            $this->importStructure($course, $payload, $studyTrackName);
            $this->activeStudyPlanRefresher->refreshCourseFromNextWeek($course);

            return $course->fresh(['modules.tracks.lessons', 'studyTracks.modules']);
        });
    }

    protected function importStructure(Course $course, array $payload, string $studyTrackName, bool $replaceTrackModules = true): void
    {
        $moduleIds = [];

        if ($replaceTrackModules) {
            $this->replaceExistingOfficialStructure($course, $studyTrackName);
        }

        foreach ($payload['modules'] as $moduleData) {
            if ($this->shouldAttachCompoundTrackModules($moduleData)) {
                $moduleData = $this->attachCompoundTrackModules($course, $moduleData, $moduleIds);
            }

            if (empty($moduleData['tracks']) && empty($moduleData['lessons'])) {
                continue;
            }

            $module = $this->resolveModuleForCourse($course, $moduleData['name'])
                ?? $this->resolveReusableModule($moduleData['name'])
                ?? new CourseModule([
                    'course_id' => null,
                    'name' => $moduleData['name'],
                ]);

            $module->fill([
                'course_id' => null,
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

            $this->importTracks($course, $module, $moduleData);

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

    protected function shouldAttachCompoundTrackModules(array $moduleData): bool
    {
        return ($moduleData['sheet_name'] ?? null) === 'CSV';
    }

    protected function replaceExistingOfficialStructure(Course $course, string $studyTrackName): void
    {
        $studyTrack = $course->studyTracks()
            ->where('name', $studyTrackName)
            ->first()
            ?? $course->studyTracks()
                ->where('name', 'like', 'Trilha Oficial -%')
                ->orderBy('id')
                ->first();

        if (! $studyTrack instanceof StudyTrack) {
            return;
        }

        $modules = $studyTrack->modules()->withCount('courses')->get();

        $studyTrack->modules()->detach();

        foreach ($modules as $module) {
            $this->removedStructureModuleIds[] = (int) $module->id;
            $module->courses()->detach($course->id);

            if ((int) $module->courses_count <= 1) {
                $module->delete();
            }
        }

        $this->removedStructureModuleIds = array_values(array_unique($this->removedStructureModuleIds));
    }

    protected function attachCompoundTrackModules(Course $course, array $moduleData, array &$moduleIds): array
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

            $this->importTracks($course, $compoundModule, [
                ...$moduleData,
                'name' => $compoundModule->name,
                'workload_minutes' => $compoundModule->workload_minutes,
                'lessons' => $lessons,
                'tracks' => [[
                    ...$trackData,
                    'lessons' => $lessons,
                ]],
            ]);

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

    protected function importTracks(Course $course, CourseModule $module, array $moduleData): void
    {
        $tracks = $moduleData['tracks'] ?? [[
            'name' => $moduleData['track_name'] ?? 'Aulas',
            'sort_order' => 1,
            'workload_minutes' => $moduleData['workload_minutes'] ?? 0,
            'lessons' => $moduleData['lessons'] ?? [],
        ]];

        foreach (array_values($tracks) as $index => $trackData) {
            $trackName = trim((string) ($trackData['name'] ?? '')) ?: 'Aulas';
            $sortOrder = (int) ($trackData['sort_order'] ?? ($index + 1));
            $slug = $this->trackSlug($trackName, $sortOrder);
            $track = $this->resolveTrackForModule($module, $trackName, $slug)
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

            $this->importLessons($course, $module, $track, $trackData['lessons'] ?? []);
        }
    }

    protected function resolveTeacher(string $name): Teacher
    {
        $normalizedName = $this->normalizeName($name);

        $teacher = Teacher::query()
            ->orderBy('id')
            ->get()
            ->first(fn (Teacher $teacher): bool => $this->normalizeName($teacher->name) === $normalizedName);

        if ($teacher) {
            return $teacher;
        }

        return Teacher::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function importLessons(Course $course, CourseModule $module, CourseModuleTrack $track, array $lessons): void
    {
        $usedLessonIds = [];

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
                    ? Lesson::query()->firstOrNew(['panda_video_id' => $pandaVideoId])
                    : new Lesson([
                        'course_module_id' => null,
                        'course_module_track_id' => null,
                        'slug' => $slug,
                    ]));
            $lessonExists = $lesson->exists;
            $matchedStandaloneLibraryLesson = $lessonExists
                && blank($lesson->course_id)
                && blank($lesson->course_module_id)
                && blank($lesson->course_module_track_id);
            $hasReadyMedia = $pandaVideoId
                || filled($lessonData['panda_embed_url'] ?? null)
                || filled($lessonData['panda_player_url'] ?? null)
                || filled($lesson->panda_video_id)
                || filled($lesson->panda_embed_url)
                || filled($lesson->panda_player_url);
            $requestedStatus = filled($lessonData['status'] ?? null) ? (string) $lessonData['status'] : null;
            $hasExplicitStatus = (bool) ($lessonData['status_explicit'] ?? false);
            $previousMetadata = is_array($lesson->metadata) ? $lesson->metadata : [];

            $lesson->fill([
                'course_id' => null,
                'course_module_id' => $this->resolveLessonModuleId($lesson, $module),
                'course_module_track_id' => $this->resolveLessonTrackId($lesson, $track),
                'title' => $title,
                'slug' => $lessonExists ? $lesson->slug : $slug,
                'description' => $lessonExists && $lesson->description !== 'Aula importada por planilha.'
                    ? $lesson->description
                    : null,
                'type' => $this->normalizeLessonType((string) ($lessonData['type'] ?? 'video')),
                'thumbnail_url' => $lessonData['thumbnail_url'] ?? $lesson->thumbnail_url,
                'duration_seconds' => $minutes * 60,
                'sort_order' => $sortOrder,
                'status' => $hasReadyMedia && ! $hasExplicitStatus
                    ? 'published'
                    : ($requestedStatus ? $this->normalizeLessonStatus($requestedStatus) : ($lesson->status ?: 'draft')),
                'panda_video_id' => $pandaVideoId ?: $lesson->panda_video_id,
                'panda_embed_url' => $lessonData['panda_embed_url'] ?? $lesson->panda_embed_url,
                'panda_player_url' => $lessonData['panda_player_url'] ?? $lesson->panda_player_url,
                'google_doc_url' => $lessonData['google_doc_url'] ?? $lesson->google_doc_url,
                'source_status' => $hasReadyMedia ? 'media_ready' : ($lesson->source_status ?: 'awaiting_media'),
                'metadata' => array_merge($previousMetadata, [
                    'source' => 'spreadsheet',
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
            $conflictingLessonIds = $track->lessons()
                ->wherePivot('sort_order', $sortOrder)
                ->whereKeyNot($lesson->id)
                ->pluck('lessons.id')
                ->all();

            if ($conflictingLessonIds !== []) {
                $track->lessons()->detach($conflictingLessonIds);
            }

            $usedLessonIds[] = $lesson->id;
            $this->rememberReusableLessonCandidate($lesson->fresh());
        }
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

    protected function resolveModuleForCourse(Course $course, string $moduleName): ?CourseModule
    {
        return $course->modules()
            ->where('course_modules.name', $moduleName)
            ->first()
            ?? CourseModule::query()
                ->when($this->removedStructureModuleIds !== [], fn ($query) => $query->whereNotIn('id', $this->removedStructureModuleIds))
                ->where('name', $moduleName)
                ->get()
                ->first(fn (CourseModule $module): bool => $this->normalizeName($module->name) === $this->normalizeName($moduleName));
    }

    protected function resolveReusableModule(string $moduleName): ?CourseModule
    {
        $normalizedName = $this->normalizeName($moduleName);

        return CourseModule::query()
            ->when($this->removedStructureModuleIds !== [], fn ($query) => $query->whereNotIn('id', $this->removedStructureModuleIds))
            ->orderBy('id')
            ->get()
            ->first(fn (CourseModule $module) => $this->normalizeName($module->name) === $normalizedName);
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

    protected function resolveTrackForModule(CourseModule $module, string $trackName, string $slug): ?CourseModuleTrack
    {
        $track = $module->tracks()
            ->where('slug', $slug)
            ->first();

        if ($track) {
            return $track;
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
                ->where('panda_video_id', $pandaVideoId)
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
                ->first();

            if ($lesson && $this->lessonHasReadyMedia($lesson)) {
                return $lesson;
            }

            $fallbackLesson = $lesson;
        }

        $lesson = $module->onlineLessons()
            ->where('lessons.slug', $slug)
            ->first();

        if ($lesson && $this->lessonHasReadyMedia($lesson)) {
            return $lesson;
        }

        $fallbackLesson ??= $lesson;

        return $this->reusableLessonCandidates()
            ->when($excludeLessonIds !== [], fn (Collection $lessons) => $lessons->whereNotIn('id', $excludeLessonIds))
            ->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'score' => $this->lessonMatchScore($lesson, $title, $module, $track, $lessonData),
                'media_priority' => $this->lessonMediaPriority($lesson),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sort(function (array $left, array $right): int {
                return [$right['media_priority'], $right['score'], -$right['lesson']->id]
                    <=> [$left['media_priority'], $left['score'], -$left['lesson']->id];
            })
            ->first()['lesson'] ?? $fallbackLesson;
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
        $lessonKey = $this->matchKey($lesson->title, 'lesson-title-'.$lesson->id);
        $titleKey = $this->matchKey($title);
        $titleScore = $this->matchScoreForKeys($lessonKey, $titleKey);
        $score = $titleScore;

        if ($score <= 0) {
            return 0;
        }

        $lessonNumber = $this->leadingNumber($lesson->title, 'lesson-title-'.$lesson->id);
        $titleNumber = $this->leadingNumber($title);

        if ($lessonNumber && $titleNumber) {
            $score += $lessonNumber === $titleNumber ? 8 : 0;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $path = $this->matchKey((string) ($metadata['drive_source_folder_path'] ?? ''), 'lesson-path-'.$lesson->id);
        $trackKey = $track ? $this->matchKey($track->name, 'track-'.$track->id) : '';
        $moduleKey = $this->matchKey($module->name, 'module-'.$module->id);
        $pathMatchesTrack = $trackKey !== '' && $this->pathMatchesContext($path, $trackKey);
        $lessonProduct = $this->contentProduct($path) ?? $this->contentProduct($lessonKey);
        $trackProduct = $this->contentProduct($trackKey);

        if ($lessonProduct && $trackProduct && $lessonProduct !== $trackProduct) {
            return 0;
        }

        if ($this->romanSuffix($lessonKey) !== $this->romanSuffix($titleKey)) {
            return 0;
        }

        if ($this->isGenericLessonTitle($title) && $lessonKey !== $titleKey && ! $pathMatchesTrack) {
            return 0;
        }

        if ($pathMatchesTrack) {
            $score += 12;
        }

        if ($moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey)) {
            $score += 4;
        }

        if ($this->isGenericLessonTitle($title) && $path !== '' && $trackKey !== '' && ! $this->pathMatchesContext($path, $trackKey)) {
            $score -= 35;
        }

        $durationScore = $this->lessonDurationMatchPercent($lesson, $lessonData);

        if ($durationScore !== null) {
            if ($titleScore < 96 && $durationScore < 75) {
                return 0;
            }

            if ($titleScore < 98 && $durationScore < 50) {
                return 0;
            }

            $score = ($score * 0.82) + ($durationScore * 0.18);

            if ($durationScore >= 90) {
                $score += 6;
            } elseif ($durationScore < 60) {
                $score -= 20;
            }
        }

        return round($score, 4);
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
        if (filled($lesson->panda_video_id) || filled($lesson->panda_embed_url) || filled($lesson->panda_player_url)) {
            return 120;
        }

        if ($lesson->source_status === 'media_ready') {
            return 100;
        }

        if (in_array($lesson->source_status, ['uploading', 'upload_queued'], true)) {
            return 20;
        }

        return 0;
    }

    protected function isGenericLessonTitle(string $title): bool
    {
        return count(array_filter(explode(' ', $this->matchKey($title)))) <= 2;
    }

    protected function reusableLessonCandidates(): Collection
    {
        if ($this->reusableLessonCandidates instanceof Collection) {
            return $this->reusableLessonCandidates;
        }

        return $this->reusableLessonCandidates = Lesson::query()
            ->select([
                'id',
                'course_id',
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
            ->orderBy('id')
            ->get();
    }

    protected function rememberReusableLessonCandidate(?Lesson $lesson): void
    {
        if (! $lesson || ! $this->reusableLessonCandidates instanceof Collection) {
            return;
        }

        $this->reusableLessonCandidates = $this->reusableLessonCandidates
            ->reject(fn (Lesson $candidate): bool => (int) $candidate->id === (int) $lesson->id)
            ->push($lesson)
            ->sortBy('id')
            ->values();
    }

    protected function resetImportCaches(): void
    {
        $this->reusableLessonCandidates = null;
        $this->matchKeyCache = [];
        $this->leadingNumberCache = [];
        $this->removedStructureModuleIds = [];
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

        similar_text($leftKey, $rightKey, $percent);

        return max($this->tokenOverlapPercent($leftKey, $rightKey), $percent);
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

    protected function comparisonTokens(string $key): array
    {
        $ignored = ['a', 'as', 'ao', 'aos', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'um', 'uma'];

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

        return array_values(array_unique($variants));
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

        return null;
    }
}
