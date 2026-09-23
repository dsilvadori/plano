<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSpreadsheetImportRun;
use App\Services\CourseSpreadsheetImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportCourseSpreadsheet implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    public function __construct(
        public string $path,
        public ?int $courseId = null,
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('course-spreadsheet-import-'.($this->courseId ?: 'new')))
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function backoff(): array
    {
        return [120, 300, 900];
    }

    public function handle(CourseSpreadsheetImporter $importer): void
    {
        $absolutePath = Storage::disk('local')->path($this->path);
        $run = $this->runId ? CourseSpreadsheetImportRun::query()->find($this->runId) : null;

        if (! Storage::disk('local')->exists($this->path)) {
            $message = 'A planilha salva para esta importação não existe mais no storage local.';

            $run?->forceFill([
                'status' => 'failed',
                'latest_message' => $message,
                'error_message' => 'Reenvie a planilha para criar uma nova importação.',
                'finished_at' => now(),
            ])->save();

            $this->log('course_spreadsheet_import.missing_file', [
                'path' => $this->path,
                'course_id' => $this->courseId,
                'run_id' => $this->runId,
            ]);

            return;
        }

        $run?->forceFill([
            'status' => 'running',
            'latest_message' => 'Importação em andamento.',
            'error_message' => null,
            'started_at' => now(),
        ])->save();

        $this->log('course_spreadsheet_import.started', [
            'path' => $this->path,
            'course_id' => $this->courseId,
            'run_id' => $this->runId,
        ]);

        try {
            $course = $this->courseId
                ? $importer->importIntoWithProgress(
                    Course::query()->findOrFail($this->courseId),
                    $absolutePath,
                    fn (array $progress) => $this->updateRunProgress($progress),
                )
                : $importer->importWithProgress(
                    $absolutePath,
                    fn (array $progress) => $this->updateRunProgress($progress),
                );

            $modules = $course->modules()->with('tracks.lessons')->get();
            $moduleCount = $modules->count();
            $trackCount = $modules->sum(fn (CourseModule $module): int => $module->tracks->count());
            $lessonCount = $modules
                ->flatMap(fn (CourseModule $module) => $module->tracks->flatMap->lessons)
                ->pluck('id')
                ->unique()
                ->count();

            $run?->forceFill([
                'course_id' => $course->id,
                'course_name' => $run->course_name ?: $course->name,
                'status' => 'finished',
                'latest_message' => 'Importação concluída.',
                'error_message' => null,
                'finished_at' => now(),
                'summary' => [
                    'course_id' => $course->id,
                    'course_name' => $course->name,
                    'modules' => $moduleCount,
                    'tracks' => $trackCount,
                    'lessons' => $lessonCount,
                ],
            ])->save();

            $this->log('course_spreadsheet_import.finished', [
                'path' => $this->path,
                'course_id' => $course->id,
                'course_name' => $course->name,
                'modules' => $moduleCount,
                'tracks' => $trackCount,
                'lessons' => $lessonCount,
                'run_id' => $this->runId,
            ]);
        } catch (Throwable $exception) {
            $this->markRunFailed($exception);

            throw $exception;
        } finally {
            Storage::disk('local')->delete($this->path);
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markRunFailed($exception);

        $this->log('course_spreadsheet_import.failed', [
            'path' => $this->path,
            'course_id' => $this->courseId,
            'run_id' => $this->runId,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function log(string $event, array $context = []): void
    {
        try {
            Log::channel('course_debug')->info($event, $context);
        } catch (Throwable) {
            Log::info($event, $context);
        }
    }

    protected function updateRunProgress(array $progress): void
    {
        if (! $this->runId) {
            return;
        }

        CourseSpreadsheetImportRun::query()
            ->whereKey($this->runId)
            ->update([
                'course_id' => $progress['course_id'] ?? $this->courseId,
                'processed_modules' => (int) ($progress['processed_modules'] ?? 0),
                'total_modules' => (int) ($progress['total_modules'] ?? 0),
                'latest_message' => (string) ($progress['message'] ?? 'Importação em andamento.'),
                'updated_at' => now(),
            ]);
    }

    protected function markRunFailed(Throwable $exception): void
    {
        if (! $this->runId) {
            return;
        }

        CourseSpreadsheetImportRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'latest_message' => 'A importação falhou.',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
