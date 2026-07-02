<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Services\GoogleDriveTrackImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ImportGoogleDriveLessons implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public ?int $courseId,
        public ?int $moduleId,
        public ?int $trackId,
        public string $folderUrl,
        public string $lessonStatus = 'draft',
        public ?string $pandaFolderName = null,
        public bool $createPandaFolder = true,
        public bool $uploadPandaVideos = true,
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('google-drive-panda-import'))
                ->shared()
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(GoogleDriveTrackImporter $importer): void
    {
        $course = $this->courseId ? Course::query()->findOrFail($this->courseId) : null;
        $module = $this->moduleId ? CourseModule::query()->findOrFail($this->moduleId) : null;
        $track = $this->trackId ? CourseModuleTrack::query()->findOrFail($this->trackId) : null;
        $run = $this->runId ? GoogleDriveImportRun::query()->find($this->runId) : null;

        $run?->forceFill([
            'status' => 'running',
            'latest_message' => 'Importação de aulas iniciada.',
            'started_at' => now(),
        ])->save();

        $summary = $importer->importFolderFilesAsLessons(
            $course,
            $module,
            $track,
            $this->folderUrl,
            $this->lessonStatus,
            $this->pandaFolderName,
            $this->createPandaFolder,
            $this->uploadPandaVideos,
            $run,
        );

        $run?->forceFill([
            'status' => 'finished',
            'summary' => $summary,
            'latest_message' => 'Importação de aulas concluída.',
            'finished_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->runId) {
            return;
        }

        GoogleDriveImportRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'latest_message' => 'Importação de aulas falhou.',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
