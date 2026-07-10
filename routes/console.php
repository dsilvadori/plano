<?php

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\QuestionBank;
use App\Services\CourseAccessResolver;
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

                    $courseIds = $track->courses()->pluck('courses.id')->all();
                    $moduleCourseIds = $module->courses()->pluck('courses.id')->all();
                    $courseId = collect([...$courseIds, ...$moduleCourseIds])->filter()->first();

                    if ($courseId && blank($candidate->course_id)) {
                        $candidate->forceFill(['course_id' => $courseId])->save();
                    }

                    if (blank($candidate->course_module_id)) {
                        $candidate->forceFill(['course_module_id' => $module->id])->save();
                    }

                    if (blank($candidate->course_module_track_id)) {
                        $candidate->forceFill(['course_module_track_id' => $track->id])->save();
                    }

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
