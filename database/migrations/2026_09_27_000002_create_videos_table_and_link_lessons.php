<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_folder_id')->nullable()->constrained('lesson_folders')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('provider')->default('panda')->index();
            $table->string('provider_video_id')->nullable()->unique();
            $table->string('provider_status')->nullable()->index();
            $table->string('embed_url')->nullable();
            $table->string('player_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('source_status')->default('awaiting_media')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('video_id')->nullable()->after('lesson_folder_id')->constrained('videos')->nullOnDelete();
        });

        DB::table('lessons')
            ->select([
                'id',
                'lesson_folder_id',
                'title',
                'slug',
                'description',
                'thumbnail_url',
                'duration_seconds',
                'panda_video_id',
                'panda_embed_url',
                'panda_player_url',
                'panda_status',
                'source_status',
                'metadata',
                'created_at',
                'updated_at',
            ])
            ->where(function ($query): void {
                $query->whereNotNull('panda_video_id')
                    ->orWhereNotNull('panda_embed_url')
                    ->orWhereNotNull('panda_player_url')
                    ->orWhere('source_status', 'media_ready')
                    ->orWhere('source_status', 'panda_processing');
            })
            ->orderBy('id')
            ->chunkById(200, function ($lessons): void {
                foreach ($lessons as $lesson) {
                    $providerVideoId = filled($lesson->panda_video_id) ? (string) $lesson->panda_video_id : null;
                    $existingVideoId = $providerVideoId
                        ? DB::table('videos')->where('provider_video_id', $providerVideoId)->value('id')
                        : null;

                    $metadata = json_decode((string) $lesson->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];

                    $payload = [
                        'lesson_folder_id' => $lesson->lesson_folder_id,
                        'title' => (string) $lesson->title,
                        'slug' => (string) ($lesson->slug ?: Str::slug((string) $lesson->title)),
                        'description' => $lesson->description,
                        'provider' => 'panda',
                        'provider_video_id' => $providerVideoId,
                        'provider_status' => $lesson->panda_status,
                        'embed_url' => $lesson->panda_embed_url,
                        'player_url' => $lesson->panda_player_url,
                        'thumbnail_url' => $lesson->thumbnail_url,
                        'duration_seconds' => (int) $lesson->duration_seconds,
                        'source_status' => (string) ($lesson->source_status ?: ($providerVideoId ? 'media_ready' : 'awaiting_media')),
                        'metadata' => json_encode([
                            ...$metadata,
                            'legacy_lesson_id' => $lesson->id,
                            'legacy_source' => 'lessons_backfill',
                        ]),
                        'created_at' => $lesson->created_at ?: now(),
                        'updated_at' => now(),
                    ];

                    $videoId = $existingVideoId
                        ? (int) $existingVideoId
                        : (int) DB::table('videos')->insertGetId($payload);

                    if ($existingVideoId) {
                        DB::table('videos')->whereKey($videoId)->update([
                            ...$payload,
                            'created_at' => DB::table('videos')->whereKey($videoId)->value('created_at') ?: $payload['created_at'],
                        ]);
                    }

                    DB::table('lessons')
                        ->where('id', $lesson->id)
                        ->update(['video_id' => $videoId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('video_id');
        });

        Schema::dropIfExists('videos');
    }
};
