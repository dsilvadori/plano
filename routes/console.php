<?php

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Services\CourseAccessResolver;
use App\Services\CourseLessonMediaImporter;
use App\Services\LessonCourseLinker;
use App\Services\QuestionBankAutoLinker;
use App\Services\QuestionPdfImporter;
use App\Support\LessonTitleNormalizer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('question-banks:auto-link', function (QuestionBankAutoLinker $linker) {
    $totals = $linker->linkAll(function (QuestionBank $bank, array $result): void {
        if ($result['modules'] === 0 && $result['tracks'] === 0 && $result['lessons'] === 0) {
            return;
        }

        $this->line("{$bank->id} - {$bank->title}: +{$result['modules']} módulo(s), +{$result['tracks']} trilha(s), +{$result['lessons']} aula(s)");
    });

    $this->info("Bancos processados: {$totals['banks']}. Vínculos criados: {$totals['modules']} módulo(s), {$totals['tracks']} trilha(s), {$totals['lessons']} aula(s).");

    return 0;
})->purpose('Vincula bancos de questões a módulos, trilhas e aulas com nomes correspondentes');

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
    $linked = 0;
    $replaced = 0;

    $hasMedia = fn (Lesson $lesson): bool => filled($lesson->panda_video_id)
        || filled($lesson->panda_embed_url)
        || filled($lesson->panda_player_url)
        || in_array($lesson->source_status, ['media_ready', 'upload_queued', 'uploading'], true);

    $normalizeLesson = function (Lesson $lesson, int $position) use (&$changed): Lesson {
        $title = LessonTitleNormalizer::normalizePreservingNumber($lesson->title, $position);

        if ($lesson->title !== $title || (int) $lesson->sort_order !== $position) {
            $changed++;
        }

        $lesson->forceFill([
            'title' => $title,
            'sort_order' => $position,
        ])->save();

        return $lesson->fresh();
    };

    $candidateFor = function (Lesson $sourceLesson, ?CourseModule $module = null, ?CourseModuleTrack $track = null) use ($hasMedia): ?Lesson {
        $key = LessonTitleNormalizer::matchKey($sourceLesson->title);

        if ($key === '') {
            return null;
        }

        $trackName = $track ? LessonTitleNormalizer::matchKey($track->name) : null;
        $moduleName = $module ? LessonTitleNormalizer::matchKey($module->name) : null;

        return Lesson::query()
            ->whereKeyNot($sourceLesson->id)
            ->orderByRaw('case when panda_video_id is null then 1 else 0 end')
            ->orderBy('id')
            ->get()
            ->filter(fn (Lesson $lesson): bool => $hasMedia($lesson))
            ->map(function (Lesson $lesson) use ($sourceLesson, $key, $trackName, $moduleName): array {
                $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
                $path = LessonTitleNormalizer::matchKey((string) ($metadata['drive_source_folder_path'] ?? ''));
                $score = 0;

                if (! LessonTitleNormalizer::matches($lesson->title, $sourceLesson->title)) {
                    return ['lesson' => $lesson, 'score' => -1];
                }

                $score += LessonTitleNormalizer::matchKey($lesson->title) === $key ? 10 : 8;

                if ($trackName && str_contains($path, $trackName)) {
                    $score += 5;
                }

                if ($moduleName && str_contains($path, $moduleName)) {
                    $score += 3;
                }

                if (blank($lesson->course_id)) {
                    $score += 1;
                }

                return ['lesson' => $lesson, 'score' => $score];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 10)
            ->sortByDesc('score')
            ->first()['lesson'] ?? null;
    };

    $normalize = function () use (&$changed, &$linked, &$replaced, $normalizeLesson, $candidateFor, $hasMedia): void {
        Lesson::query()
            ->whereNull('course_module_id')
            ->orderBy('id')
            ->get()
            ->groupBy(function (Lesson $lesson): string {
                $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

                return (string) ($metadata['drive_source_folder_path'] ?? 'avulsas');
            })
            ->each(function ($lessons) use ($normalizeLesson): void {
                $lessons->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->each(fn (Lesson $lesson, int $index): Lesson => $normalizeLesson($lesson, $index + 1));
            });

        CourseModule::query()
            ->with('onlineLessons')
            ->orderBy('id')
            ->get()
            ->each(function (CourseModule $module) use ($normalizeLesson): void {
                $planningLessons = [];

                foreach ($module->onlineLessons as $index => $lesson) {
                    $position = $index + 1;
                    $lesson = $normalizeLesson($lesson, $position);

                    $lesson->modules()->updateExistingPivot($module->id, [
                        'sort_order' => $position,
                    ]);

                    $planningLessons[] = [
                        'name' => $lesson->title,
                        'minutes' => max(1, (int) ceil(((int) $lesson->duration_seconds) / 60)),
                    ];
                }

                if ($planningLessons !== []) {
                    $module->forceFill(['lessons' => $planningLessons])->save();
                }
            });

        CourseModuleTrack::query()
            ->with(['module.courses', 'lessons'])
            ->orderBy('id')
            ->get()
            ->each(function (CourseModuleTrack $track) use (&$linked, &$replaced, $normalizeLesson, $candidateFor, $hasMedia): void {
                $module = $track->module;

                if (! $module) {
                    return;
                }

                foreach ($track->lessons as $index => $lesson) {
                    $position = (int) ($lesson->pivot->sort_order ?? ($index + 1));
                    $lesson = $normalizeLesson($lesson, $position);
                    $candidate = $hasMedia($lesson) ? null : $candidateFor($lesson, $module, $track);

                    if (! $candidate) {
                        $lesson->tracks()->updateExistingPivot($track->id, [
                            'sort_order' => $position,
                        ]);

                        continue;
                    }

                    $candidate = $normalizeLesson($candidate, $position);
                    $candidate->modules()->syncWithoutDetaching([
                        $module->id => ['sort_order' => $position],
                    ]);
                    $candidate->tracks()->syncWithoutDetaching([
                        $track->id => ['sort_order' => $position],
                    ]);

                    $linked++;

                    if (! $hasMedia($lesson)) {
                        $lesson->modules()->detach($module->id);
                        $lesson->tracks()->detach($track->id);
                        $lesson->forceFill([
                            'course_id' => null,
                            'course_module_id' => null,
                            'course_module_track_id' => null,
                            'status' => 'archived',
                            'source_status' => 'structure_only',
                        ])->save();
                        $replaced++;
                    }
                }
            });
    };

    if ($this->option('dry-run')) {
        DB::beginTransaction();
        $normalize();
        DB::rollBack();
        $this->info("{$changed} aula(s) seriam normalizada(s).");
        $this->info("{$linked} aula(s) seriam vinculada(s).");
        $this->info("{$replaced} placeholder(s) seriam substituído(s).");

        return 0;
    }

    DB::transaction($normalize);

    $this->info("{$changed} aula(s) normalizada(s).");
    $this->info("{$linked} aula(s) vinculada(s).");
    $this->info("{$replaced} placeholder(s) substituído(s).");

    return 0;
})->purpose('Normaliza nomes e numeração das aulas importadas');

