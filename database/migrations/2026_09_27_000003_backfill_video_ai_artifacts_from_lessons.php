<?php

use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_artifacts') || ! Schema::hasTable('videos') || ! Schema::hasColumn('lessons', 'video_id')) {
            return;
        }

        DB::table('ai_artifacts')
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'ai_artifacts.source_id')
                    ->where('ai_artifacts.source_type', '=', Lesson::class);
            })
            ->whereNotNull('lessons.video_id')
            ->select([
                'ai_artifacts.id as artifact_id',
                'ai_artifacts.artifact_type',
                'ai_artifacts.provider',
                'ai_artifacts.status',
                'ai_artifacts.content',
                'ai_artifacts.metadata',
                'lessons.id as lesson_id',
                'lessons.video_id',
                'ai_artifacts.created_at',
                'ai_artifacts.updated_at',
            ])
            ->orderBy('ai_artifacts.id')
            ->chunkById(200, function ($artifacts): void {
                foreach ($artifacts as $artifact) {
                    $metadata = json_decode((string) $artifact->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];

                    DB::table('ai_artifacts')->updateOrInsert([
                        'source_type' => Video::class,
                        'source_id' => (int) $artifact->video_id,
                        'artifact_type' => (string) $artifact->artifact_type,
                        'provider' => (string) $artifact->provider,
                    ], [
                        'status' => (string) $artifact->status,
                        'content' => $artifact->content,
                        'metadata' => json_encode([
                            ...$metadata,
                            'lesson_id' => (int) $artifact->lesson_id,
                            'video_id' => (int) $artifact->video_id,
                            'backfilled_from_lesson_artifact' => true,
                            'backfilled_at' => now()->toIso8601String(),
                        ]),
                        'created_at' => $artifact->created_at ?: now(),
                        'updated_at' => now(),
                    ]);
                }
            }, 'ai_artifacts.id', 'artifact_id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_artifacts')) {
            return;
        }

        DB::table('ai_artifacts')
            ->where('source_type', Video::class)
            ->where('metadata', 'like', '%backfilled_from_lesson_artifact%')
            ->delete();
    }
};
