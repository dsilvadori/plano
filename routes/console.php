<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Course;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('courses:expand-santos-combo', function () {
    $combo = Course::query()
        ->where('name', 'Gabaritando Prefeitura de Santos')
        ->orWhere('slug', 'gabaritando-prefeitura-de-santos')
        ->first();

    if (! $combo) {
        $this->warn('Curso genérico "Gabaritando Prefeitura de Santos" não encontrado.');

        return 1;
    }

    $comboCourses = Course::query()
        ->where('is_active', true)
        ->where(function ($query): void {
            $query
                ->where('name', 'like', '%Santos%')
                ->orWhere('slug', 'like', '%santos%');
        })
        ->whereKeyNot($combo->id)
        ->get();

    if ($comboCourses->isEmpty()) {
        $this->warn('Nenhum curso ativo de Santos encontrado para vincular.');

        return 1;
    }

    $students = $combo->students()->where('role', 'student')->get();
    $courseLinks = $comboCourses
        ->mapWithKeys(fn (Course $course): array => [
            $course->id => [
                'source' => 'tutory',
                'external_purchase_id' => null,
            ],
        ])
        ->all();

    foreach ($students as $student) {
        $student->courses()->syncWithoutDetaching($courseLinks);
    }

    $this->info("{$students->count()} aluno(s) atualizado(s) com {$comboCourses->count()} curso(s) do combo de Santos.");

    return 0;
})->purpose('Vincula alunos do combo Gabaritando Prefeitura de Santos aos cursos ativos de Santos');
