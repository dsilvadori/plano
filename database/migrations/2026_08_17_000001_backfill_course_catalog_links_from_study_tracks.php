<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('study_tracks')
            || ! Schema::hasTable('study_track_modules')
            || ! Schema::hasTable('course_module_course')) {
            return;
        }

        DB::table('study_track_modules')
            ->join('study_tracks', 'study_tracks.id', '=', 'study_track_modules.study_track_id')
            ->whereNotNull('study_tracks.course_id')
            ->select([
                'study_tracks.course_id',
                'study_track_modules.course_module_id',
                'study_track_modules.sort_order',
            ])
            ->orderBy('study_tracks.course_id')
            ->orderBy('study_track_modules.sort_order')
            ->get()
            ->each(function (object $row): void {
                DB::table('course_module_course')->insertOrIgnore([
                    'course_id' => $row->course_id,
                    'course_module_id' => $row->course_module_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        if (! Schema::hasTable('course_module_tracks')
            || ! Schema::hasTable('course_module_track_course')) {
            return;
        }

        DB::table('course_module_course')
            ->join('course_module_tracks', 'course_module_tracks.course_module_id', '=', 'course_module_course.course_module_id')
            ->select([
                'course_module_course.course_id',
                'course_module_tracks.id as course_module_track_id',
                'course_module_tracks.sort_order',
            ])
            ->orderBy('course_module_course.course_id')
            ->orderBy('course_module_tracks.sort_order')
            ->get()
            ->each(function (object $row): void {
                DB::table('course_module_track_course')->insertOrIgnore([
                    'course_id' => $row->course_id,
                    'course_module_track_id' => $row->course_module_track_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        //
    }
};
