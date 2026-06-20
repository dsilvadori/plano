<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->text('short_description')->nullable()->after('description');
            $table->string('thumbnail_url')->nullable()->after('short_description');
            $table->string('checkout_url')->nullable()->after('thumbnail_url');
            $table->foreignId('sphere_id')->nullable()->after('checkout_url')->constrained('course_spheres')->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()->after('sphere_id')->constrained('education_levels')->nullOnDelete();
            $table->string('status')->default('published')->after('exam_date')->index();
            $table->boolean('is_featured')->default(false)->after('is_active')->index();
            $table->unsignedInteger('sort_order')->default(0)->after('is_featured')->index();
            $table->json('metadata')->nullable()->after('sort_order');
        });

        Schema::table('course_modules', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('panda_folder_id')->nullable()->after('sort_order')->index();
            $table->json('metadata')->nullable()->after('panda_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('course_modules', function (Blueprint $table) {
            $table->dropColumn(['description', 'panda_folder_id', 'metadata']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['sphere_id']);
            $table->dropForeign(['education_level_id']);
            $table->dropColumn([
                'short_description',
                'thumbnail_url',
                'checkout_url',
                'sphere_id',
                'education_level_id',
                'status',
                'is_featured',
                'sort_order',
                'metadata',
            ]);
        });
    }
};
