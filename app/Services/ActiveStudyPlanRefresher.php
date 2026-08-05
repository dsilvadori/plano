<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyTrack;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ActiveStudyPlanRefresher
{
    public function __construct(
        protected StudyPlanGenerator $generator,
    ) {}

    public function refreshCourseFromNextWeek(Course $course, ?CarbonInterface $referenceDate = null): int
    {
        $replaceRemovedModulesFrom = $this->nextWeekStart($referenceDate);
        $cutoff = $this->thirdWeekStart($referenceDate);
        $refreshed = 0;

        $course->studyPlans()
            ->where('status', 'active')
            ->with(['course', 'studyTrack', 'user'])
            ->get()
            ->each(function ($plan) use ($course, $cutoff, $replaceRemovedModulesFrom, &$refreshed): void {
                if (! $plan->user) {
                    return;
                }

                $examDate = $plan->exam_date_confirmed && $plan->exam_date
                    ? $plan->exam_date->toDateString()
                    : null;
                $studyTrack = $plan->studyTrack ?: $this->officialStudyTrackFor($course);

                $this->generator->regenerateFromDate(
                    $plan,
                    $plan->course ?: $course,
                    $studyTrack,
                    $examDate,
                    $plan->start_date?->toDateString() ?? now()->toDateString(),
                    $plan->available_days ?? [],
                    $plan->available_minutes_by_day ?? [],
                    $plan->intensity,
                    $cutoff->toDateString(),
                    false,
                    $replaceRemovedModulesFrom->toDateString(),
                );

                $refreshed++;
            });

        return $refreshed;
    }

    public function refreshCoursesForModule(CourseModule $module): int
    {
        return $this->refreshCourses($this->coursesForModule($module));
    }

    public function courseIdsForModule(CourseModule $module): array
    {
        return $this->coursesForModule($module)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    public function refreshCoursesForTrack(CourseModuleTrack $track): int
    {
        $track->loadMissing(['courses', 'module.courses']);

        $courses = $track->courses;

        if ($track->module) {
            $courses = $courses->merge($this->coursesForModule($track->module));
        }

        return $this->refreshCourses($courses);
    }

    public function courseIdsForTrack(CourseModuleTrack $track): array
    {
        $track->loadMissing(['courses', 'module.courses']);

        $courses = $track->courses;

        if ($track->module) {
            $courses = $courses->merge($this->coursesForModule($track->module));
        }

        return $courses
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    public function refreshCoursesForLesson(Lesson $lesson): int
    {
        $lesson->loadMissing(['course', 'modules.courses', 'tracks.courses']);

        $courses = collect();

        if ($lesson->course) {
            $courses->push($lesson->course);
        }

        foreach ($lesson->modules as $module) {
            $courses = $courses->merge($this->coursesForModule($module));
        }

        foreach ($lesson->tracks as $track) {
            $courses = $courses->merge($track->courses);
        }

        return $this->refreshCourses($courses);
    }

    public function courseIdsForLesson(Lesson $lesson): array
    {
        $lesson->loadMissing(['course', 'modules.courses', 'tracks.courses']);

        $courses = collect();

        if ($lesson->course) {
            $courses->push($lesson->course);
        }

        foreach ($lesson->modules as $module) {
            $courses = $courses->merge($this->coursesForModule($module));
        }

        foreach ($lesson->tracks as $track) {
            $courses = $courses->merge($track->courses);
        }

        return $courses
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    public function refreshCoursesByIds(array $courseIds): int
    {
        return $this->refreshCourses(Course::query()->whereKey(array_unique(array_filter($courseIds)))->get());
    }

    protected function coursesForModule(CourseModule $module): Collection
    {
        $module->loadMissing(['courses']);

        return $module->courses;
    }

    protected function refreshCourses(Collection $courses): int
    {
        return $courses
            ->filter()
            ->unique('id')
            ->sum(fn (Course $course): int => $this->refreshCourseFromNextWeek($course));
    }

    protected function officialStudyTrackFor(Course $course): ?StudyTrack
    {
        return $course->studyTracks()
            ->where('is_active', true)
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->first();
    }

    protected function nextWeekStart(?CarbonInterface $referenceDate = null): Carbon
    {
        return ($referenceDate ? Carbon::parse($referenceDate->toDateString()) : now())
            ->startOfDay()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addWeek();
    }

    protected function thirdWeekStart(?CarbonInterface $referenceDate = null): Carbon
    {
        return ($referenceDate ? Carbon::parse($referenceDate->toDateString()) : now())
            ->startOfDay()
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addWeeks(3);
    }
}
