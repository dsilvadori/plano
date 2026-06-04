<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->boolean('exam_date_confirmed')->default(false)->after('exam_date');
        });
    }

    public function down(): void
    {
        Schema::table('study_plans', function (Blueprint $table) {
            $table->dropColumn('exam_date_confirmed');
        });
    }
};
