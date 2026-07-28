<?php

use App\Models\Course;
use App\Models\StudyPlan;
use App\Services\CourseAccessResolver;
use App\Services\StudyPlanGenerator;
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

Artisan::command('study-plans:refresh-active {--course-id=* : Limita a correção a um ou mais IDs de curso} {--dry-run : Mostra os planos que seriam corrigidos sem gravar}', function (StudyPlanGenerator $generator) {
    $courseIds = collect($this->option('course-id'))
        ->filter(fn ($value) => filled($value))
        ->map(fn ($value) => (int) $value)
        ->filter()
        ->unique()
        ->values();

    $plans = StudyPlan::query()
        ->with(['course', 'studyTrack', 'user'])
        ->where('status', 'active')
        ->when($courseIds->isNotEmpty(), fn ($query) => $query->whereIn('course_id', $courseIds))
        ->orderBy('id')
        ->get();

    if ($plans->isEmpty()) {
        $this->warn('Nenhum plano ativo encontrado para corrigir.');

        return 0;
    }

    $refreshed = 0;

    foreach ($plans as $plan) {
        $beforeRequired = (int) $plan->total_required_minutes;
        $beforePlanned = (int) $plan->items()->sum('estimated_minutes');
        $track = $plan->studyTrack ?: $plan->course
            ->studyTracks()
            ->where('is_active', true)
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->first();

        if ($this->option('dry-run')) {
            $requiredMinutes = (int) ($track
                ? $track->modules()->where('course_modules.is_active', true)->sum('workload_minutes')
                : $plan->course->modules()->where('is_active', true)->sum('workload_minutes'));

            $this->line("Plano {$plan->id}: {$beforeRequired} min -> {$requiredMinutes} min ({$plan->course->name})");

            continue;
        }

        $refreshedPlan = $generator->regenerate(
            $plan,
            $plan->course,
            $track,
            $plan->exam_date_confirmed ? $plan->exam_date?->toDateString() : null,
            $plan->start_date?->toDateString() ?? now()->toDateString(),
            $plan->available_days ?? [],
            $plan->available_minutes_by_day ?? [],
            $plan->intensity ?: 'balanced',
        );

        $afterRequired = (int) $refreshedPlan->total_required_minutes;
        $afterPlanned = (int) $refreshedPlan->items()->sum('estimated_minutes');

        $this->line("Plano {$plan->id}: necessário {$beforeRequired} -> {$afterRequired} min; planejado {$beforePlanned} -> {$afterPlanned} min.");
        $refreshed++;
    }

    if ($this->option('dry-run')) {
        $this->info("{$plans->count()} plano(s) ativo(s) avaliados em modo simulação.");

        return 0;
    }

    $this->info("{$refreshed} plano(s) ativo(s) corrigido(s).");

    return 0;
})->purpose('Regenera planos ativos com a estrutura atual dos cursos, preservando progresso concluído');

Artisan::command('courses:deactivate-stale-official-modules {--course-id=* : Limita a correção a um ou mais IDs de curso} {--dry-run : Mostra os módulos que seriam desativados sem gravar}', function () {
    $courseIds = collect($this->option('course-id'))
        ->filter(fn ($value) => filled($value))
        ->map(fn ($value) => (int) $value)
        ->filter()
        ->unique()
        ->values();

    $courses = Course::query()
        ->with(['studyTracks' => fn ($query) => $query
            ->where('is_active', true)
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')])
        ->when($courseIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $courseIds))
        ->orderBy('id')
        ->get();

    if ($courses->isEmpty()) {
        $this->warn('Nenhum curso encontrado.');

        return 0;
    }

    $totalStaleModules = 0;

    foreach ($courses as $course) {
        $officialTrack = $course->studyTracks->first();

        if (! $officialTrack) {
            continue;
        }

        $officialModuleIds = $officialTrack->modules()->pluck('course_modules.id');
        $staleModules = $course->modules()
            ->where('is_active', true)
            ->whereNotIn('id', $officialModuleIds)
            ->get();

        if ($staleModules->isEmpty()) {
            $this->line("Curso {$course->id}: nenhum módulo ativo fora da Trilha Oficial.");

            continue;
        }

        $totalStaleModules += $staleModules->count();
        $this->line("Curso {$course->id} ({$course->name}): {$staleModules->count()} módulo(s) ativo(s) fora da Trilha Oficial.");

        foreach ($staleModules as $module) {
            $this->line(" - {$module->id}: {$module->name} ({$module->workload_minutes} min)");
        }

        if (! $this->option('dry-run')) {
            $course->modules()
                ->whereKey($staleModules->pluck('id'))
                ->update(['is_active' => false]);
        }
    }

    if ($this->option('dry-run')) {
        $this->info("{$totalStaleModules} módulo(s) seriam desativados.");

        return 0;
    }

    $this->info("{$totalStaleModules} módulo(s) desativados.");

    return 0;
})->purpose('Desativa módulos ativos fora da Trilha Oficial para remover sobras de importações antigas');
