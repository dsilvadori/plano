<?php

namespace App\Jobs;

use App\Models\Lesson;
use App\Services\PandaTutorActivator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncPandaTutorAvailability implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(
        public int $lessonId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('panda-tutor-'.$this->lessonId))
                ->shared()
                ->expireAfter(300)
                ->releaseAfter(60),
        ];
    }

    public function backoff(): array
    {
        return collect(explode(',', (string) config('services.panda.tutor_sync_backoff_seconds', '300,600,1200,2400')))
            ->map(fn (string $seconds): int => max(60, (int) trim($seconds)))
            ->filter()
            ->values()
            ->all();
    }

    public function handle(PandaTutorActivator $activator): void
    {
        $lesson = Lesson::query()->find($this->lessonId);

        if (! $lesson) {
            return;
        }

        if (! $activator->syncAvailability($lesson)['available']) {
            $this->release($this->backoff()[0] ?? 300);
        }
    }
}
