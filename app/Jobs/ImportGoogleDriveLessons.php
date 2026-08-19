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

    public int $tries = 10;

    public int $timeout = 7200;

    public function __construct(
        public ?int $courseId,
        public ?int $moduleId,
        public ?int $trackId,
        public string $folderUrl,
        public string $lessonStatus = 'published',
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

        if (($summary['total_lessons'] ?? 0) === 0) {
            $run?->forceFill([
                'status' => 'failed',
                'summary' => $summary,
                'latest_message' => 'Nenhum arquivo foi encontrado na pasta do Drive.',
                'error_message' => 'Verifique se a pasta ou as subpastas possuem arquivos e se elas foram compartilhadas com a conta de serviço do Google Drive.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $run?->forceFill([
            'status' => 'finished',
            'summary' => $summary,
            'latest_message' => empty($summary['warnings'] ?? [])
                ? 'Importação de aulas concluída.'
                : 'Importação de aulas concluída com avisos.',
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
