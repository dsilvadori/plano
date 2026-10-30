<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->string('exam_board')->nullable()->after('title')->index();
            $table->unsignedSmallInteger('exam_year')->nullable()->after('exam_board')->index();
            $table->string('exam_name')->nullable()->after('exam_year')->index();
        });
    }

    public function down(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->dropColumn(['exam_board', 'exam_year', 'exam_name']);
        });
    }
};
