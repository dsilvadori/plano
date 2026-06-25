<?php

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\QuestionBank;
use App\Services\CourseAccessResolver;
use App\Services\QuestionLessonLinker;
use App\Services\QuestionPdfImporter;
use App\Support\LessonTitleNormalizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

Artisan::command('lessons:normalize-titles {--dry-run}', function () {
    $changed = 0;

    $normalize = function () use (&$changed): void {
        CourseModule::query()
            ->with('onlineLessons')
            ->orderBy('id')
            ->get()
            ->each(function (CourseModule $module) use (&$changed): void {
                $planningLessons = [];

                foreach ($module->onlineLessons as $index => $lesson) {
                    $position = $index + 1;
                    $title = LessonTitleNormalizer::normalize($lesson->title, $position);

                    if ($lesson->title !== $title || (int) $lesson->sort_order !== $position) {
                        $changed++;
                    }

                    $lesson->forceFill([
                        'title' => $title,
                        'sort_order' => $position,
                    ])->save();

                    $lesson->modules()->updateExistingPivot($module->id, [
                        'sort_order' => $position,
                    ]);

                    $planningLessons[] = [
                        'name' => $title,
                        'minutes' => max(1, (int) ceil(((int) $lesson->duration_seconds) / 60)),
                    ];
                }

                if ($planningLessons !== []) {
                    $module->forceFill(['lessons' => $planningLessons])->save();
                }
            });
    };

    if ($this->option('dry-run')) {
        DB::beginTransaction();
        $normalize();
        DB::rollBack();
        $this->info("{$changed} aula(s) seriam normalizada(s).");

        return 0;
    }

    DB::transaction($normalize);

    $this->info("{$changed} aula(s) normalizada(s).");

    return 0;
})->purpose('Normaliza nomes e numeração das aulas importadas');

Artisan::command('questions:import-pdf {path} {--course_id=} {--title=} {--answer-key=}', function (QuestionPdfImporter $importer) {
    $path = (string) $this->argument('path');

    if (! is_file($path)) {
        $this->error('PDF não encontrado: '.$path);

        return 1;
    }

    $storedPath = 'question-banks/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    $bank = QuestionBank::query()->create([
        'course_id' => $this->option('course_id') ? (int) $this->option('course_id') : null,
        'title' => (string) ($this->option('title') ?: pathinfo($path, PATHINFO_FILENAME)),
        'source_type' => 'pdf',
        'source_file_path' => $storedPath,
        'status' => 'draft',
    ]);

    $batch = $importer->import($bank);
    $answerKey = (string) ($this->option('answer-key') ?: '');

    if ($answerKey !== '') {
        $updated = $importer->applyAnswerKey($bank->fresh(), $answerKey);
        $this->info("Gabaritos aplicados: {$updated}");
    }

    $this->info("Banco criado: {$bank->fresh()->title} (#{$bank->id})");
    $this->info("Questões importadas: {$batch->questions_imported}");

    return 0;
})->purpose('Importa um PDF para um novo banco de questões');

Artisan::command('questions:apply-answer-key {bank_id} {answer_key}', function (QuestionPdfImporter $importer) {
    $bank = QuestionBank::query()->findOrFail((int) $this->argument('bank_id'));
    $updated = $importer->applyAnswerKey($bank, (string) $this->argument('answer_key'));

    $this->info("Gabaritos aplicados: {$updated}");

    return 0;
})->purpose('Aplica gabarito em um banco de questões. Ex.: "1:C,2:A,3:D"');

Artisan::command('questions:link-to-lessons {bank_id?} {--course_id=}', function (QuestionLessonLinker $linker) {
    $query = QuestionBank::query()->whereNotNull('course_id');

    if ($this->argument('bank_id')) {
        $query = QuestionBank::query()->whereKey((int) $this->argument('bank_id'));
    }

    $total = 0;

    $query->get()->each(function (QuestionBank $bank) use ($linker, &$total): void {
        if ($this->option('course_id')) {
            $bank->forceFill(['course_id' => (int) $this->option('course_id')])->save();
        }

        $updated = $linker->linkBank($bank);
        $total += $updated;

        $this->info("Banco {$bank->id}: {$updated} questão(ões) vinculada(s).");
    });

    $this->info("Total vinculado: {$total}");

    return 0;
})->purpose('Vincula questões importadas aos módulos e aulas do curso pelo assunto.');
