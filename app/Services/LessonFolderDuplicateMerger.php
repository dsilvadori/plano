<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonFolder;
use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LessonFolderDuplicateMerger
{
    /**
     * @return array{folders_merged:int, lessons_moved:int, videos_moved:int, children_moved:int}
     */
    public function merge(bool $apply = false, string $scope = 'global'): array
    {
        $summary = [
            'folders_merged' => 0,
            'lessons_moved' => 0,
            'videos_moved' => 0,
            'children_moved' => 0,
        ];

        $work = function () use (&$summary, $scope): void {
            while ($group = $this->nextDuplicateGroup($scope)) {
                /** @var LessonFolder $canonical */
                $canonical = $group->shift();

                $group->each(function (LessonFolder $duplicate) use ($canonical, &$summary): void {
                    $this->mergeFolderInto($duplicate->fresh(), $canonical->fresh(), $summary);
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

    protected function nextDuplicateGroup(string $scope): ?Collection
    {
        return LessonFolder::query()
            ->orderByRaw('parent_id is not null')
            ->orderBy('parent_id')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (LessonFolder $folder): string => $this->duplicateKey($folder, $scope))
            ->first(fn (Collection $group, string $key): bool => ! str_ends_with($key, '|') && $group->count() > 1)
            ?->values();
    }

    protected function duplicateKey(LessonFolder $folder, string $scope): string
    {
        $nameKey = $this->nameKey($folder->name);

        if ($scope === 'parent') {
            return ($folder->parent_id ?? 'root').'|'.$nameKey;
        }

        return 'global|'.$nameKey;
    }

    /**
     * @param array{folders_merged:int, lessons_moved:int, videos_moved:int, children_moved:int} $summary
     */
    protected function mergeFolderInto(?LessonFolder $duplicate, ?LessonFolder $canonical, array &$summary): void
    {
        if (! $duplicate || ! $canonical || $duplicate->is($canonical)) {
            return;
        }

        $duplicate->children()
            ->orderBy('id')
            ->get()
            ->each(function (LessonFolder $child) use ($canonical, &$summary): void {
                $matchingChild = $this->matchingChild($canonical, $child);

                if ($matchingChild) {
                    $this->mergeFolderInto($child, $matchingChild, $summary);

                    return;
                }

                $child->forceFill([
                    'parent_id' => $canonical->id,
                    'sort_order' => $this->nextChildSortOrder($canonical),
                ])->save();
                $this->refreshPathTree($child);
                $summary['children_moved']++;
            });

        $summary['lessons_moved'] += Lesson::query()
            ->where('lesson_folder_id', $duplicate->id)
            ->update(['lesson_folder_id' => $canonical->id]);

        $summary['videos_moved'] += Video::query()
            ->where('lesson_folder_id', $duplicate->id)
            ->update(['lesson_folder_id' => $canonical->id]);

        $canonical->forceFill([
            'metadata' => $this->mergedMetadata($canonical, $duplicate),
        ])->save();

        $duplicate->delete();
        $summary['folders_merged']++;
    }

    protected function matchingChild(LessonFolder $parent, LessonFolder $child): ?LessonFolder
    {
        $childKey = $this->nameKey($child->name);

        return $parent->children()
            ->get()
            ->first(fn (LessonFolder $candidate): bool => $this->nameKey($candidate->name) === $childKey || $candidate->slug === $child->slug);
    }

    protected function refreshPathTree(LessonFolder $folder): void
    {
        $folder = $folder->fresh('parent');
        $slug = Str::slug($folder->name) ?: $folder->slug ?: 'pasta-'.$folder->id;
        $path = $folder->parent
            ? trim($folder->parent->path.'/'.$slug, '/')
            : $slug;

        if (LessonFolder::query()->where('path', $path)->whereKeyNot($folder->id)->exists()) {
            $slug = $this->uniqueSlug($folder->parent_id, $slug, $folder->id);
            $path = $folder->parent
                ? trim($folder->parent->path.'/'.$slug, '/')
                : $slug;
        }

        $folder->forceFill([
            'slug' => $slug,
            'path' => $path,
        ])->save();

        $folder->children()
            ->orderBy('id')
            ->get()
            ->each(fn (LessonFolder $child) => $this->refreshPathTree($child));
    }

    protected function uniqueSlug(?int $parentId, string $baseSlug, int $ignoreId): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while (LessonFolder::query()
            ->where('parent_id', $parentId)
            ->where('slug', $slug)
            ->whereKeyNot($ignoreId)
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    protected function nextChildSortOrder(LessonFolder $parent): int
    {
        return ((int) $parent->children()->max('sort_order')) + 1;
    }

    protected function mergedMetadata(LessonFolder $canonical, LessonFolder $duplicate): array
    {
        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        $duplicateMetadata = is_array($duplicate->metadata) ? $duplicate->metadata : [];
        $mergedIds = collect($metadata['merged_folder_ids'] ?? [])
            ->push($duplicate->id)
            ->merge($duplicateMetadata['merged_folder_ids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return array_replace_recursive($duplicateMetadata, $metadata, [
            'merged_folder_ids' => $mergedIds,
            'merged_at' => now()->toIso8601String(),
        ]);
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
