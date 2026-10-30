<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $constraint = $this->foreignKeyName('study_tracks', 'course_id');

        if ($constraint) {
            DB::statement("ALTER TABLE `study_tracks` DROP FOREIGN KEY `{$constraint}`");
        }

        DB::statement('ALTER TABLE `study_tracks` MODIFY `course_id` BIGINT UNSIGNED NULL');
        DB::statement(
            'ALTER TABLE `study_tracks` ADD CONSTRAINT `study_tracks_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $constraint = $this->foreignKeyName('study_tracks', 'course_id');

        if ($constraint) {
            DB::statement("ALTER TABLE `study_tracks` DROP FOREIGN KEY `{$constraint}`");
        }

        DB::statement('ALTER TABLE `study_tracks` MODIFY `course_id` BIGINT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE `study_tracks` ADD CONSTRAINT `study_tracks_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE'
        );
    }

    private function foreignKeyName(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [DB::getDatabaseName(), $table, $column],
        );

        return $row?->CONSTRAINT_NAME;
    }
};
