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
            $readyLessons = $this->readyLessons();

            $this->tracksQuery($course)
                ->with(['module.courses', 'courses', 'lessons.modules', 'lessons.tracks'])
                ->chunkById(50, function ($tracks) use ($readyLessons, &$stats): void {
                    foreach ($tracks as $track) {
                        $module = $track->module;

                        if (! $module instanceof CourseModule) {
                            continue;
                        }

                        $stats['tracks']++;
                        $courseIds = $track->courses
                            ->pluck('id')
                            ->merge($module->courses->pluck('id'))
                            ->unique()
                            ->values()
                            ->all();

                        foreach ($track->lessons as $lesson) {
                            $sortOrder = (int) ($lesson->pivot?->sort_order ?? $lesson->sort_order);

                            if ($this->lessonHasReadyMedia($lesson)) {
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

                            $candidates = $this->findReadyCandidates($readyLessons, $lesson, $module, $track, $sortOrder);

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

            $this->linkReadyLessonsToPlanningLessons($course, $readyLessons, $stats);

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

    protected function readyLessons(): Collection
    {
        return Lesson::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('panda_video_id')
                    ->orWhereNotNull('panda_embed_url')
                    ->orWhereNotNull('panda_player_url')
                    ->orWhere('source_status', 'media_ready')
                    ->orWhereHas('video', function (Builder $query): void {
                        $query->whereNotNull('provider_video_id')
                            ->orWhereNotNull('embed_url')
                            ->orWhereNotNull('player_url')
                            ->orWhere('source_status', 'media_ready');
                    });
            })
            ->with('video')
            ->get();
    }

    protected function findReadyCandidate(Collection $readyLessons, Lesson $placeholder, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): ?Lesson
    {
        return $this->findReadyCandidates($readyLessons, $placeholder, $module, $track, $sortOrder)->first();
    }

    protected function findReadyCandidates(Collection $readyLessons, Lesson $placeholder, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): Collection
    {
        $candidates = $readyLessons
            ->where('id', '!=', $placeholder->id)
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

        $placeholderNumber = $this->contextualLessonNumber($placeholder->title) ?? $sortOrder;
        $sameNumberContextCandidates = $candidates
            ->filter(function (array $candidate) use ($placeholderNumber): bool {
                $lessonNumber = $this->contextualLessonNumber($candidate['lesson']->title);

                return $placeholderNumber !== null && $lessonNumber !== null && $placeholderNumber === $lessonNumber;
            })
            ->pluck('lesson')
            ->values();

        return $sameNumberContextCandidates->isNotEmpty()
            ? $sameNumberContextCandidates
            : $candidates->take(1)->pluck('lesson');
    }

    protected function linkReadyLessonsToPlanningLessons(?Course $course, Collection $readyLessons, array &$stats): void
    {
        $this->planningModulesQuery($course)
            ->with(['courses', 'onlineLessons', 'tracks.courses'])
            ->chunkById(50, function ($modules) use ($course, $readyLessons, &$stats): void {
                foreach ($modules as $module) {
                    $planningLessons = $module->planning_lessons;

                    if ($planningLessons === []) {
                        continue;
                    }

                    $courseIds = $module->courses
                        ->pluck('id')
                        ->when($course instanceof Course, fn (Collection $ids) => $ids->push($course->id))
                        ->unique()
                        ->values()
                        ->all();
                    $attachedLessonIds = $module->onlineLessons->pluck('id')->all();

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

                        $candidate = $this->findReadyCandidateForPlanningLesson($readyLessons, $title, $module, $sortOrder, $attachedLessonIds);

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

    protected function syncCourseTrackLessonScopes(array $courseIds, CourseModuleTrack $track, Lesson $lesson, int $sortOrder, ?Lesson $replacedLesson = null): void
    {
        foreach (array_unique(array_filter($courseIds)) as $courseId) {
            DB::table('course_module_track_lesson_course')->updateOrInsert([
                'course_id' => $courseId,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
            ], [
                'sort_order' => $sortOrder,
                'status' => 'published',
                'metadata' => json_encode([
                    'source' => 'lesson_course_linker',
                    'linked_at' => now()->toIso8601String(),
                    'replaced_lesson_id' => $replacedLesson?->id,
                ]),
                'created_at' => now(),
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

    protected function findReadyCandidateForPlanningLesson(Collection $readyLessons, string $title, CourseModule $module, int $sortOrder, array $attachedLessonIds): ?Lesson
    {
        return $readyLessons
            ->whereNotIn('id', $attachedLessonIds)
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

        $sameContextualNumber = $lessonNumber && $titleNumber && $lessonNumber === $titleNumber;

        if ($sameContextualNumber) {
            $score += 20;
        }

        if ($lessonNumber && $titleNumber && $lessonNumber !== $titleNumber) {
            return 0.0;
        }

        if ($this->romanSuffix($lessonKey) !== $this->romanSuffix($titleKey)) {
            return 0.0;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $path = LessonTitleNormalizer::matchKey((string) ($metadata['drive_source_folder_path'] ?? ''));
        $trackKey = $track ? LessonTitleNormalizer::matchKey($track->name) : '';
        $moduleKey = LessonTitleNormalizer::matchKey($module->name);
        $pathMatchesTrack = $trackKey !== '' && $this->pathMatchesContext($path, $trackKey);
        $lessonProduct = $this->contentProduct($lessonKey) ?? $this->contentProduct($path);
        $trackProduct = $this->contentProduct($trackKey);

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
                $score += 8;
            } elseif ($durationScore >= 75) {
                $score += 4;
            } elseif ($durationScore < 50 && $score < 95) {
                $score -= 10;
            } elseif ($durationScore < 70 && $score < 90) {
                $score -= 6;
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
        $path = LessonTitleNormalizer::matchKey((string) ($metadata['drive_source_folder_path'] ?? ''));
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

        return $this->pathMatchesContext($path, $trackKey)
            || $this->pathMatchesContext($path, LessonTitleNormalizer::matchKey($module->name))
            || $lessonProduct === null;
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
        return array_values(array_unique([
            $value,
            Str::of($value)->replace('ppt', 'power point')->replace('powerpoint', 'power point')->replace('windowns', 'windows')->squish()->value(),
            Str::of($value)->replace('power point', 'ppt')->replace('powerpoint', 'ppt')->replace('windowns', 'windows')->squish()->value(),
        ]));
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

        if ($hasLdb) {
            return 'ldb';
        }

        if ($hasEca) {
            return 'eca';
        }

        return null;
    }
}
