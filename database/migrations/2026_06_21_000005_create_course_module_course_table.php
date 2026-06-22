<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_module_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'course_module_id']);
        });

        DB::table('course_modules')
            ->select(['id', 'course_id', 'sort_order', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $module): void {
                DB::table('course_module_course')->insertOrIgnore([
                    'course_id' => $module->course_id,
                    'course_module_id' => $module->id,
                    'sort_order' => $module->sort_order ?? 0,
                    'created_at' => $module->created_at,
                    'updated_at' => $module->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_module_course');
    }
};
