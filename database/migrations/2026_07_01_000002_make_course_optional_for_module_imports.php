<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->change();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->change();
        });

        Schema::table('panda_import_runs', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->change();
        });

        Schema::table('google_drive_import_runs', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable(false)->change();
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable(false)->change();
        });

        Schema::table('panda_import_runs', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable(false)->change();
        });

        Schema::table('google_drive_import_runs', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable(false)->change();
        });
    }
};
