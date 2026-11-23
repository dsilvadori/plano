<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_courses', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('external_purchase_id')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('access_expires_at')->nullable()->after('role');
        });

        // Acessos existentes: 1 ano a partir do vínculo (ou do cadastro do usuário, se o vínculo não tiver data).
        // O aluno de teste interno continua sem limite.
        DB::table('student_courses')
            ->join('users', 'users.id', '=', 'student_courses.user_id')
            ->where('users.email', '!=', 'aluno@teste.com')
            ->whereNull('student_courses.expires_at')
            ->select(['student_courses.id', 'student_courses.created_at', 'users.created_at as user_created_at'])
            ->orderBy('student_courses.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('student_courses')
                        ->where('id', $row->id)
                        ->update(['expires_at' => $this->oneYearFrom($row->created_at ?? $row->user_created_at)]);
                }
            });

        DB::table('users')
            ->where('role', 'subscriber')
            ->whereNull('access_expires_at')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['access_expires_at' => $this->oneYearFrom($row->created_at)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('student_courses', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('access_expires_at');
        });
    }

    protected function oneYearFrom(?string $date): string
    {
        return ($date ? Carbon::parse($date) : now())->addYear()->toDateTimeString();
    }
};
