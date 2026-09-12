<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\PandaImportRun;
use App\Services\PandaCourseImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ImportPandaLessons implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(
        public ?int $courseId,
        public ?int $moduleId,
        public ?int $trackId,
        public string $folderReference,
        public string $lessonStatus = 'published',
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-lessons-import'))
                ->shared()
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function backoff(): array
    {
        return [120, 300, 900];
    }

    public function handle(PandaCourseImporter $importer): void
    {
        $course = $this->courseId ? Course::query()->findOrFail($this->courseId) : null;
        $module = $this->moduleId ? CourseModule::query()->findOrFail($this->moduleId) : null;
        $track = $this->trackId ? CourseModuleTrack::query()->findOrFail($this->trackId) : null;
        $run = $this->runId ? PandaImportRun::query()->find($this->runId) : null;

        $run?->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        $importer->importLessons(
            $course,
            $module,
            $track,
            $this->folderReference,
            $this->lessonStatus,
            $run,
        );
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->runId) {
            return;
        }

        PandaImportRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
