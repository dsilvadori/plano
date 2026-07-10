<?php

use App\Services\QuestionBankAutoLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('question_banks')
            || ! Schema::hasTable('question_bank_course_module')
            || ! Schema::hasTable('question_bank_course_module_track')
            || ! Schema::hasTable('question_bank_lesson')
        ) {
            return;
        }

        app(QuestionBankAutoLinker::class)->linkAll();
    }

    public function down(): void
    {
        //
    }
};
