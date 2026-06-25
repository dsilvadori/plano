<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('source_type')->default('pdf')->index();
            $table->string('source_file_path')->nullable();
            $table->string('status')->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('question_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type')->default('pdf')->index();
            $table->string('file_path')->nullable();
            $table->string('status')->default('uploaded')->index();
            $table->unsignedInteger('questions_found')->default(0);
            $table->unsignedInteger('questions_imported')->default(0);
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_module_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('number')->nullable();
            $table->string('subject')->nullable()->index();
            $table->string('topic')->nullable()->index();
            $table->string('subtopic')->nullable()->index();
            $table->longText('statement');
            $table->string('type')->default('multiple_choice')->index();
            $table->string('answer_key')->nullable();
            $table->longText('commentary')->nullable();
            $table->string('commentary_provider')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('difficulty')->nullable()->index();
            $table->string('status')->default('review')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['question_bank_id', 'number']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 5);
            $table->longText('text');
            $table->boolean('is_correct')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['question_id', 'label']);
        });

        Schema::create('question_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('answer_label', 5)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_import_batches');
        Schema::dropIfExists('question_banks');
    }
};
