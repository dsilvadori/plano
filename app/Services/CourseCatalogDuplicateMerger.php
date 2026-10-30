<?php

namespace App\Services;

use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CourseCatalogDuplicateMerger
{
    /**
     * @return array{modules_merged:int, tracks_merged:int, tracks_moved:int}
     */
    public function merge(bool $apply = false): array
    {
        $summary = [
            'modules_merged' => 0,
            'tracks_merged' => 0,
            'tracks_moved' => 0,
        ];

        $work = function () use (&$summary): void {
            while ($group = $this->nextDuplicateModuleGroup()) {
                /** @var CourseModule $canonical */
                $canonical = $group->shift();

                $group->each(function (CourseModule $duplicate) use ($canonical, &$summary): void {
                    $this->mergeModuleInto($duplicate->fresh(), $canonical->fresh(), $summary);
                });
            }
        };

        if (! $apply) {
            DB::beginTransaction();
            $work();
            DB::rollBack();

            return $summary;
        }

        DB::transaction($work);

        return $summary;
    }

    protected function nextDuplicateModuleGroup(): ?Collection
    {
        return CourseModule::query()
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CourseModule $module): string => $this->nameKey($module->name))
            ->first(fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)
            ?->values();
    }

    /**
     * @param array{modules_merged:int, tracks_merged:int, tracks_moved:int} $summary
     */
    protected function mergeModuleInto(?CourseModule $duplicate, ?CourseModule $canonical, array &$summary): void
    {
        if (! $duplicate || ! $canonical || $duplicate->is($canonical)) {
            return;
        }

        $duplicate->tracks()
            ->withoutGlobalScopes()
            ->orderBy('id')
            ->get()
            ->each(function (CourseModuleTrack $track) use ($canonical, &$summary): void {
                $matchingTrack = $this->matchingTrack($canonical, $track);

                if ($matchingTrack) {
                    $this->mergeTrackInto($track, $matchingTrack, $summary);

                    return;
                }

                $track->forceFill([
                    'course_module_id' => $canonical->id,
                    'sort_order' => $this->nextTrackSortOrder($canonical),
                    'slug' => $this->uniqueTrackSlug($canonical, $track->slug ?: Str::slug($track->name), $track->id),
                    'metadata' => $this->markMergedMetadata($track->metadata, 'moved_from_module_id', $track->course_module_id),
                ])->save();
                $summary['tracks_moved']++;
            });

        $this->copyPivot('course_module_course', 'course_module_id', $duplicate->id, $canonical->id, ['course_id'], ['sort_order']);
        $this->copyPivot('course_module_lessons', 'course_module_id', $duplicate->id, $canonical->id, ['lesson_id'], ['sort_order']);
        $this->copyPivot('question_bank_course_module', 'course_module_id', $duplicate->id, $canonical->id, ['question_bank_id']);
        $this->copyPivot('study_track_modules', 'course_module_id', $duplicate->id, $canonical->id, ['study_track_id'], ['weight', 'sort_order']);

        $this->updateForeignKey('lessons', 'course_module_id', $duplicate->id, $canonical->id);
        $this->updateForeignKey('study_plan_items', 'course_module_id', $duplicate->id, $canonical->id);
        $this->updateForeignKey('google_drive_import_runs', 'course_module_id', $duplicate->id, $canonical->id);
        $this->updateForeignKey('question_banks', 'course_module_id', $duplicate->id, $canonical->id);

        $canonical->forceFill([
            'lessons' => $this->mergedPlanningLessons($canonical, $duplicate),
            'workload_minutes' => max((int) $canonical->workload_minutes, (int) $duplicate->workload_minutes),
            'panda_folder_id' => $canonical->panda_folder_id ?: $duplicate->panda_folder_id,
            'metadata' => $this->markMergedMetadata($canonical->metadata, 'merged_module_ids', $duplicate->id),
        ])->save();

        $this->deleteModuleRows($duplicate->id);
        $summary['modules_merged']++;
    }

    /**
     * @param array{modules_merged:int, tracks_merged:int, tracks_moved:int} $summary
     */
    protected function mergeTrackInto(CourseModuleTrack $duplicate, CourseModuleTrack $canonical, array &$summary): void
    {
        $this->copyPivot('course_module_track_course', 'course_module_track_id', $duplicate->id, $canonical->id, ['course_id'], ['sort_order']);
        $this->copyPivot('course_module_track_lessons', 'course_module_track_id', $duplicate->id, $canonical->id, ['lesson_id'], ['sort_order', 'status_override']);
        $this->copyPivot('course_module_track_lesson_course', 'course_module_track_id', $duplicate->id, $canonical->id, ['course_id', 'lesson_id'], ['sort_order', 'status', 'metadata']);
        $this->copyPivot('question_bank_course_module_track', 'course_module_track_id', $duplicate->id, $canonical->id, ['question_bank_id']);

        $this->updateForeignKey('lessons', 'course_module_track_id', $duplicate->id, $canonical->id);

        $canonical->forceFill([
            'panda_folder_id' => $canonical->panda_folder_id ?: $duplicate->panda_folder_id,
            'google_doc_url' => $canonical->google_doc_url ?: $duplicate->google_doc_url,
            'metadata' => $this->markMergedMetadata($canonical->metadata, 'merged_track_ids', $duplicate->id),
        ])->save();

        $this->deleteTrackRows($duplicate->id);
        $summary['tracks_merged']++;
    }

    protected function matchingTrack(CourseModule $module, CourseModuleTrack $track): ?CourseModuleTrack
    {
        $trackKey = $this->nameKey($track->name);

        return $module->tracks()
            ->withoutGlobalScopes()
            ->get()
            ->first(fn (CourseModuleTrack $candidate): bool => $this->nameKey($candidate->name) === $trackKey || $candidate->slug === $track->slug);
    }

    protected function copyPivot(string $table, string $foreignKey, int $fromId, int $toId, array $uniqueKeys, array $payloadKeys = []): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)
            ->where($foreignKey, $fromId)
            ->get()
            ->each(function (object $row) use ($table, $foreignKey, $toId, $uniqueKeys, $payloadKeys): void {
                $payload = [
                    $foreignKey => $toId,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => now(),
                ];

                foreach ([...$uniqueKeys, ...$payloadKeys] as $key) {
                    if (property_exists($row, $key)) {
                        $payload[$key] = $row->{$key};
                    }
                }

                DB::table($table)->insertOrIgnore($payload);
            });
    }

    protected function updateForeignKey(string $table, string $column, int $fromId, int $toId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)->where($column, $fromId)->update([$column => $toId]);
    }

    protected function deleteModuleRows(int $moduleId): void
    {
        DB::table('course_module_course')->where('course_module_id', $moduleId)->delete();
        DB::table('course_module_lessons')->where('course_module_id', $moduleId)->delete();
        DB::table('question_bank_course_module')->where('course_module_id', $moduleId)->delete();
        DB::table('study_track_modules')->where('course_module_id', $moduleId)->delete();
        DB::table('course_modules')->where('id', $moduleId)->delete();
    }

    protected function deleteTrackRows(int $trackId): void
    {
        DB::table('course_module_track_course')->where('course_module_track_id', $trackId)->delete();
        DB::table('course_module_track_lessons')->where('course_module_track_id', $trackId)->delete();
        DB::table('course_module_track_lesson_course')->where('course_module_track_id', $trackId)->delete();
        DB::table('question_bank_course_module_track')->where('course_module_track_id', $trackId)->delete();
        DB::table('course_module_tracks')->where('id', $trackId)->delete();
    }

    protected function mergedPlanningLessons(CourseModule $canonical, CourseModule $duplicate): array
    {
        return collect($canonical->lessons ?? [])
            ->merge($duplicate->lessons ?? [])
            ->filter(fn (array $lesson): bool => filled($lesson['name'] ?? null))
            ->unique(fn (array $lesson): string => $this->nameKey((string) $lesson['name']))
            ->values()
            ->all();
    }

    protected function markMergedMetadata(mixed $metadata, string $key, int|string|null $id): array
    {
        $metadata = is_array($metadata) ? $metadata : [];

        if ($id === null || $id === '') {
            return $metadata;
        }

        $metadata[$key] = collect($metadata[$key] ?? [])
            ->push($id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $metadata['merged_at'] = now()->toIso8601String();

        return $metadata;
    }

    protected function nextTrackSortOrder(CourseModule $module): int
    {
        return ((int) $module->tracks()->withoutGlobalScopes()->max('sort_order')) + 1;
    }

    protected function uniqueTrackSlug(CourseModule $module, string $baseSlug, int $ignoreId): string
    {
        $baseSlug = $baseSlug ?: 'trilha';
        $slug = $baseSlug;
        $suffix = 2;

        while (CourseModuleTrack::query()
            ->where('course_module_id', $module->id)
            ->where('slug', $slug)
            ->whereKeyNot($ignoreId)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    protected function nameKey(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();
    }
}
