<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panda_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('panda_folder_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('panda_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panda_import_run_id')->constrained()->cascadeOnDelete();
            $table->string('external_type')->index();
            $table->string('external_id')->index();
            $table->string('local_type')->nullable();
            $table->unsignedBigInteger('local_id')->nullable();
            $table->string('status')->default('created')->index();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panda_import_items');
        Schema::dropIfExists('panda_import_runs');
    }
};
