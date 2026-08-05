<?php

namespace App\Jobs;

use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use App\Services\ActiveStudyPlanRefresher;
use App\Services\GoogleDriveTrackImporter;
use App\Services\LessonCourseLinker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class UploadLessonToPanda implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 7200;

    public function __construct(
        public int $lessonId,
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-video-upload'))
                ->shared()
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function handle(GoogleDriveTrackImporter $importer, ?LessonCourseLinker $linker = null, ?ActiveStudyPlanRefresher $refresher = null): void
    {
        $lesson = Lesson::query()->findOrFail($this->lessonId);
        $run = $this->runId ? GoogleDriveImportRun::query()->find($this->runId) : null;

        try {
            $importer->uploadQueuedLessonToPanda($lesson, $run);

            if (($lesson->fresh()?->source_status) === 'panda_processing') {
                SyncPandaVideoStatus::dispatch($lesson->id, $run?->id)
                    ->delay(now()->addSeconds(max(0, (int) config('services.panda.video_status_sync_delay_seconds', 300))))
                    ->afterResponse();
            } elseif (($lesson->fresh()?->source_status) === 'media_ready') {
                ($linker ?? app(LessonCourseLinker::class))->sync();
                ($refresher ?? app(ActiveStudyPlanRefresher::class))->refreshCoursesForLesson($lesson->fresh());
            }

            $this->pauseAfterUpload();
        } catch (Throwable $exception) {
            if ($this->isPandaUploadConcurrencyLimit($exception)) {
                $this->markLessonQueued($lesson, $exception);
                $this->updateRunForRetry($run, $lesson, $exception);
                $this->release($this->backoffSeconds());

                return;
            }

            $this->markLessonFailed($lesson, $exception);
            $this->updateRunForFailure($run, $lesson, $exception);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $lesson = Lesson::query()->find($this->lessonId);
        $run = $this->runId ? GoogleDriveImportRun::query()->find($this->runId) : null;

        if ($lesson) {
            $this->markLessonFailed($lesson, $exception);
        }

        $this->updateRunForFailure($run, $lesson, $exception);
    }

    protected function markLessonQueued(Lesson $lesson, Throwable $exception): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $lesson->forceFill([
            'source_status' => 'upload_queued',
            'metadata' => [
                ...$metadata,
                'panda_upload_error' => $exception->getMessage(),
                'panda_upload_retry_at' => now()->addSeconds($this->backoffSeconds())->toIso8601String(),
                'panda_upload_attempts' => $this->attempts(),
            ],
        ])->save();
    }

    protected function markLessonFailed(Lesson $lesson, Throwable $exception): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $lesson->forceFill([
            'source_status' => 'upload_failed',
            'metadata' => [
                ...$metadata,
                'panda_upload_error' => $exception->getMessage(),
                'panda_upload_failed_at' => now()->toIso8601String(),
                'panda_upload_attempts' => $this->attempts(),
            ],
        ])->save();
    }

    protected function updateRunForRetry(?GoogleDriveImportRun $run, Lesson $lesson, Throwable $exception): void
    {
        $run?->forceFill([
            'latest_message' => 'Upload Panda reagendado: '.$lesson->title,
            'error_message' => $exception->getMessage(),
        ])->save();
    }

    protected function updateRunForFailure(?GoogleDriveImportRun $run, ?Lesson $lesson, Throwable $exception): void
    {
        $run?->forceFill([
            'latest_message' => $lesson
                ? 'Upload Panda falhou: '.$lesson->title
                : 'Upload Panda falhou.',
            'error_message' => $exception->getMessage(),
        ])->save();
    }

    protected function isPandaUploadConcurrencyLimit(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'upload concurrency limit')
            || str_contains($message, 'reached the upload concurrency')
            || str_contains($message, 'errcode":10')
            || str_contains($message, 'errcode": 10');
    }

    protected function backoffSeconds(): int
    {
        $values = collect(explode(',', (string) config('services.panda.video_upload_job_backoff_seconds', '300,600,1200,2400')))
            ->map(fn (string $value): int => max(0, (int) trim($value)))
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($values->isEmpty()) {
            return 300;
        }

        return $values->get(min($this->attempts() - 1, $values->count() - 1));
    }

    protected function pauseAfterUpload(): void
    {
        $seconds = max(0, (int) config('services.panda.video_upload_job_delay_seconds', 120));

        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
