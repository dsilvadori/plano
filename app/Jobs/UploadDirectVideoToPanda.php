<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\ActiveStudyPlanRefresher;
use App\Services\LessonCourseLinker;
use App\Services\LessonDirectVideoUploader;
use App\Services\PandaVideoClient;
use App\Support\PandaUploadErrors;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Envia ao Panda um vídeo que o admin subiu pela plataforma e reflete o
 * resultado na aula (e, por consequência, na Biblioteca de Vídeos).
 */
class UploadDirectVideoToPanda implements ShouldQueue
{
    use Queueable;

    public int $tries = 50;

    public int $timeout = 7200;

    public function __construct(
        public int $lessonId,
        public string $disk,
        public string $path,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-direct-upload-'.$this->lessonId))
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(PandaVideoClient $panda, ?LessonCourseLinker $linker = null, ?ActiveStudyPlanRefresher $refresher = null): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson || ! $this->isCurrentUpload($lesson)) {
            // A aula foi apagada ou recebeu um envio mais novo: este arquivo não é mais necessário.
            $this->deleteTemporaryFile();

            return;
        }

        $upload = $this->uploadMetadata($lesson);

        try {
            if (! Storage::disk($this->disk)->exists($this->path)) {
                throw new RuntimeException('O arquivo enviado não foi encontrado no servidor. Envie o vídeo novamente.');
            }

            $this->markUploading($lesson);

            $video = $panda->uploadVideo(
                Storage::disk($this->disk)->path($this->path),
                (string) ($upload['title'] ?? $lesson->title),
                filled($upload['folder_id'] ?? null) ? (string) $upload['folder_id'] : null,
            );

            if (blank($video['panda_video_id'] ?? null)) {
                throw new RuntimeException('O Panda aceitou o arquivo, mas não retornou o ID do vídeo.');
            }

            $sourceStatus = match (true) {
                $panda->videoIsFailed($video) => 'upload_failed',
                $panda->videoIsReady($video) => 'media_ready',
                default => 'panda_processing',
            };

            $this->markUploaded($lesson->fresh(), $video, $sourceStatus);
            $this->deleteTemporaryFile();
        } catch (Throwable $exception) {
            if (PandaUploadErrors::isRetryable($exception) && $this->attempts() < $this->tries) {
                $this->markQueuedForRetry($lesson->fresh(), $exception);
                $this->release($this->backoffSeconds());

                return;
            }

            $this->markFailed($lesson->fresh(), $exception);
            $this->deleteTemporaryFile();

            throw $exception;
        }

        if ($sourceStatus === 'panda_processing') {
            $dispatch = SyncPandaVideoStatus::dispatch($lesson->id)
                ->delay(now()->addSeconds(max(0, (int) config('services.panda.video_status_sync_delay_seconds', 300))));

            if (config('queue.default') === 'sync') {
                $dispatch->afterResponse();
            }
        } elseif ($sourceStatus === 'media_ready') {
            ($linker ?? app(LessonCourseLinker::class))->sync();
            ($refresher ?? app(ActiveStudyPlanRefresher::class))->refreshCoursesForLesson($lesson->fresh());
        }
    }

    public function failed(Throwable $exception): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if ($lesson && $this->isCurrentUpload($lesson)) {
            $this->markFailed($lesson, $exception);
        }

        $this->deleteTemporaryFile();
    }

    protected function isCurrentUpload(Lesson $lesson): bool
    {
        return ($this->uploadMetadata($lesson)['path'] ?? null) === $this->path;
    }

    protected function uploadMetadata(Lesson $lesson): array
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        return is_array($metadata['panda_direct_upload'] ?? null) ? $metadata['panda_direct_upload'] : [];
    }

    protected function markUploading(Lesson $lesson): void
    {
        $this->updateLesson($lesson, LessonDirectVideoUploader::lessonHasPandaMedia($lesson) ? null : 'uploading', [
            'status' => 'uploading',
            'started_at' => now()->toIso8601String(),
            'attempts' => $this->attempts(),
            'error' => null,
        ]);
    }

    protected function markUploaded(Lesson $lesson, array $video, string $sourceStatus): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];
        $durationSeconds = (int) ($video['duration_seconds'] ?? 0);
        $upload = $this->uploadMetadata($lesson);

        $lesson->forceFill([
            'type' => 'video',
            'panda_video_id' => (string) $video['panda_video_id'],
            'panda_status' => $video['panda_status'] ?? null,
            'panda_embed_url' => $video['panda_embed_url'] ?? null,
            'panda_player_url' => $video['panda_player_url'] ?? null,
            'thumbnail_url' => filled($video['thumbnail_url'] ?? null) ? $video['thumbnail_url'] : $lesson->thumbnail_url,
            'duration_seconds' => $durationSeconds > 0 ? $durationSeconds : (int) $lesson->duration_seconds,
            'source_status' => $sourceStatus,
            'metadata' => array_replace($metadata, [
                'source' => 'panda',
                'panda_folder_id' => $upload['folder_id'] ?? ($metadata['panda_folder_id'] ?? null),
                'panda_upload' => $video['payload'] ?? null,
                'panda_upload_error' => $sourceStatus === 'upload_failed' ? 'O Panda marcou o vídeo como falho após o envio.' : null,
                'panda_upload_completed_at' => now()->toIso8601String(),
                'panda_status_sync_queued_at' => $sourceStatus === 'panda_processing' ? now()->toIso8601String() : null,
                'panda_direct_upload' => array_replace($upload, [
                    'status' => $sourceStatus === 'upload_failed' ? 'failed' : 'uploaded',
                    'panda_video_id' => (string) $video['panda_video_id'],
                    'uploaded_at' => now()->toIso8601String(),
                    'attempts' => $this->attempts(),
                    'error' => null,
                ]),
            ]),
        ])->save();
    }

    protected function markQueuedForRetry(Lesson $lesson, Throwable $exception): void
    {
        $this->updateLesson($lesson, LessonDirectVideoUploader::lessonHasPandaMedia($lesson) ? null : 'upload_queued', [
            'status' => 'queued',
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'retry_at' => now()->addSeconds($this->backoffSeconds())->toIso8601String(),
        ], [
            'panda_upload_error' => $exception->getMessage(),
        ]);
    }

    protected function markFailed(Lesson $lesson, Throwable $exception): void
    {
        $this->updateLesson($lesson, LessonDirectVideoUploader::lessonHasPandaMedia($lesson) ? null : 'upload_failed', [
            'status' => 'failed',
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'failed_at' => now()->toIso8601String(),
        ], [
            'panda_upload_error' => $exception->getMessage(),
            'panda_upload_failed_at' => now()->toIso8601String(),
        ]);
    }

    protected function updateLesson(Lesson $lesson, ?string $sourceStatus, array $uploadChanges, array $metadataChanges = []): void
    {
        $metadata = is_array($lesson->metadata) ? $lesson->metadata : [];

        $attributes = [
            'metadata' => array_replace($metadata, $metadataChanges, [
                'panda_direct_upload' => array_replace($this->uploadMetadata($lesson), $uploadChanges),
            ]),
        ];

        if ($sourceStatus !== null) {
            $attributes['source_status'] = $sourceStatus;
        }

        $lesson->forceFill($attributes)->save();
    }

    protected function deleteTemporaryFile(): void
    {
        try {
            Storage::disk($this->disk)->delete($this->path);
        } catch (Throwable $exception) {
            report($exception);
        }
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

        return $values->get(min(max(1, $this->attempts()) - 1, $values->count() - 1));
    }
}
