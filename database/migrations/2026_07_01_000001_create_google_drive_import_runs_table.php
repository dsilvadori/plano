<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_drive_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->string('folder_url', 2048);
            $table->string('folder_id')->nullable()->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('total_tracks')->default(0);
            $table->unsignedInteger('processed_tracks')->default(0);
            $table->unsignedInteger('total_lessons')->default(0);
            $table->unsignedInteger('processed_lessons')->default(0);
            $table->unsignedInteger('panda_folders')->default(0);
            $table->unsignedInteger('panda_videos_uploaded')->default(0);
            $table->unsignedInteger('panda_videos_skipped')->default(0);
            $table->unsignedInteger('panda_videos_failed')->default(0);
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
        Schema::dropIfExists('google_drive_import_runs');
    }
};
