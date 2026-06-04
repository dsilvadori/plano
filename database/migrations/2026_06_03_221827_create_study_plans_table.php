<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('study_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('exam_date');
            $table->date('start_date');
            $table->json('available_days');
            $table->json('available_minutes_by_day');
            $table->unsignedInteger('total_available_minutes')->default(0);
            $table->unsignedInteger('total_required_minutes')->default(0);
            $table->string('intensity')->default('balanced');
            $table->string('status')->default('active');
            $table->string('viability_status')->default('good');
            $table->text('viability_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_plans');
    }
};
