<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->string('status')->default('open')->index();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lesson_id', 'status', 'created_at'], 'lesson_comments_lesson_status_idx');
            $table->index(['user_id', 'created_at'], 'lesson_comments_user_created_idx');
            $table->index(['course_id', 'created_at'], 'lesson_comments_course_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_comments');
    }
};