Artisan::command('catalog:detach-course-bindings {--dry-run}', function () {
    $modules = CourseModule::query()
        ->whereNotNull('course_id')
        ->orderBy('id')
        ->get();
    $lessons = Lesson::query()
        ->where(function ($query): void {
            $query
                ->whereNotNull('course_id')
                ->orWhereNotNull('course_module_id')
                ->orWhereNotNull('course_module_track_id');
        })
        ->orderBy('id')
        ->get();

    $modulesLinked = 0;
    $modulesDetached = 0;
    $lessonModuleLinks = 0;
    $lessonTrackLinks = 0;
    $lessonsDetached = 0;

    $sync = function () use ($modules, $lessons, &$modulesLinked, &$modulesDetached, &$lessonModuleLinks, &$lessonTrackLinks, &$lessonsDetached): void {
        foreach ($modules as $module) {
            $courseId = $module->course_id;

            if (blank($courseId)) {
                continue;
            }

            $module->courses()->syncWithoutDetaching([
                $courseId => ['sort_order' => (int) $module->sort_order],
            ]);
            $modulesLinked++;

            $module->forceFill(['course_id' => null])->save();
            $modulesDetached++;
        }

        foreach ($lessons as $lesson) {
            if (filled($lesson->course_module_id)) {
                $lesson->modules()->syncWithoutDetaching([
                    $lesson->course_module_id => ['sort_order' => (int) $lesson->sort_order],
                ]);
                $lessonModuleLinks++;
            }

            if (filled($lesson->course_module_track_id)) {
                $lesson->tracks()->syncWithoutDetaching([
                    $lesson->course_module_track_id => ['sort_order' => (int) $lesson->sort_order],
                ]);
                $lessonTrackLinks++;
            }

            $lesson->forceFill([
                'course_id' => null,
                'course_module_id' => null,
                'course_module_track_id' => null,
            ])->save();
            $lessonsDetached++;
        }
    };

    if ($this->option('dry-run')) {
        DB::beginTransaction();
        $sync();
        DB::rollBack();
        $this->info('Simulação concluída. Nada foi gravado.');
    } else {
        DB::transaction($sync);
        $this->info('Saneamento concluído.');
    }

    $this->line("Módulos avaliados: {$modules->count()}");
    $this->line("Vínculos de módulo preservados no agrupamento do curso: {$modulesLinked}");
    $this->line("Módulos desvinculados do course_id legado: {$modulesDetached}");
    $this->line("Aulas avaliadas: {$lessons->count()}");
    $this->line("Vínculos de aula com módulo preservados: {$lessonModuleLinks}");
    $this->line("Vínculos de aula com trilha preservados: {$lessonTrackLinks}");
    $this->line("Aulas desvinculadas dos campos legados: {$lessonsDetached}");

    return 0;
})->purpose('Transforma vínculos diretos legados em agrupamentos reutilizáveis por pivot');

