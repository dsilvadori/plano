<?php

namespace App\Livewire;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyPlan;
use App\Support\StudyTime;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class StudyPlanBuilder extends Component
{
    public ?StudyPlan $studyPlan = null;
    public ?int $course_id = null;
    public string $exam_date = '';
    public string $start_date = '';
    public array $available_days = [];
    public array $available_minutes_by_day = [];
    public string $intensity = 'balanced';
    public bool $exam_date_locked = false;
    public array $oldInput = [];

    public array $dayLabels = [
        'monday' => 'Segunda',
        'tuesday' => 'Terça',
        'wednesday' => 'Quarta',
        'thursday' => 'Quinta',
        'friday' => 'Sexta',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    public function mount(?StudyPlan $studyPlan = null, array $oldInput = []): void
    {
        $this->studyPlan = $studyPlan;
        $this->oldInput = $oldInput;

        if ($this->studyPlan) {
            $this->fillFromStudyPlan($this->studyPlan);
            $this->fillFromOldInput();

            return;
        }

        $this->resetBuilder();
        $this->fillFromOldInput();
    }

    public function resetBuilder(): void
    {
        $this->course_id = null;
        $this->start_date = now()->toDateString();
        $this->exam_date = '';
        $this->exam_date_locked = false;
        $this->available_days = [];
        $this->intensity = 'balanced';

        foreach (array_keys($this->dayLabels) as $day) {
            $this->available_minutes_by_day[$day] = '00:00';
        }
    }

    public function fillFromStudyPlan(StudyPlan $studyPlan): void
    {
        $this->course_id = $studyPlan->course_id;
        $this->start_date = $studyPlan->start_date?->toDateString() ?? now()->toDateString();
        $this->exam_date = $studyPlan->exam_date?->toDateString() ?? '';
        $this->available_days = $studyPlan->available_days ?? [];
        $this->intensity = $studyPlan->intensity ?: 'balanced';
        $this->syncExamDateFromCourse();

        foreach (array_keys($this->dayLabels) as $day) {
            $minutes = (int) ($studyPlan->available_minutes_by_day[$day] ?? 0);
            $this->available_minutes_by_day[$day] = $minutes > 0
                ? StudyTime::formatMinutes($minutes)
                : '00:00';
        }
    }

    protected function fillFromOldInput(): void
    {
        $oldInput = $this->oldInput !== [] ? $this->oldInput : request()->old();

        if (! is_array($oldInput) || $oldInput === []) {
            return;
        }

        if (array_key_exists('course_id', $oldInput)) {
            $this->course_id = filled($oldInput['course_id']) ? (int) $oldInput['course_id'] : null;
            $this->syncExamDateFromCourse();
        }

        if (array_key_exists('start_date', $oldInput)) {
            $this->start_date = (string) ($oldInput['start_date'] ?: now()->toDateString());
        }

        if (! $this->exam_date_locked && array_key_exists('exam_date', $oldInput)) {
            $this->exam_date = (string) ($oldInput['exam_date'] ?: '');
        }

        if (array_key_exists('available_days', $oldInput) && is_array($oldInput['available_days'])) {
            $this->available_days = array_values(array_filter($oldInput['available_days']));
        }

        if (array_key_exists('intensity', $oldInput)) {
            $this->intensity = in_array($oldInput['intensity'], ['light', 'balanced', 'intense'], true)
                ? $oldInput['intensity']
                : 'balanced';
        }

        $oldMinutes = is_array($oldInput['available_minutes_by_day'] ?? null)
            ? $oldInput['available_minutes_by_day']
            : [];

        foreach (array_keys($this->dayLabels) as $day) {
            if (array_key_exists($day, $oldMinutes)) {
                $this->available_minutes_by_day[$day] = StudyTime::normalizeForInput($oldMinutes[$day]);
            }
        }
    }

    public function updatedCourseId(): void
    {
        $this->course_id = filled($this->course_id) ? (int) $this->course_id : null;
        $this->syncExamDateFromCourse();
    }

    public function updated(string $property, mixed $value = null): void
    {
        if ($property !== 'course_id') {
            return;
        }

        $this->course_id = filled($value) ? (int) $value : null;
        $this->syncExamDateFromCourse();
    }

    public function selectCourse(mixed $courseId): void
    {
        $this->course_id = filled($courseId) ? (int) $courseId : null;
        $this->syncExamDateFromCourse();
    }

    public function updatedAvailableDays(): void
    {
        foreach (array_keys($this->dayLabels) as $day) {
            $isSelected = in_array($day, $this->available_days, true);
            $currentValue = $this->available_minutes_by_day[$day] ?? '00:00';

            if ($isSelected && $currentValue === '00:00') {
                $this->available_minutes_by_day[$day] = '02:00';
            }

            if (! $isSelected && $currentValue === '02:00') {
                $this->available_minutes_by_day[$day] = '00:00';
            }
        }
    }

    public function updatedAvailableMinutesByDay(mixed $value, string $day): void
    {
        $this->available_minutes_by_day[$day] = StudyTime::normalizeForInput($value);
    }

    public function render(): View
    {
        $user = Auth::user();
        $availableCoursesQuery = $user
            ->availableCoursesQuery();
        $hasAvailableCourses = (clone $availableCoursesQuery)->exists();
        $courses = $availableCoursesQuery
            ->select('courses.id', 'courses.name', 'courses.exam_date')
            ->where(function ($query) use ($user) {
                $query->whereDoesntHave('studyPlans', function ($planQuery) use ($user) {
                    $planQuery
                        ->where('user_id', $user->id)
                        ->where('status', 'active');
                });

                if ($this->studyPlan) {
                    $query->orWhere('courses.id', $this->studyPlan->course_id);
                }
            })
            ->orderBy('name')
            ->get();

        return view('livewire.study-plan-builder', [
            'courses' => $courses,
            'courseExamDates' => $courses
                ->mapWithKeys(fn (Course $course): array => [
                    (string) $course->id => $course->exam_date?->toDateString(),
                ])
                ->all(),
            'hasAvailableCourses' => $hasAvailableCourses,
            'minimumWeeklySuggestion' => $this->minimumWeeklyStudySuggestion(),
        ]);
    }

    public function minimumWeeklyStudySuggestion(): ?array
    {
        if (! $this->course_id || blank($this->exam_date) || blank($this->start_date)) {
            return null;
        }

        $start = Carbon::parse($this->start_date)->startOfDay();
        $exam = Carbon::parse($this->exam_date)->startOfDay();

        if ($exam->lt($start)) {
            return null;
        }

        $modules = $this->modulesForSuggestion();
        $theoryMinutes = (int) $modules->sum('workload_minutes');

        if ($theoryMinutes <= 0) {
            return null;
        }

        $practicePercent = match ($this->intensity) {
            'light' => 0.35,
            'intense' => 0.25,
            default => 0.30,
        };
        $practiceMinutes = (int) ceil($theoryMinutes * $practicePercent);
        $requiredMinutes = $theoryMinutes + $practiceMinutes;
        $weeks = max(1, (int) ceil(($start->diffInDays($exam) + 1) / 7));
        $minimumWeeklyMinutes = (int) ceil(($requiredMinutes / $weeks) / 15) * 15;
        $selectedDays = collect($this->available_days)
            ->filter(fn (string $day): bool => array_key_exists($day, $this->dayLabels))
            ->unique()
            ->values();
        $currentWeeklyMinutes = $selectedDays
            ->sum(fn (string $day): int => StudyTime::parseToMinutes($this->available_minutes_by_day[$day] ?? null));
        $minimumDailyAverage = $selectedDays->isNotEmpty()
            ? (int) ceil(($minimumWeeklyMinutes / $selectedDays->count()) / 15) * 15
            : null;

        return [
            'weeks' => $weeks,
            'theory_minutes' => $theoryMinutes,
            'practice_minutes' => $practiceMinutes,
            'required_minutes' => $requiredMinutes,
            'minimum_weekly_minutes' => $minimumWeeklyMinutes,
            'minimum_weekly_label' => StudyTime::formatMinutes($minimumWeeklyMinutes),
            'minimum_daily_average_minutes' => $minimumDailyAverage,
            'minimum_daily_average_label' => $minimumDailyAverage ? StudyTime::formatMinutes($minimumDailyAverage) : null,
            'current_weekly_minutes' => $currentWeeklyMinutes,
            'current_weekly_label' => StudyTime::formatMinutes($currentWeeklyMinutes),
            'selected_days_count' => $selectedDays->count(),
            'status' => $currentWeeklyMinutes >= $minimumWeeklyMinutes ? 'good' : 'warning',
            'deficit_minutes' => max(0, $minimumWeeklyMinutes - $currentWeeklyMinutes),
            'deficit_label' => StudyTime::formatMinutes(max(0, $minimumWeeklyMinutes - $currentWeeklyMinutes)),
        ];
    }

    protected function modulesForSuggestion(): \Illuminate\Support\Collection
    {
        $course = Course::query()
            ->with(['studyTracks' => fn ($query) => $query
                ->where('is_active', true)
                ->where('name', 'like', 'Trilha Oficial -%')
                ->orderBy('id')])
            ->find($this->course_id);

        if (! $course) {
            return collect();
        }

        $studyTrack = $this->studyPlan?->course_id === $course->id
            ? $this->studyPlan->studyTrack
            : $course->studyTracks->first();

        $modules = $studyTrack
            ? $studyTrack->modules()->where('course_modules.is_active', true)->get()
            : $course->modules()->where('is_active', true)->get();

        return $modules
            ->reject(fn (CourseModule $module): bool => $this->shouldSkipModule($module))
            ->values();
    }

    protected function shouldSkipModule(CourseModule $module): bool
    {
        return $module->shouldBeExcludedFromStudyPlan();
    }

    protected function syncExamDateFromCourse(): void
    {
        if (! $this->course_id) {
            $this->exam_date_locked = false;
            $this->exam_date = '';

            return;
        }

        $course = Course::query()->select('id', 'exam_date')->find($this->course_id);

        if (! $course?->exam_date) {
            $this->exam_date_locked = false;

            if ($this->studyPlan?->course_id !== $this->course_id) {
                $this->exam_date = '';
            }

            return;
        }

        $this->exam_date_locked = true;
        $this->exam_date = $course->exam_date->toDateString();
    }
}
