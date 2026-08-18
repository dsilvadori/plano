<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->string('teacher_name')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->dropColumn('teacher_name');
        });
    }
};
