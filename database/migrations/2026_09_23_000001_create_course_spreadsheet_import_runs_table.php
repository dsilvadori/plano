<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_spreadsheet_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('course_name')->nullable();
            $table->string('file_name')->nullable();
            $table->string('stored_path', 2048)->nullable();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('total_modules')->default(0);
            $table->unsignedInteger('processed_modules')->default(0);
            $table->unsignedInteger('total_tracks')->default(0);
            $table->unsignedInteger('total_lessons')->default(0);
            $table->unsignedInteger('total_minutes')->default(0);
            $table->json('summary')->nullable();
            $table->string('latest_message')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_spreadsheet_import_runs');
    }
};
