<?php

namespace App\Services;

use App\Models\Lesson;
use App\Support\LessonTitleNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LessonDuplicateMerger
{
    public function duplicateGroups(bool $includeArchived = false, string $scope = 'global'): Collection
    {
        return Lesson::query()
            ->select([
                'id',
                'lesson_folder_id',
                'video_id',
                'title',
                'description',
                'type',
                'status',
                'duration_seconds',
                'panda_video_id',
                'panda_embed_url',
                'panda_player_url',
                'google_doc_url',
                'digital_book_path',
                'source_status',
            ])
            ->when(! $includeArchived, fn ($query) => $query->where('status', '!=', 'archived'))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Lesson $lesson): string => $this->groupKey($lesson, $scope))
            ->filter(fn (Collection $lessons, string $key): bool => $key !== '' && $lessons->count() > 1)
            ->map(function (Collection $lessons, string $key): array {
                $canonical = $this->canonicalLesson($lessons);

                return [
                    'key' => $key,
                    'title' => $canonical->title,
                    'canonical_id' => (int) $canonical->id,
                    'duplicate_ids' => $lessons
                        ->where('id', '!=', $canonical->id)
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->values()
                        ->all(),
                    'count' => $lessons->count(),
                ];
            })
            ->values();
    }

    public function mergeAll(bool $includeArchived = false, string $scope = 'global'): array
    {
        $groups = $this->duplicateGroups($includeArchived, $scope);
        $merged = 0;
        $deleted = 0;

        foreach ($groups as $group) {
            $result = $this->mergeGroup((int) $group['canonical_id'], $group['duplicate_ids']);
            $merged++;
            $deleted += $result['deleted'];
        }

        return [
            'groups' => $groups->count(),
            'merged' => $merged,
            'deleted' => $deleted,
        ];
    }

    public function mergeGroup(int $canonicalId, array $duplicateIds): array
    {
        $duplicateIds = collect($duplicateIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $canonicalId)
            ->unique()
            ->values()
            ->all();

        if ($duplicateIds === []) {
            return ['deleted' => 0];
        }

        return DB::transaction(function () use ($canonicalId, $duplicateIds): array {
            $canonical = Lesson::query()->findOrFail($canonicalId);
            $duplicates = Lesson::query()
                ->whereIn('id', $duplicateIds)
                ->orderBy('id')
                ->get();

            $this->mergeLessonFields($canonical, $duplicates);
            $this->copyLessonPivotRows('course_module_lessons', 'course_module_id', $canonicalId, $duplicateIds);
            $this->copyLessonPivotRows('course_module_track_lessons', 'course_module_track_id', $canonicalId, $duplicateIds);
            $this->copyLessonPivotRows('course_module_track_lesson_course', ['course_id', 'course_module_track_id'], $canonicalId, $duplicateIds);
            $this->copyLessonPivotRows('study_plan_item_lessons', 'study_plan_item_id', $canonicalId, $duplicateIds);
            $this->copyLessonPivotRows('question_bank_lesson', 'question_bank_id', $canonicalId, $duplicateIds);
            $this->mergeLessonProgress($canonicalId, $duplicateIds);
            $this->mergeAiArtifacts($canonicalId, $duplicateIds);
            $this->updateLessonReferences('lesson_comments', $canonicalId, $duplicateIds);
            $this->updateLessonReferences('questions', $canonicalId, $duplicateIds);

            $deleted = DB::table('lessons')
                ->whereIn('id', $duplicateIds)
                ->delete();

            return ['deleted' => $deleted];
        });
    }

    protected function groupKey(Lesson $lesson, string $scope): string
    {
        $titleKey = LessonTitleNormalizer::matchKey((string) $lesson->title);

        if ($titleKey === '') {
            return '';
        }

        return $scope === 'folder'
            ? $titleKey.'|folder:'.((int) $lesson->lesson_folder_id)
            : $titleKey;
    }

    protected function canonicalLesson(Collection $lessons): Lesson
    {
        return $lessons
            ->sortByDesc(fn (Lesson $lesson): int => $this->canonicalScore($lesson))
            ->sortBy('id')
            ->sortByDesc(fn (Lesson $lesson): int => $this->canonicalScore($lesson))
            ->first();
    }

    protected function canonicalScore(Lesson $lesson): int
    {
        $score = 0;

        if ($lesson->video_id || filled($lesson->panda_video_id) || filled($lesson->panda_embed_url) || filled($lesson->panda_player_url)) {
            $score += 100;
        }

        if (filled($lesson->digital_book_path) || filled($lesson->google_doc_url) || $lesson->type === 'pdf') {
            $score += 80;
        }

        if (in_array((string) $lesson->source_status, ['media_ready', 'published'], true)) {
            $score += 30;
        }

        if ($lesson->status === 'published') {
            $score += 20;
        } elseif ($lesson->status === 'archived') {
            $score -= 100;
        }

        if ((int) $lesson->duration_seconds > 0) {
            $score += 5;
        }

        if (filled($lesson->description)) {
            $score += 3;
        }

        return $score + $this->referenceCount((int) $lesson->id);
    }

    protected function referenceCount(int $lessonId): int
    {
        return collect([
            'course_module_lessons',
            'course_module_track_lessons',
            'course_module_track_lesson_course',
            'study_plan_item_lessons',
            'lesson_progress',
            'lesson_comments',
            'questions',
            'question_bank_lesson',
        ])->sum(fn (string $table): int => Schema::hasTable($table)
            ? (int) DB::table($table)->where('lesson_id', $lessonId)->count()
            : 0);
    }

    protected function mergeLessonFields(Lesson $canonical, Collection $duplicates): void
    {
        $updates = [];

        foreach ($duplicates as $duplicate) {
            foreach ([
                'lesson_folder_id',
                'video_id',
                'course_id',
                'course_module_id',
                'course_module_track_id',
                'description',
                'thumbnail_url',
                'panda_video_id',
                'panda_embed_url',
                'panda_player_url',
                'panda_status',
                'google_doc_url',
                'digital_book_path',
                'source_status',
            ] as $field) {
                if (blank($canonical->{$field}) && filled($duplicate->{$field})) {
                    $updates[$field] = $duplicate->{$field};
                    $canonical->{$field} = $duplicate->{$field};
                }
            }

            if ((int) $canonical->duration_seconds <= 0 && (int) $duplicate->duration_seconds > 0) {
                $updates['duration_seconds'] = (int) $duplicate->duration_seconds;
                $canonical->duration_seconds = (int) $duplicate->duration_seconds;
            }

            if ($canonical->status !== 'published' && $duplicate->status === 'published') {
                $updates['status'] = 'published';
                $canonical->status = 'published';
            }

            if ($canonical->type === 'video' && in_array($duplicate->type, ['pdf', 'text'], true)) {
                $updates['type'] = $duplicate->type;
                $canonical->type = $duplicate->type;
            }
        }

        $metadata = is_array($canonical->metadata) ? $canonical->metadata : [];
        $metadata['merged_duplicate_lesson_ids'] = collect($metadata['merged_duplicate_lesson_ids'] ?? [])
            ->merge($duplicates->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $metadata['merged_duplicate_lessons'] = collect($metadata['merged_duplicate_lessons'] ?? [])
            ->merge($duplicates->map(fn (Lesson $lesson): array => [
                'id' => (int) $lesson->id,
                'title' => (string) $lesson->title,
                'slug' => (string) $lesson->slug,
                'merged_at' => now()->toIso8601String(),
            ]))
            ->values()
            ->all();

        $updates['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $updates['updated_at'] = now();

        DB::table('lessons')
            ->where('id', $canonical->id)
            ->update($updates);
    }

    protected function copyLessonPivotRows(string $table, string|array $parentColumns, int $canonicalId, array $duplicateIds): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $parentColumns = (array) $parentColumns;
        $rows = DB::table($table)
            ->whereIn('lesson_id', $duplicateIds)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $payload = (array) $row;
            unset($payload['id']);
            $payload['lesson_id'] = $canonicalId;
            $payload['updated_at'] = now();

            if (array_key_exists('created_at', $payload) && blank($payload['created_at'])) {
                $payload['created_at'] = now();
            }

            DB::table($table)->insertOrIgnore($payload);

            $query = DB::table($table)->where('lesson_id', $canonicalId);
            foreach ($parentColumns as $column) {
                $query->where($column, $row->{$column});
            }

            if (Schema::hasColumn($table, 'sort_order') && isset($row->sort_order)) {
                $query->update([
                    'sort_order' => min((int) $row->sort_order, (int) ($query->value('sort_order') ?? $row->sort_order)),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table($table)
            ->whereIn('lesson_id', $duplicateIds)
            ->delete();
    }

    protected function mergeLessonProgress(int $canonicalId, array $duplicateIds): void
    {
        if (! Schema::hasTable('lesson_progress')) {
            return;
        }

        DB::table('lesson_progress')
            ->whereIn('lesson_id', $duplicateIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $progress) use ($canonicalId): void {
                $existing = DB::table('lesson_progress')
                    ->where('user_id', $progress->user_id)
                    ->where('lesson_id', $canonicalId)
                    ->first();

                if (! $existing) {
                    DB::table('lesson_progress')
                        ->where('id', $progress->id)
                        ->update([
                            'lesson_id' => $canonicalId,
                            'updated_at' => now(),
                        ]);

                    return;
                }

                DB::table('lesson_progress')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => $this->strongestProgressStatus((string) $existing->status, (string) $progress->status),
                        'progress_seconds' => max((int) $existing->progress_seconds, (int) $progress->progress_seconds),
                        'completed_at' => $this->mergedCompletedAt($existing->completed_at, $progress->completed_at),
                        'updated_at' => now(),
                    ]);

                DB::table('lesson_progress')->where('id', $progress->id)->delete();
            });
    }

    protected function mergeAiArtifacts(int $canonicalId, array $duplicateIds): void
    {
        if (! Schema::hasTable('ai_artifacts')) {
            return;
        }

        DB::table('ai_artifacts')
            ->where('source_type', Lesson::class)
            ->whereIn('source_id', $duplicateIds)
            ->orderBy('id')
            ->get()
            ->each(function (object $artifact) use ($canonicalId): void {
                $existing = DB::table('ai_artifacts')
                    ->where('source_type', Lesson::class)
                    ->where('source_id', $canonicalId)
                    ->where('artifact_type', $artifact->artifact_type)
                    ->where('provider', $artifact->provider)
                    ->first();

                if (! $existing) {
                    DB::table('ai_artifacts')
                        ->where('id', $artifact->id)
                        ->update([
                            'source_id' => $canonicalId,
                            'updated_at' => now(),
                        ]);

                    return;
                }

                if (blank($existing->content) && filled($artifact->content)) {
                    DB::table('ai_artifacts')
                        ->where('id', $existing->id)
                        ->update([
                            'content' => $artifact->content,
                            'status' => $artifact->status,
                            'metadata' => $this->mergeJson($existing->metadata, $artifact->metadata),
                            'updated_at' => now(),
                        ]);
                }

                DB::table('ai_artifacts')->where('id', $artifact->id)->delete();
            });
    }

    protected function updateLessonReferences(string $table, int $canonicalId, array $duplicateIds): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'lesson_id')) {
            return;
        }

        DB::table($table)
            ->whereIn('lesson_id', $duplicateIds)
            ->update([
                'lesson_id' => $canonicalId,
                'updated_at' => now(),
            ]);
    }

    protected function strongestProgressStatus(string $left, string $right): string
    {
        $rank = [
            'not_started' => 0,
            'in_progress' => 1,
            'completed' => 2,
        ];

        return ($rank[$right] ?? 0) > ($rank[$left] ?? 0) ? $right : $left;
    }

    protected function mergedCompletedAt(mixed $left, mixed $right): mixed
    {
        if (blank($left)) {
            return $right;
        }

        if (blank($right)) {
            return $left;
        }

        return min((string) $left, (string) $right);
    }

    protected function mergeJson(?string $left, ?string $right): string
    {
        $left = json_decode((string) $left, true);
        $right = json_decode((string) $right, true);

        return json_encode(array_replace_recursive(is_array($left) ? $left : [], is_array($right) ? $right : []), JSON_UNESCAPED_UNICODE);
    }
}
