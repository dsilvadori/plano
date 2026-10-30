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
        Schema::create('lesson_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('lesson_folders')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('path')->unique();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'slug']);
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->foreignId('lesson_folder_id')->nullable()->after('id')->constrained('lesson_folders')->nullOnDelete();
        });

        $folderIdsByPath = [];

        DB::table('lessons')
            ->select(['id', 'metadata'])
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($lessons) use (&$folderIdsByPath): void {
                foreach ($lessons as $lesson) {
                    $metadata = json_decode((string) $lesson->metadata, true);

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $path = trim((string) ($metadata['library_folder_path'] ?? $metadata['drive_source_folder_path'] ?? ''));

                    if ($path === '') {
                        continue;
                    }

                    $folderId = $this->ensureFolderPath($path, $folderIdsByPath);

                    DB::table('lessons')
                        ->where('id', $lesson->id)
                        ->update(['lesson_folder_id' => $folderId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lesson_folder_id');
        });

        Schema::dropIfExists('lesson_folders');
    }

    protected function ensureFolderPath(string $path, array &$folderIdsByPath): int
    {
        $segments = collect(preg_split('/\s*\/\s*/', $path) ?: [])
            ->map(fn (string $segment): string => trim($segment))
            ->filter()
            ->values();

        $parentId = null;
        $currentPath = '';

        foreach ($segments as $index => $segment) {
            $slug = Str::slug($segment) ?: 'pasta-'.($index + 1);
            $currentPath = $currentPath === '' ? $slug : $currentPath.'/'.$slug;

            if (isset($folderIdsByPath[$currentPath])) {
                $parentId = $folderIdsByPath[$currentPath];

                continue;
            }

            $existingId = DB::table('lesson_folders')->where('path', $currentPath)->value('id');

            if ($existingId) {
                $parentId = (int) $existingId;
                $folderIdsByPath[$currentPath] = $parentId;

                continue;
            }

            $parentId = (int) DB::table('lesson_folders')->insertGetId([
                'parent_id' => $parentId,
                'name' => $segment,
                'slug' => $slug,
                'path' => $currentPath,
                'sort_order' => $index + 1,
                'metadata' => json_encode(['source' => 'lesson_metadata_backfill']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $folderIdsByPath[$currentPath] = $parentId;
        }

        return (int) $parentId;
    }
};
