<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('course_module_tracks', 'available_from')) {
            return;
        }

        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->date('available_from')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('course_module_tracks', 'available_from')) {
            return;
        }

        Schema::table('course_module_tracks', function (Blueprint $table) {
            $table->dropColumn('available_from');
        });
    }
};
