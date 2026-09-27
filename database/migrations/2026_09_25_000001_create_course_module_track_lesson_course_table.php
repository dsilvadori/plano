<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_module_track_lesson_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('published')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'course_module_track_id', 'lesson_id'], 'cmtlc_course_track_lesson_unique');
            $table->index(['course_module_track_id', 'course_id', 'sort_order'], 'cmtlc_track_course_sort_idx');
            $table->index(['lesson_id', 'course_id'], 'cmtlc_lesson_course_idx');
        });

        DB::table('course_module_track_course')
            ->join('course_module_track_lessons', 'course_module_track_lessons.course_module_track_id', '=', 'course_module_track_course.course_module_track_id')
            ->select([
                'course_module_track_course.course_id',
                'course_module_track_lessons.course_module_track_id',
                'course_module_track_lessons.lesson_id',
                'course_module_track_lessons.sort_order',
                'course_module_track_lessons.status_override',
                'course_module_track_lessons.created_at',
                'course_module_track_lessons.updated_at',
            ])
            ->orderBy('course_module_track_course.course_id')
            ->orderBy('course_module_track_lessons.course_module_track_id')
            ->orderBy('course_module_track_lessons.sort_order')
            ->get()
            ->each(function (object $row): void {
                DB::table('course_module_track_lesson_course')->insertOrIgnore([
                    'course_id' => $row->course_id,
                    'course_module_track_id' => $row->course_module_track_id,
                    'lesson_id' => $row->lesson_id,
                    'sort_order' => $row->sort_order ?? 0,
                    'status' => $row->status_override ?: 'published',
                    'metadata' => json_encode(['source' => 'backfill_from_track_lessons']),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module_track_lesson_course');
    }
};
