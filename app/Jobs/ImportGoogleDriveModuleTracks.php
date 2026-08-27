<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\GoogleDriveImportRun;
use App\Services\GoogleDriveTrackImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ImportGoogleDriveModuleTracks implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 7200;

    public function __construct(
        public ?int $courseId,
        public int $moduleId,
        public string $folderUrl,
        public string $lessonStatus = 'published',
        public bool $createPandaFolders = true,
        public bool $uploadPandaVideos = true,
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('google-drive-panda-import'))
                ->shared()
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function backoff(): array
    {
        return [120, 300, 900];
    }

    public function handle(GoogleDriveTrackImporter $importer): void
    {
        $course = $this->courseId ? Course::query()->findOrFail($this->courseId) : null;
        $module = CourseModule::query()->findOrFail($this->moduleId);
        $run = $this->runId ? GoogleDriveImportRun::query()->find($this->runId) : null;

        if ($run?->isCanceled()) {
            return;
        }

        $run?->forceFill([
            'status' => 'running',
            'latest_message' => 'Importação iniciada.',
            'started_at' => now(),
        ])->save();

        $summary = $importer->importFolderSubfoldersAsTracks(
            $course,
            $module,
            $this->folderUrl,
            $this->lessonStatus,
            $this->createPandaFolders,
            $this->uploadPandaVideos,
            $run,
        );

        if ($run?->fresh()?->isCanceled()) {
            return;
        }

        $run?->forceFill([
            'status' => 'finished',
            'summary' => $summary,
            'latest_message' => empty($summary['warnings'] ?? [])
                ? 'Importação concluída.'
                : 'Importação concluída com avisos.',
            'finished_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->runId) {
            return;
        }

        if (GoogleDriveImportRun::query()->find($this->runId)?->isCanceled()) {
            return;
        }

        GoogleDriveImportRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'latest_message' => 'Importação falhou.',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
