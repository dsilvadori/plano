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
            || ! Schema::hasTable('course_module_course')
            || ! Schema::hasTable('course_module_track_course')) {
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

        DB::table('courses')
            ->select(['id'])
            ->orderBy('id')
            ->get()
            ->each(function (object $course) use ($moduleId, $trackId, $now): void {
                DB::table('course_module_course')->insertOrIgnore([
                    'course_id' => $course->id,
                    'course_module_id' => $moduleId,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('course_module_track_course')->insertOrIgnore([
                    'course_id' => $course->id,
                    'course_module_track_id' => $trackId,
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
