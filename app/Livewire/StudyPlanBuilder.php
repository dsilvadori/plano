<?php

namespace App\Livewire;

use App\Models\Course;
use App\Models\StudyPlan;
use App\Services\StudyPlanGenerator;
use App\Support\StudyTime;
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

    public array $dayLabels = [
        'monday' => 'Segunda',
        'tuesday' => 'Terça',
        'wednesday' => 'Quarta',
        'thursday' => 'Quinta',
        'friday' => 'Sexta',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];

    public function mount(?StudyPlan $studyPlan = null): void
    {
        $this->studyPlan = $studyPlan;

        if ($this->studyPlan) {
            $this->fillFromStudyPlan($this->studyPlan);

            return;
        }

        $this->resetBuilder();
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

    public function updatedCourseId(): void
    {
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
            ->select('courses.id', 'courses.name')
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
            'hasAvailableCourses' => $hasAvailableCourses,
        ]);
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
