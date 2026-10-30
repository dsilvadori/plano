<?php

namespace App\Jobs;

use App\Models\Course;
use App\Services\ActiveStudyPlanRefresher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshActiveCourseStudyPlans implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 7200;

    public function __construct(
        public int $courseId,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('study-plan-refresh-course-'.$this->courseId))
                ->expireAfter($this->timeout)
                ->releaseAfter(60),
        ];
    }

    public function handle(ActiveStudyPlanRefresher $refresher): void
    {
        $course = Course::query()->find($this->courseId);

        if (! $course) {
            return;
        }

        $refresher->refreshCourseFromNextWeek($course);
    }
}
