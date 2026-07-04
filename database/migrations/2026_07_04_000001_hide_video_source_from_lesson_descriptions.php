<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lessons')
            ->whereIn('description', [
                'Aula criada a partir do Google Drive.',
                'Aula importada pelo Drive.',
            ])
            ->update(['description' => 'Aula adicionada à plataforma.']);
    }

    public function down(): void
    {
        DB::table('lessons')
            ->where('description', 'Aula adicionada à plataforma.')
            ->whereJsonContains('metadata->source', 'google_drive')
            ->update(['description' => 'Aula criada a partir do Google Drive.']);
    }
};
