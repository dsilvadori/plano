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

        $constraint = $this->foreignKeyName('lessons', 'course_module_id');

        if ($constraint) {
            DB::statement("ALTER TABLE `lessons` DROP FOREIGN KEY `{$constraint}`");
        }

        DB::statement(
            'ALTER TABLE `lessons` ADD CONSTRAINT `lessons_course_module_id_foreign` FOREIGN KEY (`course_module_id`) REFERENCES `course_modules` (`id`) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $constraint = $this->foreignKeyName('lessons', 'course_module_id');

        if ($constraint) {
            DB::statement("ALTER TABLE `lessons` DROP FOREIGN KEY `{$constraint}`");
        }

        DB::statement(
            'ALTER TABLE `lessons` ADD CONSTRAINT `lessons_course_module_id_foreign` FOREIGN KEY (`course_module_id`) REFERENCES `course_modules` (`id`) ON DELETE CASCADE'
        );
    }

    private function foreignKeyName(string $table, string $column): ?string
    {
        $database = DB::getDatabaseName();

        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column],
        );

        return $row?->CONSTRAINT_NAME;
    }
};
