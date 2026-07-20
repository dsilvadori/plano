<?php

use App\Models\Course;
use App\Services\CourseAccessResolver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('courses:expand-combo {comboName}', function (string $comboName) {
    $comboCourses = app(CourseAccessResolver::class)->coursesForCombo($comboName);

    if ($comboCourses->isEmpty()) {
        $this->warn("Nenhum curso ativo encontrado para o combo {$comboName}.");

        return 1;
    }

    $comboPlaceholders = Course::query()
        ->where('name', $comboName)
        ->orWhere('tutory_product_id', $comboName)
        ->get();

    if ($comboPlaceholders->isEmpty()) {
        $this->warn("Nenhum curso placeholder encontrado com nome ou ID {$comboName}.");

        return 1;
    }

    $students = $comboPlaceholders
        ->flatMap(fn (Course $course) => $course->students()->where('role', 'student')->get())
        ->unique('id')
        ->values();

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

    $this->info("{$students->count()} aluno(s) atualizado(s) com {$comboCourses->count()} curso(s) do combo {$comboName}.");

    return 0;
})->purpose('Vincula alunos de um curso placeholder aos cursos ativos de um combo');

Artisan::command('courses:expand-santos-combo', function () {
    return Artisan::call('courses:expand-combo', [
        'comboName' => 'Gabaritando Prefeitura de Santos',
    ]);
})->purpose('Vincula alunos do combo Gabaritando Prefeitura de Santos aos cursos ativos marcados com esse combo');

Artisan::command('courses:link-santos-nivel-medio {--dry-run : Mostra o que seria vinculado sem gravar no banco} {--include-inactive : Inclui cursos inativos que tenham o prefixo}', function () {
    $comboName = 'GABARITANDO SANTOS - COMBO NÍVEL MÉDIO';
    $coursePrefix = 'Gabaritando Santos';

    $comboPlaceholders = Course::query()
        ->where(function ($query) use ($comboName) {
            $query
                ->where('name', $comboName)
                ->orWhere('tutory_product_id', $comboName);
        })
        ->get();

    if ($comboPlaceholders->isEmpty()) {
        $this->warn("Nenhum curso placeholder encontrado com nome ou ID {$comboName}.");

        return 1;
    }

    $students = $comboPlaceholders
        ->flatMap(fn (Course $course) => $course->students()->where('role', 'student')->get())
        ->unique('id')
        ->values();

    if ($students->isEmpty()) {
        $this->warn("Nenhum aluno encontrado no combo {$comboName}.");

        return 1;
    }

    $targetCoursesQuery = Course::query()
        ->whereRaw('LOWER(name) LIKE ?', [Str::lower($coursePrefix).'%'])
        ->whereNotIn('id', $comboPlaceholders->pluck('id'))
        ->orderBy('name');

    if (! $this->option('include-inactive')) {
        $targetCoursesQuery->where('is_active', true);
    }

    $targetCourses = $targetCoursesQuery->get();

    if ($targetCourses->isEmpty()) {
        $activeLabel = $this->option('include-inactive') ? '' : 'ativo ';

        $this->warn("Nenhum curso {$activeLabel}encontrado com prefixo {$coursePrefix}.");

        return 1;
    }

    $courseLinks = $targetCourses
        ->mapWithKeys(fn (Course $course): array => [
            $course->id => [
                'source' => 'tutory',
                'external_purchase_id' => null,
            ],
        ])
        ->all();
    $targetCourseIds = $targetCourses->pluck('id');

    $newLinks = 0;

    foreach ($students as $student) {
        $existingCourseIds = $student->courses()
            ->whereIn('courses.id', $targetCourseIds)
            ->pluck('courses.id');

        $newLinks += $targetCourseIds->diff($existingCourseIds)->count();

        if (! $this->option('dry-run')) {
            $student->courses()->syncWithoutDetaching($courseLinks);
        }
    }

    $mode = $this->option('dry-run') ? 'simulados' : 'criados';

    $this->info("{$students->count()} aluno(s) do combo {$comboName}.");
    $activeLabel = $this->option('include-inactive') ? '' : 'ativo(s) ';

    $this->info("{$targetCourses->count()} curso(s) {$activeLabel}com prefixo {$coursePrefix}.");
    $this->info("{$newLinks} vínculo(s) {$mode}.");

    return 0;
})->purpose('Vincula alunos do combo Gabaritando Santos nível médio aos cursos ativos com prefixo Gabaritando Santos');
