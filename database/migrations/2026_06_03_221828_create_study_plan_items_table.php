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
        Schema::create('study_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_id')->nullable()->constrained()->nullOnDelete();
            $table->date('scheduled_date');
            $table->unsignedInteger('week_number');
            $table->string('day_of_week');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('other');
            $table->unsignedInteger('estimated_minutes');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('study_plan_items');
    }
};
