<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_bank_course_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['question_bank_id', 'course_module_id'], 'qb_module_unique');
            $table->index(['course_module_id', 'question_bank_id'], 'qb_module_module_idx');
        });

        Schema::create('question_bank_course_module_track', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_track_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['question_bank_id', 'course_module_track_id'], 'qb_track_unique');
            $table->index(['course_module_track_id', 'question_bank_id'], 'qb_track_track_idx');
        });

        Schema::create('question_bank_lesson', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['question_bank_id', 'lesson_id'], 'qb_lesson_unique');
            $table->index(['lesson_id', 'question_bank_id'], 'qb_lesson_lesson_idx');
        });

        DB::table('questions')->update([
            'course_id' => null,
            'course_module_id' => null,
            'lesson_id' => null,
        ]);

        DB::table('question_banks')->update(['course_id' => null]);
        DB::table('question_import_batches')->update(['course_id' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_lesson');
        Schema::dropIfExists('question_bank_course_module_track');
        Schema::dropIfExists('question_bank_course_module');
    }
};
