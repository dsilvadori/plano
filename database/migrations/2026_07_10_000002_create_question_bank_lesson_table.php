<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('question_bank_lesson')) {
            Schema::create('question_bank_lesson', function (Blueprint $table) {
                $table->id();
                $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
                $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['question_bank_id', 'lesson_id'], 'qb_lesson_unique');
                $table->index(['lesson_id', 'question_bank_id'], 'qb_lesson_lesson_idx');
            });
        }

        DB::table('questions')
            ->select(['question_bank_id', 'lesson_id'])
            ->whereNotNull('lesson_id')
            ->distinct()
            ->orderBy('question_bank_id')
            ->get()
            ->each(function (object $row): void {
                DB::table('question_bank_lesson')->insertOrIgnore([
                    'question_bank_id' => $row->question_bank_id,
                    'lesson_id' => $row->lesson_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_bank_lesson');
    }
};
