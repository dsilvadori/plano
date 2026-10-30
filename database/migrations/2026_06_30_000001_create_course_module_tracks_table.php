<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_module_tracks')) {
            Schema::create('course_module_tracks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->string('thumbnail_url')->nullable();
                $table->string('thumbnail_path')->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->string('status')->default('draft')->index();
                $table->string('panda_folder_id')->nullable()->index();
                $table->string('google_doc_url')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['course_module_id', 'slug'], 'cmt_module_slug_unique');
            });
        }

        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'course_module_track_id')) {
                $table->foreignId('course_module_track_id')
                    ->nullable()
                    ->after('course_module_id')
                    ->constrained('course_module_tracks')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('lessons', 'google_doc_url')) {
                $table->string('google_doc_url')->nullable()->after('panda_status');
            }

            if (! Schema::hasColumn('lessons', 'source_status')) {
                $table->string('source_status')->default('media_ready')->after('google_doc_url')->index();
            }
        });

        if (! Schema::hasTable('course_module_track_lessons')) {
            Schema::create('course_module_track_lessons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_module_track_id')->constrained()->cascadeOnDelete();
                $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status_override')->nullable()->index();
                $table->timestamps();

                $table->unique(['course_module_track_id', 'lesson_id'], 'cmtl_track_lesson_unique');
                $table->index(['lesson_id', 'course_module_track_id'], 'cmtl_lesson_track_index');
            });
        }

        DB::table('course_modules')
            ->select(['id', 'name', 'panda_folder_id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $module): void {
                $trackId = DB::table('course_module_tracks')
                    ->where('course_module_id', $module->id)
                    ->where('slug', 'aulas')
                    ->value('id');

                if (! $trackId) {
                    $trackId = DB::table('course_module_tracks')->insertGetId([
                        'course_module_id' => $module->id,
                        'name' => 'Aulas',
                        'slug' => 'aulas',
                        'sort_order' => 1,
                        'status' => 'published',
                        'panda_folder_id' => $module->panda_folder_id,
                        'metadata' => json_encode(['source' => 'legacy_module']),
                        'created_at' => $module->created_at,
                        'updated_at' => $module->updated_at,
                    ]);
                }

                DB::table('course_module_lessons')
                    ->where('course_module_id', $module->id)
                    ->orderBy('sort_order')
                    ->get()
                    ->each(function (object $pivot) use ($trackId): void {
                        DB::table('course_module_track_lessons')->insertOrIgnore([
                            'course_module_track_id' => $trackId,
                            'lesson_id' => $pivot->lesson_id,
                            'sort_order' => $pivot->sort_order ?? 0,
                            'created_at' => $pivot->created_at,
                            'updated_at' => $pivot->updated_at,
                        ]);

                        DB::table('lessons')
                            ->where('id', $pivot->lesson_id)
                            ->whereNull('course_module_track_id')
                            ->update(['course_module_track_id' => $trackId]);
                    });
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module_track_lessons');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_module_track_id');
            $table->dropColumn(['google_doc_url', 'source_status']);
        });

        Schema::dropIfExists('course_module_tracks');
    }
};
