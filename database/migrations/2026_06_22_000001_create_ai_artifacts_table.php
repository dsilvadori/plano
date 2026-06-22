<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->string('artifact_type')->index();
            $table->string('provider')->default('panda')->index();
            $table->string('status')->default('ready')->index();
            $table->json('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'artifact_type', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_artifacts');
    }
};
