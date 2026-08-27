<?php

namespace App\Jobs;

use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use App\Services\ActiveStudyPlanRefresher;
use App\Services\LessonCourseLinker;
use App\Services\PandaVideoClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncPandaVideoStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    public int $timeout = 120;

    public function __construct(
        public int $lessonId,
        public ?int $runId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-video-status-'.$this->lessonId))
                ->shared()
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(3);
    }

    public function handle(PandaVideoClient $panda, ?LessonCourseLinker $linker = null, ?ActiveStudyPlanRefresher $refresher = null): void
    {
        if ($this->runId && GoogleDriveImportRun::query()->find($this->runId)?->isCanceled()) {
            return;
        }

        $lesson = Lesson::query()->findOrFail($this->lessonId);

        if (blank($lesson->panda_video_id)) {
            return;
        }

        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $folderId = filled($metadata['panda_folder_id'] ?? null) ? (string) $metadata['panda_folder_id'] : null;
        $video = $panda->video((string) $lesson->panda_video_id, $folderId);

        if (! $video) {
            $this->markProcessingAndRelease($lesson, 'Vídeo ainda não apareceu na API do Panda.');

            return;
        }

        if ($this->runId && GoogleDriveImportRun::query()->find($this->runId)?->isCanceled()) {
            return;
        }

        if ($panda->videoIsFailed($video)) {
            $this->markFailed($lesson, $video, 'O Panda retornou falha no processamento do vídeo.');

            return;
        }

        $lesson->forceFill([
            'panda_embed_url' => $video['panda_embed_url'] ?? $lesson->panda_embed_url,
            'panda_player_url' => $video['panda_player_url'] ?? $lesson->panda_player_url,
            'panda_status' => $video['panda_status'] ?? $lesson->panda_status,
            'duration_seconds' => $this->durationSecondsFromPandaVideo($video, $lesson),
            'source_status' => $panda->videoIsReady($video) ? 'media_ready' : 'panda_processing',
            'metadata' => [
                ...$metadata,
                'panda_upload' => $video['payload'] ?? ($metadata['panda_upload'] ?? null),
                'panda_status_checked_at' => now()->toIso8601String(),
                'panda_processing_error' => null,
            ],
        ])->save();

        if ($panda->videoIsReady($video)) {
            $this->markRunReady($lesson);
            ($linker ?? app(LessonCourseLinker::class))->sync();
            ($refresher ?? app(ActiveStudyPlanRefresher::class))->refreshCoursesForLesson($lesson->fresh());

            return;
        }

        $this->updateRunMessage('Vídeo ainda processando no Panda: '.$lesson->title);
        $this->release($this->backoffSeconds());
    }

    public function failed(Throwable $exception): void
    {
        if ($this->runId && GoogleDriveImportRun::query()->find($this->runId)?->isCanceled()) {
            return;
        }

        $lesson = Lesson::query()->find($this->lessonId);

        if ($lesson) {
            $this->markFailed($lesson, null, $exception->getMessage());
        }
    }

    protected function markProcessingAndRelease(Lesson $lesson, string $message): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $lesson->forceFill([
            'source_status' => 'panda_processing',
            'metadata' => [
                ...$metadata,
                'panda_status_checked_at' => now()->toIso8601String(),
                'panda_processing_error' => $message,
            ],
        ])->save();

        $this->updateRunMessage('Aguardando processamento no Panda: '.$lesson->title);
        $this->release($this->backoffSeconds());
    }

    protected function markFailed(Lesson $lesson, ?array $video, string $message): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $validationError = (string) data_get($video, 'payload.validation_error', '');
        $failureMessage = $validationError !== ''
            ? $message.' Erro Panda: '.$validationError.'.'
            : $message;

        $lesson->forceFill([
            'panda_status' => $video['panda_status'] ?? $lesson->panda_status,
            'source_status' => 'upload_failed',
            'metadata' => [
                ...$metadata,
                'panda_upload' => $video['payload'] ?? ($metadata['panda_upload'] ?? null),
                'panda_status_checked_at' => now()->toIso8601String(),
                'panda_processing_error' => $failureMessage,
            ],
        ])->save();

        $this->updateRunMessage('Processamento Panda falhou: '.$lesson->title, $failureMessage);
    }

    protected function markRunReady(Lesson $lesson): void
    {
        if (! $this->runId) {
            return;
        }

        if (GoogleDriveImportRun::query()->find($this->runId)?->isCanceled()) {
            return;
        }

        $updates = [
            'latest_message' => 'Vídeo pronto no Panda: '.$lesson->title,
            'error_message' => null,
            'updated_at' => now(),
        ];

        $run = GoogleDriveImportRun::query()->find($this->runId);

        if ($run && $run->panda_videos_failed > 0) {
            $updates['panda_videos_failed'] = DB::raw('panda_videos_failed - 1');
        }

        GoogleDriveImportRun::query()->whereKey($this->runId)->update($updates);
    }

    protected function updateRunMessage(string $message, ?string $error = null): void
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
                'latest_message' => $message,
                'error_message' => $error,
                'updated_at' => now(),
            ]);
    }

    protected function durationSecondsFromPandaVideo(array $video, Lesson $lesson): int
    {
        $duration = $video['duration_seconds'] ?? null;

        if (is_numeric($duration) && (int) $duration > 0) {
            return (int) $duration;
        }

        return (int) ($lesson->duration_seconds ?: 0);
    }

    protected function backoffSeconds(): int
    {
        $values = collect(explode(',', (string) config('services.panda.video_status_sync_backoff_seconds', '300,600,1200,2400,3600')))
            ->map(fn (string $value): int => max(0, (int) trim($value)))
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        if ($values->isEmpty()) {
            return 300;
        }

        return $values->get(min($this->attempts() - 1, $values->count() - 1));
    }
}
