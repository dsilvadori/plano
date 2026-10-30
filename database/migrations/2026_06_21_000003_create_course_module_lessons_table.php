<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_module_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['course_module_id', 'lesson_id']);
            $table->index(['lesson_id', 'course_module_id']);
        });

        DB::table('lessons')
            ->select(['id', 'course_module_id', 'sort_order', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $lesson): void {
                DB::table('course_module_lessons')->insertOrIgnore([
                    'course_module_id' => $lesson->course_module_id,
                    'lesson_id' => $lesson->id,
                    'sort_order' => $lesson->sort_order ?? 0,
                    'created_at' => $lesson->created_at,
                    'updated_at' => $lesson->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module_lessons');
    }
};
