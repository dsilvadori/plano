<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_module_track_course')) {
            Schema::create('course_module_track_course', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_module_track_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['course_id', 'course_module_track_id'], 'cmtc_course_track_unique');
                $table->index(['course_module_track_id', 'course_id'], 'cmtc_track_course_index');
            });
        }

        DB::table('course_module_course')
            ->join('course_module_tracks', 'course_module_tracks.course_module_id', '=', 'course_module_course.course_module_id')
            ->select([
                'course_module_course.course_id',
                'course_module_tracks.id as course_module_track_id',
                'course_module_tracks.sort_order',
                'course_module_tracks.created_at',
                'course_module_tracks.updated_at',
            ])
            ->orderBy('course_module_course.course_id')
            ->orderBy('course_module_tracks.sort_order')
            ->get()
            ->each(function (object $row): void {
                DB::table('course_module_track_course')->insertOrIgnore([
                    'course_id' => $row->course_id,
                    'course_module_track_id' => $row->course_module_track_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module_track_course');
    }
};
