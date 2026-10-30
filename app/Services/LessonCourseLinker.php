<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Support\LessonTitleNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LessonCourseLinker
{
    public function sync(?Course $course = null): array
    {
        return DB::transaction(function () use ($course): array {
            $stats = [
                'tracks' => 0,
                'linked' => 0,
                'replaced' => 0,
                'published' => 0,
                'plans_synced' => 0,
            ];
            $this->tracksQuery($course)
                ->with(['module:id,name'])
                ->chunkById(25, function ($tracks) use ($course, &$stats): void {
                    foreach ($tracks as $track) {
                        $module = $track->module;

                        if (! $module instanceof CourseModule) {
                            continue;
                        }

                        $stats['tracks']++;
                        $courseIds = $this->courseIdsForTrack($track, $module, $course);
                        $scopeCourseIds = $course instanceof Course ? [(int) $course->id] : $courseIds;
                        $hasSpreadsheetScope = $this->trackHasSpreadsheetScope($scopeCourseIds, $track);

                        if ($hasSpreadsheetScope) {
                            $this->removeNonSpreadsheetCourseScopes($scopeCourseIds, $track);
                        }

                        $trackLessons = $this->lessonsForTrack($track);

                        foreach ($trackLessons as $lesson) {
                            $sortOrder = (int) ($lesson->pivot?->sort_order ?? $lesson->sort_order);

                            if ($hasSpreadsheetScope && ! $this->lessonHasSpreadsheetScope($scopeCourseIds, $track, $lesson)) {
                                $track->lessons()->detach($lesson->id);
                                DB::table('course_module_track_lesson_course')
                                    ->whereIn('course_id', $scopeCourseIds)
                                    ->where('course_module_track_id', $track->id)
                                    ->where('lesson_id', $lesson->id)
                                    ->delete();

                                continue;
                            }

                            if ($this->lessonHasReadyMedia($lesson)) {
                                if ($this->hasDifferentSpreadsheetLessonAtSort($courseIds, $track, $sortOrder, $lesson)) {
                                    $track->lessons()->detach($lesson->id);
                                    DB::table('course_module_track_lesson_course')
                                        ->whereIn('course_id', $courseIds)
                                        ->where('course_module_track_id', $track->id)
                                        ->where('lesson_id', $lesson->id)
                                        ->delete();

                                    continue;
                                }

                                if (! $this->readyLessonBelongsToTrack($lesson, $module, $track)) {
                                    $track->lessons()->detach($lesson->id);
                                    DB::table('course_module_track_lesson_course')
                                        ->whereIn('course_id', $courseIds)
                                        ->where('course_module_track_id', $track->id)
                                        ->where('lesson_id', $lesson->id)
                                        ->delete();

                                    continue;
                                }

                                if ($this->publishLesson($lesson)) {
                                    $stats['published']++;
                                }

                                $module->onlineLessons()->syncWithoutDetaching([
                                    $lesson->id => ['sort_order' => $sortOrder],
                                ]);
                                $this->syncCourseTrackLessonScopes($courseIds, $track, $lesson, $sortOrder);

                                continue;
                            }

                            if ($hasSpreadsheetScope && $this->lessonHasSpreadsheetScope($scopeCourseIds, $track, $lesson)) {
                                continue;
                            }

                            $candidates = $this->findReadyCandidates($lesson, $module, $track, $sortOrder);

                            if ($candidates->isEmpty()) {
                                continue;
                            }

                            $candidates->each(function (Lesson $candidate) use ($module, $track, $courseIds, $sortOrder, $lesson, &$stats): void {
                                if ($this->publishLesson($candidate)) {
                                    $stats['published']++;
                                }

                                $module->onlineLessons()->syncWithoutDetaching([
                                    $candidate->id => ['sort_order' => $sortOrder],
                                ]);
                                $track->lessons()->syncWithoutDetaching([
                                    $candidate->id => ['sort_order' => $sortOrder],
                                ]);
                                $this->syncCourseTrackLessonScopes($courseIds, $track, $candidate, $sortOrder, $lesson);

                                $stats['linked']++;
                            });

                            if ($candidates->doesntContain(fn (Lesson $candidate): bool => $lesson->is($candidate))) {
                                $module->onlineLessons()->detach($lesson->id);
                                $track->lessons()->detach($lesson->id);
                                $this->archiveDetachedPlaceholder($lesson);
                                $stats['replaced']++;
                            }
                        }
                    }
                });

            $this->linkReadyLessonsToPlanningLessons($course, $stats);

            return $stats;
        });
    }

    protected function tracksQuery(?Course $course): Builder
    {
        $query = CourseModuleTrack::query()->whereHas('module');

        if (! $course instanceof Course) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($course): void {
            $query
                ->whereHas('courses', fn (Builder $query) => $query->whereKey($course->id))
                ->orWhereHas('module.courses', fn (Builder $query) => $query->whereKey($course->id));
        });
    }

    protected function lessonsForTrack(CourseModuleTrack $track): Collection
    {
        return $track->lessons()
            ->select($this->lessonSelectColumns())
            ->with($this->videoRelationSelect())
            ->orderByPivot('sort_order')
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.title')
            ->get();
    }

    protected function findReadyCandidate(Lesson $placeholder, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): ?Lesson
    {
        return $this->findReadyCandidates($placeholder, $module, $track, $sortOrder)->first();
    }

    protected function findReadyCandidates(Lesson $placeholder, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): Collection
    {
        $candidates = $this->readyLessonCandidatesFor($placeholder->title, $module, $track, [$placeholder->id])
            ->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'score' => $this->lessonMatchScore($lesson, $placeholder->title, $module, $track, $sortOrder, (int) $placeholder->duration_seconds),
                'priority' => $this->lessonMediaPriority($lesson),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sortByDesc(fn (array $candidate): float => ($candidate['score'] * 10) + $candidate['priority'])
            ->values();

        if (! $this->isGenericLessonTitle($placeholder->title)) {
            return $candidates->take(1)->pluck('lesson');
        }

        $placeholderKey = LessonTitleNormalizer::matchKey($placeholder->title);
        $exactCandidates = $candidates
            ->filter(fn (array $candidate): bool => LessonTitleNormalizer::matchKey($candidate['lesson']->title) === $placeholderKey)
            ->take(1)
            ->pluck('lesson')
            ->values();

        if ($exactCandidates->isNotEmpty()) {
            return $exactCandidates;
        }

        $placeholderNumber = $this->contextualLessonNumber($placeholder->title) ?? $sortOrder;
        $sameNumberContextCandidates = $candidates
            ->filter(function (array $candidate) use ($placeholderNumber): bool {
                $lessonNumber = $this->contextualLessonNumber($candidate['lesson']->title);

                return $placeholderNumber !== null && $lessonNumber !== null && $placeholderNumber === $lessonNumber;
            })
            ->take(1)
            ->pluck('lesson')
            ->values();

        return $sameNumberContextCandidates->isNotEmpty()
            ? $sameNumberContextCandidates
            : $candidates->take(1)->pluck('lesson');
    }

    protected function linkReadyLessonsToPlanningLessons(?Course $course, array &$stats): void
    {
        $this->planningModulesQuery($course)
            ->with([
                'onlineLessons' => fn ($query) => $query->select($this->lessonSelectColumns())->with($this->videoRelationSelect()),
                'tracks' => fn ($query) => $query->select(['course_module_tracks.id', 'course_module_tracks.course_module_id', 'course_module_tracks.name', 'course_module_tracks.sort_order', 'course_module_tracks.status']),
            ])
            ->chunkById(25, function ($modules) use ($course, &$stats): void {
                foreach ($modules as $module) {
                    $planningLessons = $module->planning_lessons;

                    if ($planningLessons === []) {
                        continue;
                    }

                    $courseIds = $this->courseIdsForModule($module, $course);

                    if ($course instanceof Course && $this->moduleHasCourseScopedTrackLessons($module, $courseIds)) {
                        continue;
                    }

                    $attachedLessonIds = DB::table('course_module_lessons')
                        ->where('course_module_id', $module->id)
                        ->pluck('lesson_id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    foreach ($planningLessons as $index => $planningLesson) {
                        $sortOrder = $index + 1;
                        $title = (string) ($planningLesson['name'] ?? '');

                        if ($title === '') {
                            continue;
                        }

                        $alreadyLinked = $module->onlineLessons->first(function (Lesson $lesson) use ($title, $module, $sortOrder): bool {
                            return $this->lessonMatchScore($lesson, $title, $module) >= 92;
                        });

                        if ($alreadyLinked instanceof Lesson) {
                            continue;
                        }

                        $candidate = $this->findReadyCandidateForPlanningLesson($title, $module, $sortOrder, $attachedLessonIds);

                        if (! $candidate instanceof Lesson) {
                            continue;
                        }

                        if ($this->publishLesson($candidate)) {
                            $stats['published']++;
                        }

                        $module->onlineLessons()->syncWithoutDetaching([
                            $candidate->id => ['sort_order' => $sortOrder],
                        ]);

                        $matchingTrack = $this->bestTrackForPlanningCandidate($candidate, $title, $module);

                        if ($matchingTrack instanceof CourseModuleTrack) {
                            $matchingTrack->lessons()->syncWithoutDetaching([
                                $candidate->id => ['sort_order' => $sortOrder],
                            ]);
                            $this->syncCourseTrackLessonScopes($courseIds, $matchingTrack, $candidate, $sortOrder);
                        }

                        $attachedLessonIds[] = $candidate->id;
                        $stats['linked']++;
                    }
                }
            });
    }

    protected function planningModulesQuery(?Course $course): Builder
    {
        $query = CourseModule::query()
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('lessons')
                    ->orWhereHas('onlineLessons');
            });

        if (! $course instanceof Course) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($course): void {
            $query
                ->where('course_id', $course->id)
                ->orWhereHas('courses', fn (Builder $query) => $query->whereKey($course->id));
            });
    }

    protected function readyLessonCandidatesFor(string $title, CourseModule $module, ?CourseModuleTrack $track = null, array $excludeLessonIds = []): Collection
    {
        $tokens = $this->candidateSearchTokens($title, $module, $track);

        return Lesson::query()
            ->select($this->lessonSelectColumns())
            ->whereNotIn('lessons.id', array_values(array_filter(array_map('intval', $excludeLessonIds))))
            ->where($this->readyLessonFilter())
            ->when($tokens !== [], function (Builder $query) use ($tokens): void {
                $query->where(function (Builder $query) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $like = '%'.$token.'%';
                        $query
                            ->orWhere('lessons.title', 'like', $like)
                            ->orWhere('lessons.metadata', 'like', $like)
                            ->orWhereHas('video', function (Builder $query) use ($like): void {
                                $query
                                    ->where('title', 'like', $like)
                                    ->orWhere('provider_video_id', 'like', $like);
                            });
                    }
                });
            })
            ->with($this->videoRelationSelect())
            ->orderByRaw("(case when panda_video_id is not null or panda_embed_url is not null or panda_player_url is not null or exists (select 1 from videos where videos.id = lessons.video_id and (videos.provider_video_id is not null or videos.embed_url is not null or videos.player_url is not null)) then 0 when source_status = 'media_ready' then 1 else 2 end)")
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.title')
            ->limit(500)
            ->get();
    }

    protected function readyLessonFilter(): callable
    {
        return function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->whereNotNull('lessons.panda_video_id')
                    ->orWhereNotNull('lessons.panda_embed_url')
                    ->orWhereNotNull('lessons.panda_player_url')
                    ->orWhere('lessons.source_status', 'media_ready')
                    ->orWhereHas('video', function (Builder $query): void {
                        $query->whereNotNull('provider_video_id')
                            ->orWhereNotNull('embed_url')
                            ->orWhereNotNull('player_url')
                            ->orWhere('source_status', 'media_ready');
                    });
            });
        };
    }

    protected function candidateSearchTokens(string $title, CourseModule $module, ?CourseModuleTrack $track = null): array
    {
        $tokens = [
            ...$this->tokensForSearch($title),
            ...$this->knownAcronymSearchTokens($title),
            ...$this->tokensForSearch($track?->name ?? ''),
            ...$this->knownAcronymSearchTokens($track?->name ?? ''),
            ...$this->tokensForSearch($module->name),
            ...$this->knownAcronymSearchTokens($module->name),
        ];

        $number = $this->contextualLessonNumber($title);

        if ($number !== null) {
            $tokens[] = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $tokens[] = (string) $number;
        }

        return collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '' && (mb_strlen($token) >= 3 || is_numeric($token)))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    protected function tokensForSearch(string $value): array
    {
        $ignored = ['a', 'as', 'ao', 'aos', 'aula', 'bloco', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'um', 'uma'];

        return collect(explode(' ', LessonTitleNormalizer::matchKey($value)))
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) >= 4 && ! in_array($token, $ignored, true))
            ->values()
            ->all();
    }

    protected function knownAcronymSearchTokens(string $value): array
    {
        $key = LessonTitleNormalizer::matchKey($value);
        $tokens = [];

        if (str_contains($key, 'estatuto') && str_contains($key, 'crianca') && str_contains($key, 'adolescente')) {
            $tokens[] = 'eca';
        }

        if (str_contains($key, 'improbidade administrativa')) {
            $tokens[] = 'lia';
        }

        if (str_contains($key, 'acesso') && str_contains($key, 'informacao')) {
            $tokens[] = 'lai';
        }

        if (str_contains($key, 'geral') && str_contains($key, 'protecao') && str_contains($key, 'dados')) {
            $tokens[] = 'lgpd';
        }

        if (str_contains($key, 'ldb') || str_contains($key, 'diretrizes bases')) {
            $tokens[] = 'ldb';
        }

        return $tokens;
    }

    protected function courseIdsForTrack(CourseModuleTrack $track, CourseModule $module, ?Course $course = null): array
    {
        $courseIds = DB::table('course_module_track_course')
            ->where('course_module_track_id', $track->id)
            ->pluck('course_id')
            ->merge(DB::table('course_module_course')
                ->where('course_module_id', $module->id)
                ->pluck('course_id'));

        if ($course instanceof Course) {
            $courseIds->push($course->id);
        }

        return $courseIds
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function courseIdsForModule(CourseModule $module, ?Course $course = null): array
    {
        $courseIds = DB::table('course_module_course')
            ->where('course_module_id', $module->id)
            ->pluck('course_id');

        if (filled($module->course_id)) {
            $courseIds->push((int) $module->course_id);
        }

        if ($course instanceof Course) {
            $courseIds->push($course->id);
        }

        return $courseIds
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function lessonSelectColumns(): array
    {
        return [
            'lessons.id',
            'lessons.video_id',
            'lessons.title',
            'lessons.duration_seconds',
            'lessons.status',
            'lessons.source_status',
            'lessons.panda_video_id',
            'lessons.panda_embed_url',
            'lessons.panda_player_url',
            'lessons.sort_order',
            'lessons.metadata',
        ];
    }

    protected function videoRelationSelect(): array
    {
        return [
            'video:id,title,provider_video_id,embed_url,player_url,source_status',
        ];
    }

    protected function syncCourseTrackLessonScopes(array $courseIds, CourseModuleTrack $track, Lesson $lesson, int $sortOrder, ?Lesson $replacedLesson = null): void
    {
        foreach (array_unique(array_filter($courseIds)) as $courseId) {
            $existingScope = DB::table('course_module_track_lesson_course')
                ->where('course_id', $courseId)
                ->where('course_module_track_id', $track->id)
                ->where('lesson_id', $lesson->id)
                ->first(['metadata', 'created_at']);
            $existingMetadata = $existingScope
                ? json_decode((string) $existingScope->metadata, true)
                : null;
            $preserveSpreadsheetScope = is_array($existingMetadata)
                && ($existingMetadata['source'] ?? null) === 'spreadsheet';

            DB::table('course_module_track_lesson_course')->updateOrInsert([
                'course_id' => $courseId,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
            ], [
                'sort_order' => $sortOrder,
                'status' => 'published',
                'metadata' => $preserveSpreadsheetScope
                    ? $existingScope->metadata
                    : json_encode([
                        'source' => 'lesson_course_linker',
                        'linked_at' => now()->toIso8601String(),
                        'replaced_lesson_id' => $replacedLesson?->id,
                    ]),
                'created_at' => $existingScope?->created_at ?? now(),
                'updated_at' => now(),
            ]);

            if ($replacedLesson && $replacedLesson->isNot($lesson)) {
                DB::table('course_module_track_lesson_course')
                    ->where('course_id', $courseId)
                    ->where('course_module_track_id', $track->id)
                    ->where('lesson_id', $replacedLesson->id)
                    ->delete();
            }
        }
    }

    protected function hasDifferentSpreadsheetLessonAtSort(array $courseIds, CourseModuleTrack $track, int $sortOrder, Lesson $lesson): bool
    {
        if ($courseIds === []) {
            return false;
        }

        return DB::table('course_module_track_lesson_course')
            ->whereIn('course_id', $courseIds)
            ->where('course_module_track_id', $track->id)
            ->where('sort_order', $sortOrder)
            ->where('lesson_id', '!=', $lesson->id)
            ->where('metadata', 'like', '%"source":"spreadsheet"%')
            ->exists();
    }

    protected function trackHasSpreadsheetScope(array $courseIds, CourseModuleTrack $track): bool
    {
        if ($courseIds === []) {
            return false;
        }

        return DB::table('course_module_track_lesson_course')
            ->whereIn('course_id', $courseIds)
            ->where('course_module_track_id', $track->id)
            ->where('metadata', 'like', '%"source":"spreadsheet"%')
            ->exists();
    }

    protected function lessonHasSpreadsheetScope(array $courseIds, CourseModuleTrack $track, Lesson $lesson): bool
    {
        if ($courseIds === []) {
            return false;
        }

        return DB::table('course_module_track_lesson_course')
            ->whereIn('course_id', $courseIds)
            ->where('course_module_track_id', $track->id)
            ->where('lesson_id', $lesson->id)
            ->where('metadata', 'like', '%"source":"spreadsheet"%')
            ->exists();
    }

    protected function removeNonSpreadsheetCourseScopes(array $courseIds, CourseModuleTrack $track): void
    {
        if ($courseIds === []) {
            return;
        }

        DB::table('course_module_track_lesson_course')
            ->whereIn('course_id', $courseIds)
            ->where('course_module_track_id', $track->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('metadata')
                    ->orWhere('metadata', 'not like', '%"source":"spreadsheet"%');
            })
            ->delete();
    }

    protected function findReadyCandidateForPlanningLesson(string $title, CourseModule $module, int $sortOrder, array $attachedLessonIds): ?Lesson
    {
        return $this->readyLessonCandidatesFor($title, $module, null, $attachedLessonIds)
            ->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'score' => $this->lessonMatchScore($lesson, $title, $module),
                'priority' => $this->lessonMediaPriority($lesson),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sortByDesc(fn (array $candidate): float => ($candidate['score'] * 10) + $candidate['priority'])
            ->first()['lesson'] ?? null;
    }

    protected function bestTrackForPlanningCandidate(Lesson $lesson, string $title, CourseModule $module): ?CourseModuleTrack
    {
        $tracks = $module->relationLoaded('tracks') ? $module->tracks : $module->tracks()->with('courses')->get();

        return $tracks
            ->map(fn (CourseModuleTrack $track): array => [
                'track' => $track,
                'score' => $this->lessonMatchScore($lesson, $title, $module, $track),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sortByDesc('score')
            ->first()['track'] ?? null;
    }

    protected function lessonMatchScore(Lesson $lesson, string $title, CourseModule $module, ?CourseModuleTrack $track = null, ?int $sortOrder = null, ?int $expectedDurationSeconds = null): float
    {
        $titleKey = LessonTitleNormalizer::matchKey($title);
        [$lessonKey, $score] = $this->bestLessonTitleMatch($lesson, $titleKey);

        if ($score <= 0) {
            return 0.0;
        }

        $lessonNumber = $this->contextualLessonNumber($lesson->title);
        $titleNumber = $this->contextualLessonNumber($title) ?? $sortOrder;
        $lessonPartNumber = $this->partNumber($lesson->title);
        $titlePartNumber = $this->partNumber($title);

        $sameContextualNumber = $lessonNumber && $titleNumber && $lessonNumber === $titleNumber;

        if ($sameContextualNumber) {
            $score += 20;
        }

        if ($lessonNumber && $titleNumber && $lessonNumber !== $titleNumber) {
            return 0.0;
        }

        if ($lessonPartNumber && $titlePartNumber && $lessonPartNumber !== $titlePartNumber) {
            return 0.0;
        }

        if ($lessonPartNumber && $titlePartNumber && $lessonPartNumber === $titlePartNumber) {
            $score += 12;
        }

        if ($this->romanSuffix($lessonKey) !== $this->romanSuffix($titleKey)) {
            return 0.0;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $path = LessonTitleNormalizer::matchKey((string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? ''));
        $trackKey = $track ? LessonTitleNormalizer::matchKey($track->name) : '';
        $moduleKey = LessonTitleNormalizer::matchKey($module->name);
        $pathMatchesTrack = $trackKey !== '' && $this->pathMatchesContext($path, $trackKey);
        $lessonProduct = $this->contentProduct($lessonKey) ?? $this->contentProduct($path);
        $trackProduct = $this->contentProduct($trackKey);

        if ($trackKey !== '' && $path !== '' && ! $pathMatchesTrack) {
            return 0.0;
        }

        if ($lessonProduct && $trackProduct && $lessonProduct !== $trackProduct) {
            return 0.0;
        }

        if ($this->isGenericLessonTitle($title) && $lessonKey !== $titleKey && ! $pathMatchesTrack) {
            return 0.0;
        }

        if ($this->isGenericLessonTitle($title) && $pathMatchesTrack && $sameContextualNumber) {
            $score = max($score, 96);
        }

        if ($pathMatchesTrack) {
            $score += 12;
        }

        if ($moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey)) {
            $score += 4;
        }

        $durationScore = $this->durationMatchPercent($lesson, $expectedDurationSeconds);

        if ($durationScore !== null) {
            if ($durationScore >= 90) {
                $score += 10;
            } elseif ($durationScore >= 75) {
                $score += 4;
            } elseif ($durationScore < 50) {
                $score -= 30;
            } else {
                $score -= 12;
            }
        }

        return $score;
    }

    protected function durationMatchPercent(Lesson $lesson, ?int $expectedDurationSeconds): ?float
    {
        $expectedDurationSeconds = max(0, (int) $expectedDurationSeconds);
        $actualDurationSeconds = max(0, (int) $lesson->duration_seconds);

        if ($expectedDurationSeconds <= 0 || $actualDurationSeconds <= 0) {
            return null;
        }

        $shorter = min($expectedDurationSeconds, $actualDurationSeconds);
        $longer = max($expectedDurationSeconds, $actualDurationSeconds);

        return round(($shorter / $longer) * 100, 4);
    }

    protected function publishLesson(Lesson $lesson): bool
    {
        if (! $this->lessonHasReadyMedia($lesson) || $lesson->status === 'published') {
            return false;
        }

        $lesson->forceFill([
            'status' => 'published',
            'source_status' => 'media_ready',
        ])->save();

        return true;
    }

    protected function archiveDetachedPlaceholder(Lesson $lesson): void
    {
        $lesson->refresh();

        if ($this->lessonHasReadyMedia($lesson) || $lesson->modules()->exists() || $lesson->tracks()->exists()) {
            return;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $lesson->forceFill([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'status' => 'archived',
            'metadata' => array_merge($metadata, [
                'replaced_by_ready_lesson' => true,
            ]),
        ])->save();
    }

    protected function lessonHasReadyMedia(Lesson $lesson): bool
    {
        return $this->lessonMediaPriority($lesson) >= 100;
    }

    protected function readyLessonBelongsToTrack(Lesson $lesson, CourseModule $module, CourseModuleTrack $track): bool
    {
        $lesson->loadMissing('video');

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $path = LessonTitleNormalizer::matchKey((string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? ''));
        $lessonKey = LessonTitleNormalizer::matchKey((string) ($lesson->video?->title ?: $lesson->title));
        $trackKey = LessonTitleNormalizer::matchKey($track->name);
        $lessonProduct = $this->contentProduct($lessonKey);
        $trackProduct = $this->contentProduct($trackKey);

        if ($lessonProduct && $trackProduct && $lessonProduct !== $trackProduct) {
            return false;
        }

        if ($lessonProduct && $trackProduct && $lessonProduct === $trackProduct) {
            return true;
        }

        if ($path !== '') {
            return $this->pathMatchesContext($path, $trackKey);
        }

        return $lessonProduct === null;
    }

    protected function moduleHasCourseScopedTrackLessons(CourseModule $module, array $courseIds): bool
    {
        $trackIds = ($module->relationLoaded('tracks') ? $module->tracks : $module->tracks()->get())
            ->pluck('id')
            ->filter()
            ->values();

        if ($trackIds->isEmpty() || $courseIds === []) {
            return false;
        }

        return DB::table('course_module_track_lesson_course')
            ->whereIn('course_id', $courseIds)
            ->whereIn('course_module_track_id', $trackIds)
            ->exists();
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

        return 0;
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
                $lessonKey = LessonTitleNormalizer::matchKey($value);
                $score = $this->matchScoreForKeys($lessonKey, $titleKey);

                return [$lessonKey, $score];
            })
            ->sortByDesc(fn (array $match): float => $match[1])
            ->first() ?? ['', 0.0];
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

    protected function isGenericLessonTitle(string $title): bool
    {
        if (preg_match('/\b(?:L\.?\s*I\.?\s*A|L\.?\s*A\.?\s*I|L\.?\s*G\.?\s*P\.?\s*D|E\.?\s*C\.?\s*A)\b/iu', $title) === 1) {
            return true;
        }

        return count(array_filter(explode(' ', LessonTitleNormalizer::matchKey($title)))) <= 2;
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

        if (preg_match('/\bparte\s+0*(\d{1,3})\b/u', $key, $matches) !== 1) {
            return null;
        }

        return max(1, (int) $matches[1]);
    }

    protected function contextualLessonNumber(string $title): ?int
    {
        $key = LessonTitleNormalizer::matchKey($title);
        $leadingNumber = LessonTitleNormalizer::leadingNumber($title);

        if (preg_match('/\blei\s+organica\s+santos\s+0*(\d{1,3})\b/u', $key, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return $leadingNumber;
    }

    protected function pathMatchesContext(string $path, string $context): bool
    {
        if ($path === '' || $context === '') {
            return false;
        }

        foreach ($this->contextVariants($path) as $pathVariant) {
            foreach ($this->contextVariants($context) as $contextVariant) {
                if ($pathVariant !== '' && $contextVariant !== '' && (str_contains($pathVariant, $contextVariant) || str_contains($contextVariant, $pathVariant))) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function contextVariants(string $value): array
    {
        $variants = [
            $value,
            Str::of($value)->replace('ppt', 'power point')->replace('powerpoint', 'power point')->replace('windowns', 'windows')->squish()->value(),
            Str::of($value)->replace('power point', 'ppt')->replace('powerpoint', 'ppt')->replace('windowns', 'windows')->squish()->value(),
        ];

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
