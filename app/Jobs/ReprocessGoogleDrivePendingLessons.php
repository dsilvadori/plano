<?php

namespace App\Jobs;

use App\Models\GoogleDriveImportRun;
use App\Services\GoogleDriveTrackImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ReprocessGoogleDrivePendingLessons implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 7200;

    public function __construct(
        public int $sourceRunId,
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
        $sourceRun = GoogleDriveImportRun::query()->findOrFail($this->sourceRunId);
        $run = $this->runId ? GoogleDriveImportRun::query()->find($this->runId) : null;

        if ($run?->isCanceled()) {
            return;
        }

        $run?->forceFill([
            'status' => 'running',
            'latest_message' => 'Reprocessamento de aulas pendentes iniciado.',
            'started_at' => now(),
        ])->save();

        $summary = $importer->reprocessPendingLessonsForRun($sourceRun, $run);

        if ($run?->fresh()?->isCanceled()) {
            return;
        }

        $run?->forceFill([
            'status' => 'finished',
            'summary' => $summary,
            'latest_message' => ($summary['total_lessons'] ?? 0) > 0
                ? 'Reprocessamento de pendentes concluído.'
                : 'Nenhuma aula pendente encontrada para reprocessar.',
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
                'latest_message' => 'Reprocessamento de pendentes falhou.',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
