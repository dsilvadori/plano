<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyPlan;
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
            $affectedCourseIds = [];
            $readyLessons = $this->readyLessons();

            $this->tracksQuery($course)
                ->with(['module.courses', 'courses', 'lessons.modules', 'lessons.tracks'])
                ->chunkById(50, function ($tracks) use ($readyLessons, &$stats, &$affectedCourseIds): void {
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
                                if ($this->publishLesson($lesson)) {
                                    $stats['published']++;
                                }

                                $module->onlineLessons()->syncWithoutDetaching([
                                    $lesson->id => ['sort_order' => $sortOrder],
                                ]);

                                continue;
                            }

                            $candidate = $this->findReadyCandidate($readyLessons, $lesson, $module, $track, $sortOrder);

                            if (! $candidate instanceof Lesson) {
                                continue;
                            }

                            if ($this->publishLesson($candidate)) {
                                $stats['published']++;
                            }

                            $module->onlineLessons()->syncWithoutDetaching([
                                $candidate->id => ['sort_order' => $sortOrder],
                            ]);
                            $track->lessons()->syncWithoutDetaching([
                                $candidate->id => ['sort_order' => $sortOrder],
                            ]);

                            if ($lesson->isNot($candidate)) {
                                $module->onlineLessons()->detach($lesson->id);
                                $track->lessons()->detach($lesson->id);
                                $this->archiveDetachedPlaceholder($lesson);
                                $stats['replaced']++;
                            }

                            $stats['linked']++;
                            $affectedCourseIds = array_values(array_unique([...$affectedCourseIds, ...$courseIds]));
                        }
                    }
                });

            if ($course instanceof Course) {
                $affectedCourseIds[] = $course->id;
            }

            $affectedCourseIds = array_values(array_unique(array_filter($affectedCourseIds)));

            if ($affectedCourseIds !== []) {
                $generator = app(StudyPlanGenerator::class);

                StudyPlan::query()
                    ->whereIn('course_id', $affectedCourseIds)
                    ->where('status', 'active')
                    ->with(['items.courseModule', 'course', 'studyTrack', 'user'])
                    ->chunkById(50, function ($plans) use ($generator, &$stats): void {
                        foreach ($plans as $plan) {
                            $generator->syncPublishedLessonsForPlan($plan);
                            $stats['plans_synced']++;
                        }
                    });
            }

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
                    ->orWhere('source_status', 'media_ready');
            })
            ->get();
    }

    protected function findReadyCandidate(Collection $readyLessons, Lesson $placeholder, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): ?Lesson
    {
        return $readyLessons
            ->where('id', '!=', $placeholder->id)
            ->map(fn (Lesson $lesson): array => [
                'lesson' => $lesson,
                'score' => $this->lessonMatchScore($lesson, $placeholder->title, $module, $track, $sortOrder),
                'priority' => $this->lessonMediaPriority($lesson),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 72)
            ->sortByDesc(fn (array $candidate): float => ($candidate['score'] * 10) + $candidate['priority'])
            ->first()['lesson'] ?? null;
    }

    protected function lessonMatchScore(Lesson $lesson, string $title, CourseModule $module, CourseModuleTrack $track, ?int $sortOrder = null): float
    {
        $score = LessonTitleNormalizer::matchScore($lesson->title, $title);

        if ($score <= 0) {
            return 0.0;
        }

        $lessonKey = LessonTitleNormalizer::matchKey($lesson->title);
        $titleKey = LessonTitleNormalizer::matchKey($title);
        $lessonNumber = $this->contextualLessonNumber($lesson->title);
        $titleNumber = $this->contextualLessonNumber($title) ?? $sortOrder;

        if ($lessonNumber && $titleNumber && $lessonNumber === $titleNumber) {
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
        $trackKey = LessonTitleNormalizer::matchKey($track->name);
        $moduleKey = LessonTitleNormalizer::matchKey($module->name);
        $pathMatchesTrack = $this->pathMatchesContext($path, $trackKey);
        $lessonProduct = $this->contentProduct($path) ?? $this->contentProduct($lessonKey);
        $trackProduct = $this->contentProduct($trackKey);

        if ($lessonProduct && $trackProduct && $lessonProduct !== $trackProduct) {
            return 0.0;
        }

        if ($this->isGenericLessonTitle($title) && $lessonKey !== $titleKey && ! $pathMatchesTrack) {
            return 0.0;
        }

        if ($pathMatchesTrack) {
            $score += 12;
        }

        if ($moduleKey !== '' && $this->pathMatchesContext($path, $moduleKey)) {
            $score += 4;
        }

        return $score;
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

    protected function lessonMediaPriority(Lesson $lesson): int
    {
        if (filled($lesson->panda_video_id) || filled($lesson->panda_embed_url) || filled($lesson->panda_player_url)) {
            return 120;
        }

        if ($lesson->source_status === 'media_ready') {
            return 100;
        }

        return 0;
    }

    protected function isGenericLessonTitle(string $title): bool
    {
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

        return null;
    }
}
