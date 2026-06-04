<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestStudentSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::updateOrCreate(
            ['email' => 'aluno@teste.com'],
            [
                'name' => 'Aluno Teste',
                'password' => Hash::make('password'),
                'role' => 'student',
                'email_verified_at' => now(),
            ],
        );

        $courses = Course::query()->pluck('id')->all();

        $student->courses()->sync(
            collect($courses)->mapWithKeys(fn (int $courseId) => [
                $courseId => ['source' => 'manual'],
            ])->all()
        );
    }
}
