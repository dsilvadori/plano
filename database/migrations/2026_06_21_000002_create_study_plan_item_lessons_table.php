<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_plan_item_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_plan_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['study_plan_item_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_item_lessons');
    }
};