Artisan::command('lessons:sync-course-links {courseId?} {--dry-run}', function (LessonCourseLinker $linker) {
    $courseId = $this->argument('courseId');
    $course = filled($courseId) ? Course::query()->findOrFail((int) $courseId) : null;

    if ($this->option('dry-run')) {
        DB::beginTransaction();
        $stats = $linker->sync($course);
        DB::rollBack();

        $this->info('Simulação concluída. Nada foi gravado.');
    } else {
        $stats = $linker->sync($course);

        $this->info('Sincronização concluída.');
    }

    if ($course instanceof Course) {
        $this->line("Curso {$course->id}: {$course->name}");
    }

    $this->line("Trilhas avaliadas: {$stats['tracks']}");
    $this->line("Aulas vinculadas: {$stats['linked']}");
    $this->line("Placeholders substituídos: {$stats['replaced']}");
    $this->line("Aulas publicadas: {$stats['published']}");
    $this->line("Planos ativos sincronizados: {$stats['plans_synced']}");

    return 0;
})->purpose('Substitui aulas sem mídia por aulas prontas correspondentes no curso');

Artisan::command('questions:import-pdf {path} {--title=} {--answer-key=}', function (QuestionPdfImporter $importer) {
    $path = (string) $this->argument('path');

    if (! is_file($path)) {
        $this->error('PDF não encontrado: '.$path);

        return 1;
    }

    $storedPath = 'question-banks/'.basename($path);
    Storage::disk('local')->put($storedPath, file_get_contents($path));

    $bank = QuestionBank::query()->create([
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

Artisan::command('course-modules:sync-drive-media {courseId} {--folder-id= : ID da pasta do Google Drive com as mídias} {--manifest= : Caminho para JSON com arquivos, útil para testar sem API} {--confidence=0.72 : Confiança mínima do matching aproximado}', function (int $courseId, CourseLessonMediaImporter $importer) {
    $course = Course::query()->findOrFail($courseId);
    $confidence = (float) $this->option('confidence');

    $summary = filled($this->option('manifest'))
        ? $importer->importFromManifest($course, (string) $this->option('manifest'), $confidence)
        : $importer->importFromDrive($course, $this->option('folder-id') ?: null, $confidence);

    $this->info("Curso {$course->id}: {$course->name}");
    $this->line("Módulos avaliados: {$summary['modules']}");
    $this->line("Aulas avaliadas: {$summary['lessons']}");
    $this->line("Com mídia importada: {$summary['imported']}");
    $this->line("Sem mídia: {$summary['missing']}");

    return 0;
})->purpose('Marca aulas dos módulos com mídia importada a partir do Google Drive ou de um manifesto JSON');
