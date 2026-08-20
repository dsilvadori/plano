<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('study_plans')) {
            Schema::table('study_plans', function (Blueprint $table): void {
                $table->index(['user_id', 'course_id', 'status', 'id'], 'sp_user_course_status_idx');
            });
        }

        if (Schema::hasTable('study_plan_items')) {
            Schema::table('study_plan_items', function (Blueprint $table): void {
                $table->index(['study_plan_id', 'scheduled_date', 'completed_at', 'sort_order'], 'spi_plan_schedule_status_idx');
            });
        }

        if (Schema::hasTable('study_plan_item_lessons')) {
            Schema::table('study_plan_item_lessons', function (Blueprint $table): void {
                $table->index(['lesson_id', 'study_plan_item_id'], 'spil_lesson_item_idx');
            });
        }

        if (Schema::hasTable('course_module_course')) {
            Schema::table('course_module_course', function (Blueprint $table): void {
                $table->index(['course_module_id', 'course_id', 'sort_order'], 'cmc_module_course_sort_idx');
            });
        }

        if (Schema::hasTable('course_module_track_lessons')) {
            Schema::table('course_module_track_lessons', function (Blueprint $table): void {
                $table->index(['course_module_track_id', 'sort_order', 'lesson_id'], 'cmtl_track_sort_lesson_idx');
            });
        }

        if (Schema::hasTable('lesson_progress')) {
            Schema::table('lesson_progress', function (Blueprint $table): void {
                $table->index(['user_id', 'course_id', 'lesson_id', 'status'], 'lp_user_course_lesson_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('lesson_progress')) {
            Schema::table('lesson_progress', function (Blueprint $table): void {
                $table->dropIndex('lp_user_course_lesson_status_idx');
            });
        }

        if (Schema::hasTable('course_module_track_lessons')) {
            Schema::table('course_module_track_lessons', function (Blueprint $table): void {
                $table->dropIndex('cmtl_track_sort_lesson_idx');
            });
        }

        if (Schema::hasTable('course_module_course')) {
            Schema::table('course_module_course', function (Blueprint $table): void {
                $table->dropIndex('cmc_module_course_sort_idx');
            });
        }

        if (Schema::hasTable('study_plan_item_lessons')) {
            Schema::table('study_plan_item_lessons', function (Blueprint $table): void {
                $table->dropIndex('spil_lesson_item_idx');
            });
        }

        if (Schema::hasTable('study_plan_items')) {
            Schema::table('study_plan_items', function (Blueprint $table): void {
                $table->dropIndex('spi_plan_schedule_status_idx');
            });
        }

        if (Schema::hasTable('study_plans')) {
            Schema::table('study_plans', function (Blueprint $table): void {
                $table->dropIndex('sp_user_course_status_idx');
            });
        }
    }
};
