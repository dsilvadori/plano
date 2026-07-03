<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\PandaAiResourceActivator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncPandaAiArtifacts implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $lessonId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-ai-artifacts-'.$this->lessonId))
                ->shared()
                ->expireAfter(300)
                ->releaseAfter(60),
        ];
    }

    public function backoff(): array
    {
        return collect(explode(',', (string) config('services.panda.ai_sync_backoff_seconds', '300,600,1200,2400')))
            ->map(fn (string $seconds): int => max(60, (int) trim($seconds)))
            ->filter()
            ->values()
            ->all();
    }

    public function handle(PandaAiResourceActivator $activator): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        if ($activator->syncReadyArtifacts($lesson) === 0) {
            $this->release($this->backoff()[0] ?? 300);
        }
    }
}
