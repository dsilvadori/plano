<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('teacher_name')
                ->constrained('teachers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
        });

        Schema::dropIfExists('teachers');
    }
};
