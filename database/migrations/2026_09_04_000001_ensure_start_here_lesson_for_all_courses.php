<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courses')
            || ! Schema::hasTable('course_modules')
            || ! Schema::hasTable('course_module_tracks')
            || ! Schema::hasTable('lessons')
            || ! Schema::hasTable('course_module_course')
            || ! Schema::hasTable('course_module_lessons')
            || ! Schema::hasTable('course_module_track_course')
            || ! Schema::hasTable('course_module_track_lessons')
            || ! Schema::hasTable('study_tracks')
            || ! Schema::hasTable('study_track_modules')) {
            return;
        }

        $courseIds = DB::table('courses')
            ->orderBy('id')
            ->pluck('id');

        if ($courseIds->isEmpty()) {
            return;
        }

        $now = now();

        $moduleId = DB::table('course_modules')
            ->where('name', 'Comece por aqui')
            ->value('id');

        if (! $moduleId) {
            $moduleId = DB::table('course_modules')->insertGetId([
                'course_id' => null,
                'name' => 'Comece por aqui',
                'description' => 'Orientações iniciais do curso, com instruções em vídeo, texto e links úteis para os alunos.',
                'type' => 'complementary',
                'lessons' => json_encode([]),
                'workload_minutes' => 0,
                'sort_order' => 0,
                'panda_folder_id' => null,
                'metadata' => json_encode(['source' => 'system_start_here']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $trackId = DB::table('course_module_tracks')
            ->where('course_module_id', $moduleId)
            ->where('slug', 'instrucoes')
            ->value('id');

        if (! $trackId) {
            $trackId = DB::table('course_module_tracks')->insertGetId([
                'course_module_id' => $moduleId,
                'name' => 'Instruções',
                'slug' => 'instrucoes',
                'description' => 'Aulas e materiais de orientação para começar o curso.',
                'thumbnail_url' => null,
                'thumbnail_path' => null,
                'sort_order' => 0,
                'status' => 'published',
                'panda_folder_id' => null,
                'google_doc_url' => null,
                'metadata' => json_encode(['source' => 'system_start_here']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $lessonId = DB::table('lessons')
            ->where('title', 'Comece por aqui')
            ->where('slug', 'comece-por-aqui')
            ->whereNull('course_module_id')
            ->value('id');

        if (! $lessonId) {
            $lessonId = DB::table('lessons')->insertGetId([
                'course_id' => null,
                'course_module_id' => null,
                'course_module_track_id' => null,
                'title' => 'Comece por aqui',
                'slug' => 'comece-por-aqui',
                'description' => 'Orientações iniciais para começar o curso.',
                'type' => 'video',
                'thumbnail_url' => null,
                'duration_seconds' => 0,
                'sort_order' => 0,
                'status' => 'published',
                'panda_video_id' => null,
                'panda_embed_url' => null,
                'panda_player_url' => null,
                'panda_status' => null,
                'google_doc_url' => null,
                'source_status' => 'awaiting_media',
                'metadata' => json_encode(['source' => 'system_start_here']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('course_module_lessons')->insertOrIgnore([
            'course_module_id' => $moduleId,
            'lesson_id' => $lessonId,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('course_module_track_lessons')->insertOrIgnore([
            'course_module_track_id' => $trackId,
            'lesson_id' => $lessonId,
            'sort_order' => 0,
            'status_override' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($courseIds as $courseId) {
            DB::table('course_module_course')->insertOrIgnore([
                'course_id' => $courseId,
                'course_module_id' => $moduleId,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('course_module_track_course')->insertOrIgnore([
                'course_id' => $courseId,
                'course_module_track_id' => $trackId,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('study_tracks')
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $studyTrack) use ($moduleId, $now): void {
                DB::table('study_track_modules')->insertOrIgnore([
                    'study_track_id' => $studyTrack->id,
                    'course_module_id' => $moduleId,
                    'weight' => 1,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        //
    }
};
